<?php
/* rc.nut: a upsd that fails to start (a LISTEN address that comes up later, such as a Tailscale address at boot) is
 * retried in the background, and alerts only once the plugins are installed and a grace period has passed.
 * Run: php tests/test_upsd_retry.php */

$rc = file_get_contents(dirname(__DIR__) . '/source/nut3/etc/rc.d/rc.nut');
$failures = 0;
function check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

$tmp = sys_get_temp_dir() . '/nut-upsd-retry-' . bin2hex(random_bytes(12));
if (!mkdir("$tmp/misc", 0700, true)) throw new RuntimeException('Cannot create test sandbox');

$fns = '';
foreach (['start_upsd', 'upsd_retry', 'upsd_try'] as $name) {
    check(preg_match("/^$name\\(\\) \\{.*?^\\}$/ms", $rc, $m) === 1, "rc.nut has $name");
    $fns .= ($m[0] ?? '') . "\n";
}
$fns = str_replace(['/usr/local/emhttp/webGui/scripts/notify', '/usr/sbin/upsd'], ["$tmp/notify", 'upsd'], $fns);
file_put_contents("$tmp/notify", "#!/bin/bash\necho \"\$@\" >> '$tmp/notify.calls'\n");
chmod("$tmp/notify", 0755);

/* Runs $body after the functions, with stubs: a clock that sleep advances, pgrep answering from marker files
 * ($tmp/running.<name>), and RC_SCRIPT, the script upsd_retry calls for each try, answering from $tmp/tries
 * (one exit code per try, the last one repeats; "emhttpd" in a line also starts emhttpd from that try on). */
function run($body) {
    global $tmp, $fns;
    foreach (glob("$tmp/{notify.calls,log,n,spawned,running.*,upsd.*,misc/start-failed}", GLOB_BRACE) as $f) unlink($f);
    $stubs = <<<SH
sleep() { SECONDS=\$((SECONDS + \${1%.*})); }
pgrep() { local name="\${@: -1}"; name="\${name##*/}"; name="\${name%\\\$}"; [ -f "$tmp/running.\$name" ]; }
pkill() { :; }
timeout() { shift 2; "\$@"; }
logger() { shift 2; echo "\$*" >> "$tmp/log"; }
nohup() { echo "\$*" >> "$tmp/spawned"; }
upsd() { [ -f "$tmp/upsd.ok" ] && touch "$tmp/running.upsd"; [ -f "$tmp/upsd.ok" ]; }
DOCROOT='$tmp'; UPSD_RETRY_SECONDS=10; UPSD_GRACE_SECONDS=300
RC_SCRIPT='$tmp/rc'
SH;
    file_put_contents("$tmp/rc", <<<SH
#!/bin/bash
n=0; [ -f "$tmp/n" ] && read -r n < "$tmp/n"; echo \$((n + 1)) > "$tmp/n"
line=\$(sed -n "\$((n + 1))p" "$tmp/tries"); [ -n "\$line" ] || line=\$(tail -n 1 "$tmp/tries")
case "\$line" in *emhttpd*) touch "$tmp/running.emhttpd" ;; esac
exit "\${line%% *}"
SH);
    chmod("$tmp/rc", 0755);
    exec('bash -c ' . escapeshellarg("$stubs\n$fns\n$body") . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$read = fn($name) => is_file("$tmp/$name") ? file_get_contents("$tmp/$name") : '';
$tries = fn() => (int)trim($read('n'));
$alerts = fn() => substr_count($read('notify.calls'), 'upsd failed to start');

// upsd starts on a later try: no alert
file_put_contents("$tmp/tries", "1\n1\n0\n");
run('upsd_retry');
check($tries() === 3 && $alerts() === 0 && !is_file("$tmp/misc/start-failed"), 'a upsd that starts on a retry alerts nothing');

// NUT stopped meanwhile: the retry ends without an alert
file_put_contents("$tmp/tries", "1\n2\n");
run('upsd_retry');
check($tries() === 2 && $alerts() === 0, 'a stop ends the retry quietly');

// The grace period starts once emhttpd runs: plugins install for 2 minutes, then 5 more minutes of retries
file_put_contents("$tmp/tries", str_repeat("1\n", 11) . "1 emhttpd\n1\n");
run('upsd_retry');
check($tries() === 12 + 30 && $alerts() === 1, 'the alert comes once, 5 minutes after emhttpd started: ' . $tries() . ' tries');
check(is_file("$tmp/misc/start-failed") && strpos($read('log'), 'giving up') !== false, 'giving up marks the start failed and logs it');
check(strpos($read('notify.calls'), '-i alert') !== false, 'the notification is an alert');

// A upsd that starts during the grace period alerts nothing
file_put_contents("$tmp/tries", "1 emhttpd\n" . str_repeat("1\n", 20) . "0\n");
run('upsd_retry');
check($tries() === 22 && $alerts() === 0, 'a upsd starting within the grace period alerts nothing');

// emhttpd never starts: the grace period begins after a 5-minute wait
file_put_contents("$tmp/tries", "1\n");
run('upsd_retry');
check($tries() === 60 && $alerts() === 1, 'without emhttpd the alert comes after 10 minutes: ' . $tries() . ' tries');

// upsd_try: 2 without upsmon, 0 when upsd already runs, 1 when it fails, 0 and a log line when it starts
check(trim(run('upsd_try; echo $?')[1]) === '2', 'upsd_try without upsmon reports a stop');
check(trim(run("touch '$tmp/running.upsmon'; touch '$tmp/running.upsd'; upsd_try; echo \$?")[1]) === '0', 'upsd_try with upsd running reports success');
check(trim(run("touch '$tmp/running.upsmon'; upsd_try; echo \$?")[1]) === '1', 'upsd_try reports a failed start');
check(trim(run("touch '$tmp/running.upsmon' '$tmp/upsd.ok'; upsd_try; echo \$?")[1]) === '0' && strpos($read('log'), 'started after a retry') !== false, 'upsd_try starts upsd and logs it');

// start_upsd: a failed start spawns one background retry and still succeeds, so upsmon starts after it
[$code, $out] = run('start_upsd; echo "rc=$?"');
check(strpos($out, 'rc=0') !== false && trim($read('spawned')) === "$tmp/rc upsd_retry", 'a failed upsd start spawns the retry: ' . $out . $read('spawned'));
run("touch '$tmp/running.rc upsd_retry'; start_upsd");
check($read('spawned') === '', 'a retry already running is not spawned twice');
run("touch '$tmp/upsd.ok'; start_upsd");
check($read('spawned') === '', 'a upsd that starts spawns no retry');

exec('rm -rf ' . escapeshellarg($tmp));
if ($failures) {
    fwrite(STDERR, "$failures check(s) failed\n");
    exit(1);
}
echo "OK\n";
