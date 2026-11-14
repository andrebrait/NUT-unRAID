<?php
/* Behavioural tests for the shutdown path: nut-notify (cancel on line, recheck before FSD, no-data timeout,
 * delayed alerts never delay a shutdown) and rc.nut (restart ordering, invalid settings, serialisation,
 * stale workers, driver start retries, UPS power-off targets). Run: php tests/test_david_notify.php
 *
 * The real scripts run in isolated processes. Every absolute system path in a copy of the script is redirected
 * into a throw-away sandbox directory, and upsmon, upsd, upsdrvctl, upssched, upsc, php, wall, logger, pkill -x
 * and killall are stubs that only write to the sandbox. `upsmon -c fsd` is a stub that records "FSD", so no test
 * can shut anything down. Real pgrep, pkill -f, flock, timeout and tee are used, and only ever see processes
 * whose command line contains the sandbox path.
 *
 * Limitations: Linux only (/proc), needs flock/pgrep/pkill/timeout/tee/mkfifo. The no-data timeout runs on a
 * fake clock (a `sleep` function that advances $SECONDS), the cancellation and stale worker tests use real time
 * and take about 10 seconds. The script copies are path-rewritten, so a new absolute path in rc.nut or
 * nut-notify that the rewrite does not know makes the harness stop instead of touching the host. upsmon, upsd
 * and the drivers are not run, only their call order and the configuration they would see are checked. */

$repo = dirname(__DIR__);
$srcRc = "$repo/source/nut3/etc/rc.d/rc.nut";
$srcNotify = "$repo/source/nut3/usr/sbin/nut-notify";

$failures = 0;
$procs = [];
$sandboxes = [];
$runCount = 0;

function check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

function skip($why) {
    echo "SKIPPED: $why\n";
    exit(0);
}

function tool($name) {
    return trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
}

if (PHP_OS_FAMILY !== 'Linux') skip('needs Linux /proc');
foreach (['bash', 'flock', 'pgrep', 'pkill', 'timeout', 'tee', 'awk', 'mkfifo'] as $name) {
    if (tool($name) === '') skip("$name is not installed");
}
$realPgrep = tool('pgrep');
$realPkill = tool('pkill');

register_shutdown_function(function () {
    global $procs, $sandboxes;
    foreach ($procs as $p) {
        $s = @proc_get_status($p);
        if ($s && $s['running']) @proc_terminate($p, 9);
    }
    foreach ($sandboxes as $sb) exec('rm -rf ' . escapeshellarg($sb));
});

function put($path, $body) {
    file_put_contents($path, "#!/bin/bash\n$body");
    chmod($path, 0755);
}

/* Send the system paths the scripts use into the sandbox; stop if a path is left over. */
function sb_rewrite($text, $sb, $what) {
    $out = preg_replace_callback(
        '~(?<![A-Za-z0-9_.$}/-])(/(?:usr/local/emhttp|usr/sbin|usr/libexec|etc|var|boot|sys|tmp)/|/usr/bin/(?:php|upsc)\b)~',
        fn($m) => $sb . $m[1],
        $text
    );
    $left = str_replace($sb, '@SB@', $out);
    if (preg_match('~(?<![A-Za-z0-9_.$}/@-])/(?:usr/sbin|usr/libexec|usr/local|usr/bin|etc|var|boot|sys|root|home|mnt|opt|tmp)/~', $left, $m)) {
        fwrite(STDERR, "The test harness cannot sandbox a path in $what ({$m[0]}), refusing to run it.\n");
        exit(1);
    }
    return $out;
}

function sb_new($name) {
    global $srcRc, $srcNotify, $realPgrep, $realPkill, $sandboxes;
    $sb = sys_get_temp_dir() . '/nut-notify-test-' . bin2hex(random_bytes(12)) . "-$name";
    if (!mkdir($sb, 0700)) throw new RuntimeException('Cannot create test sandbox');
    foreach (['bin', 'state', 'etc/nut', 'etc/rc.d', 'boot/config/plugins/nut3/ups', 'boot/logs', 'usr/sbin', 'usr/bin',
        'usr/libexec/nut', 'usr/local/emhttp/plugins/nut3/misc', 'usr/local/emhttp/plugins/nut3/scripts',
        'usr/local/emhttp/plugins/dynamix/scripts', 'usr/local/emhttp/webGui/scripts', 'var/run/nut', 'var/state/ups', 'tmp'] as $dir) {
        mkdir("$sb/$dir", 0777, true);
    }
    $sandboxes[] = $sb;

    $stubs = [
        'bin/logger' => <<<'SH'
tag=""; [ "$1" = "-t" ] && tag="$2"
while IFS= read -r line; do printf '%s: %s\n' "$tag" "$line" >> "$SB/log"; done
exit 0
SH,
        'bin/wall' => 'cat >> "$SB/wall"',
        'bin/pgrep' => <<<'SH'
if [ "$1" = "-x" ]; then [ -e "$SB/state/$2" ]; exit $?; fi
exec @PGREP@ "$@"
SH,
        'bin/pkill' => <<<'SH'
echo "$*" >> "$SB/pkill.calls"
if [ "$1" = "-x" ]; then exit 1; fi
exec @PKILL@ "$@"
SH,
        'bin/killall' => <<<'SH'
[ "$1" = "-9" ] && shift
[ -e "$SB/state/$1" ] || exit 1
rm -f "$SB/state/$1"
SH,
        'usr/sbin/upsmon' => <<<'SH'
case "$*" in
    "-c stop")   echo upsmon-stop >> "$SB/events"; rm -f "$SB/state/upsmon" ;;
    "-p")        echo upsmon-start >> "$SB/events"; touch "$SB/state/upsmon" ;;
    "-c fsd")    echo FSD >> "$SB/events" ;;
    "-c reload") echo upsmon-reload >> "$SB/events" ;;
esac
exit 0
SH,
        'usr/sbin/upsd' => <<<'SH'
case "$*" in
    "-c stop")   echo upsd-stop >> "$SB/events"; rm -f "$SB/state/upsd" ;;
    "-u root")   echo upsd-start >> "$SB/events"; touch "$SB/state/upsd" ;;
    "-c reload") echo upsd-reload >> "$SB/events" ;;
esac
exit 0
SH,
        'usr/sbin/upsdrvctl' => <<<'SH'
case "$*" in
    "stop")            echo "drvctl-stop old=$(cat "$SB/etc/nut/ups.conf")" >> "$SB/events" ;;
    "-u root -- start "?*) echo "drvctl-start-one $5 conf=$(cat "$SB/etc/nut/ups.conf" 2>/dev/null)" >> "$SB/events"
        # driver.retry: the single driver comes up (its pid file names this running process), otherwise it keeps failing
        if [ -e "$SB/driver.retry" ]; then echo "$PPID" > "$SB/var/run/nut/usbhid-ups-$5.pid"; else exit 1; fi ;;
    "-u root start")   echo "drvctl-start conf=$(cat "$SB/etc/nut/ups.conf" 2>/dev/null)" >> "$SB/events"; [ -e "$SB/driver.fail" ] && exit 1 ;;
    "-u root -- shutdown "*) echo "drvctl-shutdown ${*:5}" >> "$SB/events" ;;
    *shutdown*)        echo "drvctl-shutdown-bad $*" >> "$SB/events" ;;
esac
exit 0
SH,
        'usr/sbin/upssched' => <<<'SH'
echo "upssched UPSNAME=$UPSNAME TYPE=$NOTIFYTYPE" >> "$SB/events"
exit "${UPSSCHED_RC:-0}"
SH,
        'usr/bin/upsc' => <<<'SH'
var="$2"; seq="$SB/upsc.seq.$var"
if [ -f "$seq" ]; then
    n=0; [ -f "$SB/upsc.n.$var" ] && read -r n < "$SB/upsc.n.$var"
    echo $((n + 1)) > "$SB/upsc.n.$var"
    awk -v n=$((n + 1)) 'NR == n { print; exit }' "$seq"
    exit 0
fi
[ -f "$SB/upsc.$var" ] && cat "$SB/upsc.$var"
exit 0
SH,
        'usr/bin/php' => <<<'SH'
if [ -n "$PHP_SLEEP" ]; then
    echo generate-begin >> "$SB/events"; command sleep "$PHP_SLEEP"; echo generate-end >> "$SB/events"
else
    echo generate >> "$SB/events"
fi
[ -e "$SB/php.fail" ] && exit 1
conf="$SB/boot/config/plugins/nut3/ups/ups.conf"
if [ -e "$SB/ups.gen" ]; then cp "$SB/ups.gen" "$conf"; elif [ -e "$SB/driver.fail" ]; then echo '[ups]' > "$conf"; else echo NEW > "$conf"; fi
[ -e "$SB/ups.gone" ] && rm -f "$conf" "$SB/etc/nut/ups.conf"
exit 0
SH,
        'etc/rc.d/rc.nutstats' => 'echo "nutstats-$1" >> "$SB/events"',
        'usr/local/emhttp/plugins/dynamix/scripts/notify' => 'echo "$@" >> "$SB/notify.calls"',
        'usr/local/emhttp/webGui/scripts/notify' => 'echo "$@" >> "$SB/driver-notify.calls"',
    ];
    foreach ($stubs as $path => $body) {
        put("$sb/$path", str_replace(['@PGREP@', '@PKILL@'], [$realPgrep, $realPkill], $body) . "\n");
    }
    file_put_contents("$sb/benv-nosleep", "sleep() { :; }\n");
    // a fake clock: the no-data timeout is measured in $SECONDS
    file_put_contents("$sb/benv-fast", "sleep() { SECONDS=\$((SECONDS + \${1%%.*})); }\n");
    file_put_contents("$sb/etc/rc.d/rc.6", "remount,ro\n# Now halt\n");
    file_put_contents("$sb/etc/nut/ups.conf", "OLD\n");
    file_put_contents("$sb/boot/config/plugins/nut3/ups/ups.conf", "OLD\n");
    exec('mkfifo ' . escapeshellarg("$sb/fifo"));

    file_put_contents("$sb/etc/rc.d/rc.nut", sb_rewrite(file_get_contents($srcRc), $sb, 'rc.nut'));
    chmod("$sb/etc/rc.d/rc.nut", 0755);
    file_put_contents("$sb/usr/sbin/nut-notify", sb_rewrite(file_get_contents($srcNotify), $sb, 'nut-notify'));
    chmod("$sb/usr/sbin/nut-notify", 0755);
    sb_cfg($sb, []);
    return $sb;
}

function sb_cfg($sb, array $vars) {
    $vars += ['SERVICE' => 'enable', 'MODE' => 'standalone', 'NAME' => 'ups', 'IPADDR' => '127.0.0.1', 'SHUTDOWN' => 'sec_timer',
        'TIMEOUT' => '300', 'BATTERYLEVEL' => '20', 'RTVALUE' => '240', 'RTUNIT' => 'seconds', 'RUNTIME' => 'battery.runtime',
        'REPLBATTMSG' => 'disable', 'SYSLOGMETHOD' => 'syslog', 'SYSLOGFILTER' => 'disable', 'SYSLOGBACKUP' => 'disable'];
    $text = '';
    foreach ($vars as $key => $value) $text .= "$key=\"$value\"\n";
    file_put_contents("$sb/boot/config/plugins/nut3/nut3.cfg", $text);
}

function spawn($sb, array $cmd, array $env = [], $tag = 'proc') {
    global $procs;
    $env += ['PATH' => "$sb/bin:" . getenv('PATH'), 'SB' => $sb, 'HOSTNAME' => 'tower', 'LC_ALL' => 'C', 'HOME' => $sb, 'TERM' => 'dumb'];
    $out = "$sb/$tag.out";
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'a'], 2 => ['file', $out, 'a']], $pipes, $sb, $env);
    $procs[] = $p;
    return $p;
}

function is_running($p) {
    $s = proc_get_status($p);
    return $s['running'];
}

/* Wait for the process to end and return its exit code (-1 if it had to be killed). */
function finish($p, $timeout = 60) {
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $s = proc_get_status($p);
        if (!$s['running']) return $s['exitcode'];
        usleep(20000);
    }
    proc_terminate($p, 9);
    return -1;
}

function wait_for(callable $condition, $timeout = 8.0) {
    $deadline = microtime(true) + $timeout;
    do {
        if ($condition()) return true;
        usleep(30000);
    } while (microtime(true) < $deadline);
    return false;
}

function run($sb, array $cmd, array $env = []) {
    global $runCount;
    $tag = 'run' . ++$runCount;
    $code = finish(spawn($sb, array_merge(['timeout', '60'], $cmd), $env, $tag), 70);
    return [$code, is_file("$sb/$tag.out") ? file_get_contents("$sb/$tag.out") : ''];
}

function events($sb) {
    return is_file("$sb/events") ? array_values(array_filter(explode("\n", file_get_contents("$sb/events")), 'strlen')) : [];
}

function read_file($sb, $name) {
    return is_file("$sb/$name") ? file_get_contents("$sb/$name") : '';
}

function log_count($sb, $needle) {
    return substr_count(read_file($sb, 'log'), $needle);
}

function wait_log($sb, $needle, $count = 1, $timeout = 5.0) {
    return wait_for(fn() => log_count($sb, $needle) >= $count, $timeout);
}

function notify_run($sb, $type, $ups = 'ups@127.0.0.1', array $env = [], $fast = true) {
    $env += ['NOTIFYTYPE' => $type];
    if ($ups !== null) $env['UPSNAME'] = $ups;
    if ($fast) $env['BASH_ENV'] = "$sb/benv-fast";
    return run($sb, ["$sb/usr/sbin/nut-notify", "$type event"], $env);
}

function worker($sb, $ups, $tag) {
    return spawn($sb, ["$sb/usr/sbin/nut-notify", 'UPS on battery'], ['NOTIFYTYPE' => 'ONBATT'] + ($ups === null ? [] : ['UPSNAME' => $ups]), $tag);
}

function rc($sb, $arg, array $env = []) {
    return run($sb, ['bash', "$sb/etc/rc.d/rc.nut", $arg], $env + ['BASH_ENV' => "$sb/benv-nosleep"]);
}

function services_up($sb) {
    touch("$sb/state/upsmon");
    touch("$sb/state/upsd");
}

$monitoring = 'Shutdown criteria are now being monitored';

// ================= nut-notify: an ONLINE event cancels the shutdown worker of the same UPS only
$sb = sb_new('cancel');
$w1 = worker($sb, 'ups@127.0.0.1', 'w1');
check(wait_log($sb, $monitoring, 1), 'the first worker monitors the shutdown criteria');
sb_cfg($sb, ['NAME' => 'other', 'IPADDR' => '10.0.0.2']);
$w2 = worker($sb, 'other@10.0.0.2', 'w2');
check(wait_log($sb, $monitoring, 2), 'the second worker (another UPS) monitors its criteria');
sb_cfg($sb, []);
[$code] = notify_run($sb, 'ONLINE', 'ups@127.0.0.1', [], false);
check($code === 0, 'the ONLINE handler exits cleanly');
check(wait_for(fn() => !is_running($w1), 5), 'ONLINE ends the shutdown worker of the same UPS');
// a worker acts on SIGTERM when its one second sleep ends: wait past that, so a signal sent to every worker shows
usleep(2500000);
check(is_running($w2), 'ONLINE leaves the shutdown worker of another UPS running');
check(log_count($sb, 'has received a termination signal') === 1, 'only one worker received the termination signal');
check(!in_array('FSD', events($sb), true), 'no FSD was set while cancelling');
proc_terminate($w2, 15);
finish($w2, 5);

// a legacy ONLINE event without UPSNAME cancels every worker
$sb = sb_new('cancel-all');
$a = worker($sb, 'ups@127.0.0.1', 'a');
$b = worker($sb, 'ups@127.0.0.1', 'b');
check(wait_log($sb, $monitoring, 2), 'two workers monitor the criteria');
notify_run($sb, 'ONLINE', null, [], false);
check(wait_for(fn() => !is_running($a) && !is_running($b), 5), 'ONLINE without UPSNAME ends all workers');
check(!in_array('FSD', events($sb), true), 'no FSD after cancelling all workers');

// ================= nut-notify: power that came back is rechecked right before FSD
$sb = sb_new('recheck');
sb_cfg($sb, ['TIMEOUT' => '3']);
file_put_contents("$sb/upsc.ups.status", "OL\n");
[$code] = notify_run($sb, 'ONBATT');
check($code === 0, 'the worker exits cleanly when power is back');
check(!in_array('FSD', events($sb), true), 'no FSD when the UPS reports OL right before the point of no return');
check(wait_log($sb, 'FSD cancelled', 1), 'the cancelled FSD is logged');

$sb = sb_new('fsd');
sb_cfg($sb, ['TIMEOUT' => '3']);
file_put_contents("$sb/upsc.ups.status", "OB DISCHRG\n");
[$code] = notify_run($sb, 'ONBATT');
check($code === 0 && events($sb) === ['FSD'], 'still on battery after the timer: FSD is set exactly once: ' . implode(',', events($sb)));
check(log_count($sb, 'Setting FSD') === 1, 'setting FSD is logged');

// ================= nut-notify: no usable battery data for 120 seconds while on battery
$sb = sb_new('nodata');
sb_cfg($sb, ['SHUTDOWN' => 'batt_level', 'BATTERYLEVEL' => '20']);
file_put_contents("$sb/upsc.ups.status", "OB DISCHRG\n");
[$code] = notify_run($sb, 'ONBATT');
check($code === 0 && events($sb) === ['FSD'], 'without any battery data the shutdown is not skipped: ' . implode(',', events($sb)));
check(wait_log($sb, 'No usable battery.charge data from [ups@127.0.0.1] for 120 seconds', 1), 'the data timeout is logged');

$sb = sb_new('stale');
sb_cfg($sb, ['SHUTDOWN' => 'batt_level', 'BATTERYLEVEL' => '20']);
file_put_contents("$sb/upsc.ups.status", "OB DISCHRG\n");
file_put_contents("$sb/upsc.battery.charge", "Data stale\n");
[$code] = notify_run($sb, 'ONBATT');
check($code === 0 && events($sb) === ['FSD'] && log_count($sb, 'No usable battery.charge data') === 1, 'non-numeric data counts as no data');

// 100 s without data, a good reading, 100 s without data again: the timer restarts, the threshold decides
$sb = sb_new('reset');
sb_cfg($sb, ['SHUTDOWN' => 'batt_level', 'BATTERYLEVEL' => '20']);
file_put_contents("$sb/upsc.ups.status", "OB DISCHRG\n");
file_put_contents("$sb/upsc.seq.battery.charge", str_repeat("\n", 100) . "50\n" . str_repeat("\n", 100) . "15\n");
[$code] = notify_run($sb, 'ONBATT');
check($code === 0 && events($sb) === ['FSD'], 'the threshold shutdown happens: ' . implode(',', events($sb)));
check(log_count($sb, 'No usable battery.charge data') === 0, 'a good reading restarts the no-data timer');
check(wait_log($sb, 'Shutdown criteria are now met', 1), 'the criteria are met by the reading');

$sb = sb_new('threshold');
sb_cfg($sb, ['SHUTDOWN' => 'batt_level', 'BATTERYLEVEL' => '20']);
file_put_contents("$sb/upsc.ups.status", "OB DISCHRG\n");
file_put_contents("$sb/upsc.seq.battery.charge", "90\n70\n45\n21\n20\n");
[$code] = notify_run($sb, 'ONBATT');
check($code === 0 && events($sb) === ['FSD'] && log_count($sb, 'No usable') === 0, 'FSD at the configured battery level');
check(trim(read_file($sb, 'upsc.n.battery.charge')) === '5', 'the shutdown waited until the level was reached (five readings, no early shutdown at 21%)');

// malformed criteria fall back to a 30 second timer, a shutdown still happens
$sb = sb_new('malformed');
sb_cfg($sb, ['SHUTDOWN' => 'batt_level', 'BATTERYLEVEL' => 'abc']);
file_put_contents("$sb/upsc.ups.status", "OB\n");
[$code] = notify_run($sb, 'ONBATT');
check($code === 0 && events($sb) === ['FSD'] && log_count($sb, 'Malformed shutdown criteria') === 1, 'malformed criteria still end in a shutdown');

// another UPS than the configured one never drives the shutdown
$sb = sb_new('other-ups');
sb_cfg($sb, ['TIMEOUT' => '2']);
file_put_contents("$sb/upsc.ups.status", "OB\n");
[$code] = notify_run($sb, 'ONBATT', 'other@10.0.0.2');
check($code === 0 && events($sb) === [], 'an event of another UPS sets no FSD: ' . implode(',', events($sb)));
check(wait_log($sb, 'not the UPS configured', 1, 3), 'the monitor-only event is explained');

// the configured UPS under another loopback spelling (legacy monitors) still drives the shutdown
$sb = sb_new('loopback');
file_put_contents("$sb/upsc.ups.status", "OB\n");
$spellings = ['ups@localhost', 'ups@LOCALHOST:3493', 'ups@[::1]', 'ups@::1', 'ups@[::1]:3493', 'ups@127.0.0.1:3493'];
foreach ($spellings as $name) {
    sb_cfg($sb, ['TIMEOUT' => '2']);
    file_put_contents("$sb/events", '');
    [$code] = notify_run($sb, 'ONBATT', $name);
    check($code === 0 && events($sb) === ['FSD'], "the legacy monitor $name shuts down: " . implode(',', events($sb)));
}
sb_cfg($sb, ['TIMEOUT' => '2', 'IPADDR' => 'localhost']);
file_put_contents("$sb/events", '');
[$code] = notify_run($sb, 'ONBATT', 'ups@127.0.0.1');
check($code === 0 && events($sb) === ['FSD'], 'a configured localhost matches a 127.0.0.1 monitor: ' . implode(',', events($sb)));

// remote hosts match only themselves (host case and the default port aside); another name or a lookalike host never does
sb_cfg($sb, ['TIMEOUT' => '2', 'NAME' => 'ippon', 'IPADDR' => 'nas.example.com:3493']);
file_put_contents("$sb/events", '');
[$code] = notify_run($sb, 'ONBATT', 'ippon@NAS.example.com');
check($code === 0 && events($sb) === ['FSD'], 'the configured remote UPS matches: ' . implode(',', events($sb)));
sb_cfg($sb, ['TIMEOUT' => '2']);
foreach (['other@localhost', 'UPS@localhost', 'ups@10.0.0.2', 'ups@localhost.example.com', 'ups@127.0.0.1.example.com',
    'ups@localhost:3494', 'ups@[::2]', 'ups@'] as $name) {
    file_put_contents("$sb/events", '');
    [$code] = notify_run($sb, 'ONBATT', $name);
    check($code === 0 && events($sb) === [], "$name is monitor-only: " . implode(',', events($sb)));
}

// ================= nut-notify: a delayed on-battery alert never delays the shutdown
$sb = sb_new('delay');
sb_cfg($sb, ['TIMEOUT' => '2', 'ONBATTDELAY' => '30']);
file_put_contents("$sb/upsc.ups.status", "OB\n");
[$code] = notify_run($sb, 'ONBATT');
$ev = events($sb);
check($code === 0 && $ev === ['upssched UPSNAME=ups@127.0.0.1 TYPE=ONBATT', 'FSD'], 'upssched gets the event and the shutdown still follows: ' . implode(',', $ev));
check(strpos(read_file($sb, 'notify.calls'), 'UPS On Battery') === false, 'the on-battery alert is held back for upssched');
check(strpos(read_file($sb, 'notify.calls'), 'System Shutdown') !== false, 'the shutdown alert is not delayed');

$sb = sb_new('delay-fail');
sb_cfg($sb, ['TIMEOUT' => '2', 'ONBATTDELAY' => '30']);
file_put_contents("$sb/upsc.ups.status", "OB\n");
[$code] = notify_run($sb, 'ONBATT', 'ups@127.0.0.1', ['UPSSCHED_RC' => '1']);
check($code === 0 && in_array('FSD', events($sb), true), 'a failing upssched does not stop the shutdown');
check(strpos(read_file($sb, 'notify.calls'), 'UPS On Battery') !== false, 'a failing upssched sends the alert without delay');

// ================= rc.nut: restart ordering
$stopped = ['upsmon-stop', 'upsd-stop', 'drvctl-stop old=OLD'];
$sb = sb_new('restart');
services_up($sb);
[$code, $out] = rc($sb, 'restart');
check($code === 0, "restart succeeds ($code): $out");
check(events($sb) === array_merge(['generate'], $stopped, ['generate', 'nutstats-check', 'drvctl-start conf=NEW', 'upsd-start', 'upsmon-start']),
    'restart validates, stops with the old files, writes the new files, starts with them: ' . implode(' | ', events($sb)));
check(file_exists("$sb/state/upsmon") && file_exists("$sb/state/upsd"), 'the services are running again');
check(trim(read_file($sb, 'etc/nut/ups.conf')) === 'NEW', 'the new configuration is in place');

// invalid settings: nothing is stopped, nothing is overwritten
$sb = sb_new('restart-invalid');
services_up($sb);
touch("$sb/php.fail");
[$code] = rc($sb, 'restart');
check($code !== 0, 'restart with invalid settings fails');
check(events($sb) === ['generate'], 'restart with invalid settings touches nothing else: ' . implode(' | ', events($sb)));
check(file_exists("$sb/state/upsmon") && file_exists("$sb/state/upsd"), 'the running services are left alone');
check(trim(read_file($sb, 'etc/nut/ups.conf')) === 'OLD', 'the running configuration is not replaced');
check(file_exists("$sb/usr/local/emhttp/plugins/nut3/misc/start-failed"), 'the failure is flagged for the GUI');

$sb = sb_new('start-invalid');
touch("$sb/php.fail");
[$code] = rc($sb, 'start');
check($code === 1 && !preg_grep('/start|reload/', events($sb)), 'start with invalid settings starts nothing: ' . implode(' | ', events($sb)));

$sb = sb_new('driver-start-failure');
touch("$sb/driver.fail");
[$code] = rc($sb, 'start');
check($code !== 0 && strpos(read_file($sb, 'driver-notify.calls'), '-i alert') !== false, 'a total UPS driver startup failure sends an alert');

// no UPS section or no ups.conf at all: the retry cannot report a start that started nothing as recovered
foreach (['without-section' => "# no UPS section\n", 'without-file' => null] as $case => $generated) {
    $sb = sb_new("driver-start-$case");
    touch("$sb/driver.fail");
    if ($generated === null) touch("$sb/ups.gone"); else file_put_contents("$sb/ups.gen", $generated);
    [$code] = rc($sb, 'start');
    check($code === 1, "start $case ends in failure ($code)");
    check(strpos(read_file($sb, 'driver-notify.calls'), '-i alert') !== false, "start $case sends the alert");
    check(file_exists("$sb/usr/local/emhttp/plugins/nut3/misc/start-failed"), "start $case is flagged for the GUI");
    check(!preg_grep('/^(upsd|upsmon)-start$/', events($sb)), "start $case starts no upsd or upsmon: " . implode(' | ', events($sb)));
    check(!preg_grep('/^drvctl-start-one/', events($sb)), "start $case has no driver to retry: " . implode(' | ', events($sb)));
}

// the retry starts a driver that is still down and the start then goes on
$sb = sb_new('driver-retry');
touch("$sb/driver.fail");
touch("$sb/driver.retry");
[$code] = rc($sb, 'start');
check($code === 0, "start succeeds once the retry started the driver ($code)");
check(events($sb) === ['generate', 'nutstats-check', 'drvctl-start conf=[ups]', 'drvctl-start-one ups conf=[ups]', 'upsd-start', 'upsmon-start'],
    'the retry starts the missing driver, then upsd and upsmon: ' . implode(' | ', events($sb)));
check(read_file($sb, 'driver-notify.calls') === '', 'no alert when the retry brought the driver up');

$sb = sb_new('reload-invalid');
services_up($sb);
touch("$sb/php.fail");
[$code] = rc($sb, 'reload');
check($code === 1 && !preg_grep('/reload|start/', events($sb)), 'reload with invalid settings reloads nothing: ' . implode(' | ', events($sb)));

// stop writes the configuration only after the services are down
$sb = sb_new('stop');
services_up($sb);
[$code] = rc($sb, 'stop');
check($code === 0 && events($sb) === array_merge($stopped, ['generate', 'nutstats-check']), 'stop writes the configuration after stopping: ' . implode(' | ', events($sb)));

// UPS power-off targets only the configured UPS and the local UPSes upsmon powers from, never every local driver.
$sb = sb_new('killpower');
sb_cfg($sb, ['NAME' => 'primary']);
touch("$sb/etc/nut/killpower");
[$code] = rc($sb, 'shutdown');
check($code === 0 && events($sb) === ['drvctl-shutdown primary'], 'shutdown powers off only the configured UPS: ' . implode(' | ', events($sb)));
sb_cfg($sb, ['MODE' => 'slave', 'NAME' => 'remote']);
file_put_contents("$sb/events", '');
[$code] = rc($sb, 'shutdown');
check($code === 0 && events($sb) === [], 'slave mode never powers off local UPS drivers');
sb_cfg($sb, ['NAME' => '']);
[$code] = rc($sb, 'shutdown');
check($code !== 0 && events($sb) === [], 'invalid UPS name never powers off all UPS drivers: ' . implode(' | ', events($sb)));

// a legacy redundant setup: every local UPS with a power value above 0 goes off, whatever its role and spelling
$sb = sb_new('killpower-multi');
sb_cfg($sb, ['NAME' => 'primary']);
touch("$sb/etc/nut/killpower");
file_put_contents("$sb/etc/nut/upsmon.conf", <<<'CONF'
# redundant legacy supplies
MONITOR primary@localhost 1 monuser "p w" master
MONITOR "second@[::1]:3493" 1 monuser pw secondary   # quoted system, IPv6 with port
MONITOR third@127.0.0.1:3493 2 monuser pw "slave"
MONITOR fourth@::1 1 monuser pw primary
monitor fifth@LocalHost 1 monuser pw slave
MONITOR watcher@localhost 0 monuser pw slave
MONITOR remote@192.168.1.5 1 monuser pw master
MONITOR lookalike@localhost.example.com 1 monuser pw master
MONITOR tunnel@127.0.0.1.example.com 1 monuser pw master
MONITOR short@localhost 1 monuser pw
MONITOR badpower@localhost many monuser pw master
MONITOR broken@localhost 1 monuser "pw master
MONITOR @localhost 1 monuser pw master
#MONITOR commented@localhost 1 monuser pw master
NOTIFYFLAG ONBATT SYSLOG+EXEC
CONF);
$all = ['drvctl-shutdown primary', 'drvctl-shutdown second', 'drvctl-shutdown third', 'drvctl-shutdown fourth', 'drvctl-shutdown fifth'];
[$code] = rc($sb, 'shutdown');
check($code === 0 && events($sb) === $all, 'the configured and every local powered UPS go off, watchers and remote systems stay: ' . implode(' | ', events($sb)));
sb_cfg($sb, ['MODE' => 'netserver', 'NAME' => 'primary']);
file_put_contents("$sb/events", '');
[$code] = rc($sb, 'shutdown');
check($code === 0 && events($sb) === $all, 'netserver mode powers off the same UPSes: ' . implode(' | ', events($sb)));
sb_cfg($sb, ['MODE' => 'slave', 'NAME' => 'primary']);
file_put_contents("$sb/events", '');
[$code] = rc($sb, 'shutdown');
check($code === 0 && events($sb) === [], 'slave mode powers off nothing, whatever upsmon.conf monitors: ' . implode(' | ', events($sb)));
sb_cfg($sb, ['NAME' => '']);
file_put_contents("$sb/events", '');
[$code] = rc($sb, 'shutdown');
check($code !== 0 && events($sb) === $all, 'an invalid configured name is skipped, the monitored UPSes still go off: ' . implode(' | ', events($sb)));
check(!preg_grep('/^drvctl-shutdown\s*$/', events($sb)) && !preg_grep('/^drvctl-shutdown-bad/', events($sb)), 'no empty or malformed upsdrvctl call');

// import pending: the previous files stay in use, so their monitors decide next to the imported UPS name
$sb = sb_new('killpower-pending');
sb_cfg($sb, ['NAME' => 'ups', 'IPADDR' => '127.0.0.1']);
file_put_contents("$sb/boot/config/plugins/nut3/import-report.json", json_encode(['date' => '2026-01-01 00:00', 'notes' => []]));
touch("$sb/etc/nut/killpower");
file_put_contents("$sb/etc/nut/upsmon.conf", "MONITOR ups@localhost 1 monuser monpass master\nMONITOR backup@localhost 1 monuser monpass slave\nMONITOR watch@localhost 0 monuser monpass slave\n");
[$code] = rc($sb, 'shutdown');
check($code === 0 && events($sb) === ['drvctl-shutdown ups', 'drvctl-shutdown backup'], 'a pending import powers off the legacy monitors, not the watcher: ' . implode(' | ', events($sb)));

// names go to upsdrvctl only when safe: invalid ones are reported, a leading hyphen is passed after "--"
$sb = sb_new('killpower-names');
sb_cfg($sb, ['NAME' => '-lead']);
touch("$sb/etc/nut/killpower");
file_put_contents("$sb/etc/nut/upsmon.conf", "MONITOR \"bad name@localhost\" 1 u p master\nMONITOR bad\$(id)@localhost 1 u p master\nMONITOR -two@localhost 1 u p master\n");
[$code] = rc($sb, 'shutdown');
check($code !== 0 && events($sb) === ['drvctl-shutdown -lead', 'drvctl-shutdown -two'], 'only safe names are powered off: ' . implode(' | ', events($sb)));
check(log_count($sb, 'invalid UPS name [bad name]') === 1 && log_count($sb, 'invalid UPS name [bad$(id)]') === 1, 'invalid names are logged');

// two concurrent restarts do not interleave
$sb = sb_new('serial');
services_up($sb);
$cmd = ['timeout', '60', 'bash', "$sb/etc/rc.d/rc.nut", 'restart'];
$env = ['BASH_ENV' => "$sb/benv-nosleep", 'PHP_SLEEP' => '0.2'];
$p1 = spawn($sb, $cmd, $env, 'serial1');
$p2 = spawn($sb, $cmd, $env, 'serial2');
finish($p1, 70);
finish($p2, 70);
$pair = ['generate-begin', 'generate-end'];
$run = fn($old) => array_merge($pair, ['upsmon-stop', 'upsd-stop', "drvctl-stop old=$old"], $pair, ['nutstats-check', 'drvctl-start conf=NEW', 'upsd-start', 'upsmon-start']);
check(events($sb) === array_merge($run('OLD'), $run('NEW')), 'concurrent restarts run one after the other: ' . implode(' | ', events($sb)));

// ================= rc.nut stop ends stale workers, so they cannot set FSD afterwards
$sb = sb_new('stale-control');
sb_cfg($sb, ['TIMEOUT' => '3']);
$ctl = worker($sb, 'ups@127.0.0.1', 'ctl');
check(wait_for(fn() => in_array('FSD', events($sb), true), 8), 'control: the same worker sets FSD when nothing stops it');
finish($ctl, 5);

$sb = sb_new('stale-stop');
sb_cfg($sb, ['TIMEOUT' => '3']);
services_up($sb);
touch("$sb/var/run/nut/upssched.pipe");
touch("$sb/var/run/nut/upssched.lock");
$stale = worker($sb, 'ups@127.0.0.1', 'stale');
check(wait_log($sb, $monitoring, 1), 'a worker is monitoring the criteria');
// a process that only mentions the worker path must survive
$decoy = spawn($sb, ['/bin/bash', '-c', 'exec 3<>"$0"; read -t 300 -u 3', "$sb/fifo", "$sb/usr/sbin/nut-notify"], [], 'decoy');
usleep(200000);
[$code] = rc($sb, 'stop');
check($code === 0, 'stop succeeds');
check(wait_for(fn() => !is_running($stale), 4), 'stop ends the running on-battery worker');
check(is_running($decoy), 'stop spares a process that merely mentions the worker path');
check(!file_exists("$sb/var/run/nut/upssched.pipe") && !file_exists("$sb/var/run/nut/upssched.lock"), 'stop removes the upssched pipe and lock');
check(strpos(read_file($sb, 'pkill.calls'), '-x upssched') !== false, 'stop ends the upssched timers');
sleep(5);
check(!in_array('FSD', events($sb), true), 'the stopped worker never sets FSD');
proc_terminate($decoy, 15);
finish($decoy, 5);

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "Notify and service behaviour tests passed.\n";
