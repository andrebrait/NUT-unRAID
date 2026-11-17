<?php
/* Tests for the debug package redaction: no credential survives in the copied NUT files, the device dump or the
 * syslogs, and the diagnostics without credentials are kept. The main check runs the debug_redact script on a staged
 * package, as the debug script does. Run: php tests/test_debug_redaction.php */

$plugin = __DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3';
require_once "$plugin/include/nut_redact.php";

$failures = 0;

function check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

function remove_tree($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) remove_tree("$path/$entry");
        rmdir($path);
    } elseif (is_file($path) || is_link($path)) {
        unlink($path);
    }
}

function texts_below($dir) {
    $texts = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $texts[$file->getPathname()] = file_get_contents($file->getPathname());
    }
    return $texts;
}

// Credentials chosen to break naive sed substitution: slashes, regex metacharacters, a space and a hash sign.
$secrets = [
    'mon/pa.ss*[x]^$|{}+?()',
    'sl/ave#pa.ss',
    'adm.in*pw[1]',
    'pub/lic.*[c]',
    'auth/pa.ss*[1]^$',
    'priv|pa{s}s+?(2)',
    'custom/pa.ss*[y]',
    'sp ace/hidden',
    'pa#ss/bad',
];

$tmp = sys_get_temp_dir() . '/nut-redact-' . bin2hex(random_bytes(12));
mkdir("$tmp/debug/RAM", 0700, true);
mkdir("$tmp/debug/USB", 0700, true);
mkdir("$tmp/debug/PLUGIN", 0700, true);

// the settings file as the plugin writes it: base64 passwords, plain user names
file_put_contents("$tmp/nut3.cfg", implode("\n", [
    'MONUSER="monuser"',
    'MONPASS="' . base64_encode($secrets[0] . "\n") . '"',
    'SLAVEUSER="slaveuser"',
    'SLAVEPASS="' . base64_encode($secrets[1]) . '"',
    'ADMINUSER="upsadmin"',
    'ADMINPASS="' . base64_encode($secrets[2]) . '"',
    'MODE="netserver"',
]) . "\n");

file_put_contents("$tmp/debug/RAM/ups.conf.txt", <<<'TXT'
[ups]
	driver = "snmp-ups"
	port = "192.168.1.50"
	pollfreq = 15
	community = "pub/lic.*[c]"
	snmp_version = v3
	secLevel = authPriv
	secName = "snmpuser"
	authProtocol = SHA
	authPassword = "auth/pa.ss*[1]^$"
	privProtocol = AES
	privPassword = "priv|pa{s}s+?(2)"
TXT);

file_put_contents("$tmp/debug/RAM/upsd.users.txt", <<<'TXT'
[monuser]
	password = "mon/pa.ss*[x]^$|{}+?()"
	upsmon master
[slaveuser]
	password = "sl/ave\#pa.ss"
	upsmon slave
[upsadmin]
	password = "adm.in*pw[1]"
	actions = SET
	instcmds = ALL
TXT);

$monitor = <<<'TXT'
MONITOR ups@127.0.0.1 1 monuser "mon/pa.ss*[x]^$|{}+?()" master
MONITOR ups2@192.168.1.9 0 remote/user "custom/pa.ss*[y]" slave
MONITOR ups3@192.168.1.8 0 remote "sp ace/hidden" slave
MONITOR ups4@192.168.1.7 0 remote pa#ss/bad slave
NOTIFYCMD "/usr/sbin/nut-notify"
DEBUG_MIN 2
TXT;
file_put_contents("$tmp/debug/RAM/upsmon.conf.txt", $monitor);
file_put_contents("$tmp/debug/USB/upsmon.conf.txt", $monitor);
file_put_contents("$tmp/debug/RAM/upsd.conf.txt", "LISTEN 0.0.0.0 3493\n");

file_put_contents("$tmp/debug/PLUGIN/ups.dev.txt", <<<'TXT'
ups.status: OL
battery.charge: 100
driver.parameter.authPassword: auth/pa.ss*[1]^$
TXT);

file_put_contents("$tmp/debug/PLUGIN/nut-syslog-file.txt", <<<'TXT'
Jan  1 00:00:01 tower upsmon[10]: Startup successful
Jan  1 00:00:02 tower upsd[11]: user monuser logged in with mon/pa.ss*[x]^$|{}+?()
Jan  1 00:00:03 tower upsmon[10]: monitor custom/pa.ss*[y] rejected
Jan  1 00:00:04 tower upsmon[10]: malformed pa#ss/bad rejected
Jan  1 00:00:05 tower upsmon[10]: spaced sp ace/hidden and sl/ave#pa.ss
Jan  1 00:00:06 tower upsmon[10]: on battery
TXT);

// the CLI the debug script calls
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$plugin/scripts/debug_redact") . ' '
    . escapeshellarg("$tmp/nut3.cfg") . ' ' . escapeshellarg("$tmp/debug") . ' 2>&1', $output, $code);
check($code === 0, 'debug_redact exits 0: ' . implode(' ', $output));

$texts = texts_below("$tmp/debug");
check(count($texts) === 7, 'every staged file is still in the package');

// no credential survives in any file
foreach ($texts as $path => $text) {
    foreach ($secrets as $secret) {
        check(strpos($text, $secret) === false, basename($path) . " still holds the credential '$secret'");
    }
    check(strpos($text, 'monuser') === false, basename($path) . ' still holds the monitor user name');
}

// the diagnostics without credentials are kept
$ups = $texts["$tmp/debug/RAM/ups.conf.txt"];
check(strpos($ups, 'driver = "snmp-ups"') !== false, 'ups.conf keeps the driver');
check(strpos($ups, 'snmp_version = v3') !== false, 'ups.conf keeps the SNMP version');
check(strpos($ups, 'secName = "snmpuser"') !== false, 'ups.conf keeps the SNMPv3 user name');
check(substr_count($ups, '[credential line removed]') === 3, 'ups.conf removes the community and both SNMPv3 passwords');

$users = $texts["$tmp/debug/RAM/upsd.users.txt"];
check(strpos($users, '[USER-REMOVED]') !== false && strpos($users, 'upsmon master') !== false, 'upsd.users keeps the sections and roles');
check(substr_count($users, '[credential line removed]') === 3, 'upsd.users removes every password line');

foreach (['RAM', 'USB'] as $copy) {
    $mon = $texts["$tmp/debug/$copy/upsmon.conf.txt"];
    check(strpos($mon, 'MONITOR ups@127.0.0.1 1 USER-REMOVED PASS-REMOVED master') !== false, "$copy upsmon.conf masks the password of the local MONITOR");
    check(strpos($mon, 'MONITOR ups2@192.168.1.9 0 remote/user PASS-REMOVED slave') !== false, "$copy upsmon.conf masks a custom MONITOR password");
    check(strpos($mon, 'MONITOR ups3@192.168.1.8 0 remote PASS-REMOVED slave') !== false, "$copy upsmon.conf masks a quoted password with a space");
    check(strpos($mon, '[MONITOR line removed: unrecognised format]') !== false, "$copy upsmon.conf removes a MONITOR line it cannot parse");
    check(strpos($mon, 'NOTIFYCMD "/usr/sbin/nut-notify"') !== false, "$copy upsmon.conf keeps the other directives");
}

$dev = $texts["$tmp/debug/PLUGIN/ups.dev.txt"];
check(strpos($dev, 'ups.status: OL') !== false && strpos($dev, 'battery.charge: 100') !== false, 'device dump keeps its status variables');
check(substr_count($dev, '[credential line removed]') === 1, 'device dump removes the driver authPassword');

$syslog = $texts["$tmp/debug/PLUGIN/nut-syslog-file.txt"];
check(strpos($syslog, 'Startup successful') !== false && strpos($syslog, 'on battery') !== false, 'syslog keeps the ordinary lines');
check(strpos($syslog, 'user USER-REMOVED logged in') !== false, 'syslog keeps the event with the user name removed');

// a known credential that still appears after the replacement fails closed: the file is replaced by a notice
$guard = sys_get_temp_dir() . '/nut-redact-guard-' . bin2hex(random_bytes(12));
mkdir($guard, 0700);
file_put_contents("$guard/notes.txt", "value xPASSy logged\n");
check(nut_redact_dir($guard, nut_mc_settings(['ADMINPASS' => base64_encode('PASS')])), 'redaction of the guard case succeeds');
check(file_get_contents("$guard/notes.txt") === "[file removed: a credential could not be redacted]\n",
    'a file that still holds a credential after redaction is replaced by a notice');

// Values quoted for parseconf can appear decoded in logs.
$escaped = "$guard/escaped";
mkdir($escaped, 0700);
file_put_contents("$escaped/ups.conf", 'authPasswo\\rd = "back\\\\slash"' . "\n");
file_put_contents("$escaped/log.txt", "authentication failed for back\\slash\n");
check(nut_redact_dir($escaped, nut_mc_settings([])), 'escaped credential redaction succeeds');
check(strpos(file_get_contents("$escaped/log.txt"), 'back\\slash') === false, 'decoded custom credentials are also removed from logs');

// A malformed tail must not hide a decoded directive name or weaken conservative removal.
foreach (['"MONITOR"', 'MONI\\TOR'] as $index => $directive) {
    $malformed = "$guard/malformed-$index";
    mkdir($malformed, 0700);
    file_put_contents("$malformed/upsmon.conf", "$directive other@host 0 observer m8G2q4L6 \"\n");
    file_put_contents("$malformed/log.txt", "Peer rejected: m8G2q4L6\n");
    check(nut_redact_dir($malformed, nut_mc_settings([])), 'malformed decoded MONITOR redaction succeeds');
    check(strpos(file_get_contents("$malformed/upsmon.conf"), '[MONITOR line removed: unrecognised format]') !== false,
        'a malformed quoted or escaped MONITOR is removed conservatively');
    check(strpos(file_get_contents("$malformed/log.txt"), 'm8G2q4L6') === false,
        'the malformed MONITOR fallback still collects the visible credential');
}

remove_tree($tmp);
remove_tree($guard);

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "Debug redaction tests passed.\n";
