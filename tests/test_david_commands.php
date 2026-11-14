<?php
/* Tests for the command, outlet, writable-variable (upsrw), status-cache and multi-UPS overview helpers taken over
 * from davidcoulson/NUT-unRAID, and for the rules the two command endpoints (nut_commands.php, nut_upsrw.php) run under
 * here: POST only, the module switch, only commands the UPS lists, the NUT Admin credentials from the settings
 * (never from the browser), bounded and escaped shell calls. The endpoints are thin wrappers around
 * nut_commands_response() / nut_upsrw_response(), which run against a fake command runner here.
 * Run: php tests/test_david_commands.php */

// the status cache runs in a private directory here, not in the shared /dev/shm
$cacheDir = sys_get_temp_dir() . '/nut-rows-' . bin2hex(random_bytes(12));
if (!mkdir($cacheDir, 0700)) throw new RuntimeException('Cannot create test sandbox');
define('NUT_ROWS_CACHE_DIR', $cacheDir);

// a stand-in for upsc in a directory of its own (the cache directory is emptied by the tests below). Like the real
// client's getopt it takes a leading "-name" for an option unless "--" ends the options first.
$binDir = sys_get_temp_dir() . '/nut-bin-' . bin2hex(random_bytes(12));
if (!mkdir($binDir, 0700)) throw new RuntimeException('Cannot create test sandbox');
define('NUT_UPSC', "$binDir/upsc");
file_put_contents(NUT_UPSC, <<<'SH'
#!/bin/bash
dir=$(dirname "$0")
echo "$*" >> "$dir/upsc.log"
if [ "$1" = -- ]; then
    shift
elif [[ "$1" == -* ]]; then
    echo "Error: invalid option -- '$1'" >&2
    exit 1
fi
if [ -n "$2" ]; then # upsc <ups> <variable>
    case "$2" in
        ups.status) echo OL ;;
        battery.charge) echo 88 ;;
        *) echo "Error: Variable not supported by UPS" >&2; exit 1 ;;
    esac
    exit 0
fi
[ -e "$dir/listall" ] || { echo "Error: Protocol error" >&2; exit 1; }
printf '%s\n' 'ups.status: OL' 'battery.charge: 88'
SH);
chmod(NUT_UPSC, 0700);

require_once __DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3/include/nut_helpers.php';

$failures = 0;

function check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

// nut_ups_status() and nut_ups_overview() read these from nut_config.php
$nut_runtime = 'battery.runtime';
$nut_rtunit = 'seconds';
$nut_msgSeverity = [0 => ['css_class' => 'green-text'], 1 => ['css_class' => 'orange-text'], 2 => ['css_class' => 'red-text']];
$nut_states = [
    'OL' => ['severity' => 0, 'msg' => 'On Line'],
    'OB' => ['severity' => 1, 'msg' => 'On Battery'],
    'LB' => ['severity' => 2, 'msg' => 'Low Battery'],
];

// --- upscmd -l: only advertised commands are known ---------------------------------------------------------------
$cmds = nut_parse_upscmd([
    'Instant commands supported on UPS [ups]:',
    '',
    'beeper.disable - Disable the UPS beeper',
    'load.off.delay - Turn off the load with a delay (seconds)',
    'test.battery.start - Start a battery test - quick or deep',
]);
check(array_keys($cmds) === ['beeper.disable', 'load.off.delay', 'test.battery.start'], 'upscmd list holds the three advertised commands, not the header');
check(($cmds['test.battery.start'] ?? '') === 'Start a battery test - quick or deep', 'a description may contain " - "');
check(!isset($cmds['shutdown.return']) && !isset($cmds['outlet.1.load.off']), 'unlisted commands are not accepted');
check(nut_parse_upscmd([]) === [], 'no output, no commands');

// --- upsrw: parsing ----------------------------------------------------------------------------------------------
$upsrwLines = [
    '[ups.delay.shutdown]',
    'Interval to wait after shutdown with delay command (seconds)',
    'Type: NUMBER',
    'Value: 20',
    '',
    '[ups.beeper.status]',
    'Beeper status',
    'Type: ENUM',
    'Option: "disabled"',
    'Option: "enabled" SELECTED',
    'Option: "muted"',
    '',
    '[ups.id]',
    'Description string',
    'Type: STRING',
    'Maximum length: 10',
    'Value: UPS1',
    '',
    '[battery.charge.low]',
    'Low battery threshold',
    'Type: RANGE NUMBER',
    'Option: "10-50" SELECTED',
    '',
    '[input.transfer.low]',
    'Low transfer point',
    'Type: RANGE NUMBER',
    'Option: "-5-5" SELECTED',
    'Option: "100-120"',
];
$vars = nut_parse_upsrw($upsrwLines);
check(array_keys($vars) === ['ups.delay.shutdown', 'ups.beeper.status', 'ups.id', 'battery.charge.low', 'input.transfer.low'], 'upsrw variables parsed in order');
check($vars['ups.delay.shutdown']['type'] === 'NUMBER' && $vars['ups.delay.shutdown']['value'] === '20' && $vars['ups.delay.shutdown']['desc'] === 'Interval to wait after shutdown with delay command (seconds)', 'NUMBER variable');
check($vars['ups.beeper.status']['options'] === ['disabled', 'enabled', 'muted'] && $vars['ups.beeper.status']['value'] === 'enabled', 'ENUM options and the SELECTED value');
check($vars['ups.id']['maxlen'] === 10 && $vars['ups.id']['value'] === 'UPS1', 'STRING maximum length and value');
check($vars['battery.charge.low']['type'] === 'RANGE' && $vars['battery.charge.low']['options'] === ['10-50'], 'RANGE: "RANGE NUMBER" is a RANGE, the option is kept verbatim');
check($vars['battery.charge.low']['value'] === '' && $vars['input.transfer.low']['value'] === '', 'RANGE: the SELECTED range is not the value (upsrw prints none)');
check(nut_parse_upsrw(['[x]', 'Type: ENUM NUMBER', 'Option: "1"', 'Option: "2" SELECTED'])['x']['value'] === '2', 'ENUM NUMBER: the SELECTED option is the value');
check(nut_check_upsrw_value(['type' => 'RANGE', 'options' => ['10 - 50', '100 120'], 'maxlen' => null], '110') === null, 'RANGE: the "min - max" and "min max" spellings are still read');
check(nut_parse_upsrw(['Error: Variable not supported', 'Value: 1']) === [], 'text before the first [variable] block is ignored');

// --- upsrw: a value is only accepted if the variable's own definition allows it -----------------------------------
check(nut_check_upsrw_value($vars['ups.beeper.status'], 'muted') === null, 'ENUM: listed option accepted');
check(nut_check_upsrw_value($vars['ups.beeper.status'], 'loud') !== null, 'ENUM: unlisted option rejected');
check(nut_check_upsrw_value($vars['battery.charge.low'], '10') === null && nut_check_upsrw_value($vars['battery.charge.low'], '50') === null, 'RANGE: both bounds accepted');
check(nut_check_upsrw_value($vars['battery.charge.low'], '9') !== null && nut_check_upsrw_value($vars['battery.charge.low'], '51') !== null, 'RANGE: outside rejected');
check(nut_check_upsrw_value($vars['battery.charge.low'], 'abc') !== null, 'RANGE: non-number rejected');
check(nut_check_upsrw_value($vars['input.transfer.low'], '-3') === null && nut_check_upsrw_value($vars['input.transfer.low'], '110') === null, 'RANGE: negative bounds and a second "min max" option');
check(nut_check_upsrw_value($vars['input.transfer.low'], '50') !== null, 'RANGE: gap between two ranges rejected');
check(nut_check_upsrw_value($vars['ups.delay.shutdown'], '30') === null && nut_check_upsrw_value($vars['ups.delay.shutdown'], '3x') !== null, 'NUMBER: numeric only');
foreach (['1e1', '1E1', ' 5', '5 ', '+5', '.5', '5.', '0x1A', '1_0', '--5', '', 'INF', 'NAN', '5,0', "\u{663}"] as $loose) {
    check(nut_check_upsrw_value($vars['ups.delay.shutdown'], $loose) === 'Value must be a number.', 'NUMBER: ' . json_encode($loose) . ' is not a plain decimal');
    check(nut_check_upsrw_value($vars['battery.charge.low'], $loose) === 'Value must be a number.', 'RANGE: ' . json_encode($loose) . ' is not a plain decimal (1e1 would be 10, inside the range)');
}
foreach (['0', '30', '-3', '12.5', '007', '-0.25'] as $plain) {
    check(nut_check_upsrw_value($vars['ups.delay.shutdown'], $plain) === null, "NUMBER: $plain is a plain decimal");
}
check(nut_check_upsrw_value($vars['battery.charge.low'], '12.5') === null && nut_check_upsrw_value($vars['input.transfer.low'], '-0.25') === null, 'RANGE: decimals with a fraction are accepted');
check(nut_check_upsrw_value($vars['ups.id'], 'ABCDEFGHIJ') === null && nut_check_upsrw_value($vars['ups.id'], 'ABCDEFGHIJK') !== null, 'STRING: maximum length');
check(nut_check_upsrw_value($vars['ups.id'], "A\nB") !== null && nut_check_upsrw_value($vars['ups.id'], "A\x00B") !== null, 'control characters rejected for every type');
check(nut_check_upsrw_value(['type' => '', 'options' => [], 'maxlen' => null], 'x') !== null, 'unknown variable type rejected');

// --- outlets -----------------------------------------------------------------------------------------------------
$outlets = nut_parse_outlets([
    'battery.charge: 100',
    'outlet.1.desc: Server',
    'outlet.1.status: on',
    'outlet.1.switchable: yes',
    'outlet.10.name: Switch',
    'outlet.2.desc: NAS',
    'outlet.2.name: Ignored, a description exists',
    'outlet.group.1.status: on',
], [
    'outlet.1.load.off', 'outlet.1.load.on', 'outlet.1.load.cycle', 'outlet.10.load.cycle', 'outlet.group.1.load.off',
    'outlet.2.load.reboot', 'shutdown.return',
]);
check(array_map('strval', array_keys($outlets)) === ['1', '2', '10', 'group.1'], 'outlets in natural order, groups last');
check($outlets[1]['desc'] === 'Server' && $outlets[1]['status'] === 'on' && $outlets[1]['switchable'] === 'yes', 'outlet variables');
check($outlets[1]['cmds'] === ['off' => 'outlet.1.load.off', 'on' => 'outlet.1.load.on', 'cycle' => 'outlet.1.load.cycle'], 'outlet commands mapped by action');
check($outlets[10]['desc'] === 'Switch' && array_keys($outlets[10]['cmds']) === ['cycle'], 'outlet.N.name stands in for a missing description');
check($outlets[2]['desc'] === 'NAS' && $outlets[2]['cmds'] === [], 'description wins over name, non-load commands ignored');
check($outlets['group.1']['cmds'] === ['off' => 'outlet.group.1.load.off'], 'outlet groups');
check(nut_parse_outlets(['battery.charge: 100'], ['shutdown.return']) === [], 'no outlets reported');

// --- status cache and multi-UPS overview ----------------------------------------------------------------------------
function clear_dir($dir) {
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) unlink("$dir/$f"); // unlink removes a symlink itself
}
$rows = [
    'battery.charge: 55', 'battery.runtime: 3725', 'ups.load: 12', 'input.voltage: 230.1', 'output.voltage: 230.0',
    'ups.status: OB LB X<Y>', 'device.mfr: Eve"il', 'ups.model: <b>M</b>',
];
$files = [nut_rows_cache_file('main', '203.0.113.7'), nut_rows_cache_file('other', '203.0.113.7'), nut_rows_cache_file('main', '203.0.113.8')];
check(count(array_unique($files)) === 3, 'the cache is keyed by UPS name and host');
check(dirname($files[0]) === $cacheDir, 'the cache directory can be chosen');

// reading: only a fresh regular file of our own user that holds rows is a hit
file_put_contents($files[0], json_encode($rows));
check(nut_rows_cache_load($files[0]) === $rows, 'cache: a fresh entry is loaded');
check(nut_status_rows('main', '203.0.113.7') === $rows, 'a fresh cache entry is returned without calling upsc');
touch($files[0], time() - NUT_ROWS_CACHE_TTL - 1);
check(nut_rows_cache_load($files[0]) === [], 'cache: an entry older than the TTL is a miss');
foreach (['not json', '"text"', '[]', ''] as $junk) {
    file_put_contents($files[0], $junk);
    check(nut_rows_cache_load($files[0]) === [], 'cache: ' . json_encode($junk) . ' is a miss');
}
check(nut_rows_cache_load("$cacheDir/absent.json") === [], 'cache: no entry is a miss');
file_put_contents($files[0], json_encode($rows));
symlink($files[0], "$cacheDir/link.json");
check(nut_rows_cache_load("$cacheDir/link.json") === [], 'cache: a symlink is not followed');
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && @chown($files[0], 65534)) {
    check(nut_rows_cache_load($files[0]) === [], 'cache: a file of another user is not trusted');
} else {
    fwrite(STDERR, "SKIP: cannot give a cache file to another user, not tested.\n");
}
clear_dir($cacheDir);

// writing: through a fresh private temp file, never a name another user can guess or plant
$entry = $files[1];
$victim = "$cacheDir/victim";
file_put_contents($victim, 'untouched');
symlink($victim, $entry . '.' . getmypid()); // the temp name the cache used to write through
check(nut_rows_cache_store($entry, $rows) === true, 'cache: rows are stored');
check(file_get_contents($victim) === 'untouched', 'cache: nothing is written through a symlink planted at a guessable temp name');
check(is_file($entry) && !is_link($entry) && (fileperms($entry) & 0777) === 0600 && nut_rows_cache_load($entry) === $rows, 'cache: the entry is a private regular file holding the rows');
check(array_diff(glob("$cacheDir/nut3-rows-*"), [$entry, $entry . '.' . getmypid()]) === [], 'cache: no temp file is left behind');
check(nut_rows_cache_store($entry, ['x: 1']) === true && nut_rows_cache_load($entry) === ['x: 1'], 'cache: an entry is replaced');
unlink($entry);
symlink($victim, $entry);
check(nut_rows_cache_store($entry, $rows) === true && file_get_contents($victim) === 'untouched' && !is_link($entry) && nut_rows_cache_load($entry) === $rows, 'cache: a symlink planted at the entry itself is replaced, not written through');
check(nut_rows_cache_store("$cacheDir/missing/entry.json", $rows) === false, 'cache: a store that cannot be done reports it and throws nothing');
clear_dir($cacheDir);

file_put_contents($files[0], json_encode($rows));
$ov = nut_ups_overview(['main' => '203.0.113.7']);
$u = $ov['main'];
check($u['ok'] === true && $u['css'] === 'red-text' && $u['word'] === 'On Battery', 'overview: reachable UPS with the highest severity');
check($u['status'] === 'On Battery - Low Battery - X&lt;Y&gt;', 'overview: status escaped exactly once');
check($u['desc'] === 'Eve&quot;il &lt;b&gt;M&lt;/b&gt;', 'overview: description escaped');
check($u['bat'] === '55&thinsp;%' && $u['rt'] === '01:02:05' && $u['load'] === '12&thinsp;%' && $u['vin'] === '230.1&thinsp;V', 'overview: values and units');
clear_dir($cacheDir);
check(nut_ups_status(['ups.status: OL X<Y>'])['fulltext'] === ['On Line', 'X<Y>'], 'unknown status flags are returned raw, escaping happens where they reach the page');

// --- NUT Admin credentials of the settings -----------------------------------------------------------------------------
$admin = ['ADMINUSER' => 'upsadmin', 'ADMINPASS' => base64_encode('Adm1nPass')];
check(nut_admin_credentials($admin) === ['upsadmin', 'Adm1nPass'], 'credentials: user and decoded password');
check(nut_admin_credentials(['ADMINUSER' => 'upsadmin', 'ADMINPASS' => base64_encode("Adm1nPass\n")]) === ['upsadmin', 'Adm1nPass'], 'credentials: a password stored with `echo | base64` loses its newline');
check(nut_admin_credentials(['ADMINUSER' => '', 'ADMINPASS' => $admin['ADMINPASS']]) === null, 'credentials: no user, none');
check(nut_admin_credentials(['ADMINUSER' => 'upsadmin', 'ADMINPASS' => '']) === null, 'credentials: no password, none');
check(nut_admin_credentials(['ADMINUSER' => 'upsadmin', 'ADMINPASS' => '!!not base64!!']) === null, 'credentials: corrupt password, none');
check(nut_admin_credentials(['ADMINUSER' => 'upsadmin']) === null && nut_admin_credentials([]) === null && nut_admin_credentials(false) === null, 'credentials: missing settings, none');

// --- the endpoints, against a fake command runner -------------------------------------------------------------------
$upsList = ['ups' => '127.0.0.1', 'second' => '192.0.2.5', '-dash' => '198.51.100.9'];
$upscmdList = [
    'ups' => ['Instant commands supported on UPS [ups]:', '', 'beeper.disable - Disable the UPS beeper', 'outlet.1.load.off - Switch outlet 1 off', 'outlet.1.load.on - Switch outlet 1 on'],
    'second' => ['Instant commands supported on UPS [second]:', '', 'load.on - Turn on the load'],
];

/* Answers the NUT clients like a UPS would and logs every command line. $opts: out/ret = result of upscmd and
 * upsrw -s, listout/listret = result of upsrw (list), rowsout/rowsret = result of upsc. */
function fake_runner(array &$log, array $opts) {
    global $upscmdList, $upsrwLines;
    return function ($cmd, &$out, &$ret) use (&$log, $opts, $upscmdList, $upsrwLines) {
        $log[] = $cmd;
        $out = ['OK'];
        $ret = 0;
        if (strpos($cmd, '/usr/bin/upscmd -l ') !== false) {
            $out = strpos($cmd, "'second'@") !== false ? $upscmdList['second'] : $upscmdList['ups'];
        } elseif (strpos($cmd, '/usr/bin/upsc ') !== false) {
            $out = $opts['rowsout'] ?? ['battery.charge: 100', 'battery.charge.low: 20', 'input.transfer.low: 0', 'outlet.1.desc: Server', 'outlet.1.status: on'];
            $ret = $opts['rowsret'] ?? 0;
        } elseif (strpos($cmd, '/usr/bin/upsrw -s ') !== false || strpos($cmd, '/usr/bin/upscmd -u ') !== false) {
            $out = $opts['out'] ?? ['OK'];
            $ret = $opts['ret'] ?? 0;
        } elseif (strpos($cmd, '/usr/bin/upsrw ') !== false) {
            $out = $opts['listout'] ?? $upsrwLines;
            $ret = $opts['listret'] ?? 0;
        }
    };
}

/* Calls a handler as the webGui would: [response, every command run, commands run with credentials on their command line]. */
function call($handler, array $post, array $over = [], array $opts = [], $method = 'POST') {
    global $admin, $upsList;
    $log = [];
    $resp = $handler($method, $post, array_merge($admin, $over), $upsList, 'ups', fake_runner($log, $opts));
    return [$resp, $log, array_values(array_filter($log, fn($c) => strpos($c, ' -p ') !== false))];
}

function error_of($resp) {
    return $resp['error']['response'] ?? null;
}

$noCreds = [['ADMINUSER' => ''], ['ADMINPASS' => ''], ['ADMINPASS' => '!!not base64!!']];
$browserCreds = ['nutcmduser' => 'root', 'nutcmdpass' => 'hunter2'];

// both endpoints: POST only (so the webGui checks the CSRF token), and refuse while the module is switched off
foreach (['nut_commands_response' => ['nutaction' => 'outlets'], 'nut_upsrw_response' => ['action' => 'list']] as $handler => $post) {
    foreach (['GET', 'HEAD', 'PUT', ''] as $method) {
        [$r, $log] = call($handler, $post, [], [], $method);
        check(error_of($r) === 'POST required.' && $log === [], "$handler: $method is refused before anything runs");
    }
    [$r, $log] = call($handler, $post, ['COMMANDS' => 'disable']);
    check(error_of($r) === 'NUT commands are disabled in the NUT Settings.' && $log === [], "$handler: refused while the NUT Command Center Module is disabled");
    [$r] = call($handler, $post, ['COMMANDS' => 'enable']);
    check(!isset($r['error']), "$handler: works while the module is enabled");
    [$r] = call($handler, $post);
    check(!isset($r['error']), "$handler: works when the setting is absent (enabled by default)");
    [$r, $log] = call($handler, $post + ['nutups' => 'nope']);
    check(error_of($r) === 'Unknown UPS.' && $log === [], "$handler: an unknown UPS is refused before anything runs");
    [$r, $log] = call($handler, $post + ['nutups' => ['ups']]);
    check(error_of($r) === 'Unknown UPS.' && $log === [], "$handler: an array as UPS name is refused");
}

// commands: only what the UPS lists, as the stored NUT Admin user
$expected = NUT_CLIENT_TIMEOUT . "/usr/bin/upscmd -u 'upsadmin' -p 'Adm1nPass' -- 'ups'@'127.0.0.1' 'beeper.disable' 2>&1";
[$r, $log, $with] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable']);
check($with === [$expected] && $r === ['success' => ['response' => 'OK']], 'commands: a listed command runs once, bounded, with the stored credentials');
check($log[0] === NUT_CLIENT_TIMEOUT . "/usr/bin/upscmd -l -- 'ups'@'127.0.0.1' 2>/dev/null", 'commands: the UPS is asked for its command list first, bounded');

[$r, , $with] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable'] + $browserCreds);
check($with === [$expected], 'commands: credentials sent by the browser are ignored');

foreach (['shutdown.return', 'beeper.disable; id', "beeper.disable\nid", '', ['beeper.disable']] as $bad) {
    [$r, , $with] = call('nut_commands_response', ['nutcmdname' => $bad]);
    check(error_of($r) === 'Command is not listed by the UPS.' && $with === [], 'commands: ' . json_encode($bad) . ' is not run');
}
[$r, , $with] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable', 'nutups' => 'second']);
check(error_of($r) === 'Command is not listed by the UPS.' && $with === [], 'commands: a command only another UPS lists is not run');
[$r, , $with] = call('nut_commands_response', ['nutcmdname' => 'load.on', 'nutups' => 'second']);
check($with === [NUT_CLIENT_TIMEOUT . "/usr/bin/upscmd -u 'upsadmin' -p 'Adm1nPass' -- 'second'@'192.0.2.5' 'load.on' 2>&1"], 'commands: a second UPS is validated against its own list and addressed by its host');

[$r, , $with] = call('nut_commands_response', []);
check(error_of($r) === 'Missing required POST variables!' && $with === [], 'commands: no command name');
[$r, , $with] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable', 'nutcmdarg' => ['x']]);
check(error_of($r) === 'Missing required POST variables!' && $with === [], 'commands: an array as argument');
foreach ($noCreds as $over) {
    [$r, $log, $with] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable'] + $browserCreds, $over);
    check(error_of($r) === 'No NUT Admin credentials are configured in the NUT Settings.' && $with === [], 'commands: without stored credentials nothing runs, whatever the browser sends (' . json_encode($over) . ')');
    check(strpos(implode("\n", $log), 'hunter2') === false, 'commands: browser-sent passwords never reach a command line');
}

[, , $with] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable', 'nutcmdwait' => 'on', 'nutcmdarg' => '5']);
check($with === [NUT_CLIENT_TIMEOUT_WAIT . "/usr/bin/upscmd -u 'upsadmin' -p 'Adm1nPass' -w -- 'ups'@'127.0.0.1' 'beeper.disable' '5' 2>&1"], 'commands: waiting for the driver gets its own, longer bound; the argument is passed');
[, , $with] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable', 'nutcmdarg' => '0']);
check($with === [str_replace("'beeper.disable'", "'beeper.disable' '0'", $expected)], 'commands: an argument of 0 is passed');
[, , $with] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable', 'nutcmdarg' => '$(id); `id` \'x\'']);
check($with === [str_replace("'beeper.disable'", "'beeper.disable' " . escapeshellarg('$(id); `id` \'x\''), $expected)] && strpos($with[0], "'\\''x'\\''") !== false, 'commands: shell metacharacters in the argument stay inside one quoted word');

[$r] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable'], [], ['ret' => 1, 'out' => ['Error: <b>bad</b> & worse']]);
check($r === ['error' => ['response' => 'Error: &lt;b&gt;bad&lt;/b&gt; &amp; worse']], 'commands: a failure is reported as an error, escaped');
[$r] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable'], [], ['ret' => 1, 'out' => ['Error: login with Adm1nPass failed', 'again Adm1nPass']]);
check($r === ['error' => ['response' => 'Error: login with *** failed' . PHP_EOL . 'again ***']], 'commands: the password is masked if the output echoes it');
[$r] = call('nut_commands_response', ['nutcmdname' => 'beeper.disable'], [], ['out' => ['line 1', 'line <2>']]);
check($r === ['success' => ['response' => 'line 1' . PHP_EOL . 'line &lt;2&gt;']], 'commands: success output joined and escaped');

// outlets: the table is readable without credentials, switching is not
foreach ($noCreds as $over) {
    [$r, $log, $with] = call('nut_commands_response', ['nutaction' => 'outlets'], $over);
    check($with === [] && in_array(NUT_CLIENT_TIMEOUT . "/usr/bin/upsc -- 'ups'@'127.0.0.1' 2>/dev/null", $log, true), 'outlets: listed with a bounded upsc call, no credentials used (' . json_encode($over) . ')');
    check(array_keys($r['success']['outlets'] ?? []) === [1] && $r['success']['outlets'][1]['desc'] === 'Server' && $r['success']['outlets'][1]['cmds'] === ['off' => 'outlet.1.load.off', 'on' => 'outlet.1.load.on'], 'outlets: variables and the commands the UPS lists');
}
[$r, , $with] = call('nut_commands_response', ['nutcmdname' => 'outlet.1.load.off']);
check($with === [NUT_CLIENT_TIMEOUT . "/usr/bin/upscmd -u 'upsadmin' -p 'Adm1nPass' -- 'ups'@'127.0.0.1' 'outlet.1.load.off' 2>&1"], 'outlets: switching goes through the same checked command path');

// writable variables
$setPost = ['action' => 'set', 'nutvar' => 'battery.charge.low', 'nutvalue' => '30'];
foreach ($noCreds as $over) {
    [$r, , $with] = call('nut_upsrw_response', ['action' => 'list'], $over);
    check(array_keys($r['success']['vars'] ?? []) === ['ups.delay.shutdown', 'ups.beeper.status', 'ups.id', 'battery.charge.low', 'input.transfer.low'] && $with === [], 'upsrw: listing works without credentials (' . json_encode($over) . ')');
    [$r, $log, $with] = call('nut_upsrw_response', $setPost + $browserCreds, $over);
    check(error_of($r) === 'No NUT Admin credentials are configured in the NUT Settings.' && $with === [], 'upsrw: without stored credentials nothing is set, whatever the browser sends (' . json_encode($over) . ')');
    check(strpos(implode("\n", $log), 'hunter2') === false, 'upsrw: browser-sent passwords never reach a command line');
}
[$r, $log] = call('nut_upsrw_response', ['action' => 'list']);
check($log === [NUT_CLIENT_TIMEOUT . "/usr/bin/upsrw -- 'ups'@'127.0.0.1' 2>&1", NUT_CLIENT_TIMEOUT . "/usr/bin/upsc -- 'ups'@'127.0.0.1' 2>/dev/null"], 'upsrw: listing is one bounded upsrw call and one bounded upsc call for the RANGE values');
$listed = $r['success']['vars'];
check($listed['battery.charge.low']['value'] === '20' && $listed['input.transfer.low']['value'] === '0', 'upsrw: a RANGE shows its current value from upsc, not its SELECTED range');
check($listed['ups.beeper.status']['value'] === 'enabled' && $listed['ups.delay.shutdown']['value'] === '20' && $listed['ups.id']['value'] === 'UPS1', 'upsrw: ENUM, NUMBER and STRING keep the value upsrw printed');
[$r] = call('nut_upsrw_response', ['action' => 'list'], [], ['rowsout' => ['battery.charge.low: 35', 'ups.delay.shutdown: 77', 'ups.id: other']]);
$listed = $r['success']['vars'];
check($listed['battery.charge.low']['value'] === '35' && $listed['input.transfer.low']['value'] === '', 'upsrw: the RANGE value is upsc\'s live one, a RANGE upsc does not report stays empty');
check($listed['ups.delay.shutdown']['value'] === '20' && $listed['ups.id']['value'] === 'UPS1', 'upsrw: only RANGE variables take their value from upsc');
[$r] = call('nut_upsrw_response', ['action' => 'list'], [], ['rowsret' => 1, 'rowsout' => ['battery.charge.low: 35']]);
check(!isset($r['error']) && $r['success']['vars']['battery.charge.low']['value'] === '' && $r['success']['vars']['ups.id']['value'] === 'UPS1', 'upsrw: if upsc fails the listing still works and a RANGE value stays empty, never its SELECTED range');
[$r, $log] = call('nut_upsrw_response', ['action' => 'list'], [], ['listout' => array_slice($upsrwLines, 0, array_search('[battery.charge.low]', $upsrwLines))]);
check(count($log) === 1 && array_keys($r['success']['vars']) === ['ups.delay.shutdown', 'ups.beeper.status', 'ups.id'], 'upsrw: without a RANGE variable upsc is not called');
[$r] = call('nut_upsrw_response', ['action' => 'list'], [], ['listret' => 1, 'listout' => ['Error: <x>']]);
check($r === ['error' => ['response' => 'Error: &lt;x&gt;']], 'upsrw: a failing listing is an escaped error');
foreach ([[], ['action' => 'delete'], ['action' => ['set']]] as $post) {
    [$r, $log] = call('nut_upsrw_response', $post);
    check(error_of($r) === 'Missing required POST variables!' && $log === [], 'upsrw: ' . json_encode($post) . ' is not an action');
}

$expected = NUT_CLIENT_TIMEOUT . "/usr/bin/upsrw -s 'battery.charge.low=30' -u 'upsadmin' -p 'Adm1nPass' -- 'ups'@'127.0.0.1' 2>&1";
[$r, $log, $with] = call('nut_upsrw_response', $setPost + $browserCreds);
check($with === [$expected] && $r === ['success' => ['response' => 'OK']], 'upsrw: a valid value is set once, bounded, with the stored credentials, not the browser\'s');
check(count($log) === 2 && strpos($log[0], '/usr/bin/upsrw -- ') !== false, 'upsrw: a set reads the variable definitions once and fetches no RANGE value');
[, , $with] = call('nut_upsrw_response', ['nutups' => 'second'] + $setPost);
check($with === [str_replace(["'ups'@'127.0.0.1'"], ["'second'@'192.0.2.5'"], $expected)], 'upsrw: a second UPS is addressed by its host');

foreach ([
    'ups.id; id' => 'Unknown or invalid variable name.', 'foo.bar' => 'Unknown or invalid variable name.', 'UPS.ID' => 'Unknown or invalid variable name.',
] as $name => $message) {
    [$r, , $with] = call('nut_upsrw_response', ['nutvar' => $name] + $setPost);
    check(error_of($r) === $message && $with === [], "upsrw: variable $name is not set");
}
[$r, , $with] = call('nut_upsrw_response', ['nutvar' => ['ups.id']] + $setPost);
check(error_of($r) === 'Unknown or invalid variable name.' && $with === [], 'upsrw: an array as variable name');
[$r, , $with] = call('nut_upsrw_response', ['nutvalue' => ['30']] + $setPost);
check(error_of($r) === 'Missing required POST variables!' && $with === [], 'upsrw: an array as value');
foreach ([
    ['battery.charge.low', '99', 'Value is outside the allowed range.'],
    ['battery.charge.low', 'abc', 'Value must be a number.'],
    ['ups.beeper.status', 'loud', 'Value is not one of the allowed options.'],
    ['ups.id', 'ABCDEFGHIJK', 'Value is longer than 10 characters.'],
    ['ups.id', "a\nb", 'Control characters are not allowed.'],
    ['battery.charge.low', '3e1', 'Value must be a number.'],
    ['battery.charge.low', ' 30', 'Value must be a number.'],
    ['ups.delay.shutdown', '30 ', 'Value must be a number.'],
    ['ups.delay.shutdown', '1e3', 'Value must be a number.'],
] as [$name, $value, $message]) {
    [$r, , $with] = call('nut_upsrw_response', ['nutvar' => $name, 'nutvalue' => $value] + $setPost);
    check(error_of($r) === $message && $with === [], "upsrw: $name=" . json_encode($value) . ' is refused by the variable\'s own definition');
}
[, , $with] = call('nut_upsrw_response', ['nutvar' => 'ups.id', 'nutvalue' => "a'b"] + $setPost);
check($with === [str_replace(["'battery.charge.low=30'"], [escapeshellarg("ups.id=a'b")], $expected)] && strpos($with[0], "'ups.id=a'\\''b'") !== false, 'upsrw: a quote in a string value stays inside one quoted word');
[$r] = call('nut_upsrw_response', $setPost, [], ['ret' => 1, 'out' => ['upsrw: bad login Adm1nPass <now>']]);
check($r === ['error' => ['response' => 'upsrw: bad login *** &lt;now&gt;']], 'upsrw: a failure is an escaped error with the password masked');

// a UPS name may start with "-": every client call ends its option parsing before the target
$dash = escapeshellarg('-dash') . '@' . escapeshellarg('198.51.100.9');
$dashList = NUT_CLIENT_TIMEOUT . "/usr/bin/upscmd -l -- $dash 2>/dev/null";
[$r, $log] = call('nut_commands_response', ['nutups' => '-dash', 'nutcmdname' => 'beeper.disable', 'nutcmdwait' => 'on', 'nutcmdarg' => '-5']);
check(!isset($r['error']) && $log === [$dashList, NUT_CLIENT_TIMEOUT_WAIT . "/usr/bin/upscmd -u 'upsadmin' -p 'Adm1nPass' -w -- $dash 'beeper.disable' '-5' 2>&1"], 'dash UPS: upscmd -l and upscmd -w end the options before the target, an argument may start with "-"');
[$r, $log] = call('nut_commands_response', ['nutups' => '-dash', 'nutaction' => 'outlets']);
check(!isset($r['error']) && $log === [$dashList, NUT_CLIENT_TIMEOUT . "/usr/bin/upsc -- $dash 2>/dev/null"], 'dash UPS: the outlet table\'s upsc ends the options before the target');
[$r, $log] = call('nut_upsrw_response', ['nutups' => '-dash', 'action' => 'list']);
check(!isset($r['error']) && $log === [NUT_CLIENT_TIMEOUT . "/usr/bin/upsrw -- $dash 2>&1", NUT_CLIENT_TIMEOUT . "/usr/bin/upsc -- $dash 2>/dev/null"], 'dash UPS: upsrw and the RANGE value upsc end the options before the target');
[$r, $log] = call('nut_upsrw_response', ['nutups' => '-dash'] + $setPost);
check(!isset($r['error']) && $log === [NUT_CLIENT_TIMEOUT . "/usr/bin/upsrw -- $dash 2>&1", NUT_CLIENT_TIMEOUT . "/usr/bin/upsrw -s 'battery.charge.low=30' -u 'upsadmin' -p 'Adm1nPass' -- $dash 2>&1"], 'dash UPS: upsrw -s ends the options before the target');

// the status read runs the real upsc code path against the stand-in client (the clients above run through the fake runner)
exec(escapeshellarg(NUT_UPSC) . ' ' . escapeshellarg('-dash@127.0.0.1') . ' 2>&1', $unused, $code);
check($code !== 0, 'the stand-in upsc takes a leading "-name" for an option, like the real client');
$upscLog = "$binDir/upsc.log";
@unlink($upscLog);
touch("$binDir/listall");
check(nut_status_rows_uncached('-dash', '127.0.0.1') === ['ups.status: OL', 'battery.charge: 88'], 'dash UPS: upsc lists the variables of a UPS named "-dash"');
check(file($upscLog, FILE_IGNORE_NEW_LINES) === ['-- -dash@127.0.0.1'], 'dash UPS: the listing is one upsc call with "--" before the target');
unlink("$binDir/listall");
@unlink($upscLog);
check(nut_status_rows_uncached('-dash', '127.0.0.1') === ['battery.charge: 88', 'ups.status: OL', 'x.plugin.varfetch: fallback'], 'dash UPS: the one-by-one fallback reads a UPS named "-dash"');
$calls = file($upscLog, FILE_IGNORE_NEW_LINES);
check(count($calls) > 2 && count(preg_grep('/^-- -dash@127\.0\.0\.1( [a-z.]+)?$/', $calls)) === count($calls), 'dash UPS: every fallback upsc call has "--" before the target');

clear_dir($cacheDir);
rmdir($cacheDir);
clear_dir($binDir);
rmdir($binDir);

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "David's commands, outlets, upsrw, endpoint rules, cache and overview checks passed.\n";
