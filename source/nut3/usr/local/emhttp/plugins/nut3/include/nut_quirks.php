<?php
/* Copyright Andre Brait (as author of this file)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * Hardware and driver quirk registry: matches known UPS models against the
 * configured driver, backend and detected USB/configured hardware. Exact
 * model matches ('match') are enabled by default (opt-out via QUIRKS_DISABLED);
 * related/likely matches ('likely_match') are disabled by default (opt-in via
 * QUIRKS_ENABLED).
 */

const NUT_QUIRK_BACKENDS_28 = ['default', 'preview', 'previous', 'stable'];
// USB UPS vendor IDs from NUT's 62-nut-usbups.rules (usbhid-ups + common USB UPSes) for multi-UPS ambiguity checks
const NUT_QUIRK_UPS_VENDORS = [
    '03f0', '0463', '047c', '0483', '04d8', '050d', '051d', '0592',
    '05dd', '0665', '06da', '075d', '0764', '0925', '09ae', '0d9f',
    '10af', '1cb0', '2341', '2a03', '2e51', '2e66', '3746', '4234',
];

const NUT_QUIRKS = [
    'apc_bx_mi_lbrb' => [
        'title'        => 'APC Back-UPS BX...MI: filter spurious LB/RB blips',
        'details'      => "APC Back-UPS BX-series units (BX750MI, BX950MI, BX1200MI, BX1600MI, BX2200MI) have a known firmware quirk (upstream NUT #2347, #3623) where they briefly flash a false Low Battery (LB) flag over USB HID while online at full charge. Some related APC Back-UPS models (such as BVK/BK/BV series) can show similar blips.\n\nThis workaround adds lbrb_log_delay_sec = 4 and lbrb_log_delay_without_calibrating to ups.conf so usbhid-ups ignores LB (and RB while LB is active) unless it persists for at least 4 seconds.\n\nNotes:\n- A standalone OL RB (Replace Battery without LB) is still reported immediately.\n- With lbrb_log_delay_without_calibrating, a genuine low-battery transition is also delayed by 4 seconds.\n- You can override lbrb_log_delay_sec in Custom ups.conf (UPS section) Directives, or toggle this workaround here.",
        'drivers'      => ['usbhid-ups'],
        'backends'     => NUT_QUIRK_BACKENDS_28,
        'match'        => [
            'vendorid' => ['051d'],
            'model_re' => '/\bBX\d+MI\b/i',
        ],
        'likely_match' => [
            'vendorid' => ['051d'],
            'model_re' => '/\bBack-UPS\b/i',
        ],
        'ups_conf'     => [
            'lbrb_log_delay_sec'                 => '4',
            'lbrb_log_delay_without_calibrating' => null,
        ],
    ],
    'apc_onlinedischarge_calibration' => [
        'title'        => 'APC: treat OL+DISCHRG as calibration',
        'details'      => "Some APC Back-UPS and Smart-UPS units report OL+DISCHRG (online while discharging) during routine self-tests or battery calibration, which can flood syslog with warnings.\n\nThis workaround adds onlinedischarge_calibration to ups.conf so usbhid-ups recognizes OL+DISCHRG as calibration instead of logging repeated warnings.\n\nOnly enable this if your UPS emits OL+DISCHRG during normal line-power self-tests (not during real power outages).",
        'drivers'      => ['usbhid-ups'],
        'backends'     => NUT_QUIRK_BACKENDS_28,
        'match'        => null,
        'likely_match' => [
            'vendorid' => ['051d'],
        ],
        'ups_conf'     => [
            'onlinedischarge_calibration' => null,
        ],
    ],
    'cyberpower_ut_onlinedischarge' => [
        'title'        => 'CyberPower UT series: treat OL+DISCHRG as on-battery',
        'details'      => "CyberPower UT-series units (and some related CyberPower USB HID models) report OL+DISCHRG instead of OB when mains power is lost. Without this option, NUT may not treat a real outage as on-battery.\n\nThis workaround adds onlinedischarge_onbattery to ups.conf so usbhid-ups treats OL+DISCHRG as an on-battery state.",
        'drivers'      => ['usbhid-ups'],
        'backends'     => NUT_QUIRK_BACKENDS_28,
        'match'        => [
            'vendorid' => ['0764'],
            'model_re' => '/\bUT\d+/i',
        ],
        'likely_match' => [
            'vendorid' => ['0764'],
        ],
        'ups_conf'     => [
            'onlinedischarge_onbattery' => null,
        ],
    ],
    'usbhid_pollonly' => [
        'title'        => 'USB HID: polling-only mode (pollonly)',
        'details'      => "Some CyberPower, Tripp Lite, and Eaton / MGE USB HID units suffer from periodic USB disconnects, EAGAIN errors, or stale-data loops on the USB interrupt endpoint under usbhid-ups.\n\nThis workaround adds pollonly to ups.conf so usbhid-ups skips USB interrupt transfers and polls on the regular interval instead. Leave this off unless you see recurring USB interrupt or stale-data errors in syslog.",
        'drivers'      => ['usbhid-ups'],
        'backends'     => null,
        'match'        => null,
        'likely_match' => [
            'vendorid' => ['0764', '09ae', '0463'],
        ],
        'ups_conf'     => [
            'pollonly' => null,
        ],
    ],
    'usbhid_disable_fix_report_desc' => [
        'title'        => 'Disable USB HID report descriptor fix-ups',
        'details'      => "NUT applies built-in report descriptor fix-ups for certain APC, CyberPower, Belkin, and Liebert USB HID models with known firmware bugs. If a newer hardware revision or firmware update fixed the bug, NUT's fix-up can cause incorrect voltage or battery readings.\n\nThis workaround adds disable_fix_report_desc to ups.conf so usbhid-ups uses the raw USB HID report descriptor as-is. Leave this off unless your readings look clearly scaled wrong.",
        'drivers'      => ['usbhid-ups'],
        'backends'     => NUT_QUIRK_BACKENDS_28,
        'match'        => null,
        'likely_match' => [
            'vendorid' => ['051d', '0764', '050d', '10af'],
        ],
        'ups_conf'     => [
            'disable_fix_report_desc' => null,
        ],
    ],
];

function nut_quirk_norm_hex($value) {
    $v = strtolower(trim((string)$value));
    if (strncmp($v, '0x', 2) === 0) $v = substr($v, 2);
    return $v;
}

/* Read USB devices from Linux sysfs. NUT_QUIRK_USB_SYSFS overrides the path for tests (empty string = no devices). */
function nut_quirk_usb_devices($sysfs = null) {
    if ($sysfs === null) {
        $env = getenv('NUT_QUIRK_USB_SYSFS');
        $sysfs = $env !== false ? $env : '/sys/bus/usb/devices';
    }
    if ($sysfs === '' || !is_dir($sysfs)) return [];
    $devices = [];
    foreach (glob(rtrim($sysfs, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (strpos(basename($dir), ':') !== false) continue;
        $vid = @file_get_contents("$dir/idVendor");
        if ($vid === false) continue;
        $busRaw = trim((string)@file_get_contents("$dir/busnum"));
        $devices[] = [
            'vendorid'  => nut_quirk_norm_hex($vid),
            'productid' => nut_quirk_norm_hex((string)@file_get_contents("$dir/idProduct")),
            'model'     => trim((string)@file_get_contents("$dir/product")),
            'serial'    => trim((string)@file_get_contents("$dir/serial")),
            'bus'       => ctype_digit($busRaw) ? sprintf('%03d', (int)$busRaw) : '',
        ];
    }
    return $devices;
}

/* Candidate hardware descriptors for the configured primary UPS, combining CUSTOM_UPS pins and USB sysfs. */
function nut_quirk_detect_devices(array $c, ?array $usbDevices = null) {
    $pinned = [];
    foreach (nut_mc_parse(nut_mc_custom($c, 'CUSTOM_UPS')) as $d) {
        if ($d['section'] !== null || $d['header'] || $d['error'] || count($d['words']) !== 3 || $d['words'][1] !== '=') continue;
        $k = strtolower($d['words'][0]);
        if (in_array($k, ['vendorid', 'productid', 'product', 'serial', 'bus'], true)) {
            $pinned[$k] = $d['words'][2];
        }
    }
    $pinVid = isset($pinned['vendorid']) ? nut_quirk_norm_hex($pinned['vendorid']) : '';
    $pinPid = isset($pinned['productid']) ? nut_quirk_norm_hex($pinned['productid']) : '';
    $pinProd = $pinned['product'] ?? '';
    $pinSer = $pinned['serial'] ?? '';
    $pinBusRaw = trim((string)($pinned['bus'] ?? ''));
    $pinBus = ctype_digit($pinBusRaw) ? sprintf('%03d', (int)$pinBusRaw) : $pinBusRaw;

    $candidates = [];
    if ($pinProd !== '') {
        $candidates[] = [
            'vendorid'  => $pinVid,
            'productid' => $pinPid,
            'model'     => $pinProd,
            'serial'    => $pinSer,
            'bus'       => $pinBus,
        ];
    }
    foreach ($usbDevices ?? nut_quirk_usb_devices() as $dev) {
        $vid = nut_quirk_norm_hex($dev['vendorid'] ?? '');
        $pid = nut_quirk_norm_hex($dev['productid'] ?? '');
        $model = trim((string)($dev['model'] ?? ''));
        $ser = trim((string)($dev['serial'] ?? ''));
        $busRaw = trim((string)($dev['bus'] ?? ''));
        $bus = ctype_digit($busRaw) ? sprintf('%03d', (int)$busRaw) : $busRaw;
        if ($pinVid !== '' && $vid !== $pinVid) continue;
        if ($pinPid !== '' && $pid !== $pinPid) continue;
        if ($pinSer !== '' && $ser !== $pinSer) continue;
        if ($pinBus !== '' && $bus !== $pinBus) continue;
        if ($pinProd !== '') {
            $re = '/' . str_replace('/', '\/', $pinProd) . '/i';
            $prodMatch = @preg_match($re, $model);
            if ($prodMatch === 0 || ($prodMatch === false && stripos($model, $pinProd) === false)) continue;
        }
        $candidates[] = [
            'vendorid'  => $vid,
            'productid' => $pid,
            'model'     => $model,
            'serial'    => $ser,
            'bus'       => $bus,
        ];
    }
    return $candidates;
}

/* Parse a space/comma-separated quirk ID list setting. */
function nut_quirk_id_list(array $c, $key) {
    $raw = trim((string)($c[$key] ?? ''));
    if ($raw === '') return [];
    return array_values(array_unique(array_filter(preg_split('/[\s,]+/', $raw), 'strlen')));
}

function nut_quirks_disabled(array $c) {
    return nut_quirk_id_list($c, 'QUIRKS_DISABLED');
}

function nut_quirks_enabled(array $c) {
    return nut_quirk_id_list($c, 'QUIRKS_ENABLED');
}

function nut_quirk_matches_device(array $match, array $dev) {
    if (!empty($match['vendorid']) && !in_array(nut_quirk_norm_hex($dev['vendorid'] ?? ''), $match['vendorid'], true)) return false;
    if (!empty($match['productid']) && !in_array(nut_quirk_norm_hex($dev['productid'] ?? ''), $match['productid'], true)) return false;
    if (!empty($match['model_re']) && preg_match($match['model_re'], (string)($dev['model'] ?? '')) !== 1) return false;
    return true;
}

/* Quirks applicable to the current configuration's mode, driver, backend and detected hardware.
 * Exact matches ('match') carry default_on=true; related matches ('likely_match') carry default_on=false. */
function nut_quirks_matching(array $c, ?array $usbDevices = null) {
    if (($c['MODE'] ?? '') === 'slave') return [];
    $driver = ($c['DRIVER'] ?? '') === 'custom' ? ($c['SERIAL'] ?? '') : ($c['DRIVER'] ?? '');
    $backend = ($c['BACKEND'] ?? '') !== '' ? $c['BACKEND'] : 'default';
    $devices = nut_quirk_detect_devices($c, $usbDevices);
    if (!$devices) return [];
    $upsDevices = array_values(array_filter($devices, fn($d) => in_array($d['vendorid'], NUT_QUIRK_UPS_VENDORS, true)));
    $matched = [];
    foreach (NUT_QUIRKS as $id => $q) {
        if (!in_array($driver, $q['drivers'], true)) continue;
        if ($q['backends'] !== null && !in_array($backend, $q['backends'], true)) continue;
        $exact = false;
        $likely = false;
        foreach ($devices as $dev) {
            if (!empty($q['match']) && nut_quirk_matches_device($q['match'], $dev)) {
                $exact = true;
            } elseif (!empty($q['likely_match']) && nut_quirk_matches_device($q['likely_match'], $dev)) {
                $likely = true;
            }
        }
        if ($exact || $likely) {
            // Only auto-enable an exact workaround when no other candidate UPS disagrees (avoids applying
            // e.g. CyberPower's onlinedischarge_onbattery to an unpinned APC on a multi-UPS host).
            $unambiguous = $exact;
            if ($exact && count($upsDevices) > 1) {
                foreach ($upsDevices as $u) {
                    if (!nut_quirk_matches_device($q['match'], $u)) {
                        $unambiguous = false;
                        break;
                    }
                }
            }
            $matched[$id] = $q + ['default_on' => $unambiguous];
        }
    }
    return $matched;
}

/* Matching quirks currently enabled: exact matches unless opted out, likely matches only if opted in. */
function nut_quirks_active(array $c, ?array $usbDevices = null) {
    $disabled = array_flip(nut_quirks_disabled($c));
    $enabled = array_flip(nut_quirks_enabled($c));
    $active = [];
    foreach (nut_quirks_matching($c, $usbDevices) as $id => $q) {
        $on = $q['default_on'] ? !isset($disabled[$id]) : isset($enabled[$id]);
        if ($on) $active[$id] = $q;
    }
    return $active;
}

/* Lowercased ups.conf directive keys emitted by active quirks (replaceable by CUSTOM_UPS). */
function nut_quirk_replaceable_keys(array $c, ?array $usbDevices = null) {
    $keys = [];
    foreach (nut_quirks_active($c, $usbDevices) as $q) {
        foreach ($q['ups_conf'] ?? [] as $k => $_) {
            $keys[strtolower($k)] = true;
        }
    }
    return $keys;
}

/* Format a quirk's ups.conf directives as lines for config generation or UI preview. */
function nut_quirk_format_directives(array $q, $indent = '') {
    $lines = [];
    foreach ($q['ups_conf'] ?? [] as $k => $v) {
        $lines[] = $v === null ? "$indent$k" : "$indent$k = $v";
    }
    return implode("\n", $lines);
}
