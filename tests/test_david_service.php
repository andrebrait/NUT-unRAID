<?php
/* Tests for the service features ported from the nut-dw fork (davidcoulson/NUT-unRAID) into the managed
 * configuration: upsmon timers and notify flags, upssched on-battery delay and EARLY_STAGE timers, scheduled
 * battery self-test, restart sequencing, statistics retention and hardening.
 * Run: php tests/test_david_service.php */

$root = dirname(__DIR__) . '/source/nut3';
$plugin = "$root/usr/local/emhttp/plugins/nut3";
require_once __DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3/include/nut_managed.php';

$failures = 0;

function check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

function has($haystack, $needle) {
    foreach ((array)$haystack as $line) {
        if (strpos($line, $needle) !== false) return true;
    }
    return false;
}

function count_lines($text, $line) {
    return count(array_keys(explode("\n", $text), $line, true));
}

$b = 'base64_encode';
$defaults = nut_mc_settings([]);
$with = fn(array $over) => array_merge($defaults, $over);

// ---- Defaults of the ported settings
foreach (['NOCOMMWARNTIME' => '300', 'FINALDELAY' => '5', 'HOSTSYNC' => '15', 'RBWARNTIME' => '43200', 'ONBATTDELAY' => '0',
    'EARLYSTAGE' => '0', 'BATTTEST' => 'disable', 'BATTTESTWDAY' => '0', 'BATTTESTMDAY' => '1', 'BATTTESTHOUR' => '3',
    'BATTTESTTYPE' => 'quick', 'BATTTESTUPS' => ''] as $key => $value) {
    check(($defaults[$key] ?? null) === $value, "default.cfg has $key=\"$value\"");
}
check(nut_mc_validate($defaults) === [], 'defaults are valid: ' . implode(' | ', nut_mc_validate($defaults)));

// ---- upsmon.conf: timers and notify flags come from the settings
$gen = nut_mc_generate($with(['NOCOMMWARNTIME' => '600', 'FINALDELAY' => '0', 'HOSTSYNC' => '30', 'RBWARNTIME' => '86400']));
foreach (['NOCOMMWARNTIME 600', 'FINALDELAY 0', 'HOSTSYNC 30', 'RBWARNTIME 86400'] as $line) {
    check(count_lines($gen['upsmon.conf'], $line) === 1, "upsmon.conf has $line once");
}
foreach (['LOWBATT', 'FSD', 'COMMBAD', 'COMMOK', 'NOCOMM', 'SHUTDOWN', 'ONBATT', 'ONLINE', 'REPLBATT'] as $event) {
    check(count_lines($gen['upsmon.conf'], "NOTIFYFLAG $event SYSLOG+EXEC") === 1, "upsmon.conf notifies $event through nut-notify");
}
check(strpos($gen['upssched.conf'], 'CMDSCRIPT') === false, 'no upssched directives while both delays are 0');

// A custom directive of the same key replaces the default, it is not a conflict (existing custom files keep working)
$custom = $b("NOTIFYFLAG LOWBATT SYSLOG+WALL\nFINALDELAY 10\nDEADTIME 20\n");
$c = $with(['CUSTOM_UPSMON' => $custom, 'FINALDELAY' => '7']);
check(nut_mc_validate($c) === [], 'custom timer/flag directives are valid: ' . implode(' | ', nut_mc_validate($c)));
$gen = nut_mc_generate($c)['upsmon.conf'];
check(count_lines($gen, 'FINALDELAY 10') === 1 && strpos($gen, 'FINALDELAY 7') === false && strpos($gen, 'FINALDELAY 5') === false, 'custom FINALDELAY replaces the setting');
check(count_lines($gen, 'NOTIFYFLAG LOWBATT SYSLOG+WALL') === 1 && strpos($gen, 'NOTIFYFLAG LOWBATT SYSLOG+EXEC') === false, 'custom LOWBATT flag replaces the default');
check(count_lines($gen, 'NOCOMMWARNTIME 300') === 1 && count_lines($gen, 'NOTIFYFLAG FSD SYSLOG+EXEC') === 1, 'other defaults stay');
check(has(nut_mc_validate($with(['CUSTOM_UPSMON' => $b("NOTIFYFLAG ONBATT SYSLOG\n")])), '"NOTIFYFLAG" is set from the settings'), 'ONBATT flag stays managed');

// ---- upssched.conf: on-battery alert delay and EARLY_STAGE timers for every monitored UPS
$c = $with(['ONBATTDELAY' => '45', 'EARLYSTAGE' => '120', 'CUSTOM_UPSMON' => $b("MONITOR other@10.0.0.2 1 x y slave\n")]);
check(nut_mc_validate($c) === [], 'delays are valid: ' . implode(' | ', nut_mc_validate($c)));
$sched = nut_mc_generate($c)['upssched.conf'];
foreach (['CMDSCRIPT /usr/sbin/nut-upssched-cmd', 'PIPEFN /var/run/nut/upssched.pipe', 'LOCKFN /var/run/nut/upssched.lock'] as $line) {
    check(count_lines($sched, $line) === 1, "upssched.conf has $line");
}
foreach (['ups@127.0.0.1', 'other@10.0.0.2'] as $ups) {
    foreach (["AT ONBATT $ups START-TIMER alert:$ups 45", "AT ONLINE $ups CANCEL-TIMER alert:$ups online:$ups",
        "AT ONBATT $ups START-TIMER early:$ups 120", "AT ONLINE $ups CANCEL-TIMER early:$ups"] as $line) {
        check(count_lines($sched, $line) === 1, "upssched.conf has $line");
    }
}
$sched = nut_mc_generate($with(['ONBATTDELAY' => '30']))['upssched.conf'];
check(strpos($sched, 'START-TIMER alert:ups@127.0.0.1 30') !== false && strpos($sched, 'early:') === false, 'EARLYSTAGE=0 adds no early timers');
$sched = nut_mc_generate($with(['EARLYSTAGE' => '30']))['upssched.conf'];
check(strpos($sched, 'START-TIMER early:ups@127.0.0.1 30') !== false && strpos($sched, 'alert:') === false, 'ONBATTDELAY=0 adds no alert timers');
$words = nut_mc_parse(nut_mc_generate($c)['upssched.conf']);
check(!array_filter($words, fn($d) => $d['error'] !== null), 'generated upssched.conf tokenises cleanly');

// The user's own scheduling survives: it only conflicts once a delay makes the directives managed
$mine = $b("CMDSCRIPT /etc/nut/upssched-cmd\nAT ONBATT * START-TIMER onbatt 30\n");
check(nut_mc_validate($with(['CUSTOM_UPSSCHED' => $mine])) === [], 'custom upssched.conf is valid while the delays are 0');
check(strpos(nut_mc_generate($with(['CUSTOM_UPSSCHED' => $mine]))['upssched.conf'], "# Custom directives\nCMDSCRIPT /etc/nut/upssched-cmd\n") !== false, 'custom upssched.conf is kept verbatim');
check(has(nut_mc_validate($with(['CUSTOM_UPSSCHED' => $mine, 'EARLYSTAGE' => '60'])), '"CMDSCRIPT" is set from the settings'), 'custom CMDSCRIPT conflicts once EARLYSTAGE is on');

// ---- Server-side validation of the new settings
foreach (['NOCOMMWARNTIME' => [1, 86400], 'FINALDELAY' => [0, 3600], 'HOSTSYNC' => [1, 3600], 'RBWARNTIME' => [1, 31536000],
    'ONBATTDELAY' => [0, 86400], 'EARLYSTAGE' => [0, 86400], 'BATTTESTWDAY' => [0, 6], 'BATTTESTMDAY' => [1, 28], 'BATTTESTHOUR' => [0, 23]] as $key => [$min, $max]) {
    check(nut_mc_validate($with([$key => (string)$min])) === [] && nut_mc_validate($with([$key => (string)$max])) === [], "$key accepts $min and $max");
    foreach ([(string)($max + 1), (string)($min - 1), '', 'abc', '1.5', '0' . $max, ' 5'] as $bad) {
        $errors = nut_mc_validate($with([$key => $bad]));
        check($errors !== [], "$key rejects '$bad'");
    }
}
check(has(nut_mc_validate($with(['HOSTSYNC' => '0'])), 'Host Sync Time'), 'range errors name the setting');
$admin = ['ADMINUSER' => 'upsadmin', 'ADMINPASS' => $b('Adm1n!')];
check(nut_mc_validate($with($admin + ['BATTTEST' => 'weekly', 'BATTTESTTYPE' => 'deep', 'BATTTESTUPS' => 'ups'])) === [], 'weekly deep self-test with an admin user is valid');
check(nut_mc_validate($with($admin + ['BATTTEST' => 'monthly'])) === [], 'monthly self-test is valid');
check(has(nut_mc_validate($with(['BATTTEST' => 'weekly'])), 'NUT Admin'), 'a self-test schedule needs the NUT Admin user');
check(nut_mc_validate($with(['BATTTEST' => 'disable'])) === [], 'no admin user is needed while the schedule is off');
check(nut_mc_validate($with($admin + ['BATTTEST' => 'daily'])) !== [], 'unknown self-test schedule is rejected');
check(nut_mc_validate($with($admin + ['BATTTESTTYPE' => 'full'])) !== [], 'unknown self-test type is rejected');
check(nut_mc_validate($with(['BATTTESTUPS' => 'bad name'])) !== [], 'invalid self-test UPS name is rejected');
check(nut_mc_validate($with(['BATTTESTUPS' => 'renamed'])) === [], 'a self-test UPS other than the configured one does not block the service');

// ---- Import: settings the old backend managed stay authoritative, the user's own lines stay custom
$legacyFiles = [
    'upsmon.conf' => "MONITOR ups@127.0.0.1 1 monuser monpass master\nSHUTDOWNCMD \"/sbin/poweroff\"\nPOWERDOWNFLAG \"/etc/nut/no_killpower\"\n"
        . "NOTIFYCMD \"/usr/sbin/nut-notify\"\nNOTIFYFLAG ONBATT SYSLOG+EXEC\nNOTIFYFLAG ONLINE SYSLOG+EXEC\nNOTIFYFLAG REPLBATT SYSLOG+EXEC\n"
        . "NOTIFYFLAG LOWBATT SYSLOG+EXEC\n\nNOCOMMWARNTIME 600\nFINALDELAY 5\nHOSTSYNC 30\nRBWARNTIME 43200\n",
    'upssched.conf' => "# Written by the NUT plugin from the GUI settings (not in manual mode).\nCMDSCRIPT /usr/sbin/nut-upssched-cmd\n"
        . "PIPEFN /var/run/nut/upssched.pipe\nLOCKFN /var/run/nut/upssched.lock\n"
        . "AT ONBATT ups@127.0.0.1 START-TIMER alert:ups@127.0.0.1 45\nAT ONLINE ups@127.0.0.1 CANCEL-TIMER alert:ups@127.0.0.1 online:ups@127.0.0.1\n",
];
$forkCfg = ['NOCOMMWARNTIME' => '600', 'FINALDELAY' => '5', 'HOSTSYNC' => '30', 'RBWARNTIME' => '43200', 'ONBATTDELAY' => '45',
    'EARLYSTAGE' => '0', 'BATTTEST' => 'disable', 'MONPASS' => $b('monpass')];
[$c, $notes] = nut_mc_import($forkCfg, $legacyFiles);
check($c['NOCOMMWARNTIME'] === '600' && $c['HOSTSYNC'] === '30' && $c['ONBATTDELAY'] === '45', 'settings of the fork are imported');
check(nut_mc_decode($c['CUSTOM_UPSMON']) === 'NOTIFYFLAG LOWBATT SYSLOG+EXEC', 'rewritten timer lines are not copied into the custom field');
check($c['CUSTOM_UPSSCHED'] === '', 'the rewritten upssched.conf is regenerated, not kept as custom directives');
check(nut_mc_validate($c) === [], 'imported fork settings are valid: ' . implode(' | ', nut_mc_validate($c)));
$gen = nut_mc_generate($c);
check(count_lines($gen['upsmon.conf'], 'NOCOMMWARNTIME 600') === 1 && count_lines($gen['upsmon.conf'], 'HOSTSYNC 30') === 1, 'imported timers are generated once');
check(strpos($gen['upssched.conf'], 'START-TIMER alert:ups@127.0.0.1 45') !== false, 'imported on-battery delay is generated');
[$c] = nut_mc_import(array_merge($forkCfg, ['BATTTEST' => 'weekly']), $legacyFiles);
check(has(nut_mc_validate($c), 'NUT Admin'), 'an imported schedule without an admin user is reported, not silently dropped');

// The user's own timer line stays custom (the old settings never held it) and wins over the setting
[$c] = nut_mc_import(['MONPASS' => $b('monpass')], ['upsmon.conf' => "MONITOR ups@127.0.0.1 1 monuser monpass master\nFINALDELAY 10\nHOSTSYNC 0\n"]);
check(nut_mc_decode($c['CUSTOM_UPSMON']) === "FINALDELAY 10\nHOSTSYNC 0" && $c['FINALDELAY'] === '5', 'timer lines of a file the old settings did not manage stay custom');
check(count_lines(nut_mc_generate($c)['upsmon.conf'], 'FINALDELAY 10') === 1 && count_lines(nut_mc_generate($c)['upsmon.conf'], 'FINALDELAY 5') === 0, 'the user\'s FINALDELAY is generated');
// an out-of-range value of a managed timer is not turned into a setting
[$c] = nut_mc_import(['FINALDELAY' => '5', 'HOSTSYNC' => '15', 'MONPASS' => $b('monpass')], ['upsmon.conf' => "MONITOR ups@127.0.0.1 1 monuser monpass master\nHOSTSYNC 0\n"]);
check($c['HOSTSYNC'] === '15' && nut_mc_decode($c['CUSTOM_UPSMON']) === 'HOSTSYNC 0', 'out-of-range timer line stays custom');
// a user's own upssched.conf is still imported verbatim
[$c] = nut_mc_import(['MONPASS' => $b('monpass')], ['upssched.conf' => "CMDSCRIPT /etc/nut/upssched-cmd\nAT ONBATT * START-TIMER onbatt 30\n"]);
check(nut_mc_decode($c['CUSTOM_UPSSCHED']) === "CMDSCRIPT /etc/nut/upssched-cmd\nAT ONBATT * START-TIMER onbatt 30" && nut_mc_validate($c) === [], 'custom upssched.conf is imported verbatim and valid');
// manual mode: the files were authoritative, so a managed timer takes the file value
[$c] = nut_mc_import(['MANUAL' => 'enable', 'FINALDELAY' => '5', 'MONPASS' => $b('monpass')], ['upsmon.conf' => "MONITOR ups@127.0.0.1 1 monuser monpass master\nFINALDELAY 20\n"]);
check($c['FINALDELAY'] === '20', 'manual mode imports the timer from the file');

// ---- Shell scripts, run against stubs
$tmp = sys_get_temp_dir() . '/nut-service-' . bin2hex(random_bytes(12));
if (!mkdir($tmp, 0700)) throw new RuntimeException('Cannot create test sandbox');
$bin = "$tmp/bin";
mkdir($bin, 0700);
function stub($path, $body) {
    file_put_contents($path, "#!/bin/bash\n$body\n");
    chmod($path, 0755);
}
stub("$bin/upsc", 'echo "$1" >> "$STUB/upsc.targets"; if [ -f "$STUB/upsc.down" ] && grep -qxF "$1" "$STUB/upsc.down"; then echo "Error: Data stale" >&2; exit 1; fi; [ -f "$STUB/upsc.$2" ] && cat "$STUB/upsc.$2"; exit 0');
stub("$bin/upscmd", 'if [ "$1" == "-l" ]; then cat "$STUB/upscmd.list"; exit 0; fi; if [ -f "$STUB/upscmd.error" ]; then cat "$STUB/upscmd.error"; exit 1; fi; echo "$@" >> "$STUB/upscmd.calls"; exit 0');
stub("$bin/logger", 'cat >> "$STUB/log"');
stub("$bin/wall", 'cat >> "$STUB/wall"');
stub("$bin/sleep", 'exit 0');
stub("$bin/pgrep", 'exit 0');
stub("$tmp/notify", 'echo "$@" >> "$STUB/notify.calls"');

function run_bash($script, array $env = [], array $args = []) {
    global $bin, $tmp;
    $env += ['STUB' => $tmp, 'PATH' => "$bin:" . getenv('PATH'), 'HOSTNAME' => 'tower'];
    $prefix = '';
    foreach ($env as $key => $value) $prefix .= "$key=" . escapeshellarg($value) . ' ';
    exec("env $prefix bash -c " . escapeshellarg($script) . ' nut ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
function reset_stub() {
    global $tmp;
    foreach (glob("$tmp/{upsc.*,upscmd.*,log,wall,notify.calls}", GLOB_BRACE) as $f) unlink($f);
}
function stub_read($name) {
    global $tmp;
    return is_file("$tmp/$name") ? file_get_contents("$tmp/$name") : '';
}

// nut-batttest: uses the NUT Admin account of the settings and only tests a healthy UPS on line
$batt = "$root/usr/sbin/nut-batttest";
$cfg = "$tmp/nut3.cfg";
file_put_contents($cfg, "NAME=\"ups\"\nIPADDR=\"127.0.0.1\"\nADMINUSER=\"upsadmin\"\nADMINPASS=\"" . $b('Adm1n!') . "\"\nBATTTESTTYPE=\"deep\"\n");
$runBatt = fn(array $args = []) => run_bash("source '$batt'; CONFIG='$cfg'; NOTIFY='$tmp/notify'; main \"\$@\"", [], $args);
$ready = function () use ($tmp) {
    reset_stub();
    file_put_contents("$tmp/upsc.ups.status", "OL\n");
    file_put_contents("$tmp/upsc.battery.charge", "100\n");
    file_put_contents("$tmp/upsc.ups.test.result", "Done and passed\n");
    file_put_contents("$tmp/upscmd.list", "test.battery.start.quick - quick\ntest.battery.start.deep - deep\n");
};
$ready();
[$code] = $runBatt();
check($code === 0, 'nut-batttest runs the configured test');
check(trim(stub_read('upscmd.calls')) === '-u upsadmin -p Adm1n! ups@127.0.0.1 test.battery.start.deep', 'nut-batttest starts the configured test type with the admin account of the settings: ' . stub_read('upscmd.calls'));
check(strpos(stub_read('notify.calls'), 'finished') !== false && strpos(stub_read('notify.calls'), 'normal') !== false, 'a passed test sends a notice');
$ready();
file_put_contents("$tmp/upscmd.error", "Command failed for Adm1n!\n");
[$code] = $runBatt();
check($code === 1 && strpos(stub_read('log') . stub_read('notify.calls'), 'Adm1n!') === false && strpos(stub_read('notify.calls'), '***') !== false, 'failed self-test does not expose the admin password');
$ready();
$runBatt(['ups', 'quick']);
check(strpos(stub_read('upscmd.calls'), 'test.battery.start.quick') !== false, 'the test type can be given on the command line');
$ready();
file_put_contents("$tmp/upsc.ups.status", "OB DISCHRG\n");
[$code] = $runBatt();
check($code === 0 && stub_read('upscmd.calls') === '' && strpos(stub_read('log'), 'not OL') !== false, 'no test while on battery');
$ready();
file_put_contents("$tmp/upsc.battery.charge", "60\n");
$runBatt();
check(stub_read('upscmd.calls') === '' && strpos(stub_read('log'), '90% or more') !== false, 'no test below 90% charge');
$ready();
file_put_contents("$tmp/upscmd.list", "test.battery.start.quick - quick\n");
$runBatt();
check(stub_read('upscmd.calls') === '' && strpos(stub_read('log'), 'does not support') !== false, 'no test for a UPS without the command');
$ready();
file_put_contents("$tmp/upsc.ups.test.result", "Done and error\n");
$runBatt();
check(strpos(stub_read('notify.calls'), 'warning') !== false && strpos(stub_read('notify.calls'), 'did not pass') !== false, 'a failed test sends a warning');
$ready();
file_put_contents($cfg, "NAME=\"ups\"\nIPADDR=\"127.0.0.1\"\nADMINUSER=\"\"\nADMINPASS=\"\"\n");
[$code] = $runBatt();
check($code === 1 && stub_read('upscmd.calls') === '' && strpos(stub_read('notify.calls'), 'NUT Admin') !== false, 'no admin user: nothing is started and the user is warned');
$ready();
file_put_contents($cfg, "NAME=\"ups\"\nIPADDR=\"127.0.0.1\"\n");
[$code] = $runBatt(['bad name']);
check($code === 1 && stub_read('upscmd.calls') === '', 'an invalid UPS name is refused');

// nut-batttest: a missing, renamed or stale UPS is reported instead of silently skipped; additional local UPSes can be tested
$battCfg = fn($extra = '', $ip = '127.0.0.1') => file_put_contents($cfg, "NAME=\"ups\"\nIPADDR=\"$ip\"\nADMINUSER=\"upsadmin\"\nADMINPASS=\"" . $b('Adm1n!') . "\"\n$extra");
$targets = fn() => array_values(array_unique(array_filter(explode("\n", stub_read('upsc.targets')), 'strlen')));
$ready();
$battCfg("BATTTESTUPS=\"gone\"\n");
unlink("$tmp/upsc.ups.status");
[$code] = $runBatt();
check($code === 1 && stub_read('upscmd.calls') === '', 'a UPS that reports nothing is not tested');
check(strpos(stub_read('notify.calls'), 'warning') !== false && strpos(stub_read('notify.calls'), '[gone@127.0.0.1]') !== false && strpos(stub_read('notify.calls'), 'not available') !== false, 'a missing UPS sends a warning: ' . stub_read('notify.calls'));
check(strpos(stub_read('log'), 'not available') !== false, 'a missing UPS is logged');
$ready();
$battCfg("BATTTESTUPS=\"gone\"\n");
file_put_contents("$tmp/upsc.down", "gone@127.0.0.1\n");
[$code] = $runBatt();
check($code === 1 && stub_read('upscmd.calls') === '' && strpos(stub_read('notify.calls'), 'Data stale') !== false, 'a stale UPS sends a warning with the reason: ' . stub_read('notify.calls'));
$ready();
$battCfg("BATTTESTUPS=\"second\"\n", '10.0.0.5');
[$code] = $runBatt();
check($code === 0 && trim(stub_read('upscmd.calls')) === '-u upsadmin -p Adm1n! second@127.0.0.1 test.battery.start.quick', 'an additional local UPS is tested on the local upsd: ' . stub_read('upscmd.calls'));
check($targets() === ['second@127.0.0.1'], 'an additional local UPS is only queried locally: ' . implode(' ', $targets()));
$ready();
file_put_contents("$tmp/upscmd.error", "Command failed for Adm1n!\n");
[$code] = $runBatt();
check($code === 1 && strpos(stub_read('log') . stub_read('notify.calls'), 'Adm1n!') === false && strpos(stub_read('notify.calls'), '***') !== false, 'the admin password stays out of the messages for an additional UPS');
$ready();
$battCfg('', '10.0.0.5');
$runBatt();
check($targets() === ['ups@10.0.0.5'] && strpos(stub_read('upscmd.calls'), 'ups@10.0.0.5 test.battery.start.quick') !== false, 'the configured UPS is tested at its configured address: ' . implode(' ', $targets()));

// rc.nut write_batttest_cron: the cron file follows the schedule and is only rewritten on change
$rc = file_get_contents("$root/etc/rc.d/rc.nut");
preg_match('/^write_batttest_cron\(\) \{.*?^\}$/ms', $rc, $m);
$fn = str_replace('/boot/config/plugins/dynamix/nut-batttest.cron', "$tmp/nut-batttest.cron", $m[0] ?? '');
check($fn !== '', 'rc.nut has write_batttest_cron');
$cron = function (array $vars) use ($fn, $tmp) {
    $set = '';
    foreach ($vars as $key => $value) $set .= "$key=" . escapeshellarg($value) . '; ';
    run_bash("update_cron() { echo update >> '$tmp/cron.calls'; }; $fn; $set write_batttest_cron");
    return [is_file("$tmp/nut-batttest.cron") ? file_get_contents("$tmp/nut-batttest.cron") : null, substr_count(stub_read('cron.calls'), 'update')];
};
@unlink("$tmp/cron.calls");
[$text, $calls] = $cron(['BATTTEST' => 'weekly', 'BATTTESTHOUR' => '4', 'BATTTESTWDAY' => '2']);
check($text === "# NUT scheduled battery self-test\n0 4 * * 2 /usr/sbin/nut-batttest > /dev/null 2>&1\n" && $calls === 1, 'weekly schedule writes the cron file and updates cron');
[$text, $calls] = $cron(['BATTTEST' => 'weekly', 'BATTTESTHOUR' => '4', 'BATTTESTWDAY' => '2']);
check($calls === 1, 'an unchanged schedule does not touch cron again');
[$text, $calls] = $cron(['BATTTEST' => 'monthly', 'BATTTESTHOUR' => '23', 'BATTTESTMDAY' => '28']);
check($text === "# NUT scheduled battery self-test\n0 23 28 * * /usr/sbin/nut-batttest > /dev/null 2>&1\n" && $calls === 2, 'monthly schedule replaces the weekly one');
[$text, $calls] = $cron(['BATTTEST' => 'monthly', 'BATTTESTHOUR' => '99', 'BATTTESTMDAY' => '31']);
check($text === "# NUT scheduled battery self-test\n0 3 1 * * /usr/sbin/nut-batttest > /dev/null 2>&1\n", 'invalid hour and day fall back to 3:00 on the 1st');
[$text, $calls] = $cron(['BATTTEST' => 'disable']);
check($text === null && $calls === 4, 'disabling the schedule removes the cron file and updates cron');
[$text, $calls] = $cron([]);
check($text === null && $calls === 4, 'a settings file without the keys schedules nothing');

// nut-upssched-cmd: delayed alert, online after an alert, EARLY_STAGE hook
$sc = file_get_contents("$root/usr/sbin/nut-upssched-cmd");
$sc = str_replace(['/boot/config/plugins/nut3', '/usr/local/emhttp/plugins/dynamix/scripts/notify', '/etc/nut/xnut-notify-hooks.sh'],
    ["$tmp/plg", "$tmp/notify", "$tmp/hooks.sh"], $sc);
@mkdir("$tmp/plg", 0777, true);
file_put_contents("$tmp/plg/nut3.cfg", "ONBATTDELAY=\"45\"\nEARLYSTAGE=\"120\"\n");
file_put_contents("$tmp/hooks.sh", "EARLY_STAGE () { echo \"early hook for \$UPSNAME\" > \"\$STUB/early\"; }\n");
file_put_contents("$tmp/nut-upssched-cmd", $sc);
@unlink("$tmp/early");
reset_stub();
[$code] = run_bash("bash '$tmp/nut-upssched-cmd' \"\$1\"", [], ['alert:ups@127.0.0.1']);
check($code === 0 && strpos(stub_read('notify.calls'), '] NUT: [ups@127.0.0.1] UPS On Battery') !== false && strpos(stub_read('notify.calls'), '45 seconds') !== false && strpos(stub_read('notify.calls'), '-i alert') !== false, 'alert timer sends the delayed on-battery alert: ' . stub_read('notify.calls'));
usleep(300000); // the wall copy is written by a process substitution that outlives the script
check(strpos(stub_read('wall'), '[ups@127.0.0.1] UPS has been on battery for 45 seconds.') !== false, 'alert timer warns the logged-in users');
reset_stub();
run_bash("bash '$tmp/nut-upssched-cmd' \"\$1\"", [], ['online:ups@127.0.0.1']);
check(strpos(stub_read('notify.calls'), 'normal') !== false && strpos(stub_read('notify.calls'), 'On Line') !== false, 'online timer command reports the return of power');
reset_stub();
run_bash("bash '$tmp/nut-upssched-cmd' \"\$1\"; wait", [], ['early:ups@127.0.0.1']);
usleep(300000);
check(trim((string)@file_get_contents("$tmp/early")) === 'early hook for ups@127.0.0.1', 'early timer runs the EARLY_STAGE hook with UPSNAME');
reset_stub();
[$code] = run_bash("bash '$tmp/nut-upssched-cmd' \"\$1\"", [], ['bogus:ups@127.0.0.1']);
check($code === 0 && stub_read('notify.calls') === '', 'an unknown timer kind does nothing');

// nutstats: bounded history and no file paths from the settings
$ns = file_get_contents("$root/usr/sbin/nutstats");
$ns = str_replace(['STATROOT="/etc/nutstats"', '/usr/bin/upsc', '/usr/bin/pgrep'], ["STATROOT=\"$tmp/stats\"", 'upsc', 'pgrep'], $ns);
file_put_contents("$tmp/nutstats", $ns);
@mkdir("$tmp/stats", 0777, true);
$statsCfg = fn($extra = '') => file_put_contents("$tmp/stats/nut3.cfg", "STATISTICS=\"enable\"\nSERVICE=\"enable\"\nNAME=\"ups\"\nIPADDR=\"127.0.0.1\"\n$extra");
foreach (['ups.realpower', 'battery.charge', 'battery.voltage', 'input.voltage', 'input.frequency', 'output.voltage', 'output.frequency'] as $var) {
    file_put_contents("$tmp/upsc.$var", "7\n");
    file_put_contents("$tmp/stats/$var.json", json_encode(['data' => array_map(fn($i) => ['x' => $i, 'y' => 1], range(1, 5004))]));
}
$statsCfg();
[$code, $out] = run_bash("bash '$tmp/nutstats'");
$data = json_decode(file_get_contents("$tmp/stats/ups.realpower.json"), true)['data'];
check(strpos($out, 'Updated ups.realpower.json') !== false, 'nutstats updates the chart data: ' . $out);
check(count($data) === 5000 && $data[4999]['y'] === 7 && $data[0]['x'] === 6, 'nutstats keeps the newest 5000 points');
@mkdir("$tmp/outside", 0777, true);
file_put_contents("$tmp/outside/secret.json", json_encode(['data' => []]));
$statsCfg("STATSOVERRIDE=\"enable\"\nSTATSCHART1VAR=\"../outside/secret\"\nSTATSCHART2VAR=\"battery.charge\"\nSTATSCHART3VAR=\"battery.voltage\"\nSTATSCHART4VAR=\"input.voltage\"\nSTATSCHART5VAR=\"input.frequency\"\nSTATSCHART6VAR=\"output.voltage\"\nSTATSCHART7VAR=\"output.frequency\"\n");
[$code, $out] = run_bash("bash '$tmp/nutstats'");
check(strpos($out, 'did not pass, using defaults') !== false, 'nutstats rejects variable names that are file paths: ' . $out);
check(json_decode(file_get_contents("$tmp/outside/secret.json"), true) === ['data' => []], 'nutstats never writes outside its own folder');

// plugin upgrade: the restart safety net recovers a failed upgrade and cannot restart a NUT the user stopped after a completed one
$plgDoc = new DOMDocument();
$plgDoc->load(__DIR__ . '/../plugin/nut3.plg', LIBXML_NOENT);
$installScripts = [];
foreach ($plgDoc->getElementsByTagName('FILE') as $file) {
    $inline = $file->getElementsByTagName('INLINE')->item(0);
    if ($inline && !$file->hasAttribute('Method')) $installScripts[] = $inline->textContent;
}
$sandbox = fn($script) => str_replace(['/etc/rc.d/', '/var/run/', '/boot/config/plugins/'], ["$tmp/rc.d/", "$tmp/run/", "$tmp/boot/plugins/"], $script);
$preUpgrade = $sandbox(reset($installScripts)); // the first install step stops NUT and schedules the safety net
$postUpgrade = $sandbox(end($installScripts)); // the last install step runs once the upgrade completed
$binu = "$tmp/binu";
foreach ([$binu, "$tmp/rc.d", "$tmp/run", "$tmp/boot/plugins"] as $dir) mkdir($dir, 0700, true);
stub("$binu/pgrep", '[ -f "$STUB/upsmon.running" ]');
stub("$binu/killall", 'exit 0');
stub("$binu/at", 'n=$(ls "$STUB"/at.job.* 2>/dev/null | wc -l); cat > "$STUB/at.job.$n"');
foreach (['rc.nut', 'rc.nutstats'] as $name) {
    stub("$tmp/rc.d/$name", 'echo "$(basename "$0") $1" >> "$STUB/rc.calls"; case "$1" in stop) rm -f "$STUB/upsmon.running";; start) touch "$STUB/upsmon.running";; esac');
}
/* Runs the install steps for each [NUT was running, upgrade completed] attempt, then lets the scheduled jobs fire with
 * NUT running or not; returns [scheduled jobs, rc.nut starts by those jobs]. */
$upgrade = function (array $attempts, bool $runningAfter) use ($tmp, $binu, $bin, $preUpgrade, $postUpgrade) {
    foreach (glob("$tmp/{run/*,at.job.*,rc.calls,upsmon.running}", GLOB_BRACE) as $f) unlink($f);
    $env = ['PATH' => "$binu:$bin:" . getenv('PATH')];
    foreach ($attempts as [$wasRunning, $completed]) {
        $wasRunning ? touch("$tmp/upsmon.running") : @unlink("$tmp/upsmon.running");
        run_bash($preUpgrade, $env);
        if ($completed) run_bash($postUpgrade, $env);
    }
    $runningAfter ? touch("$tmp/upsmon.running") : @unlink("$tmp/upsmon.running");
    @unlink("$tmp/rc.calls");
    run_bash('for j in "$STUB"/at.job.*; do [ -f "$j" ] && sh "$j"; done', $env);
    return [count(glob("$tmp/at.job.*")), substr_count(stub_read('rc.calls'), 'rc.nut start')];
};
[$jobs, $starts] = $upgrade([[true, false]], false);
check($jobs === 1 && $starts === 1, "a failed upgrade restarts the NUT that was running ($jobs jobs, $starts starts)");
[$jobs, $starts] = $upgrade([[true, true]], false);
check($jobs === 1 && $starts === 0, "a completed upgrade cancels the restart when the user stopped NUT afterwards ($jobs jobs, $starts starts)");
[$jobs, $starts] = $upgrade([[true, false]], true);
check($jobs === 1 && $starts === 0, "a failed upgrade does not restart a NUT that is running again ($jobs jobs, $starts starts)");
[$jobs, $starts] = $upgrade([[false, false]], false);
check($jobs === 0 && $starts === 0, "an upgrade never starts a NUT that was not running ($jobs jobs, $starts starts)");
[$jobs, $starts] = $upgrade([[true, false], [true, true]], false);
check($jobs === 2 && $starts === 0, "a completed retry cancels the restart of the failed attempt too ($jobs jobs, $starts starts)");

// rc.nutstats: the settings copy of the statistics (it holds the UPS passwords) is 0600, never replaces a looser file in place
$ns = "$tmp/ns";
$obs = "$tmp/obs";
foreach (["$ns/plg", "$ns/etc", "$ns/dynamix", "$ns/docroot", $obs] as $dir) mkdir($dir, 0700, true);
exec('cp -r ' . escapeshellarg("$plugin/nutstats-defaults") . ' ' . escapeshellarg("$plugin/misc") . ' ' . escapeshellarg("$ns/docroot/"));
$rcStats = str_replace(
    ['"/boot/config/plugins/nut3"', '"/usr/local/emhttp/plugins/nut3"', '/etc/nutstats', '/boot/config/plugins/dynamix', '/usr/sbin/nutstats'],
    ["\"$ns/plg\"", "\"$ns/docroot\"", "$ns/etc/nutstats", "$ns/dynamix", "$ns/nutstats-run"],
    file_get_contents("$root/etc/rc.d/rc.nutstats"));
if (preg_match('#(?<!' . preg_quote($ns, '#') . ')/etc/nutstats|/boot/config|/usr/local/emhttp|/usr/sbin#', $rcStats)) {
    throw new RuntimeException('rc.nutstats still names a real system path, refusing to run it');
}
file_put_contents("$tmp/rc.nutstats", $rcStats);
stub("$ns/nutstats-run", 'exit 0');
stub("$obs/update_cron", 'exit 0');
// every command that may touch the copy reports its mode afterwards, so the states between the commands are seen
$observe = '[ -e "$STATSCFG" ] && stat -c %a "$STATSCFG" >> "$STUB/statscfg.modes"';
foreach (['install', 'cp', 'chmod', 'ln', 'mkdir'] as $cmd) {
    stub("$obs/$cmd", trim((string)shell_exec("command -v $cmd")) . " \"\$@\"; rc=\$?; $observe; exit \$rc");
}
stub("$obs/chown", "$observe; exit 0");
$statsEnv = ['PATH' => "$obs:$bin:" . getenv('PATH'), 'STATSCFG' => "$ns/etc/nutstats/nut3.cfg"];
$copy = "$ns/etc/nutstats/nut3.cfg";
$observed = fn() => array_filter(explode("\n", stub_read('statscfg.modes')), 'strlen');
$exposed = fn() => array_filter($observed(), fn($mode) => (octdec($mode) & 077) !== 0);
$modeOf = fn($path) => is_file($path) ? substr(sprintf('%o', fileperms($path)), -4) : 'missing';

file_put_contents("$ns/plg/nut3.cfg", "STATISTICS=\"enable\"\nSTATSPOLL=\"01min\"\nADMINPASS=\"" . $b('S3cret!') . "\"\n");
chmod("$ns/plg/nut3.cfg", 0755);
@unlink("$tmp/statscfg.modes");
run_bash("umask 000; bash '$tmp/rc.nutstats' enable", $statsEnv);
clearstatcache();
check(is_file($copy) && file_get_contents($copy) === file_get_contents("$ns/plg/nut3.cfg"), 'rc.nutstats copies the settings for the statistics');
check($modeOf($copy) === '0600', 'the new settings copy is 0600 whatever the umask and the mode of the source: ' . $modeOf($copy));
check($observed() !== [] && $exposed() === [], 'the new settings copy is never group or world accessible: ' . implode(' ', $observed()));

file_put_contents($copy, "STATISTICS=\"enable\"\nADMINPASS=\"old\"\n");
chmod($copy, 0666);
link($copy, "$ns/stale-copy"); // keeps the old file alive, so an in-place rewrite of it can be told from a replacement
file_put_contents("$ns/plg/nut3.cfg", "STATISTICS=\"enable\"\nSTATSPOLL=\"01min\"\nADMINPASS=\"" . $b('N3w!') . "\"\n");
@unlink("$tmp/statscfg.modes");
run_bash("umask 000; bash '$tmp/rc.nutstats' enable", $statsEnv);
clearstatcache();
check($modeOf($copy) === '0600' && file_get_contents($copy) === file_get_contents("$ns/plg/nut3.cfg"), 'an update replaces a looser settings copy with a 0600 file: ' . $modeOf($copy));
check($observed() !== [] && $exposed() === [], 'an update never exposes the new settings in a looser file: ' . implode(' ', $observed()));
check(file_get_contents("$ns/stale-copy") === "STATISTICS=\"enable\"\nADMINPASS=\"old\"\n", 'an update does not rewrite the old copy in place');


exec('rm -rf ' . escapeshellarg($tmp));

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "Service tests passed.\n";
