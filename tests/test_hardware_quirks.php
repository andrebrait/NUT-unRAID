<?php
/* Hardware and driver quirks: detection, exact (default-on, opt-out) vs likely
 * (default-off, opt-in) matching, ups.conf generation, custom override
 * compatibility, backend/driver gating and settings UI wiring. */

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
$bxUsb = [['vendorid' => '051d', 'productid' => '0002', 'model' => 'Back-UPS BX750MI FW:295202G -302202G', 'serial' => '9B2312A01234']];
$otherBackUpsUsb = [['vendorid' => '051d', 'productid' => '0002', 'model' => 'Back-UPS ES 700G', 'serial' => 'ES1234']];
$smartUpsUsb = [['vendorid' => '051d', 'productid' => '0002', 'model' => 'Smart-UPS 1500', 'serial' => 'AS1234']];
$cyberUtUsb = [['vendorid' => '0764', 'productid' => '0501', 'model' => 'UT1500E', 'serial' => 'UT999']];
$cyberCpUsb = [['vendorid' => '0764', 'productid' => '0501', 'model' => 'CP1500PFCLCD', 'serial' => 'CP999']];
$eatonUsb = [['vendorid' => '0463', 'productid' => 'ffff', 'model' => 'Ellipse ECO', 'serial' => 'EA123']];
$trippUsb = [['vendorid' => '09ae', 'productid' => '2012', 'model' => 'Tripp Lite SMART1500LCDT', 'serial' => 'TL123']];
$unknownUsb = [['vendorid' => '1234', 'productid' => '5678', 'model' => 'Generic UPS', 'serial' => 'GEN1']];

// 1. USB sysfs reading (ignores interface dirs with ':', normalizes hex)
$sysfs = sys_get_temp_dir() . '/nut-quirks-sysfs-' . bin2hex(random_bytes(8));
mkdir("$sysfs/1-1", 0700, true);
mkdir("$sysfs/1-1:1.0", 0700, true);
file_put_contents("$sysfs/1-1/idVendor", "051D\n");
file_put_contents("$sysfs/1-1/idProduct", "0002\n");
file_put_contents("$sysfs/1-1/product", "Back-UPS BX1600MI\n");
file_put_contents("$sysfs/1-1/serial", "SER123\n");
file_put_contents("$sysfs/1-1:1.0/idVendor", "051D\n");
$scanned = nut_quirk_usb_devices($sysfs);
exec('rm -rf ' . escapeshellarg($sysfs));
check(count($scanned) === 1 && $scanned[0]['vendorid'] === '051d' && $scanned[0]['model'] === 'Back-UPS BX1600MI',
    'sysfs scan finds USB device, normalizes vendorid, and skips interface subdirs');

// 2. Exact match (default ON) + related likely matches (default OFF) on APC BX750MI
$matched = nut_quirks_matching($defaults, $bxUsb);
check(isset($matched['apc_bx_mi_lbrb']) && $matched['apc_bx_mi_lbrb']['default_on'] === true,
    'APC Back-UPS BX750MI matches apc_bx_mi_lbrb as default_on=true');
check(isset($matched['apc_onlinedischarge_calibration']) && $matched['apc_onlinedischarge_calibration']['default_on'] === false,
    'APC Back-UPS BX750MI shows apc_onlinedischarge_calibration as optional (default_on=false)');
$active = nut_quirks_active($defaults, $bxUsb);
check(array_keys($active) === ['apc_bx_mi_lbrb'],
    'only default_on quirks are active by default on APC BX750MI');
$gen = nut_mc_generate($defaults, true, $bxUsb);
check(strpos($gen['ups.conf'], "\t# Hardware quirks\n\tlbrb_log_delay_sec = 4\n\tlbrb_log_delay_without_calibrating\n") !== false,
    'active quirk directives are emitted by default in ups.conf');
check(strpos($gen['ups.conf'], 'onlinedischarge_calibration') === false,
    'optional likely quirks are not emitted unless opted in');
check(nut_mc_validate($defaults, $bxUsb) === [], 'config with active quirk validates cleanly');

// 3. Related model (APC Back-UPS ES 700G) shows apc_bx_mi_lbrb as optional (default_on=false), opt-in via QUIRKS_ENABLED
$mEs = nut_quirks_matching($defaults, $otherBackUpsUsb);
check(isset($mEs['apc_bx_mi_lbrb']) && $mEs['apc_bx_mi_lbrb']['default_on'] === false,
    'related APC Back-UPS ES 700G shows apc_bx_mi_lbrb as optional (default_on=false)');
check(nut_quirks_active($defaults, $otherBackUpsUsb) === [],
    'related APC Back-UPS ES 700G has no active quirks by default');
$optIn = array_merge($defaults, ['QUIRKS_ENABLED' => 'apc_bx_mi_lbrb']);
check(isset(nut_quirks_active($optIn, $otherBackUpsUsb)['apc_bx_mi_lbrb']),
    'opting in via QUIRKS_ENABLED activates a likely quirk');
check(strpos(nut_mc_generate($optIn, true, $otherBackUpsUsb)['ups.conf'], "\tlbrb_log_delay_sec = 4\n") !== false,
    'opted-in likely quirk emits its ups.conf directives');

// 4. Smart-UPS 1500 does not match Back-UPS quirk, only general APC optional quirks
$mSmart = nut_quirks_matching($defaults, $smartUpsUsb);
check(!isset($mSmart['apc_bx_mi_lbrb']) && isset($mSmart['apc_onlinedischarge_calibration']) && $mSmart['apc_onlinedischarge_calibration']['default_on'] === false,
    'APC Smart-UPS 1500 skips Back-UPS quirk and offers general APC optional quirks');

// 5. CyberPower UT (exact default ON) vs CP (likely default OFF), Eaton & Tripp Lite (optional pollonly)
$mUt = nut_quirks_matching($defaults, $cyberUtUsb);
check(isset($mUt['cyberpower_ut_onlinedischarge']) && $mUt['cyberpower_ut_onlinedischarge']['default_on'] === true,
    'CyberPower UT1500E enables cyberpower_ut_onlinedischarge by default');
check(array_keys(nut_quirks_active($defaults, $cyberUtUsb)) === ['cyberpower_ut_onlinedischarge'],
    'CyberPower UT1500E activates only its exact quirk by default');
$mCp = nut_quirks_matching($defaults, $cyberCpUsb);
check(isset($mCp['cyberpower_ut_onlinedischarge']) && $mCp['cyberpower_ut_onlinedischarge']['default_on'] === false
    && isset($mCp['usbhid_pollonly']) && $mCp['usbhid_pollonly']['default_on'] === false,
    'CyberPower CP1500PFCLCD offers UT and pollonly quirks as optional');
check(nut_quirks_active($defaults, $cyberCpUsb) === [], 'CyberPower CP1500PFCLCD has no active quirks by default');

$mEaton = nut_quirks_matching($defaults, $eatonUsb);
check(array_keys($mEaton) === ['usbhid_pollonly'] && $mEaton['usbhid_pollonly']['default_on'] === false,
    'Eaton Ellipse ECO offers usbhid_pollonly as optional (default_on=false)');
$mTripp = nut_quirks_matching($defaults, $trippUsb);
check(array_keys($mTripp) === ['usbhid_pollonly'] && $mTripp['usbhid_pollonly']['default_on'] === false,
    'Tripp Lite offers usbhid_pollonly as optional (default_on=false)');
check(nut_quirks_matching($defaults, $unknownUsb) === [], 'unknown vendor matches no quirks');

// 6. Matching via CUSTOM_UPS product (Auto Config) even with no USB sysfs devices
$autoCfg = array_merge($defaults, ['CUSTOM_UPS' => $b("vendorid = \"051D\"\nproductid = \"0002\"\nproduct = \"Back-UPS BX950MI\"\n")]);
check(isset(nut_quirks_matching($autoCfg, [])['apc_bx_mi_lbrb']) && nut_quirks_matching($autoCfg, [])['apc_bx_mi_lbrb']['default_on'] === true,
    'Auto Config CUSTOM_UPS product matches apc_bx_mi_lbrb as default_on=true without sysfs');

// 7. Multi-UPS pin isolation
$pinnedOther = array_merge($defaults, ['CUSTOM_UPS' => $b("vendorid = \"0764\"\n")]);
check(!isset(nut_quirks_matching($pinnedOther, array_merge($bxUsb, $cyberCpUsb))['apc_bx_mi_lbrb']),
    'pinning CyberPower vendorid in CUSTOM_UPS prevents an unrelated USB APC BX from matching');
$pinnedSer = array_merge($defaults, ['CUSTOM_UPS' => $b("serial = \"OTHER999\"\n")]);
check(nut_quirks_matching($pinnedSer, $bxUsb) === [],
    'pinning another serial in CUSTOM_UPS prevents a different USB APC BX from matching');
$bxNoSer = [['vendorid' => '051d', 'productid' => '0002', 'model' => 'Back-UPS BX750MI', 'serial' => '']];
check(nut_quirks_matching($pinnedSer, $bxNoSer) === [],
    'pinning a serial rejects a USB APC BX with an empty/unreadable serial');
$pinnedProd = array_merge($defaults, ['CUSTOM_UPS' => $b("vendorid = \"051D\"\nproduct = \"Back-UPS ES 700G\"\n")]);
$mPinProd = nut_quirks_matching($pinnedProd, array_merge($bxUsb, $otherBackUpsUsb));
check(isset($mPinProd['apc_bx_mi_lbrb']) && $mPinProd['apc_bx_mi_lbrb']['default_on'] === false,
    'pinning Back-UPS ES 700G on 051d:0002 prevents a second USB BX750MI from triggering default_on');

// 8. Driver, backend, and slave mode gating
check(nut_quirks_matching(array_merge($defaults, ['DRIVER' => 'nutdrv_qx']), $bxUsb) === [], 'wrong driver does not match');
check(nut_quirks_matching(array_merge($defaults, ['BACKEND' => 'legacy']), $bxUsb) === [], 'legacy 2.7.4 backend skips NUT 2.8.x APC quirks');
check(array_keys(nut_quirks_matching(array_merge($defaults, ['BACKEND' => 'legacy']), $eatonUsb)) === ['usbhid_pollonly'],
    'legacy 2.7.4 backend still allows 2.7.4-compatible pollonly quirk');
check(isset(nut_quirks_matching(array_merge($defaults, ['BACKEND' => 'stable']), $bxUsb)['apc_bx_mi_lbrb']), 'stable 2.8.3 backend matches');
check(nut_quirks_matching(array_merge($defaults, ['MODE' => 'slave']), $bxUsb) === [], 'slave mode matches no driver quirks');

// 9. Individual opt-out via QUIRKS_DISABLED & validation of QUIRKS_DISABLED / QUIRKS_ENABLED
$optedOut = array_merge($defaults, ['QUIRKS_DISABLED' => 'apc_bx_mi_lbrb']);
check(isset(nut_quirks_matching($optedOut, $bxUsb)['apc_bx_mi_lbrb']), 'opted-out quirk still appears in matching list for the UI');
check(nut_quirks_active($optedOut, $bxUsb) === [], 'opted-out quirk is excluded from active quirks');
$genOut = nut_mc_generate($optedOut, true, $bxUsb);
check(strpos($genOut['ups.conf'], 'lbrb_log_delay') === false && strpos($genOut['ups.conf'], '# Hardware quirks') === false,
    'opted-out quirk emits no directives in ups.conf');
check(nut_mc_validate($optedOut, $bxUsb) === [], 'valid QUIRKS_DISABLED passes validation');
check(nut_mc_validate(array_merge($defaults, ['QUIRKS_DISABLED' => '   ', 'QUIRKS_ENABLED' => '  ']), $bxUsb) === [],
    'whitespace-only QUIRKS_DISABLED/QUIRKS_ENABLED pass validation');
check(nut_mc_validate(array_merge($defaults, ['QUIRKS_DISABLED' => 'bad;id']), $bxUsb) !== [],
    'malformed QUIRKS_DISABLED is rejected by validation');
check(nut_mc_validate(array_merge($defaults, ['QUIRKS_ENABLED' => 'bad;id']), $bxUsb) !== [],
    'malformed QUIRKS_ENABLED is rejected by validation');

// 10. Custom ups.conf override replaces matching quirk directive without conflict
$override = array_merge($defaults, ['CUSTOM_UPS' => $b("lbrb_log_delay_sec = 10\nlbrb_log_delay_without_calibrating\n")]);
check(nut_mc_validate($override, $bxUsb) === [], 'overriding quirk directives (including bare flag) in CUSTOM_UPS is allowed without conflict');
$genOv = nut_mc_generate($override, true, $bxUsb);
check(strpos($genOv['ups.conf'], 'lbrb_log_delay_sec = 4') === false
    && strpos($genOv['ups.conf'], 'lbrb_log_delay_sec = 10') !== false
    && substr_count($genOv['ups.conf'], 'lbrb_log_delay_without_calibrating') === 1,
    'custom directives replace quirk defaults without duplicating bare flags');

// 11. Settings UI includes per-workaround toggles, optional tags, and info modal triggers
$page = file_get_contents(__DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3/NUTsettings.page');
foreach (['id="NUT_QUIRKS"', 'id="QUIRKS_DISABLED"', 'id="QUIRKS_ENABLED"', 'data-default-on=', 'nut-quirk-tag', 'nut-quirk-toggle', 'nut-quirk-info', 'showQuirkInfo', 'syncQuirks'] as $needle) {
    check(strpos($page, $needle) !== false, "NUTsettings.page contains $needle");
}

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "Hardware quirks tests passed.\n";
