<?php
/* rc.nut: a upsd that cannot listen on every LISTEN address (such as a Tailscale address at boot) is retried in the
 * background, alerting only once the plugins are installed and a grace period has passed; other failures still fail
 * the start. Also nut_slave_clients(), which lists slaves forwarded from 127.0.0.1.
 * Run: php tests/test_upsd_retry.php */

$src = dirname(__DIR__) . '/source/nut3';
$rc = file_get_contents("$src/etc/rc.d/rc.nut");
$failures = 0;
function check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}
function fn_body($text, $name) {
    check(preg_match("/^$name\\(\\) \\{.*?^\\}$/ms", $text, $m) === 1, "$name exists");
    return $m[0] ?? '';
}

$tmp = sys_get_temp_dir() . '/nut-upsd-retry-' . bin2hex(random_bytes(12));
if (!mkdir("$tmp/misc", 0700, true)) throw new RuntimeException('Cannot create test sandbox');

check(preg_match('/^UPSD_RETRY_MATCH=.*$/m', $rc, $mm) === 1, 'rc.nut sets UPSD_RETRY_MATCH');
$match = $mm[0] ?? '';
$fns = fn_body($rc, 'start_upsd') . "\n" . fn_body($rc, 'upsd_retry') . "\n" . fn_body($rc, 'upsd_try');
$fns = str_replace(['/usr/local/emhttp/webGui/scripts/notify', '/usr/sbin/upsd'], ["$tmp/notify", 'upsd'], $fns);
file_put_contents("$tmp/notify", "#!/bin/bash\necho \"\$@\" >> '$tmp/notify.calls'\n");
// RC_SCRIPT, called by upsd_retry for each try: one exit code per line of $tmp/tries, the last one repeats;
// "emhttpd" in a line also starts emhttpd from that try on
file_put_contents("$tmp/rc", <<<SH
#!/bin/bash
n=0; [ -f "$tmp/n" ] && read -r n < "$tmp/n"; echo \$((n + 1)) > "$tmp/n"
line=\$(sed -n "\$((n + 1))p" "$tmp/tries"); [ -n "\$line" ] || line=\$(tail -n 1 "$tmp/tries")
case "\$line" in *emhttpd*) touch "$tmp/running.emhttpd" ;; esac
[ "\$n" -lt 500 ] || exit 0  # a broken retry loop fails the counts instead of hanging the suite
exit "\${line%% *}"
SH);
chmod("$tmp/notify", 0755);
chmod("$tmp/rc", 0755);

/* Runs $body after the functions, with stubs: a clock that sleep advances (each sleep is logged), pgrep answering
 * from marker files ($tmp/running.<name>), and upsd answering from $tmp/upsd.out and $tmp/upsd.ok. */
function run($body) {
    global $tmp, $fns, $match;
    foreach (glob("$tmp/{notify.calls,log,n,sleeps,spawned,running.*,upsd.*,misc/start-failed}", GLOB_BRACE) as $f) unlink($f);
    $stubs = <<<SH
sleep() { echo "\$1" >> "$tmp/sleeps"; SECONDS=\$((SECONDS + \$1)); }
pgrep() { local name="\${@: -1}"; name="\${name##*/}"; name="\${name%\\\$}"; [ -f "$tmp/running.\$name" ]; }
timeout() { shift 2; "\$@"; }
logger() { shift 2; echo "\$*" >> "$tmp/log"; }
nohup() { echo "\$*" >> "$tmp/spawned"; }
error_at_start() { echo error_at_start; exit 1; }
upsd() { cat "$tmp/upsd.out" >&2 2>/dev/null; [ -f "$tmp/upsd.ok" ] && touch "$tmp/running.upsd"; [ -f "$tmp/upsd.ok" ]; }
UPSD_RETRY_SECONDS=10; UPSD_GRACE_SECONDS=300; MODE=netserver; DOCROOT='$tmp'
RC_SCRIPT='$tmp/rc'; $match
SH;
    exec('bash -c ' . escapeshellarg("$stubs\n$fns\n$body") . ' 2>&1', $out);
    return implode("\n", $out);
}
$read = fn($name) => is_file("$tmp/$name") ? file_get_contents("$tmp/$name") : '';
$tries = fn() => (int)trim($read('n'));
$alerts = fn() => substr_count($read('notify.calls'), '-s NUT: upsd cannot listen');

// upsd starts on a later try: no alert
file_put_contents("$tmp/tries", "1\n1\n0\n");
run('upsd_retry');
check($tries() === 3 && $alerts() === 0, 'a upsd that starts on a retry alerts nothing');

// An unexpected exit code (a missing command, a bash syntax error, a killed try) keeps retrying
file_put_contents("$tmp/tries", "127\n2\n143\n0\n");
run('upsd_retry');
check($tries() === 4 && $read('notify.calls') === '', 'an unexpected exit code is retried, not taken for success or a stop');

// NUT stopped meanwhile: the retry ends without an alert
file_put_contents("$tmp/tries", "1\n3\n");
run('upsd_retry');
check($tries() === 2 && $alerts() === 0, 'a stop ends the retry quietly');

// The grace period starts once emhttpd runs: plugins install for 2 minutes, then 5 more minutes of retries, then the
// retry goes on, backing off from 1 to 15 minutes, until upsd starts
file_put_contents("$tmp/tries", str_repeat("1\n", 11) . "1 emhttpd\n" . str_repeat("1\n", 36) . "0\n");
run('upsd_retry');
$sleeps = array_map('intval', file("$tmp/sleeps"));
check($tries() === 49 && $alerts() === 1 && !is_file("$tmp/misc/start-failed"), 'one alert, 5 minutes after emhttpd started, then retries until upsd starts: ' . $tries() . ' tries');
check(array_slice($sleeps, 0, 42) === array_fill(0, 42, 10) && array_slice($sleeps, 42) === [60, 120, 240, 480, 900, 900, 900], 'retries every 10 s, then backs off to 15 minutes after the alert');
check(strpos($read('notify.calls'), '-i alert') !== false && strpos($read('log'), 'backoff') !== false, 'the alert is an Unraid alert and is logged');
check(substr_count($read('notify.calls'), '-s NUT: upsd is listening again') === 1 && strpos($read('notify.calls'), '-i normal') !== false, 'a upsd starting after the alert sends a normal notice');

// A upsd that starts during the grace period alerts nothing
file_put_contents("$tmp/tries", "1 emhttpd\n" . str_repeat("1\n", 20) . "0\n");
run('upsd_retry');
check($tries() === 22 && $alerts() === 0, 'a upsd starting within the grace period alerts nothing');

// emhttpd never starts: the grace period begins after a 5-minute wait
file_put_contents("$tmp/tries", str_repeat("1\n", 60) . "0\n");
run('upsd_retry');
check($tries() === 61 && $alerts() === 1, 'without emhttpd the alert comes after 10 minutes: ' . $tries() . ' tries');

// upsd_try: 2 without upsmon or in slave mode, 0 when upsd already runs, 1 when it fails, 0 and a log line on a start
check(trim(run('upsd_try; echo $?')) === '3', 'upsd_try without upsmon reports a stop');
check(trim(run("touch '$tmp/running.upsmon'; MODE=slave; upsd_try; echo \$?")) === '3', 'upsd_try in slave mode reports a stop');
check(trim(run("touch '$tmp/running.upsmon' '$tmp/running.upsd'; upsd_try; echo \$?")) === '0', 'upsd_try with upsd running reports success');
check(trim(run("touch '$tmp/running.upsmon'; upsd_try; echo \$?")) === '1', 'upsd_try reports a failed start');
check(trim(run("touch '$tmp/running.upsmon' '$tmp/upsd.ok'; upsd_try; echo \$?")) === '0' && strpos($read('log'), 'started after a retry') !== false, 'upsd_try starts upsd and logs it');

// start_upsd: a missing address spawns one retry and lets the start go on; any other failure fails the start
$bind = "not listening on 100.64.0.1 port 3493\nFatal error: some listening interfaces were not available\n";
$out = run("printf '%s' '$bind' > '$tmp/upsd.out'; start_upsd; echo rc=\$?");
check(strpos($out, 'rc=0') !== false && strpos($out, 'not listening on') !== false && trim($read('spawned')) === "$tmp/rc upsd_retry", 'a missing address spawns the retry and shows upsd\'s messages: ' . $out);
run("printf '%s' '$bind' > '$tmp/upsd.out'; touch '$tmp/running.rc upsd_retry'; start_upsd");
check($read('spawned') === '', 'a retry already running is not spawned twice');
foreach (["getaddrinfo('nas.tailnet.ts.net'): Temporary failure in name resolution", 'no listening interface available'] as $line) {
    run("echo \"$line\" > '$tmp/upsd.out'; start_upsd");
    check(trim($read('spawned')) === "$tmp/rc upsd_retry", "upsd exiting with '$line' is retried");
}
foreach (['Fatal error: unusable configuration', "not listening on 100.64.0.1 port 3493\nWARNING: some listening interfaces were not available, but the ALLOW_NOT_ALL_LISTENERS setting is active\nstat /x.pem: No such file or directory"] as $text) {
    $out = run("printf '%b\\n' '$text' > '$tmp/upsd.out'; start_upsd; echo rc=\$?");
    check(strpos($out, 'error_at_start') !== false && strpos($out, 'rc=0') === false && $read('spawned') === '', 'another upsd failure still fails the start: ' . $out);
}
$out = run("touch '$tmp/upsd.ok'; start_upsd; echo rc=\$?");
check(strpos($out, 'rc=0') !== false && $read('spawned') === '', 'a upsd that starts spawns no retry');

// The tries take the service lock, and a stop ends a pending retry
check(preg_match('/^    [a-z_|]*\bupsd_try\b[a-z_|]*\)\n        if \[ -z "\$RCNUT_LOCKED" \]/m', $rc) === 1, 'upsd_try runs under the service lock');
check(preg_match('/^    upsd_try\) .*\n        upsd_try\n        exit \$\?$/m', $rc) === 1, 'rc.nut upsd_try exits with upsd_try\'s status');
check(strpos(fn_body($rc, 'stop'), 'pkill -f "$UPSD_RETRY_MATCH"') !== false, 'stop ends a pending retry');
$cmds = "/bin/bash /etc/rc.d/rc.nut upsd_retry\n/etc/rc.d/rc.nut upsd_retry\npgrep -f /etc/rc.d/rc.nut upsd_retry\n/bin/bash /etc/rc.d/rc.nut upsd_retry_x\n";
exec('bash -c ' . escapeshellarg("RC_SCRIPT=/etc/rc.d/rc.nut; $match; printf '%s' " . escapeshellarg($cmds) . ' | grep -E "$UPSD_RETRY_MATCH"'), $hits);
check($hits === ['/bin/bash /etc/rc.d/rc.nut upsd_retry', '/etc/rc.d/rc.nut upsd_retry'], 'the retry match is anchored to the retry process: ' . implode(' | ', $hits));

// nut_slave_clients: drops one login from the master address, labels the others
$cfg = file_get_contents("$src/usr/local/emhttp/plugins/nut3/include/nut_config.php");
check(preg_match('/^function nut_slave_clients\(.*?^\}$/ms', $cfg, $m) === 1, 'nut_config.php has nut_slave_clients');
eval($m[0] ?? '');
check(nut_slave_clients(['127.0.0.1'], '127.0.0.1') === [], 'the local upsmon alone is no slave');
check(nut_slave_clients(['100.64.0.5', '127.0.0.1', '127.0.0.1'], '127.0.0.1:3493') === ['100.64.0.5', '127.0.0.1 (local or forwarded)'], 'a forwarded login from the master address is listed');
check(nut_slave_clients(['10.0.0.2'], '127.0.0.1') === ['10.0.0.2'], 'without the local login nothing is dropped');
check(nut_slave_clients(['10.0.0.9', '127.0.0.1'], '10.0.0.9:3493') === ['127.0.0.1 (local or forwarded)'], 'a loopback login is labelled when the master address is a LAN address');
check(nut_slave_clients(['::1', '::1'], '[::1]:3493') === ['::1 (local or forwarded)'] && nut_slave_clients(['::1'], '::1') === [], 'an IPv6 master address, bracketed with a port or bare, drops the local login');

exec('rm -rf ' . escapeshellarg($tmp));
if ($failures) {
    fwrite(STDERR, "$failures check(s) failed\n");
    exit(1);
}
echo "OK\n";
