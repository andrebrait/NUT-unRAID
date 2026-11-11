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
 * Validates NUT settings before they are saved. Used as the #include of the settings form
 * (included by /update.php, which then skips saving and the #command on errors) and as an
 * AJAX endpoint returning {"errors": [...]} so the page can show problems before submitting.
 * Variables are prefixed because /update.php includes this file into its own scope.
 */
require_once __DIR__ . '/nut_managed.php';

$nut_v_posted = array_filter($_POST, fn($key) => ($key[0] ?? '#') !== '#' && $key !== 'csrf_token', ARRAY_FILTER_USE_KEY);
$nut_v_cfg = nut_mc_read_cfg(NUT_MC_DIR . '/nut3.cfg');
foreach ($nut_v_posted as $nut_v_key => $nut_v_value) {
    $nut_v_cfg[$nut_v_key] = (isset($default) && is_array($default) && isset($default[$nut_v_key])) ? $default[$nut_v_key] : $nut_v_value;
}
// The NUT settings are only posted when the service is stopped; otherwise just check what was posted.
$nut_v_errors = isset($_POST['MODE']) ? nut_mc_validate(nut_mc_settings($nut_v_cfg)) : nut_mc_unsafe($nut_v_posted);

if (isset($save)) {
    if ($nut_v_errors) {
        $save = false;
        unset($_POST['#command']);
        write_log('NUT settings were NOT saved:');
        foreach ($nut_v_errors as $nut_v_error) {
            write_log('- ' . $nut_v_error);
        }
    } elseif (isset($_POST['MODE'])) {
        // the user reviewed and saved the imported settings, so the generated configuration takes over
        nut_mc_accept();
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['errors' => $nut_v_errors]);
}
