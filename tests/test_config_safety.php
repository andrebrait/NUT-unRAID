<?php
/* Configuration safety regressions. Run: php tests/test_config_safety.php */
require_once __DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3/include/nut_managed.php';
$failures = 0;
function check($ok, $message) {
    global $failures;
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); $failures++; }
}
$dir = sys_get_temp_dir() . '/nut-config-safety-' . bin2hex(random_bytes(6));
mkdir("$dir/ups", 0700, true);
try {
    $defaults = nut_mc_settings([]);
    $network = array_merge($defaults, ['MODE' => 'netserver']);
    check(nut_mc_validate($network) !== [], 'netserver rejects published default control credentials');
    $network['MONPASS'] = base64_encode('UniqueMonitor123');
    check(nut_mc_validate($network) !== [], 'netserver also rejects published default slave credentials');
    $network['SLAVEPASS'] = base64_encode('UniqueSlave123');
    check(nut_mc_validate($network) === [], 'netserver accepts non-default credentials');

    nut_mc_write_cfg("$dir/nut3.cfg", $defaults);
    file_put_contents("$dir/import-report.json", json_encode(['notes' => []]));
    $saved = array_merge($defaults, ['NAME' => 'reviewed']);
    check(nut_mc_accept($dir, $saved), 'validated settings can prepare import acceptance');
    check(nut_mc_pending($dir) !== null, 'failed settings persistence leaves import pending');
    nut_mc_write_cfg("$dir/nut3.cfg", $saved);
    check(nut_mc_pending($dir) === null, 'persisting exactly the reviewed settings accepts import');
    nut_mc_write_cfg("$dir/nut3.cfg", $defaults);
    check(nut_mc_pending($dir) !== null, 'a different persisted configuration cannot consume acceptance');
    mkdir("$dir/import-report.json.tmp", 0700);
    check(!@nut_mc_accept($dir, $saved), 'acceptance write failure is reported');
    check(nut_mc_pending($dir) !== null, 'acceptance write failure keeps the import pending');
    rmdir("$dir/import-report.json.tmp");
    unlink("$dir/import-report.json");

    file_put_contents("$dir/ups/ups.conf", "[legacy]\ndriver = usbhid-ups\nport = auto\n");
    file_put_contents("$dir/backup", 'blocked backup directory');
    $before = file_get_contents("$dir/nut3.cfg");
    check(!@nut_mc_import_dir($dir), 'backup failure aborts import');
    check(file_get_contents("$dir/nut3.cfg") === $before && !is_file("$dir/import-report.json"), 'backup failure preserves settings and does not report a completed import');
    unlink("$dir/backup");
    mkdir("$dir/backup/legacy", 0700, true);
    file_put_contents("$dir/backup/legacy/ups.conf", '[truncated');
    check(!nut_mc_import_dir($dir), 'a conflicting backup aborts import instead of overwriting recovery data');
    check(file_get_contents("$dir/backup/legacy/ups.conf") === '[truncated' && file_get_contents("$dir/nut3.cfg") === $before, 'backup conflict preserves both versions for recovery');

    $retry = "$dir/retry";
    mkdir("$retry/ups", 0700, true);
    $original = "MANUAL=\"enable\"\nNAME=\"ups\"\n";
    file_put_contents("$retry/nut3.cfg", $original);
    file_put_contents("$retry/ups/ups.conf", "[rack]\ndriver = usbhid-ups\nport = auto\n");
    file_put_contents("$retry/ups/upsmon.conf", "MONITOR rack@127.0.0.1 1 observer unique-password master\n");
    mkdir("$retry/import-report.json.tmp", 0700);
    check(!@nut_mc_deploy($retry, $messages), 'a failed import report write is reported');
    rmdir("$retry/import-report.json.tmp");
    check(nut_mc_deploy($retry, $messages) && nut_mc_pending($retry) !== null, 'import recovers after a transient report write failure');
    check(nut_mc_read_cfg("$retry/nut3.cfg")['NAME'] === 'rack' && file_get_contents("$retry/backup/legacy/nut3.cfg") === $original, 'retried import retains original manual-mode semantics and recovery settings');

    unlink("$dir/nut3.cfg");
    check(nut_mc_initialize($dir), 'fresh installation creates settings');
    $first = nut_mc_read_cfg("$dir/nut3.cfg");
    $other = "$dir/other";
    mkdir($other, 0700);
    check(nut_mc_initialize($other), 'another installation creates settings');
    $second = nut_mc_read_cfg("$other/nut3.cfg");
    foreach (['MONPASS', 'SLAVEPASS'] as $key) {
        check(strlen(nut_mc_decode($first[$key])) >= 32 && $first[$key] !== $second[$key], "$key is unique per installation");
    }
    check(nut_mc_initialize($dir) && nut_mc_read_cfg("$dir/nut3.cfg") === $first, 'upgrades preserve existing credentials');
    check(nut_mc_validate(array_merge($first, ['MODE' => 'netserver'])) === [], 'fresh credentials permit netserver operation');
} finally {
    exec('rm -rf ' . escapeshellarg($dir));
}
exit($failures ? 1 : 0);
