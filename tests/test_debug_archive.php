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
    foreach (['boot/config/plugins/nut3/ups', 'etc/nut', 'var/log/packages', 'var/run', 'sys/bus/usb/devices', 'bin'] as $dir) mkdir("$root/$dir", 0700, true);
    $dest = "$root/usr/local/emhttp/plugins/nut3";
    mkdir("$dest/scripts", 0700, true);
    mkdir("$dest/include", 0700, true);
    foreach (['include/nut_redact.php', 'include/nut_managed.php', 'include/nut_quirks.php', 'scripts/debug_redact', 'default.cfg'] as $file) {
        if (is_file("$plugin/$file")) copy("$plugin/$file", "$dest/$file");
    }
    $secret = 'custom/archive^hidden';
    $monitor = "MONITOR other@10.0.0.2 0 observer \"$secret\" slave\n";
    $continued = 'r8V3n6J2';
    $certificate = 'v7Q9k2mL';
    $quoted = 's4C8d2G9';
    $splitDirective = 'x7B5k2H8';
    $quotedName = 'j5L8f3A6';
    $escapedName = 'e6P2w9R4';
    $quotedCert = 'c3M8t5F1';
    $escapedCert = 'h7W4a2D9';
    $extraCert = 'b9Z6v2K4';
    $monitor .= "MONITOR continued@10.0.0.3 0 observer \\\n$continued slave\n";
    $monitor .= "MONITOR quoted@10.0.0.4 0 observer \"s4C8\nd2G9\" slave\n";
    $monitor .= "MON\\\nITOR split@10.0.0.5 0 observer $splitDirective slave\n";
    // Quoted/escaped directive credentials below must not be learned from managed settings.
    $managedMonitor = $monitor;
    $monitor .= "\"MONITOR\" quoted-name@10.0.0.6 0 observer $quotedName slave\n";
    $monitor .= "MONI\\TOR escaped-name@10.0.0.7 0 observer $escapedName slave\n";
    put("$root/etc/nut/upsd.conf", "CERTIDENT tower $certificate\n\"CERTIDENT\" quoted \"$quotedCert\"\nCERTI\\DENT escaped \"$escapedCert\"\nCERTIDENT extra \"b9Z6\\v2K4\" ignored-argument\n");
    put("$root/etc/nut/upsmon.conf", $monitor);
    put("$root/boot/config/plugins/nut3/ups/upsmon.conf", $monitor);
    put("$root/boot/config/plugins/nut3/nut3.cfg", 'MONUSER="monuser"' . "\nMONPASS=\"" . base64_encode('sample/pass') . "\"\nSLAVEUSER=\"slaveuser\"\nSLAVEPASS=\"" . base64_encode('slave-secret') . "\"\nCUSTOM_UPSMON=\"" . base64_encode($managedMonitor) . "\"\n");
    put("$root/var/log/nut.log", "A log entry containing $secret\nContinuation rejected: $continued\nTLS identity rejected: $certificate\nQuoted identity rejected: $quoted\nSplit identity rejected: $splitDirective\nPeer rejected: $quotedName\nPeer rejected: $escapedName\nPeer rejected: $quotedCert\nPeer rejected: $escapedCert\nPeer rejected: $extraCert\nUPS is online\n");
    put("$root/boot/config/go", "#!/bin/bash\n");
    put("$root/etc/unraid-version", "version=7.3.4\n");
    put("$root/boot/config/plugins/nut3.plg", '<PLUGIN/>');
    put("$root/bin/pgrep", "#!/bin/bash\nexit 1\n");
    put("$root/bin/lsusb", "#!/bin/bash\necho 'USB diagnostic fixture'\n");
    chmod("$root/bin/pgrep", 0700);
    chmod("$root/bin/lsusb", 0700);
    // A failing caller-side text tool must not bypass the helper's checked settings scrub.
    put("$root/bin/sed", "#!/bin/bash\nexit 1\n");
    chmod("$root/bin/sed", 0700);
    $script = file_get_contents("$plugin/scripts/debug");
    $script = str_replace(['/tmp/nut-debug', '/usr/local/', '/boot/', '/etc/', '/var/log/', '/var/run/', '/sys/', '/usr/bin/upsc', '/usr/bin/nut-scanner'],
        ["$root/staging", "$root/usr/local/", "$root/boot/", "$root/etc/", "$root/var/log/", "$root/var/run/", "$root/sys/", "$root/usr/bin/upsc", "$root/usr/bin/nut-scanner"], $script);
    put("$root/debug.sh", $script);
    exec('PATH=' . escapeshellarg("$root/bin:" . getenv('PATH')) . ' bash ' . escapeshellarg("$root/debug.sh") . ' 2>&1', $output, $code);
    $archives = glob("$dest/debug/*.zip");
    check($code === 0 && count($archives) === 1, 'the real debug script creates one archive: ' . implode(' ', $output));
    if ($archives) {
        exec('unzip -p ' . escapeshellarg($archives[0]) . ' 2>/dev/null', $contents, $readCode);
        $contents = implode("\n", $contents);
        check($readCode === 0 && strpos($contents, $secret) === false && strpos($contents, 'sample/pass') === false, 'the finished archive contains no custom or regex-sensitive password');
        foreach ([$continued, $certificate, $quoted, $splitDirective, $quotedName, $escapedName, $quotedCert, $escapedCert, $extraCert] as $privateValue) {
            check(strpos($contents, $privateValue) === false, 'complete logical directives and CERTIDENT cannot leak a credential into configuration or logs');
        }
        check(strpos($contents, base64_encode('sample/pass')) === false && strpos($contents, base64_encode($managedMonitor)) === false,
            'the settings copy cannot leak encoded credentials when a caller-side scrub fails');
        check(strpos($contents, 'UPS is online') !== false && strpos($contents, 'USB diagnostic fixture') !== false, 'the archive retains useful log and USB diagnostics');
    }
    // Hold the first real exporter after redaction, immediately before the actual zip.
    $realZip = trim(shell_exec('command -v zip'));
    put("$root/bin/zip", "#!/bin/bash\nif mkdir " . escapeshellarg("$root/zip-first") . " 2>/dev/null; then\n touch " . escapeshellarg("$root/zip-held") . "\n while [ ! -e " . escapeshellarg("$root/zip-release") . " ]; do sleep 0.01; done\nfi\nexec " . escapeshellarg($realZip) . " \"\$@\"\n");
    chmod("$root/bin/zip", 0700);
    $first = proc_open(['bash', "$root/debug.sh"], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', "$root/first-output", 'w'],
        2 => ['file', "$root/first-output", 'a'],
    ], $pipes, null, ['PATH' => "$root/bin:" . getenv('PATH')]);
    try {
        $deadline = microtime(true) + 10;
        while (!is_file("$root/zip-held") && microtime(true) < $deadline) usleep(10000);
        check(is_file("$root/zip-held"), 'first export reaches zip and is held after redaction');
        if (is_file("$root/zip-held")) {
            $staged = file_get_contents("$root/staging/RAM/upsmon.conf.txt");
            put("$root/staging/lock-held", "do not reset\n");
            exec('PATH=' . escapeshellarg("$root/bin:" . getenv('PATH')) . ' timeout 5 bash ' . escapeshellarg("$root/debug.sh") . ' 2>&1', $overlapOutput, $overlapCode);
            check($overlapCode !== 0 && $overlapCode !== 124, 'an overlapping export fails immediately instead of entering staging');
            check(is_file("$root/staging/lock-held") && file_get_contents("$root/staging/RAM/upsmon.conf.txt") === $staged,
                'the rejected exporter cannot clean or repopulate the redacted staging directory');
            check(glob("$dest/debug/*.zip") === [], 'no archive is published while the first exporter is held');
        }
    } finally {
        put("$root/zip-release", "\n");
        $firstCode = proc_close($first);
    }
    $archives = glob("$dest/debug/*.zip");
    check($firstCode === 0 && count($archives) === 1, 'the lock owner finishes its archive after release');
    if ($archives) {
        exec('unzip -p ' . escapeshellarg($archives[0]) . ' 2>/dev/null', $lockedContents, $lockedReadCode);
        $lockedContents = implode("\n", $lockedContents);
        check($lockedReadCode === 0, 'the serialized export is a readable zip');
        foreach ([$secret, $continued, $certificate, $quoted, $splitDirective, $quotedName, $escapedName, $quotedCert, $escapedCert, $extraCert] as $privateValue) {
            check(strpos($lockedContents, $privateValue) === false, 'the serialized finished archive contains no credential');
        }
    }
    $settingsPath = "$root/boot/config/plugins/nut3/nut3.cfg";
    $savedSettings = file_get_contents($settingsPath);
    put($settingsPath, '[unterminated');
    exec('PATH=' . escapeshellarg("$root/bin:" . getenv('PATH')) . ' bash ' . escapeshellarg("$root/debug.sh") . ' 2>&1', $invalidOutput, $invalidCode);
    check($invalidCode !== 0 && glob("$dest/debug/*.zip") === [], 'unparseable settings prevent archive creation');
    put($settingsPath, $savedSettings);
    // A failed scrub must not leave a downloadable archive.
    put("$dest/scripts/debug_redact", "<?php exit(1);\n");
    exec('PATH=' . escapeshellarg("$root/bin:" . getenv('PATH')) . ' bash ' . escapeshellarg("$root/debug.sh") . ' 2>&1', $failedOutput, $failedCode);
    check($failedCode !== 0 && glob("$dest/debug/*.zip") === [], 'redaction failure leaves no downloadable archive');
} finally {
    exec('rm -rf ' . escapeshellarg($root));
}
exit($failures ? 1 : 0);
