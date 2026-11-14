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

/* Hard upper bound for upsc/upscmd calls, as a backstop in case a call
 * stalls past the client library's own connect timeout. */
const NUT_CLIENT_TIMEOUT = '/usr/bin/timeout -k 2 15 ';
/* Bound for upscmd -w, which waits for the driver to finish the command. */
const NUT_CLIENT_TIMEOUT_WAIT = '/usr/bin/timeout -k 2 60 ';
defined('NUT_UPSC') || define('NUT_UPSC', '/usr/bin/upsc'); // overridable so the tests can run a stand-in client

/* Run upsc with the given (already escaped) "<ups>@<host> [variable]" arguments, after "--" because a UPS name
 * may start with "-". Returns the exit code, fills $out with the stdout lines and tells whether upsd itself
 * could not be reached (as opposed to it answering with an error). */
function nut_upsc($args, &$out, &$unreachable) {
    $out = [];
    $unreachable = false;

    $proc = proc_open(NUT_CLIENT_TIMEOUT . NUT_UPSC . ' -- ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return 1;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($stdout !== false && $stdout !== '') {
        $out = explode("\n", rtrim($stdout, "\n"));
    }
    $unreachable = ($code === 124 || $code === 137 || stripos((string) $stderr, 'Connection failure') !== false);

    return $code;
}

function nut_html($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nut_tooltip_html($value) {
    return nut_html(nut_html($value));
}

function nut_dev_message_text($message) {
    return trim(html_entity_decode(strip_tags((string)$message), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/* Short-lived cache in RAM so the footer, dashboard tile and details page
 * (separate requests, separate timers) share one upsc call.
 * The directory is world-writable, so another user could plant a symlink or a file there: an entry is only read if it
 * is a regular file of our own user, and written through a fresh tempnam() file (O_EXCL, mode 0600) renamed over it. */
const NUT_ROWS_CACHE_TTL = 3;
defined('NUT_ROWS_CACHE_DIR') || define('NUT_ROWS_CACHE_DIR', '/dev/shm'); // overridable so the tests need no shared directory

function nut_rows_cache_file($name, $ip) {
    return NUT_ROWS_CACHE_DIR . '/nut3-rows-' . md5($name . '@' . $ip) . '.json';
}

/* The rows of a fresh cache entry, [] if there is none. */
function nut_rows_cache_load($file) {
    clearstatcache(true, $file);
    $st = @lstat($file); // lstat: a symlink is not followed
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || $st['uid'] !== $uid || (time() - $st['mtime']) >= NUT_ROWS_CACHE_TTL) return [];
    $cached = json_decode((string)@file_get_contents($file), true);
    return is_array($cached) ? $cached : [];
}

/* True if stored; a failure only costs the next request an upsc call. */
function nut_rows_cache_store($file, $rows) {
    $tmp = @tempnam(dirname($file), 'nut3-rows-');
    if ($tmp === false) return false;
    if (@file_put_contents($tmp, json_encode($rows)) !== false && @rename($tmp, $file)) return true;
    @unlink($tmp);
    return false;
}

function nut_status_rows($name, $ip) {
    $file = nut_rows_cache_file($name, $ip);
    if ($cached = nut_rows_cache_load($file)) return $cached;
    $rows = nut_status_rows_uncached($name, $ip);
    if ($rows) nut_rows_cache_store($file, $rows); // never cache a failure
    return $rows;
}

function nut_status_rows_uncached($name, $ip) {
    $ups = escapeshellarg($name) . '@' . escapeshellarg($ip);

    $status = nut_upsc($ups, $rows, $unreachable);

    if ($status === 0 && !empty($rows)) {
        return $rows;
    }

    // The fallback below is for an upsd that answers but cannot list all
    // variables. If upsd is not reachable at all, every one of those queries
    // would wait for the same connect timeout, so give up right away.
    if ($unreachable) {
        return [];
    }

    // Fallback for broken NUT protocol implementations
    // Try to get a number of known important variables one by one...
    $vars = [
        'battery.charge.low',
        'battery.charge',
        'battery.low', // according to user reports for UniFi UPS
        'battery.runtime',
        'battery.voltage',
        'input.current',
        'input.frequency',
        'input.voltage',
        'output.current',
        'output.frequency',
        'output.power.nominal', // according to user reports for UniFi UPS
        'output.power', // according to user reports for UniFi UPS
        'output.voltage',
        'ups.id',
        'ups.load',
        'ups.mfr',
        'ups.model',
        'ups.power.nominal',
        'ups.power',
        'ups.realpower.nominal',
        'ups.realpower',
        'ups.serial',
        'ups.status',
        'ups.test.date',
        'ups.test.interval',
        'ups.test.result',
        'ups.type',
    ];

    $rows = [];
    foreach ($vars as $var) {
        $code = nut_upsc($ups . ' ' . escapeshellarg($var), $out, $unreachable);

        if ($unreachable) {
            break;
        }

        if ($code === 0 && isset($out[0]) && $out[0] !== '') {
            $rows[] = $var . ': ' . $out[0];
        }
    }

    if(!empty($rows)) {
        $rows[] = "x.plugin.varfetch: fallback";
    }

    return $rows;
}

/* get options for battery level */
function nut_get_battery_options($selected=20){
    $range = [1,99];
    rsort($range);
    $options = "";
    foreach(range($range[0], $range[1], 1) as $level){
        $options .= "<option value='$level'";

        // set saved option as selected
        if (intval($selected) === $level) {
            $options .= " selected";
        }

        $options .= ">$level</option>";
    }
    return $options;
}

/* get options for time intervals */
function nut_get_minute_options($time){
    $options = '';
        for($i = 1; $i <= 180; $i++){
            $options .= '<option value="'.($i*60).'"';

            if(intval($time) === ($i*60)) {
                $options .= ' selected';
            }

            $options .= '>'.$i.'</option>';
        }
    return $options;
}

function nut_ups_status($rows, $valueOnly = false)
{
    global $nut_states;

    $severity = 0;
    $status_values = [];
    $status_fulltext = [];

    array_walk($rows, function($row) use (&$severity, &$status_fulltext, &$status_values, $nut_states, $valueOnly) {
        if ($valueOnly) {
            # if only ups.status value as param
            $status_values = explode(' ', $row);
        }
        elseif (preg_match('/^ups.status:\s*([^$]+)/i', $row, $matches)) {
            # if status array as param, find ups.status
            $status_values = explode(' ', $matches[1]);
        } else {
            # skip everything else
            return;
        }

        # if debug constant defined, overwrite ups.status values
        if (defined('NUT_STATUS_DEBUG')) {
            $status_values = explode(' ', NUT_STATUS_DEBUG);
        }

        # replace ups.status flags with full text message.
        $status_fulltext = array_map(function($var) use (&$severity, $nut_states) {
            if (isset($nut_states[$var]) && $nut_states[$var]) {
                # keep the highest severity message level
                $severity = max($severity, $nut_states[$var]['severity']);
                return $nut_states[$var]['msg'];
            } else {
                # if unknown status flag, return it raw (callers escape it, it comes from the UPS)
                return $var;
            }
        }, $status_values);
    });

    # return highest severity message level, array of status flags and array of full text status message
    return ['severity' => $severity, 'value' => $status_values, 'fulltext' => $status_fulltext];
}

function nut_download_url($url, $conn_timeout = 15, $timeout = 45) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $conn_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_REFERER, "");
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        $out = curl_exec($ch) ?: false;
        curl_close($ch);
        return $out;
    } catch (\Throwable $t) { // For PHP 7
        return false;
    } catch (\Exception $e) { // For PHP 5
        return false;
    }
}

function nut_get_dev_message() {
    try {
        $dev_message_url = "https://raw.githubusercontent.com/andrebrait/NUT-unRAID/master/plugin/developer_message";
        $raw_dev_message = nut_download_url($dev_message_url, 30, 45);
        if($raw_dev_message && strpos($raw_dev_message, "NODISPLAY") === false) {
            return $raw_dev_message;
        } else {
            return false;
        }
    } catch (\Throwable $t) { // For PHP 7
        return false;
    } catch (\Exception $e) { // For PHP 5
        return false;
    }
}

function nut_tailFile($filePath, $lines = 90) {
    try {
        if (!file_exists($filePath)) {
            throw new Exception("... requested syslog file is either empty or does not exist (yet)");
        }

        $f = fopen($filePath, "r");
        if (!$f) {
            throw new Exception("... requested syslog file exists but was not accessible");
        }

        $buffer = 4096;
        $lineCount = 0;
        $position = -1;
        $text = '';

        fseek($f, 0, SEEK_END);

        while ($lineCount < $lines) {
            $position -= $buffer;
            if ($position < -ftell($f)) {
                $position = -ftell($f);
            }

            fseek($f, $position, SEEK_END);
            $chunk = fread($f, $buffer);
            $text = $chunk . $text;
            $lineCount = substr_count($text, "\n");

            if ($position == -ftell($f)) {
                break;
            }
        }

        fclose($f);

        $allLines = explode("\n", str_replace("\r\n", "\n", $text));
        $sanitizedLines = array_map('htmlspecialchars', array_slice($allLines, -$lines));
        return implode("\n", $sanitizedLines);
    } catch (\Throwable $t) {
        return $t->getMessage();
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

/* All local UPS names (ups.conf sections plus the configured UPS) => the host to reach them on. */
function nut_ups_list($gui_name, $gui_ip) {
    $list = [];
    if (preg_match_all('/^\s*\[([A-Za-z0-9._-]+)\]/m', (string)@file_get_contents('/etc/nut/ups.conf'), $m)) {
        foreach ($m[1] as $n) $list[$n] = '127.0.0.1';
    }
    $list[$gui_name] = $gui_ip;
    return $list;
}

/* Parse the output of `upsrw <ups>` into [name => [desc, type, value, options[], maxlen]].
 * Blocks look like "[var]", a description line, "Type: ENUM|RANGE|NUMBER|STRING" (upsrw appends " NUMBER" or
 * " STRING" to ENUM and RANGE), "Option: ..." lines, "Maximum length: n" and "Value: v". The value of an ENUM is its
 * SELECTED option. A RANGE has no Value line: upsrw prints its ranges as "min-max" (also read as "min max" or
 * "min - max") and marks the one that holds the value SELECTED, so its value stays '' until nut_upsrw_range_values(). */
function nut_parse_upsrw($lines) {
    $vars = [];
    $cur = null;
    foreach ($lines as $line) {
        $line = rtrim($line, "\r\n");
        if (preg_match('/^\[([a-z0-9._]+)\]$/', $line, $m)) {
            $cur = $m[1];
            $vars[$cur] = ['desc' => '', 'type' => '', 'value' => '', 'options' => [], 'maxlen' => null];
        } elseif ($cur === null || trim($line) === '') {
            continue;
        } elseif (preg_match('/^Type: (\w+)/', $line, $m)) {
            $vars[$cur]['type'] = $m[1];
        } elseif (preg_match('/^Maximum length: (\d+)/', $line, $m)) {
            $vars[$cur]['maxlen'] = (int)$m[1];
        } elseif (preg_match('/^Option: "(.*)"( SELECTED)?$/', $line, $m)) {
            $vars[$cur]['options'][] = $m[1];
            if (!empty($m[2]) && $vars[$cur]['type'] === 'ENUM') $vars[$cur]['value'] = $m[1]; // ENUM blocks have no Value line; a SELECTED RANGE is not a value
        } elseif (preg_match('/^Value: ?(.*)$/', $line, $m)) {
            $vars[$cur]['value'] = $m[1];
        } elseif ($vars[$cur]['desc'] === '') {
            $vars[$cur]['desc'] = $line;
        }
    }
    return $vars;
}

/* Plain decimal only: digits, an optional leading "-", an optional fraction. is_numeric() also takes "1e3", " 5" and "5 ". */
function nut_is_decimal($value) {
    return preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) === 1;
}

/* Check $value against a variable parsed by nut_parse_upsrw(). Returns an error text, or null if allowed. */
function nut_check_upsrw_value($var, $value) {
    if (preg_match('/[\x00-\x1f\x7f]/', $value)) return 'Control characters are not allowed.';
    switch ($var['type']) {
        case 'ENUM':
            return in_array($value, $var['options'], true) ? null : 'Value is not one of the allowed options.';
        case 'RANGE':
            if (!nut_is_decimal($value)) return 'Value must be a number.';
            foreach ($var['options'] as $o) {
                if (preg_match('/^(-?[0-9.]+)\s+(-?[0-9.]+)$/', $o, $m) || preg_match('/^(-?[0-9.]+)\s*-\s*(-?[0-9.]+)$/', $o, $m)) {
                    if ($value + 0 >= $m[1] + 0 && $value + 0 <= $m[2] + 0) return null;
                }
            }
            return 'Value is outside the allowed range.';
        case 'NUMBER':
            return nut_is_decimal($value) ? null : 'Value must be a number.';
        case 'STRING':
            return ($var['maxlen'] === null || strlen($value) <= $var['maxlen']) ? null : 'Value is longer than ' . $var['maxlen'] . ' characters.';
    }
    return 'Unknown variable type.';
}

/* One summary per UPS for the "All UPS devices" table and the dashboard line, from the cached upsc rows.
 * Every returned string is already HTML-escaped. ok=false means upsc returned nothing (unreachable). */
function nut_ups_overview($list) {
    global $nut_runtime, $nut_rtunit, $nut_msgSeverity;
    $out = [];
    foreach ($list as $name => $ip) {
        $v = [];
        foreach (nut_status_rows($name, $ip) as $r) {
            $p = explode(':', $r, 2);
            if (count($p) == 2) $v[trim($p[0])] = trim($p[1]);
        }
        $e = fn($k, $unit = '') => (isset($v[$k]) && $v[$k] !== '') ? nut_html($v[$k]) . $unit : '-';
        $st = nut_ups_status(isset($v['ups.status']) ? ['ups.status: ' . $v['ups.status']] : []);
        $text = array_map('nut_html', $st['fulltext']);
        $rt = (isset($v[$nut_runtime]) && is_numeric($v[$nut_runtime])) ? gmdate('H:i:s', (int)round($v[$nut_runtime] * ($nut_rtunit == 'minutes' ? 60 : 1))) : '-';
        $out[$name] = [
            'ok'     => !empty($v),
            'desc'   => nut_html(implode(' ', array_filter([$v['device.mfr'] ?? ($v['ups.mfr'] ?? ''), $v['ups.model'] ?? '', $v['ups.desc'] ?? '']))) ?: '-',
            'status' => $text ? implode(' - ', $text) : '-',
            'css'    => $nut_msgSeverity[$st['severity']]['css_class'] ?? '',
            'word'   => $text[0] ?? '-',
            'bat'    => $e('battery.charge', '&thinsp;%'),
            'rt'     => $rt,
            'load'   => $e('ups.load', '&thinsp;%'),
            'vin'    => $e('input.voltage', '&thinsp;V'),
            'vout'   => $e('output.voltage', '&thinsp;V'),
        ];
    }
    return $out;
}

/* Command names (name => description) from `upscmd -l <ups>@<host>` output lines. */
function nut_parse_upscmd($lines) {
    $cmds = [];
    if (preg_match_all('/^(.*?) - (.*?)$/m', implode("\n", $lines), $m, PREG_SET_ORDER)) {
        foreach ($m as $c) $cmds[$c[1]] = $c[2];
    }
    return $cmds;
}

/* The commands the UPS advertises; $target is already escaped. Empty on failure. */
function nut_upscmd_list($target, $run = 'nut_run') {
    $lines = [];
    $run(NUT_CLIENT_TIMEOUT . '/usr/bin/upscmd -l -- ' . $target . ' 2>/dev/null', $lines, $ret);
    return $ret === 0 ? nut_parse_upscmd($lines) : [];
}

/* Outlets from upsc rows ("key: value") and the upscmd -l command names: outlet.N.* and outlet.group.N.*
 * variables, plus outlet.[group.]N.load.off|on|cycle commands. Returns [id => [desc, status, switchable, cmds[action => command]]]. */
function nut_parse_outlets($rows, $cmdnames) {
    $o = [];
    $blank = ['desc' => '', 'status' => '', 'switchable' => '', 'cmds' => []];
    foreach ($rows as $r) {
        if (preg_match('/^outlet\.((?:group\.)?\d+)\.(desc|name|status|switchable):\s*(.*)$/', $r, $m)) {
            $o[$m[1]] ??= $blank;
            $k = $m[2] === 'name' ? 'desc' : $m[2];
            if ($m[2] !== 'name' || $o[$m[1]]['desc'] === '') $o[$m[1]][$k] = trim($m[3]);
        }
    }
    foreach ($cmdnames as $c) {
        if (preg_match('/^outlet\.((?:group\.)?\d+)\.load\.(off|on|cycle)$/', $c, $m)) {
            $o[$m[1]] ??= $blank;
            $o[$m[1]]['cmds'][$m[2]] = $c;
        }
    }
    uksort($o, 'strnatcmp');
    return $o;
}

/* --- the command endpoints (nut_commands.php, nut_upsrw.php) ------------------------------------------------------
 * Their logic lives here so it can be tested: $method is REQUEST_METHOD, $post is $_POST, $cfg the plugin settings,
 * $upsList nut_ups_list(), $gui the GUI UPS and $run a runner like nut_run(). Each returns the JSON response array. */

function nut_run($cmd, &$out, &$ret) {
    exec($cmd, $out, $ret);
}

function nut_error_response($message) {
    return ['error' => ['response' => $message]];
}

/* The NUT Admin user and password of the settings as [user, pass], or null if either is missing.
 * Commands and variable changes run as this user, the browser never sends or sees the password. */
function nut_admin_credentials($cfg) {
    require_once __DIR__ . '/nut_managed.php'; // nut_mc_decode, loaded only where it is needed
    $user = $cfg['ADMINUSER'] ?? '';
    $pass = nut_mc_decode($cfg['ADMINPASS'] ?? '') ?? '';
    return ($user === '' || $pass === '') ? null : [$user, $pass];
}

/* POST only, so the webGui checks the CSRF token of every request that gets here, and only while the
 * NUT Command Center Module is on (the page is hidden otherwise, the endpoints must refuse too). */
function nut_commands_gate($method, $cfg) {
    if ($method !== 'POST') return nut_error_response('POST required.');
    if (($cfg['COMMANDS'] ?? 'enable') === 'disable') return nut_error_response('NUT commands are disabled in the NUT Settings.');
    return null;
}

/* Output of a command that was run with the password on its command line: the password is masked in case the
 * output echoes it, and the text is escaped for the page. */
function nut_command_result($out, $ret, $pass) {
    $resp = [];
    $resp[$ret === 0 ? 'success' : 'error']['response'] = htmlspecialchars(str_replace($pass, '***', implode(PHP_EOL, $out)));
    return $resp;
}

function nut_commands_response($method, $post, $cfg, $upsList, $gui, $run) {
    if ($gate = nut_commands_gate($method, $cfg)) return $gate;
    $ups = $post['nutups'] ?? $gui;
    if (!is_string($ups) || !isset($upsList[$ups])) return nut_error_response('Unknown UPS.');
    $target = escapeshellarg($ups) . '@' . escapeshellarg($upsList[$ups]);
    // only commands the UPS itself lists are ever run
    $cmds = nut_upscmd_list($target, $run);

    if (($post['nutaction'] ?? '') === 'outlets') {
        // outlet table for the page: upsc variables plus the commands the UPS lists
        $rows = [];
        $run(NUT_CLIENT_TIMEOUT . '/usr/bin/upsc -- ' . $target . ' 2>/dev/null', $rows, $ret);
        return ['success' => ['outlets' => nut_parse_outlets($rows, array_keys($cmds))]];
    }
    $name = $post['nutcmdname'] ?? null;
    $arg = $post['nutcmdarg'] ?? '';
    if ($name === null || !is_string($arg)) return nut_error_response('Missing required POST variables!');
    if (!($cred = nut_admin_credentials($cfg))) return nut_error_response('No NUT Admin credentials are configured in the NUT Settings.');
    if (!is_string($name) || !isset($cmds[$name])) return nut_error_response('Command is not listed by the UPS.');

    $wait = isset($post['nutcmdwait']);
    $out = [];
    $run(($wait ? NUT_CLIENT_TIMEOUT_WAIT : NUT_CLIENT_TIMEOUT) . '/usr/bin/upscmd -u ' . escapeshellarg($cred[0]) . ' -p ' . escapeshellarg($cred[1])
        . ($wait ? ' -w' : '') . ' -- ' . $target . ' ' . escapeshellarg($name) . ($arg !== '' ? ' ' . escapeshellarg($arg) : '') . ' 2>&1', $out, $ret);
    return nut_command_result($out, $ret, $cred[1]);
}

/* Fills in the current value of the RANGE variables of an nut_parse_upsrw() result: upsrw does not print it, upsc does.
 * One upsc call for all of them, and none while there is no RANGE. $target is already escaped. */
function nut_upsrw_range_values(&$vars, $target, $run) {
    $ranges = array_filter($vars, fn($v) => $v['type'] === 'RANGE');
    if (!$ranges) return;
    $rows = [];
    $run(NUT_CLIENT_TIMEOUT . '/usr/bin/upsc -- ' . $target . ' 2>/dev/null', $rows, $ret);
    if ($ret !== 0) return;
    foreach ($rows as $row) {
        $p = explode(':', $row, 2);
        if (count($p) == 2 && isset($ranges[trim($p[0])])) $vars[trim($p[0])]['value'] = trim($p[1]);
    }
}

/* action=list: the writable variables of the UPS.
 * action=set:  upsrw -s var=value as the NUT Admin user of the settings; the value is checked against the
 *              variable's own definition (ENUM/RANGE/STRING length) read from the UPS again, not from the page. */
function nut_upsrw_response($method, $post, $cfg, $upsList, $gui, $run) {
    if ($gate = nut_commands_gate($method, $cfg)) return $gate;
    $ups = $post['nutups'] ?? $gui;
    if (!is_string($ups) || !isset($upsList[$ups])) return nut_error_response('Unknown UPS.');
    $action = $post['action'] ?? '';
    if ($action !== 'list' && $action !== 'set') return nut_error_response('Missing required POST variables!');

    $target = escapeshellarg($ups) . '@' . escapeshellarg($upsList[$ups]);
    $out = [];
    $run(NUT_CLIENT_TIMEOUT . "/usr/bin/upsrw -- $target 2>&1", $out, $ret);
    if ($ret !== 0) return nut_error_response(htmlspecialchars(implode(PHP_EOL, $out)));
    $vars = nut_parse_upsrw($out);
    if ($action === 'list') {
        nut_upsrw_range_values($vars, $target, $run);
        return ['success' => ['vars' => $vars]];
    }

    $name = $post['nutvar'] ?? '';
    $value = $post['nutvalue'] ?? '';
    if (!is_string($name) || !preg_match('/^[a-z0-9._]+$/', $name) || !isset($vars[$name])) return nut_error_response('Unknown or invalid variable name.');
    if (!is_string($value)) return nut_error_response('Missing required POST variables!');
    if (!($cred = nut_admin_credentials($cfg))) return nut_error_response('No NUT Admin credentials are configured in the NUT Settings.');
    if (($check = nut_check_upsrw_value($vars[$name], $value)) !== null) return nut_error_response(htmlspecialchars($check));

    $out = [];
    $run(NUT_CLIENT_TIMEOUT . '/usr/bin/upsrw -s ' . escapeshellarg("$name=$value") . ' -u ' . escapeshellarg($cred[0]) . ' -p ' . escapeshellarg($cred[1]) . ' -- ' . $target . ' 2>&1', $out, $ret);
    return nut_command_result($out, $ret, $cred[1]);
}
?>
