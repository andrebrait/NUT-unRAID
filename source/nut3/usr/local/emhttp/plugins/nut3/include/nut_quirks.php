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
 * configured driver, backend and detected USB/configured hardware, supplies
 * default ups.conf workarounds (enabled by default, individually opt-out via
 * QUIRKS_DISABLED) and metadata for the settings UI.
 */

const NUT_QUIRKS = [
    'apc_bx_mi_lbrb' => [
        'title'    => 'APC Back-UPS BX...MI: filter spurious LB/RB blips',
        'details'  => "APC Back-UPS BX-series units (BX750MI, BX950MI, BX1200MI, BX1600MI, BX2200MI) have a known firmware quirk (upstream NUT #2347, #3623) where they briefly flash a false Low Battery (LB) flag over USB HID while online at full charge.\n\nThis workaround adds lbrb_log_delay_sec = 4 and lbrb_log_delay_without_calibrating to ups.conf so usbhid-ups ignores LB (and RB while LB is active) unless it persists for at least 4 seconds.\n\nNotes:\n- A standalone OL RB (Replace Battery without LB) is still reported immediately.\n- With lbrb_log_delay_without_calibrating, a genuine low-battery transition is also delayed by 4 seconds.\n- You can override lbrb_log_delay_sec in Custom ups.conf (UPS section) Directives, or uncheck this workaround to opt out.",
        'drivers'  => ['usbhid-ups'],
        'backends' => ['default', 'preview', 'previous', 'stable'],
        'match'    => [
            'vendorid' => ['051d'],
            'model_re' => '/\bBX\d+MI\b/i',
        ],
        'ups_conf' => [
            'lbrb_log_delay_sec'                 => '4',
            'lbrb_log_delay_without_calibrating' => null,
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
        $devices[] = [
            'vendorid'  => nut_quirk_norm_hex($vid),
            'productid' => nut_quirk_norm_hex((string)@file_get_contents("$dir/idProduct")),
            'model'     => trim((string)@file_get_contents("$dir/product")),
            'serial'    => trim((string)@file_get_contents("$dir/serial")),
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
        if (in_array($k, ['vendorid', 'productid', 'product', 'serial'], true)) {
            $pinned[$k] = $d['words'][2];
        }
    }
    $pinVid = isset($pinned['vendorid']) ? nut_quirk_norm_hex($pinned['vendorid']) : '';
    $pinPid = isset($pinned['productid']) ? nut_quirk_norm_hex($pinned['productid']) : '';
    $pinProd = $pinned['product'] ?? '';
    $pinSer = $pinned['serial'] ?? '';

    $candidates = [];
    if ($pinProd !== '') {
        $candidates[] = [
            'vendorid'  => $pinVid,
            'productid' => $pinPid,
            'model'     => $pinProd,
            'serial'    => $pinSer,
        ];
    }
    foreach ($usbDevices ?? nut_quirk_usb_devices() as $dev) {
        $vid = nut_quirk_norm_hex($dev['vendorid'] ?? '');
        $pid = nut_quirk_norm_hex($dev['productid'] ?? '');
        $model = trim((string)($dev['model'] ?? ''));
        $ser = trim((string)($dev['serial'] ?? ''));
        if ($pinVid !== '' && $vid !== $pinVid) continue;
        if ($pinPid !== '' && $pid !== $pinPid) continue;
        if ($pinSer !== '' && $ser !== $pinSer) continue;
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
        ];
    }
    return $candidates;
}

/* Disabled quirk IDs stored in QUIRKS_DISABLED. */
function nut_quirks_disabled(array $c) {
    $raw = trim((string)($c['QUIRKS_DISABLED'] ?? ''));
    if ($raw === '') return [];
    return array_values(array_unique(array_filter(preg_split('/[\s,]+/', $raw), 'strlen')));
}

function nut_quirk_matches_device(array $match, array $dev) {
    if (!empty($match['vendorid']) && !in_array(nut_quirk_norm_hex($dev['vendorid'] ?? ''), $match['vendorid'], true)) return false;
    if (!empty($match['productid']) && !in_array(nut_quirk_norm_hex($dev['productid'] ?? ''), $match['productid'], true)) return false;
    if (!empty($match['model_re']) && preg_match($match['model_re'], (string)($dev['model'] ?? '')) !== 1) return false;
    return true;
}

/* Quirks applicable to the current configuration's mode, driver, backend and detected hardware. */
function nut_quirks_matching(array $c, ?array $usbDevices = null) {
    if (($c['MODE'] ?? '') === 'slave') return [];
    $driver = ($c['DRIVER'] ?? '') === 'custom' ? ($c['SERIAL'] ?? '') : ($c['DRIVER'] ?? '');
    $backend = ($c['BACKEND'] ?? '') !== '' ? $c['BACKEND'] : 'default';
    $devices = nut_quirk_detect_devices($c, $usbDevices);
    if (!$devices) return [];
    $matched = [];
    foreach (NUT_QUIRKS as $id => $q) {
        if (!in_array($driver, $q['drivers'], true)) continue;
        if (!in_array($backend, $q['backends'], true)) continue;
        foreach ($devices as $dev) {
            if (nut_quirk_matches_device($q['match'], $dev)) {
                $matched[$id] = $q;
                break;
            }
        }
    }
    return $matched;
}

/* Matching quirks that the user has not opted out of. */
function nut_quirks_active(array $c, ?array $usbDevices = null) {
    $disabled = array_flip(nut_quirks_disabled($c));
    return array_filter(nut_quirks_matching($c, $usbDevices), fn($id) => !isset($disabled[$id]), ARRAY_FILTER_USE_KEY);
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
