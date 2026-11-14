<?
/* Copyright Derek Macias (parts of code from NUT package)
 * Copyright macester (parts of code from NUT package)
 * Copyright gfjardim (parts of code from NUT package)
 * Copyright SimonF (parts of code from NUT package)
 * Copyright Dan Landon (parts of code from Web GUI)
 * Copyright Bergware International (parts of code from Web GUI)
 * Copyright Lime Technology (any and all other parts of Unraid)
 *
 * Copyright desertwitch (as author and maintainer of this file)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 */
require_once '/usr/local/emhttp/plugins/nut3/include/nut_config.php';
$resp = [];

// the rules (POST only, module switch, advertised commands only, stored credentials) are in nut_commands_response()
try {
    $resp = nut_commands_response($_SERVER['REQUEST_METHOD'] ?? '', $_POST, $nut_cfg, nut_ups_list($nut_name, $nut_ip), $nut_name, 'nut_run');
}
catch (\Throwable $t) {
    error_log($t);
    $resp = [];
    $resp["error"]["response"] = $t->getMessage();
}
catch (\Exception $e) {
    error_log($e);
    $resp = [];
    $resp["error"]["response"] = $e->getMessage();
}

echo(json_encode($resp));
?>
