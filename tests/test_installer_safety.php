<?php
/* Tests for the install steps of nut3.plg that replace installed files: migration preserves conflicting settings,
 * backend installation checks the installed artifacts, and credential preflight preserves monitoring on refusal.
 * The actual .plg shell blocks run in a sandbox. Run: php tests/test_installer_safety.php */

$failures = 0;

function check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}


$plgPath = __DIR__ . '/../plugin/nut3.plg';
$plgDoc = new DOMDocument();
$plgDoc->load($plgPath, LIBXML_NOENT); // expands the entities (&plgPATH; and the like) as the installer does
$inlines = [];
foreach ($plgDoc->getElementsByTagName('INLINE') as $inline) $inlines[] = $inline->textContent;
$step = function (string $marker) use ($inlines) {
    $found = array_values(array_filter($inlines, fn($s) => strpos($s, $marker) !== false));
    if (count($found) !== 1) throw new RuntimeException("expected one install step containing '$marker', found " . count($found));
    return $found[0];
};
preg_match('/<!ENTITY nutNAME\s+"([^"]+)">/', file_get_contents($plgPath), $m);
$nutPkg = $m[1] . '.txz';

$tmp = sys_get_temp_dir() . '/nut-installer-' . bin2hex(random_bytes(12));
if (!mkdir($tmp, 0700)) throw new RuntimeException('Cannot create test sandbox');
// every system path of the steps is redirected into the sandbox
$sandbox = fn(string $script) => str_replace(['/boot/config/plugins/', '/var/log/plugins/', '/usr/local/emhttp/plugins/'],
    ["$tmp/boot/plugins/", "$tmp/log/plugins/", "$tmp/emhttp/plugins/"], $script);
$oldDir = "$tmp/boot/plugins/nut-dw";
$newDir = "$tmp/boot/plugins/nut3";
$write = function (string $path, string $text) {
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, $text);
};
$clean = function () use ($tmp) {
    exec('rm -rf ' . escapeshellarg("$tmp/boot") . ' ' . escapeshellarg("$tmp/log") . ' ' . escapeshellarg("$tmp/emhttp"));
};

// ---- Migration from nut-dw
$migrate = "$tmp/migrate.sh";
file_put_contents($migrate, $sandbox($step('# Migrate from nut-dw')));
if (preg_match('#(?<![\w.-])/(boot|var|usr|etc|bin|sbin)/#', str_replace("$tmp/", 'sandbox/', file_get_contents($migrate)))) {
    throw new RuntimeException('the migration step still names a real system path, refusing to run it');
}
$runScript = function (string $script) {
    exec('bash ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

// a normal migration moves everything, the cached packages are downloaded again
$clean();
$write("$oldDir/nut-dw.cfg", "NAME=\"old\"\n");
$write("$oldDir/ups/ups.conf", "[old]\n");
$write("$oldDir/nut-dw-2026.10.15-x86_64-1.txz", '');
$write("$tmp/boot/plugins/nut-dw.plg", '<PLUGIN/>');
$write("$tmp/emhttp/plugins/nut-dw/page.php", 'page');
[$code, $out] = $runScript($migrate);
check($code === 0, "a normal migration succeeds: $out");
check(file_get_contents("$newDir/nut3.cfg") === "NAME=\"old\"\n", 'a normal migration moves the settings to nut3.cfg');
check(file_get_contents("$newDir/ups/ups.conf") === "[old]\n", 'a normal migration moves the ups/ files');
check(!file_exists("$newDir/nut-dw-2026.10.15-x86_64-1.txz") && !file_exists("$newDir/nut-dw.cfg"), 'a normal migration does not copy cached packages or nut-dw.cfg');
check(!file_exists($oldDir) && !file_exists("$tmp/boot/plugins/nut-dw.plg") && !file_exists("$tmp/emhttp/plugins/nut-dw"), 'a normal migration removes the nut-dw settings and plugin');

// both nut-dw and nut3 settings exist: neither is overwritten or deleted, the nut-dw plugin is still removed
$clean();
$write("$oldDir/nut-dw.cfg", "NAME=\"old\"\n");
$write("$oldDir/ups/ups.conf", "[old]\n");
$write("$newDir/nut3.cfg", "NAME=\"new\"\n");
$write("$tmp/boot/plugins/nut-dw.plg", '<PLUGIN/>');
[$code, $out] = $runScript($migrate);
check($code === 0, "a conflict does not fail the install: $out");
check(file_get_contents("$newDir/nut3.cfg") === "NAME=\"new\"\n", 'a conflict keeps the existing nut3.cfg');
check(file_get_contents("$oldDir/nut-dw.cfg") === "NAME=\"old\"\n" && file_get_contents("$oldDir/ups/ups.conf") === "[old]\n", 'a conflict keeps the nut-dw settings and ups/ files');
check(!file_exists("$newDir/ups/ups.conf"), 'a conflict does not copy the nut-dw ups/ files');
check(!file_exists("$tmp/boot/plugins/nut-dw.plg"), 'a conflict still removes the nut-dw plugin manifest, so it is not reinstalled');
[$code] = $runScript($migrate);
check($code === 0 && file_get_contents("$oldDir/nut-dw.cfg") === "NAME=\"old\"\n" && file_get_contents("$newDir/nut3.cfg") === "NAME=\"new\"\n", 'a second run keeps both configurations');

// ---- Backend switch
$binu = "$tmp/bin";
mkdir($binu, 0700);
$payload = "$tmp/payload";
foreach (['bin/upsc', 'sbin/upsd', 'sbin/upsmon', 'sbin/upsdrvctl'] as $file) {
    $write("$payload/usr/$file", "#!/bin/bash\n# selected backend\nexit 0\n");
    chmod("$payload/usr/$file", 0755);
}
file_put_contents("$binu/upgradepkg", <<<'SH'
#!/bin/bash
echo "$@" >> "$STUB/upgradepkg.calls"
if [ "$(cat "$STUB/upgradepkg.exit")" != 0 ]; then exit "$(cat "$STUB/upgradepkg.exit")"; fi
pkg=${2##*/}
pkg=${pkg%.txz}
artifacts=$(cat "$STUB/upgradepkg.artifacts")
if [ "$artifacts" != none ]; then
    mkdir -p "$STUB/log/packages" "$STUB/usr/bin" "$STUB/usr/sbin"
    printf 'PACKAGE NAME: %s\n' "$pkg" > "$STUB/log/packages/$pkg"
    if [ "$artifacts" != record-only ]; then
        for file in bin/upsc sbin/upsd sbin/upsmon sbin/upsdrvctl; do
            if [ "$artifacts" != stale ]; then
                cat "$STUB/payload/usr/$file" > "$STUB/usr/$file"
                chmod 755 "$STUB/usr/$file"
            fi
            printf 'usr/%s\n' "$file" >> "$STUB/log/packages/$pkg"
        done
        [ "$artifacts" != partial ] || rm "$STUB/usr/sbin/upsmon"
        [ "$artifacts" != truncated ] || : > "$STUB/usr/sbin/upsmon"
        [ "$artifacts" != files-only ] || rm "$STUB/log/packages/$pkg"
    fi
fi
exit 0
SH
);
chmod("$binu/upgradepkg", 0755);
$backend = "$tmp/backend.sh";
file_put_contents($backend, str_replace(['/var/log/packages/', '/usr/bin/', '/usr/sbin/', 'EXECUTABLE="/$FILE"'],
    ["$tmp/log/packages/", "$tmp/usr/bin/", "$tmp/usr/sbin/", 'EXECUTABLE="' . $tmp . '/$FILE"'], $sandbox($step('PREVIEWPKG='))));
if (preg_match('#(?<![\w.-])/(boot|var|usr|etc|bin|sbin)/#', str_replace("$tmp/", 'sandbox/', file_get_contents($backend)))) {
    throw new RuntimeException('the backend step still names a real system path, refusing to run it');
}
// Emulate installpkg outcomes behind upgradepkg, including its swallowed failure status and stale old artifacts.
$runBackend = function (?string $setting, int $exit, string $artifacts = 'complete', bool $upgrade = false) use ($tmp, $binu, $backend, $newDir, $write) {
    exec('rm -rf ' . escapeshellarg("$tmp/log/packages") . ' ' . escapeshellarg("$tmp/usr"));
    if ($upgrade) {
        $write("$tmp/log/packages/nut-0.0-x86_64-old", "old package\n");
        foreach (['bin/upsc', 'sbin/upsd', 'sbin/upsmon', 'sbin/upsdrvctl'] as $file) {
            $write("$tmp/usr/$file", "#!/bin/bash\nexit 0\n");
            chmod("$tmp/usr/$file", 0755);
        }
    }
    @mkdir($newDir, 0700, true);
    if ($setting === null) @unlink("$newDir/nut3.cfg");
    else file_put_contents("$newDir/nut3.cfg", "BACKEND=\"$setting\"\n");
    @unlink("$tmp/upgradepkg.calls");
    file_put_contents("$tmp/upgradepkg.exit", (string)$exit);
    file_put_contents("$tmp/upgradepkg.artifacts", $artifacts);
    $env = 'PATH=' . escapeshellarg("$binu:" . getenv('PATH')) . ' STUB=' . escapeshellarg($tmp);
    exec("$env bash " . escapeshellarg($backend) . ' 2>&1', $out, $code);
    $calls = is_file("$tmp/upgradepkg.calls") ? trim(file_get_contents("$tmp/upgradepkg.calls")) : '';
    return [$code, $calls, implode("\n", $out)];
};
$cases = [
    [null, $nutPkg],
    ['default', $nutPkg],
    ['preview', $nutPkg],
    ['previous', 'nut-2.8.4-x86_64-1stable.ssl31.txz'],
    ['stable', 'nut-2.8.3-x86_64-2stable.ssl31.txz'],
    ['legacy', 'nut-2.7.4.20200318-x86_64-1.txz'],
    ['bogus', $nutPkg],
];
foreach ($cases as [$setting, $pkg]) {
    $label = $setting ?? 'no settings file';
    exec('/usr/bin/tar -C ' . escapeshellarg($payload) . ' -cJf ' . escapeshellarg("$newDir/$pkg") . ' usr', $tarOutput, $tarCode);
    if ($tarCode !== 0) throw new RuntimeException('Cannot create backend fixture');
    foreach ([false, true] as $upgrade) {
        $operation = $upgrade ? 'upgrade' : 'new install';
        [$code, $calls, $out] = $runBackend($setting, 0, 'complete', $upgrade);
        check($code === 0 && $calls === "--install-new $newDir/$pkg", "$operation for $label installs $pkg and succeeds: $out");
        [$code, $calls, $out] = $runBackend($setting, 1, 'none', $upgrade);
        check($code !== 0, "a nonzero upgradepkg status fails $operation for $label: $out");
        foreach (['none', 'record-only', 'files-only', 'partial', 'stale', 'truncated'] as $artifacts) {
            [$code, $calls, $out] = $runBackend($setting, 0, $artifacts, $upgrade);
            check($code !== 0, "zero exit with $artifacts artifacts fails $operation for $label: $out");
        }
    }
}

// Execute the actual first installer block: unsafe upgrades must fail before the stop command.
foreach (['pgrep' => 'exit 1', 'killall' => 'exit 0', 'at' => 'cat >/dev/null; exit 0'] as $name => $body) {
    file_put_contents("$binu/$name", "#!/bin/bash\n$body\n");
    chmod("$binu/$name", 0700);
}
$preflight = "$tmp/preflight.sh";
$preflightText = str_replace(['/etc/rc.d/', '/var/run/nut'], ["$tmp/rc.d/", "$tmp/run/nut"],
    $sandbox($step('Checking for other installed NUT versions...')));
file_put_contents($preflight, 'export PATH=' . escapeshellarg("$binu:" . getenv('PATH')) . "\n" . $preflightText);
$write("$tmp/rc.d/rc.nut", "#!/bin/bash\ntouch '$tmp/stopped'\nexit 0\n");
chmod("$tmp/rc.d/rc.nut", 0700);
foreach (['unsafe' => ['netserver', 'monpass', 'slavepass', false],
          'safe' => ['netserver', 'UniqueMonitor1', 'UniqueSlave1', true],
          'missing-monitor' => ['netserver', null, 'UniqueSlave1', false],
          'missing-slave' => ['netserver', 'UniqueMonitor1', null, false],
          'missing-both' => ['netserver', null, null, false],
          'local' => ['standalone', 'monpass', 'slavepass', true]] as $case => [$mode, $monitorPass, $slavePass, $allowed]) {
    foreach ([true, false] as $defaultsPresent) {
        $clean();
        @unlink("$tmp/stopped");
        $write("$newDir/nut3.cfg", "MODE=\"$mode\"\n"
            . ($monitorPass === null ? '' : 'MONPASS="' . base64_encode($monitorPass) . "\"\n")
            . ($slavePass === null ? '' : 'SLAVEPASS="' . base64_encode($slavePass) . "\"\n"));
        if ($defaultsPresent) $write("$tmp/emhttp/plugins/nut3/default.cfg", file_get_contents(__DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3/default.cfg'));
        $write("$newDir/ups/upsmon.conf", "# Generated by the NUT plugin from its settings.\n");
        [$code, $out] = $runScript($preflight);
        check(($code === 0) === $allowed && is_file("$tmp/stopped") === $allowed,
            "$case upgrade (installed defaults " . ($defaultsPresent ? 'present' : 'absent') . ") preserves monitoring when credentials must be remediated: $out");
    }
}

exec('rm -rf ' . escapeshellarg($tmp));

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "Installer safety tests passed.\n";
