<?php
/* Exercise the actual archive script, with appliance paths redirected to a disposable root. */
$plugin = __DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3';
$root = sys_get_temp_dir() . '/nut-debug-archive-' . bin2hex(random_bytes(8));
$failures = 0;
function check($ok, $message) {
    global $failures;
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); $failures++; }
}
function put($path, $text) {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $text);
}
try {
    mkdir($root, 0700);
    foreach (['boot/config/plugins/nut3/ups', 'etc/nut', 'var/log/packages', 'sys/bus/usb/devices', 'bin'] as $dir) mkdir("$root/$dir", 0700, true);
    $dest = "$root/usr/local/emhttp/plugins/nut3";
    mkdir("$dest/scripts", 0700, true);
    mkdir("$dest/include", 0700, true);
    foreach (['include/nut_redact.php', 'include/nut_managed.php', 'scripts/debug_redact', 'default.cfg'] as $file) {
        if (is_file("$plugin/$file")) copy("$plugin/$file", "$dest/$file");
    }
    $secret = 'custom/archive^hidden';
    $monitor = "MONITOR other@10.0.0.2 0 observer \"$secret\" slave\n";
    put("$root/etc/nut/upsmon.conf", $monitor);
    put("$root/boot/config/plugins/nut3/ups/upsmon.conf", $monitor);
    put("$root/boot/config/plugins/nut3/nut3.cfg", 'MONUSER="monuser"' . "\nMONPASS=\"" . base64_encode('sample/pass') . "\"\nSLAVEUSER=\"slaveuser\"\nSLAVEPASS=\"" . base64_encode('slave-secret') . "\"\nCUSTOM_UPSMON=\"" . base64_encode($monitor) . "\"\n");
    put("$root/var/log/nut.log", "A log entry containing $secret\nUPS is online\n");
    put("$root/boot/config/go", "#!/bin/bash\n");
    put("$root/etc/unraid-version", "version=7.3.4\n");
    put("$root/boot/config/plugins/nut3.plg", '<PLUGIN/>');
    put("$root/bin/pgrep", "#!/bin/bash\nexit 1\n");
    put("$root/bin/lsusb", "#!/bin/bash\necho 'USB diagnostic fixture'\n");
    chmod("$root/bin/pgrep", 0700);
    chmod("$root/bin/lsusb", 0700);
    $script = file_get_contents("$plugin/scripts/debug");
    $script = str_replace(['/tmp/nut-debug', '/usr/local/', '/boot/', '/etc/', '/var/log/', '/sys/', '/usr/bin/upsc', '/usr/bin/nut-scanner'],
        ["$root/staging", "$root/usr/local/", "$root/boot/", "$root/etc/", "$root/var/log/", "$root/sys/", "$root/usr/bin/upsc", "$root/usr/bin/nut-scanner"], $script);
    put("$root/debug.sh", $script);
    exec('PATH=' . escapeshellarg("$root/bin:" . getenv('PATH')) . ' bash ' . escapeshellarg("$root/debug.sh") . ' 2>&1', $output, $code);
    $archives = glob("$dest/debug/*.zip");
    check($code === 0 && count($archives) === 1, 'the real debug script creates one archive: ' . implode(' ', $output));
    if ($archives) {
        exec('unzip -p ' . escapeshellarg($archives[0]) . ' 2>/dev/null', $contents, $readCode);
        $contents = implode("\n", $contents);
        check($readCode === 0 && strpos($contents, $secret) === false && strpos($contents, 'sample/pass') === false, 'the finished archive contains no custom or regex-sensitive password');
        check(strpos($contents, 'UPS is online') !== false && strpos($contents, 'USB diagnostic fixture') !== false, 'the archive retains useful log and USB diagnostics');
    }
    // A failed scrub must not leave a downloadable archive.
    put("$dest/scripts/debug_redact", "<?php exit(1);\n");
    exec('PATH=' . escapeshellarg("$root/bin:" . getenv('PATH')) . ' bash ' . escapeshellarg("$root/debug.sh") . ' 2>&1', $failedOutput, $failedCode);
    check($failedCode !== 0 && glob("$dest/debug/*.zip") === [], 'redaction failure leaves no downloadable archive');
} finally {
    exec('rm -rf ' . escapeshellarg($root));
}
exit($failures ? 1 : 0);
