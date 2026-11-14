<?php
/* Tests for the managed NUT configuration. Run: php tests/managed-config.php
 * Set UPDATE_GOLDEN=1 to rewrite tests/golden after reviewing a deliberate output change. */

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

function fixture($name) {
    $dir = __DIR__ . "/fixtures/$name";
    $files = [];
    foreach (NUT_MC_FILES as $file) {
        $files[$file] = is_file("$dir/$file") ? file_get_contents("$dir/$file") : null;
    }
    return [nut_mc_read_cfg("$dir/nut3.cfg"), $files];
}

function golden($name, array $files) {
    $dir = __DIR__ . "/golden/$name";
    foreach ($files as $file => $text) {
        if (getenv('UPDATE_GOLDEN')) {
            @mkdir($dir, 0777, true);
            file_put_contents("$dir/$file", $text);
        }
        check(is_file("$dir/$file") && file_get_contents("$dir/$file") === $text, "golden file $name/$file differs");
    }
}

function words_of($text, $directive) {
    foreach (nut_mc_parse($text) as $d) {
        if (strcasecmp($d['words'][0] ?? '', $directive) === 0) return $d['words'];
    }
    return null;
}

$b = 'base64_encode';
$defaults = nut_mc_settings([]);

// Golden files per mode
$modes = [
    'standalone' => [],
    'netserver' => [
        'MODE' => 'netserver', 'LISTEN' => '0.0.0.0 192.168.1.2', 'DRIVER' => 'snmp-ups', 'PORT' => '192.168.1.50',
        'SNMPMIB' => 'apcc', 'COMMUNITY' => 'private', 'DEBLEVEL' => '2', 'DEBLEVELMON' => '3', 'UPSKILL' => 'enable',
        'ADMINUSER' => 'upsadmin', 'ADMINPASS' => $b('Adm1n!'),
        'CUSTOM_NUT' => $b("ALLOW_NO_DEVICE=true\n"),
        'CUSTOM_UPSGLOBAL' => $b("maxretry = 3\r\n"),
        'CUSTOM_UPS' => $b("desc = \"Rack UPS\"\n\n"),
        'CUSTOM_UPSD' => $b("MAXAGE 25\n"),
        'CUSTOM_USERS' => $b("[homeassistant]\n\tpassword = ha\n\tupsmon slave\n"),
        'CUSTOM_UPSMON' => $b("NOTIFYFLAG LOWBATT SYSLOG+EXEC\nFINALDELAY 10\n"),
        'CUSTOM_UPSSCHED' => $b("CMDSCRIPT /etc/nut/upssched-cmd\n"),
    ],
    'slave' => [
        'MODE' => 'slave', 'NAME' => 'ippon_ups', 'IPADDR' => '192.168.1.10:3493', 'MONUSER' => 'remote', 'MONPASS' => $b('R3mote'),
        'DRIVER' => 'custom', 'SERIAL' => '', 'CUSTOM_UPS' => $b("[ignored]\n"),
    ],
];
foreach ($modes as $mode => $override) {
    $c = array_merge($defaults, $override);
    $errors = nut_mc_validate($c);
    check($errors === [], "$mode settings are valid: " . implode(' | ', $errors));
    golden($mode, nut_mc_generate($c));
}

// The admin user exists only when configured, and needs both username and password
$users = nut_mc_generate($defaults)['upsd.users'];
check(stripos($users, 'admin') === false && stripos($users, 'instcmds') === false && stripos($users, 'actions') === false, 'no admin user by default');
check(stripos(nut_mc_generate(array_merge($defaults, ['MODE' => 'netserver']))['upsd.users'], 'admin') === false, 'no admin user in netserver mode by default');
check(has(nut_mc_validate(array_merge($defaults, ['ADMINUSER' => 'admin'])), 'NUT Admin Password'), 'admin username without password is rejected');
check(has(nut_mc_validate(array_merge($defaults, ['ADMINPASS' => $b('x')])), 'NUT Admin Username'), 'admin password without username is rejected');
check(has(nut_mc_validate(array_merge($defaults, ['ADMINUSER' => 'monuser', 'ADMINPASS' => $b('x')])), 'must differ'), 'admin username must differ from monitor username');

// Escaping of values written to NUT files
$password = <<<'EOT'
,&\"# space
EOT;
$expected = <<<'EOT'
MONITOR ups@127.0.0.1 1 monuser ",&\\\"\# space" master
EOT;
$generated = nut_mc_generate(array_merge($defaults, ['MONPASS' => $b($password), 'PORT' => '/dev/serial/by-id/usb-a b#c']));
check(strpos($generated['upsmon.conf'], "$expected\n") !== false, 'MONITOR password is quoted and escaped');
check((words_of($generated['upsmon.conf'], 'MONITOR')[4] ?? null) === $password, 'MONITOR password round-trips through the NUT parser');
check((words_of($generated['upsd.users'], 'password')[2] ?? null) === $password, 'upsd.users password round-trips through the NUT parser');
check((words_of($generated['ups.conf'], 'port')[2] ?? null) === '/dev/serial/by-id/usb-a b#c', 'port round-trips through the NUT parser');
check(has(nut_mc_validate(array_merge($defaults, ['MONPASS' => $b($password)])), 'NUT Monitor Password'), 'passwords NUT cannot send unquoted are rejected');
check(nut_mc_validate(array_merge($defaults, ['MONPASS' => $b(',&!\'%*')])) === [], 'other punctuation in passwords is accepted');
check(has(nut_mc_validate(array_merge($defaults, ['NAME' => "ups\nMODE=netserver"])), 'Setting NAME'), 'line breaks in single-value settings are rejected');
check(has(nut_mc_validate(array_merge($defaults, ['PORT' => 'a"b'])), 'Setting PORT'), 'quotes in settings are rejected');
check(has(nut_mc_validate(array_merge($defaults, ['COMMUNITY' => '$(reboot)'])), 'Setting COMMUNITY'), 'shell expansion in settings is rejected');
check(nut_mc_validate(array_merge($defaults, ['NAME' => 'ippon_ups'])) === [], 'UPS name with underscore is accepted');
check(nut_mc_validate(array_merge($defaults, ['NAME' => 'ups-1.rack'])) === [], 'UPS name with dash and dot is accepted');
check(has(nut_mc_validate(array_merge($defaults, ['NAME' => 'ippon ups'])), 'UPS Name'), 'UPS name with space is rejected');
check(has(nut_mc_validate(array_merge($defaults, ['CUSTOM_UPS' => $b("desc = \"caf\xc3\xa9\"\n")])), 'printable ASCII'), 'non-ASCII custom directives are rejected');
check(has(nut_mc_validate(array_merge($defaults, ['CUSTOM_UPS' => $b("desc = \"open\n")])), 'unterminated quote'), 'unbalanced quotes in custom directives are rejected');
check(has(nut_mc_validate(array_merge($defaults, ['CUSTOM_UPS' => 'not base64!'])), 'corrupt'), 'corrupt custom field is rejected');
check(has(nut_mc_validate(array_merge($defaults, ['MODE' => 'netserver', 'LISTEN' => '192.168.1.2'])), 'include 0.0.0.0 or 127.0.0.1'), 'netserver must stay reachable locally');
check(nut_mc_words('a = "b \\" \\# c" d\\ e # comment') === ['a', '=', 'b " # c', 'd e'], 'tokenizer follows NUT quoting rules');
check(nut_mc_words('desc = "a # b"', $error) === null && $error !== null, 'tokenizer rejects # inside quotes');

// Custom directives may not override managed ones
$conflicts = [
    'CUSTOM_UPS' => "port = /dev/ttyS0\n",
    'CUSTOM_UPSGLOBAL' => "[second]\n",
    'CUSTOM_UPSD' => "LISTEN 0.0.0.0\n",
    'CUSTOM_UPSMON' => "SHUTDOWNCMD \"/bin/true\"\nNOTIFYFLAG ONBATT SYSLOG\nMONITOR ups@127.0.0.1 1 x y master\n",
    'CUSTOM_USERS' => "instcmds = all\n[monuser]\n",
    'CUSTOM_NUT' => "MODE=none\n",
];
$errors = nut_mc_validate(array_merge($defaults, array_map($b, $conflicts)));
foreach (['"port"', '[second]', '"LISTEN"', '"SHUTDOWNCMD"', '"NOTIFYFLAG"', '"MONITOR"', 'start with a [username]', '[monuser]', '"MODE"'] as $needle) {
    check(has($errors, $needle), "conflict $needle is reported");
}
$allowed = [
    'CUSTOM_UPS' => "pollfreq = 30\ndebug_min = 4\n",
    'CUSTOM_UPSMON' => "NOTIFYFLAG LOWBATT SYSLOG\nMONITOR other@10.0.0.2 1 x y slave\n",
];
check(nut_mc_validate(array_merge($defaults, array_map($b, $allowed))) === [], 'directives the generator does not emit are allowed');

// Import of the upstream default files
[$cfg, $files] = fixture('legacy-default');
[$c, $notes] = nut_mc_import($cfg, $files);
check(nut_mc_validate($c) === [], 'imported defaults are valid: ' . implode(' | ', nut_mc_validate($c)));
check(!isset($c['MANUAL']), 'MANUAL setting is removed');
foreach (array_keys(NUT_MC_CUSTOM) as $key) {
    check($c[$key] === '', "imported defaults have no $key");
}
check(has($notes, 'default admin user'), 'default admin user removal is reported');
check(has($notes, 'Standalone mode now keeps upsd local'), 'standalone bind change is reported');
check(array_diff_key($c, $defaults) === [] && array_diff_key($defaults, $c) === [], 'imported defaults have exactly the current settings keys');
$generated = nut_mc_generate($c);
check(stripos($generated['upsd.users'], 'admin') === false, 'imported defaults generate no admin user');
check(strpos($generated['upsd.conf'], "LISTEN 127.0.0.1\n") !== false && strpos($generated['upsd.conf'], '0.0.0.0') === false, 'standalone listens on 127.0.0.1 only');
check(strpos($generated['ups.conf'], "[ups]\n\tdriver = usbhid-ups\n\tport = \"auto\"\n") !== false, 'imported default UPS section');

// Import of a customised set (Auto Config / "for UPS Driver" mode, netserver, user extras)
[$cfg, $files] = fixture('legacy-custom');
[$c, $notes] = nut_mc_import($cfg, $files);
$decoded = fn($key) => nut_mc_decode($c[$key]);
check($c['NAME'] === 'rack' && $c['DRIVER'] === 'usbhid-ups' && $c['PORT'] === 'auto' && $c['DEBLEVEL'] === '1', 'UPS section imported from file');
check($c['LISTEN'] === '0.0.0.0 ::', 'LISTEN addresses imported');
check($c['ADMINUSER'] === 'admin' && $decoded('ADMINPASS') === 'Adm1n', 'customised admin user imported as NUT Admin');
check($c['SLAVEUSER'] === 'remote' && $c['UPSKILL'] === 'enable' && $c['DEBLEVELMON'] === '2', 'settings-managed values kept');
check($decoded('CUSTOM_UPSGLOBAL') === 'maxretry = 3', 'global ups.conf directive kept');
check($decoded('CUSTOM_UPS') === "vendorid = \"051D\"\ndesc = \"Rack UPS 1\"\nport = /dev/ttyS0\n[spare]\ndriver = nutdrv_qx\nport = auto", 'extra UPS directives and sections kept');
check($decoded('CUSTOM_UPSD') === 'MAXAGE 25', 'extra upsd.conf directive kept');
check($decoded('CUSTOM_USERS') === "[remote]\ninstcmds = all\n[homeassistant]\npassword = ha\nupsmon slave", 'extra users kept');
check($decoded('CUSTOM_UPSMON') === "NOTIFYFLAG ONBATT SYSLOG+WALL+EXEC\nNOTIFYFLAG LOWBATT SYSLOG+EXEC\nFINALDELAY 10", 'extra upsmon directives kept');
check($decoded('CUSTOM_NUT') === 'UPSD_OPTIONS="-D"', 'extra nut.conf directive kept');
check($decoded('CUSTOM_UPSSCHED') === "CMDSCRIPT /etc/nut/upssched-cmd\nAT ONBATT * START-TIMER onbatt 30", 'upssched.conf kept');
check(has($notes, 'more than one UPS section') && has($notes, 'Manual configuration mode'), 'import notes reported');
$errors = nut_mc_validate($c);
check(count($errors) === 3, 'customised import conflicts: ' . implode(' | ', $errors));
foreach (['ups.conf (UPS section) directives, line 3: "port"', 'user [remote]', '"NOTIFYFLAG"'] as $needle) {
    check(has($errors, $needle), "customised import reports $needle");
}
check(!has($errors, '[spare]') && !has($errors, 'line 5') && !has($errors, 'line 6'), 'the additional UPS section is not a conflict');
$c['CUSTOM_UPS'] = $b("vendorid = \"051D\"\ndesc = \"Rack UPS 1\"\n[spare]\ndriver = nutdrv_qx\nport = auto\n");
$c['CUSTOM_USERS'] = $b("[homeassistant]\npassword = ha\nupsmon slave\n");
$c['CUSTOM_UPSMON'] = $b("NOTIFYFLAG LOWBATT SYSLOG+EXEC\nFINALDELAY 10\n");
check(nut_mc_validate($c) === [], 'resolved import is valid: ' . implode(' | ', nut_mc_validate($c)));
check(strpos(nut_mc_generate($c)['upsmon.conf'], "MONITOR spare@127.0.0.1 0 monuser \"Mon1tor!\" slave\n") !== false, 'the additional UPS is monitored without a say in shutdowns');

// Manual mode as a slave: the monitor comes from upsmon.conf, the unused server files are not imported
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable', 'MODE' => 'slave'], [
    'upsmon.conf' => "MONITOR ippon_ups@10.0.0.5:3493 1 remote R3mote slave\n",
    'upsd.users' => "[admin]\npassword = adminpass\n",
    'ups.conf' => "[other]\ndriver = dummy-ups\n",
]);
check($c['NAME'] === 'ippon_ups' && $c['IPADDR'] === '10.0.0.5:3493' && $c['MONUSER'] === 'remote' && nut_mc_decode($c['MONPASS']) === 'R3mote', 'slave monitor imported from upsmon.conf');
check($c['CUSTOM_UPS'] === '' && $c['CUSTOM_USERS'] === '' && has($notes, 'Slave mode') && nut_mc_validate($c) === [], 'slave import ignores the server files');

// upssched.conf of the nut-dw backend: rewritten from the settings at every start except in manual mode, which left the file
// alone. There the generated lines are generated again from the settings and the user's own lines are kept.
$schedFile = fn(array $lines) => "# Written by the NUT plugin from the GUI settings (not in manual mode).\n" . implode("\n", $lines) . "\n";
$directives = ['CMDSCRIPT /usr/sbin/nut-upssched-cmd', 'PIPEFN /var/run/nut/upssched.pipe', 'LOCKFN /var/run/nut/upssched.lock'];
$alert = ['AT ONBATT ups@127.0.0.1 START-TIMER alert:ups@127.0.0.1 45', 'AT ONLINE ups@127.0.0.1 CANCEL-TIMER alert:ups@127.0.0.1 online:ups@127.0.0.1'];
$early = ['AT ONBATT ups@127.0.0.1 START-TIMER early:ups@127.0.0.1 20', 'AT ONLINE ups@127.0.0.1 CANCEL-TIMER early:ups@127.0.0.1'];
$mine = 'AT ONBATT * START-TIMER mine 10';
$importSched = fn($manual, array $settings, array $lines) => nut_mc_import(array_merge(['MANUAL' => $manual, 'MONPASS' => $b('monpass')], $settings), [
    'upsmon.conf' => "MONITOR ups@127.0.0.1 1 monuser monpass master\n",
    'upssched.conf' => $schedFile($lines),
]);

// nothing but generated lines: they come back from the settings, once
[$c, $notes] = $importSched('enable', ['ONBATTDELAY' => '45', 'EARLYSTAGE' => '20'], array_merge($directives, $alert, $early));
check($c['CUSTOM_UPSSCHED'] === '' && nut_mc_validate($c) === [], 'manual mode: a generated upssched.conf is not kept as custom directives: ' . implode(' | ', nut_mc_validate($c)));
$sched = nut_mc_generate($c)['upssched.conf'];
check(substr_count($sched, 'CMDSCRIPT') === 1 && substr_count($sched, "START-TIMER alert:ups@127.0.0.1 45\n") === 1 && substr_count($sched, "START-TIMER early:ups@127.0.0.1 20\n") === 1,
    'manual mode: the timers are generated once from the settings');

// the user's own line next to the generated ones is kept, the generated ones are not: only the user's line conflicts with the timers
[$c, $notes] = $importSched('enable', ['ONBATTDELAY' => '45'], array_merge($directives, $alert, [$mine]));
check(nut_mc_decode($c['CUSTOM_UPSSCHED']) === $mine, 'manual mode: the user\'s own upssched.conf line survives the import');
$errors = nut_mc_validate($c);
check(count($errors) === 1 && has($errors, 'upssched.conf directives, line 1: "AT"'), 'manual mode: only the user\'s line conflicts with the generated timers: ' . implode(' | ', $errors));

// no timer set, so nothing is generated: the file stays together, the user's lines need its CMDSCRIPT
[$c] = $importSched('enable', [], array_merge($directives, [$mine]));
check(nut_mc_decode($c['CUSTOM_UPSSCHED']) === implode("\n", array_merge($directives, [$mine])) && nut_mc_validate($c) === [], 'manual mode: without timers the whole file is kept and valid');
$sched = nut_mc_generate($c)['upssched.conf'];
check(substr_count($sched, 'CMDSCRIPT /usr/sbin/nut-upssched-cmd') === 1 && substr_count($sched, "$mine\n") === 1, 'manual mode: without timers the kept CMDSCRIPT and the user\'s line are written as they were');
[$c] = $importSched('enable', [], $directives);
check($c['CUSTOM_UPSSCHED'] === '', 'manual mode: a header with only the generated directives and no timers holds nothing of the user\'s');

// a timer delay that differs from the settings was edited by hand: it is kept and reported, not replaced silently
[$c] = $importSched('enable', ['ONBATTDELAY' => '45'], array_merge($directives, ['AT ONBATT ups@127.0.0.1 START-TIMER alert:ups@127.0.0.1 30'], array_slice($alert, 1)));
check(nut_mc_decode($c['CUSTOM_UPSSCHED']) === 'AT ONBATT ups@127.0.0.1 START-TIMER alert:ups@127.0.0.1 30' && has(nut_mc_validate($c), '"AT"'),
    'manual mode: a timer with another delay than the setting is kept and reported');

// without manual mode the backend rewrote the file at every start: nothing in it was the user's
foreach (['disable', 'onlyups'] as $manual) {
    [$c] = $importSched($manual, ['ONBATTDELAY' => '45'], array_merge($directives, $alert, [$mine]));
    check($c['CUSTOM_UPSSCHED'] === '' && nut_mc_validate($c) === [], "$manual mode: a rewritten upssched.conf is regenerated, not kept");
}

// Deployment: import keeps the old files until the user saves, then generates with backups
$dir = sys_get_temp_dir() . '/nut-mc-' . getmypid();
@mkdir("$dir/ups", 0777, true);
[, $files] = fixture('legacy-default');
foreach ($files as $file => $text) {
    file_put_contents("$dir/ups/$file", $text);
}
copy(__DIR__ . '/fixtures/legacy-default/nut3.cfg', "$dir/nut3.cfg");
check(nut_mc_deploy($dir, $messages) && nut_mc_report($dir) !== null, 'first deploy imports and leaves the import pending');
check(file_get_contents("$dir/ups/upsd.users") === $files['upsd.users'], 'old files stay in place while the import is pending');
check(is_file("$dir/backup/legacy/upsd.users") && is_file("$dir/backup/legacy/nut3.cfg"), 'old configuration backed up');
check(!isset(nut_mc_read_cfg("$dir/nut3.cfg")['MANUAL']), 'settings file rewritten by the import');
check(nut_mc_deploy($dir, $messages) && file_get_contents("$dir/ups/upsd.users") === $files['upsd.users'], 'pending import is not activated by later starts');
check(nut_mc_accept($dir) && nut_mc_pending($dir) === null, 'saving accepts the import');
check(nut_mc_deploy($dir, $messages), 'deploy after review: ' . implode(' | ', $messages));
check(!nut_mc_is_legacy($dir) && strpos(file_get_contents("$dir/ups/upsd.users"), NUT_MC_MARK) === 0, 'generated files written');
check(nut_mc_report($dir) === null, 'import report removed once the generated files are in place');
check(file_get_contents("$dir/backup/upsd.users.bak") === $files['upsd.users'], 'replaced file kept as .bak');
$first = file_get_contents("$dir/ups/upsmon.conf");
nut_mc_write_cfg("$dir/nut3.cfg", array_merge(nut_mc_read_cfg("$dir/nut3.cfg"), ['DEBLEVELMON' => '4']));
check(nut_mc_deploy($dir, $messages) && file_get_contents("$dir/backup/upsmon.conf.bak") === $first, 'previous generated file kept as .bak');
$current = file_get_contents("$dir/ups/upsmon.conf");
nut_mc_write_cfg("$dir/nut3.cfg", array_merge(nut_mc_read_cfg("$dir/nut3.cfg"), ['NAME' => 'bad name']));
check(!nut_mc_deploy($dir, $messages) && has($messages, 'UPS Name') && file_get_contents("$dir/ups/upsmon.conf") === $current, 'invalid settings are not deployed');
file_put_contents("$dir/ups/upsmon.conf", str_replace(NUT_MC_MARK, '# Generated by the NUT plugin (nut-dw)', $current));
check(!nut_mc_is_legacy($dir), 'files generated before the rename from nut-dw are not imported again');
exec('rm -rf ' . escapeshellarg($dir));

// Auto Config writes scanner results into the settings
$scan = "[nutdev1]\n\tdriver = \"usbhid-ups\"\n\tport = \"auto\"\n\tvendorid = \"0764\"\n\tproductid = \"0501\"\n[nutdev2]\n\tdriver = \"snmp-ups\"\n\tport = \"10.0.0.9\"\n";
[$c, $messages] = nut_mc_apply_scan($defaults, $scan);
check($c['DRIVER'] === 'usbhid-ups' && $c['PORT'] === 'auto' && nut_mc_decode($c['CUSTOM_UPS']) === "vendorid = \"0764\"\nproductid = \"0501\"", 'scan applied to settings');
check($c['NAME'] === 'ups' && has($messages, '[nutdev2]') && nut_mc_validate($c) === [], 'scan keeps the UPS name and reports other devices');
[$c] = nut_mc_apply_scan($defaults, "[nutdev1]\n\tdriver = \"snmp-ups\"\n\tport = \"10.0.0.9\"\n\tmibs = \"apcc\"\n\tcommunity = \"public\"\n");
check($c['DRIVER'] === 'snmp-ups' && $c['SNMPMIB'] === 'apcc' && $c['CUSTOM_UPS'] === '' && nut_mc_validate($c) === [], 'SNMP scan applied to settings');

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "Managed configuration tests passed.\n";
