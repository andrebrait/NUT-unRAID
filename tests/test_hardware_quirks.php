<?php
/* Hardware and driver quirks: detection, default-on ups.conf generation, opt-out,
 * custom override compatibility, backend/driver gating and settings UI wiring. */

require_once __DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3/include/nut_managed.php';

$failures = 0;
function check($cond, $msg) {
    global $failures;
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: $msg\n");
    }
}

$b = fn($s) => base64_encode($s);
$defaults = nut_mc_settings([]);
$bxUsb = [['vendorid' => '051d', 'productid' => '0002', 'vendor' => 'American Power Conversion', 'model' => 'Back-UPS BX750MI FW:295202G -302202G', 'serial' => '9B2312A01234']];
$smartUpsUsb = [['vendorid' => '051d', 'productid' => '0002', 'vendor' => 'American Power Conversion', 'model' => 'Smart-UPS 1500', 'serial' => 'AS1234']];
$cyberUsb = [['vendorid' => '0764', 'productid' => '0501', 'vendor' => 'CPS', 'model' => 'CP1500PFCLCD', 'serial' => 'CP999']];

// 1. USB sysfs reading (ignores interface dirs with ':', normalizes hex)
$sysfs = sys_get_temp_dir() . '/nut-quirks-sysfs-' . bin2hex(random_bytes(8));
mkdir("$sysfs/1-1", 0700, true);
mkdir("$sysfs/1-1:1.0", 0700, true);
file_put_contents("$sysfs/1-1/idVendor", "051D\n");
file_put_contents("$sysfs/1-1/idProduct", "0002\n");
file_put_contents("$sysfs/1-1/manufacturer", "American Power Conversion\n");
file_put_contents("$sysfs/1-1/product", "Back-UPS BX1600MI\n");
file_put_contents("$sysfs/1-1/serial", "SER123\n");
file_put_contents("$sysfs/1-1:1.0/idVendor", "051D\n");
$scanned = nut_quirk_usb_devices($sysfs);
exec('rm -rf ' . escapeshellarg($sysfs));
check(count($scanned) === 1 && $scanned[0]['vendorid'] === '051d' && $scanned[0]['model'] === 'Back-UPS BX1600MI',
    'sysfs scan finds USB device, normalizes vendorid, and skips interface subdirs');

// 2. Matching + default-on ups.conf generation
$matched = nut_quirks_matching($defaults, $bxUsb);
check(isset($matched['apc_bx_mi_lbrb']), 'APC Back-UPS BX750MI matches apc_bx_mi_lbrb on usbhid-ups/default');
$gen = nut_mc_generate($defaults, true, $bxUsb);
check(strpos($gen['ups.conf'], "\t# Hardware quirks\n\tlbrb_log_delay_sec = 4\n\tlbrb_log_delay_without_calibrating\n") !== false,
    'active quirk directives are emitted by default in ups.conf');
check(nut_mc_validate($defaults, $bxUsb) === [], 'config with active quirk validates cleanly');

// 3. Matching via CUSTOM_UPS product (Auto Config) even with no USB sysfs devices
$autoCfg = array_merge($defaults, ['CUSTOM_UPS' => $b("vendorid = \"051D\"\nproductid = \"0002\"\nproduct = \"Back-UPS BX950MI\"\n")]);
check(isset(nut_quirks_matching($autoCfg, [])['apc_bx_mi_lbrb']),
    'Auto Config CUSTOM_UPS product matches apc_bx_mi_lbrb even without sysfs');

// 4. Non-matching models and pinned-other-device isolation
check(nut_quirks_matching($defaults, $smartUpsUsb) === [], 'APC Smart-UPS 1500 does not match BX...MI quirk');
check(nut_quirks_matching($defaults, $cyberUsb) === [], 'CyberPower UPS does not match APC quirk');
$pinnedOther = array_merge($defaults, ['CUSTOM_UPS' => $b("vendorid = \"0764\"\n")]);
check(nut_quirks_matching($pinnedOther, array_merge($bxUsb, $cyberUsb)) === [],
    'pinning another vendorid in CUSTOM_UPS prevents an unrelated USB APC BX from matching');
$pinnedSer = array_merge($defaults, ['CUSTOM_UPS' => $b("serial = \"OTHER999\"\n")]);
check(nut_quirks_matching($pinnedSer, $bxUsb) === [],
    'pinning another serial in CUSTOM_UPS prevents a different USB APC BX from matching');
$bxNoSer = [['vendorid' => '051d', 'productid' => '0002', 'model' => 'Back-UPS BX750MI', 'serial' => '']];
check(nut_quirks_matching($pinnedSer, $bxNoSer) === [],
    'pinning a serial rejects a USB APC BX with an empty/unreadable serial');
$pinnedProd = array_merge($defaults, ['CUSTOM_UPS' => $b("vendorid = \"051D\"\nproduct = \"Back-UPS ES 700G\"\n")]);
check(nut_quirks_matching($pinnedProd, array_merge($bxUsb, $smartUpsUsb)) === [],
    'pinning another APC product on the same 051d:0002 ID prevents a second USB APC BX from matching');

// 5. Driver, backend, and slave mode gating
check(nut_quirks_matching(array_merge($defaults, ['DRIVER' => 'nutdrv_qx']), $bxUsb) === [], 'wrong driver does not match');
check(nut_quirks_matching(array_merge($defaults, ['BACKEND' => 'legacy']), $bxUsb) === [], 'legacy 2.7.4 backend skips NUT 2.8.x quirk');
check(isset(nut_quirks_matching(array_merge($defaults, ['BACKEND' => 'stable']), $bxUsb)['apc_bx_mi_lbrb']), 'stable 2.8.3 backend matches');
check(nut_quirks_matching(array_merge($defaults, ['MODE' => 'slave']), $bxUsb) === [], 'slave mode matches no driver quirks');

// 6. Individual opt-out via QUIRKS_DISABLED
$optedOut = array_merge($defaults, ['QUIRKS_DISABLED' => 'apc_bx_mi_lbrb']);
check(isset(nut_quirks_matching($optedOut, $bxUsb)['apc_bx_mi_lbrb']), 'opted-out quirk still appears in matching list for the UI');
check(nut_quirks_active($optedOut, $bxUsb) === [], 'opted-out quirk is excluded from active quirks');
$genOut = nut_mc_generate($optedOut, true, $bxUsb);
check(strpos($genOut['ups.conf'], 'lbrb_log_delay') === false && strpos($genOut['ups.conf'], '# Hardware quirks') === false,
    'opted-out quirk emits no directives in ups.conf');
check(nut_mc_validate($optedOut, $bxUsb) === [], 'valid QUIRKS_DISABLED passes validation');
check(nut_mc_validate(array_merge($defaults, ['QUIRKS_DISABLED' => '   ']), $bxUsb) === [],
    'whitespace-only QUIRKS_DISABLED passes validation');
check(nut_mc_validate(array_merge($defaults, ['QUIRKS_DISABLED' => 'bad;id']), $bxUsb) !== [],
    'malformed QUIRKS_DISABLED is rejected by validation');

// 7. Custom ups.conf override replaces matching quirk directive without conflict
$override = array_merge($defaults, ['CUSTOM_UPS' => $b("lbrb_log_delay_sec = 10\nlbrb_log_delay_without_calibrating\n")]);
check(nut_mc_validate($override, $bxUsb) === [], 'overriding quirk directives (including bare flag) in CUSTOM_UPS is allowed without conflict');
$genOv = nut_mc_generate($override, true, $bxUsb);
check(strpos($genOv['ups.conf'], 'lbrb_log_delay_sec = 4') === false
    && strpos($genOv['ups.conf'], 'lbrb_log_delay_sec = 10') !== false
    && substr_count($genOv['ups.conf'], 'lbrb_log_delay_without_calibrating') === 1,
    'custom directives replace quirk defaults without duplicating bare flags');

// 8. Settings UI includes per-workaround toggles and info modal triggers
$page = file_get_contents(__DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3/NUTsettings.page');
foreach (['id="NUT_QUIRKS"', 'id="QUIRKS_DISABLED"', 'nut-quirk-toggle', 'nut-quirk-info', 'showQuirkInfo', 'syncQuirksDisabled'] as $needle) {
    check(strpos($page, $needle) !== false, "NUTsettings.page contains $needle");
}

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "Hardware quirks tests passed.\n";
