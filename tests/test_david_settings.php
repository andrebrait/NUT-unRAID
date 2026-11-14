<?php
/* Tests for davidcoulson's settings page work as integrated into the managed-config page:
 * the slave connection test, hostname input handling, the new settings fields and the page's accessibility wiring.
 * Run: php -d short_open_tag=1 tests/test_david_settings.php */

$plugin = __DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3';
require_once "$plugin/include/nut_managed.php";

$failures = 0;

function check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}


// ---- connection test against a stand-in upsc --------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/nut-testconn-' . bin2hex(random_bytes(12));
if (!mkdir($tmp, 0700)) throw new RuntimeException('Cannot create test sandbox');
file_put_contents("$tmp/upsc", <<<'SH'
#!/bin/sh
# -l <host> lists the UPSes of a server, <ups>@<host> ups.status reads a variable
echo "$*" >> "$(dirname "$0")/calls"
# like upsc's getopt: options end at the first other argument or at "--", so "-ups@host" is only an operand after "--"
list=
while [ $# -gt 0 ]; do
    case "$1" in
        --) shift; break ;;
        -l) list=1; shift ;;
        -*) echo "upsc: invalid option $1" >&2; exit 1 ;;
        *) break ;;
    esac
done
if [ -n "$list" ]; then
    case "$1" in
        refused*) echo "Error: Connection failure: <b>refused</b>" >&2; exit 1 ;;
        silent*) exit 124 ;;
        dash*) printf -- '-dash\nups\n'; exit 0 ;;
    esac
    printf 'ups\nrack: Rack UPS\n'
    exit 0
fi
case "$1" in
    nostatus@*) echo "Error: Variable not supported by UPS" >&2; exit 1 ;;
esac
echo "OL CHRG"
SH);
chmod("$tmp/upsc", 0755);
define('NUT_TEST_UPSC', escapeshellarg("$tmp/upsc") . ' ');
require_once "$plugin/include/nut_testconn.php";

$res = nut_testconn('nas.local:3493', 'rack');
check($res['ok'] === true, 'connection test passes for a reachable server with a known UPS');
check(in_array(['ok', 'Status: OL CHRG'], $res['lines'], true), 'connection test shows the UPS status');
check(end($res['lines'])[0] === 'note', 'connection test says the monitor credentials are not checked');

$res = nut_testconn('nas.local', 'missing');
check($res['ok'] === false && in_array(['fail', 'UPS "missing" was not found on that server. Available: ups, rack.'], $res['lines'], true),
    'connection test lists the UPSes of the server when the name does not exist');

$res = nut_testconn('nas.local', 'nostatus');
check($res['ok'] === false, 'connection test fails when the UPS name is unknown even if the server answers');

$res = nut_testconn('refused.lan', 'ups');
check($res['ok'] === false && $res['lines'][0][0] === 'fail' && strpos($res['lines'][0][1], 'Connection failure') !== false,
    'connection test reports why an unreachable server failed');
$html = nut_testconn_html($res);
check(strpos($html, '<b>') === false && strpos($html, '&lt;b&gt;refused&lt;/b&gt;') !== false, 'connection test output is HTML-escaped');
check($html === '<ul><li class="nut-fail">Could not reach the NUT server at refused.lan: Error: Connection failure: &lt;b&gt;refused&lt;/b&gt;</li></ul>',
    'connection test output is a list of classed items');

$res = nut_testconn('silent.lan', 'ups');
check($res['lines'][0] === ['fail', 'No response from silent.lan within 5 seconds.'], 'connection test reports a timeout');

$canary = "$tmp/canary";
foreach (["x;touch $canary", '$(touch ' . $canary . ')', "a b", '-l'] as $host) {
    $res = nut_testconn($host, 'ups');
    check($res['ok'] === false && strpos($res['lines'][0][1], 'Invalid host') === 0, "connection test refuses host '$host'");
}
foreach (['a/b', 'a@b', 'a b', 'a;touch', '', str_repeat('a', 65)] as $name) {
    $res = nut_testconn('nas', $name);
    check($res['ok'] === false && strpos($res['lines'][0][1], 'Invalid UPS name') === 0, "connection test refuses UPS name '$name'");
}
check(!file_exists($canary), 'connection test never runs shell input');
check(nut_testconn_valid_host(['nas']) === false && nut_testconn_valid_name(['ups']) === false, 'connection test refuses non-string input');

// a leading "-" is a valid UPS name for the settings: it must reach upsc as an operand, never as an option
@unlink("$tmp/calls");
$res = nut_testconn('dash.lan', '-dash');
check($res['ok'] === true && in_array(['ok', 'Status: OL CHRG'], $res['lines'], true), 'connection test reaches a UPS whose name starts with a dash: ' . json_encode($res['lines']));
check(file_get_contents("$tmp/calls") === "-l -- dash.lan\n-- -dash@dash.lan ups.status\n", 'both upsc calls end their options before the host and the UPS');
// a fully qualified hostname keeps its trailing dot all the way to upsc
@unlink("$tmp/calls");
foreach (['nas.local.', 'nas.local.:3493'] as $host) {
    $res = nut_testconn($host, 'rack');
    check($res['ok'] === true, "connection test passes for the fully qualified host $host: " . json_encode($res['lines']));
}
check(file_get_contents("$tmp/calls") === "-l -- nas.local.\n-- rack@nas.local. ups.status\n-l -- nas.local.:3493\n-- rack@nas.local.:3493 ups.status\n",
    'the trailing dot and the port reach upsc as given');

// ---- POST only: the webGui checks the CSRF token of every POST, so a GET from another site must not probe a host ---
$calls = "$tmp/calls";
@unlink($calls);
foreach (['GET', 'HEAD', 'PUT', ''] as $method) {
    $r = nut_testconn_response($method, ['host' => 'nas.local', 'name' => 'rack']);
    check($r === ['ok' => false, 'html' => '<ul><li class="nut-fail">POST required.</li></ul>'], "connection test refuses a '$method' request");
}
check(!file_exists($calls), 'a refused request never runs upsc');
$r = nut_testconn_response('POST', ['host' => 'nas.local:3493', 'name' => 'rack']);
check($r['ok'] === true && strpos($r['html'], 'Status: OL CHRG') !== false && file_exists($calls), 'a POST runs the test with the posted host and name');
foreach ([[], ['host' => ['nas.local'], 'name' => ['rack']]] as $post) {
    $r = nut_testconn_response('POST', $post);
    check($r['ok'] === false && strpos($r['html'], 'Invalid host') !== false, 'a POST without usable host and name is refused as invalid: ' . json_encode($post));
}

/* The endpoint script itself, run as the web server does: [ok, html] of its JSON answer, null if it did not answer in JSON. */
function testconn_endpoint($method, array $get, array $post) {
    global $plugin, $tmp;
    $script = '$_SERVER["REQUEST_METHOD"] = $argv[1]; $_SERVER["SCRIPT_FILENAME"] = $argv[2]; $_GET = json_decode($argv[3], true); $_POST = json_decode($argv[4], true);'
        . ' define("NUT_TEST_UPSC", escapeshellarg($argv[5]) . " "); require $argv[2];';
    $proc = proc_open([PHP_BINARY, '-d', 'short_open_tag=1', '-r', $script, '--', $method, "$plugin/include/nut_testconn.php", json_encode($get), json_encode($post), "$tmp/upsc"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return json_decode($out, true);
}

@unlink($calls);
$r = testconn_endpoint('GET', ['host' => 'nas.local', 'name' => 'rack'], []);
check(($r['ok'] ?? null) === false && strpos($r['html'] ?? '', 'POST required.') !== false && !file_exists($calls), 'endpoint: a GET with host and name does not run the test');
$r = testconn_endpoint('POST', ['host' => 'nas.local', 'name' => 'rack'], []);
check(($r['ok'] ?? null) === false && !file_exists($calls), 'endpoint: a POST ignores host and name in the query string');
$r = testconn_endpoint('POST', ['host' => 'query.example', 'name' => 'query'], ['host' => 'nas.local', 'name' => 'rack']);
check(($r['ok'] ?? null) === true && strpos(file_get_contents($calls), 'nas.local') !== false && strpos(file_get_contents($calls), 'query.example') === false, 'endpoint: a POST tests the posted host and name');

// ---- hostname handling: the page, the connection test and the saved settings must agree -------------------------

$accepted = ['127.0.0.1', 'nas', 'nas.local', 'nas.local.', 'nas.local.:3493', 'ups-host.example.org', 'nas.local:3493', '10.0.0.5:3493', '[fe80::1]', '[::1]:3493'];
$rejected = ['', 'a b', 'host;rm', 'fe80::1', 'host:123456', 'host:', '[::1', 'a/b', 'a@b', '[::1]x'];
foreach (array_merge($accepted, $rejected) as $host) {
    $expected = in_array($host, $accepted, true);
    $saved = nut_mc_validate(array_merge(nut_mc_settings([]), ['MODE' => 'slave', 'IPADDR' => $host]));
    $savedOk = !array_filter($saved, fn($e) => strpos($e, 'NUT Master IP Address') === 0);
    $label = $host === '' ? '(empty)' : $host;
    check($savedOk === $expected, "settings " . ($expected ? 'accept' : 'reject') . " master address $label");
    check(nut_testconn_valid_host($host) === $expected, "connection test " . ($expected ? 'accepts' : 'rejects') . " $label");
}

// the UPS name: the settings and the connection test accept the same characters, a leading dash included
$names = ['ups' => true, '-ups' => true, 'a.b_c-d' => true, str_repeat('a', 64) => true, '' => false, 'a b' => false, 'a/b' => false, 'a@b' => false, str_repeat('a', 65) => false];
foreach ($names as $name => $expected) {
    $saved = nut_mc_validate(array_merge(nut_mc_settings([]), ['MODE' => 'slave', 'NAME' => (string)$name, 'IPADDR' => 'nas']));
    check(!array_filter($saved, fn($e) => strpos($e, 'UPS Name') === 0) === $expected, "settings " . ($expected ? 'accept' : 'reject') . " UPS name '$name'");
    check(nut_testconn_valid_name((string)$name) === $expected, "connection test " . ($expected ? 'accepts' : 'rejects') . " UPS name '$name'");
}

foreach (glob("$tmp/*") as $file) unlink($file);
rmdir($tmp);

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "David settings integration passed.\n";
