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
 * Redaction of the debug package. Every copied text file goes through nut_redact_text: lines that hold a credential
 * are replaced, MONITOR passwords are masked in place, and every known credential and user name is replaced wherever
 * it still occurs. The known credentials are the settings passwords plus every credential found in the copied files,
 * so a password of a custom MONITOR line is also removed from the syslogs. A file that still holds a known credential
 * after that is replaced by a notice.
 */

require_once __DIR__ . '/nut_managed.php';

const NUT_REDACT_KEYWORD = '/pass(?:word|wd)?\b|secret|community|token/i';
const NUT_REDACT_VALUE = '/(?:pass(?:word|wd)?\b|secret|community|token)\S*?\s*[=:]?\s*(.*)$/i';
const NUT_REDACT_MONITOR = '/^\s*MONITOR\b/i';

/* Redacts every file below $dir in place (the settings copy nut3.cfg.txt is redacted by the caller). $cfg holds the
 * merged settings (see nut_mc_settings). Returns false if a file cannot be read or written. */
function nut_redact_dir($dir, array $cfg) {
    $paths = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && !$file->isLink() && $file->getFilename() !== 'nut3.cfg.txt') $paths[] = $file->getPathname();
    }

    $texts = [];
    foreach ($paths as $path) {
        $text = file_get_contents($path);
        if ($text === false) return false;
        $texts[$path] = $text;
    }

    $secrets = [];
    foreach (['MONPASS', 'SLAVEPASS', 'ADMINPASS'] as $key) {
        $secrets[] = nut_mc_decode($cfg[$key] ?? '') ?? '';
    }
    foreach ($texts as $text) {
        $secrets = array_merge($secrets, nut_redact_found($text));
    }
    $secrets = array_values(array_unique(array_filter($secrets, 'strlen')));
    // longest first, so that a secret containing another one is removed whole
    usort($secrets, fn($a, $b) => strlen($b) <=> strlen($a));
    $names = array_values(array_filter([$cfg['MONUSER'] ?? '', $cfg['SLAVEUSER'] ?? '', $cfg['ADMINUSER'] ?? ''], 'strlen'));

    foreach ($texts as $path => $text) {
        $out = nut_redact_text($text, $secrets, $names);
        foreach ($secrets as $secret) {
            if (strpos($out, $secret) !== false) {
                $out = "[file removed: a credential could not be redacted]\n";
                break;
            }
        }
        if (file_put_contents($path, $out) !== strlen($out)) return false;
    }
    return true;
}

/* Credentials written in a text: the password of a MONITOR line, or the value of a line with a credential keyword. */
function nut_redact_found($text) {
    $found = [];
    foreach (explode("\n", (string)$text) as $line) {
        if (preg_match(NUT_REDACT_MONITOR, $line)) {
            $words = nut_mc_words($line);
            $found[] = $words !== null && count($words) === 6 ? $words[4] : (preg_split('/\s+/', trim($line))[4] ?? '');
        } elseif (preg_match(NUT_REDACT_VALUE, $line, $m)) {
            $found[] = $m[1];
            $words = nut_mc_words($line);
            if ($words && preg_match(NUT_REDACT_KEYWORD, $words[0])) {
                $found[] = ($words[1] ?? '') === '=' ? ($words[2] ?? '') : ($words[1] ?? '');
            }
        }
    }
    return array_map(fn($value) => trim($value, " \t\"'"), $found);
}

/* The text without its credentials. $secrets must be sorted longest first. */
function nut_redact_text($text, array $secrets, array $names) {
    $lines = [];
    foreach (explode("\n", (string)$text) as $line) {
        if (preg_match(NUT_REDACT_MONITOR, $line)) {
            $words = nut_mc_words($line);
            $lines[] = $words !== null && count($words) === 6
                ? "MONITOR {$words[1]} {$words[2]} {$words[3]} PASS-REMOVED {$words[5]}"
                : '[MONITOR line removed: unrecognised format]';
        } elseif (preg_match(NUT_REDACT_KEYWORD, $line)) {
            $lines[] = '[credential line removed]';
        } else {
            $lines[] = $line;
        }
    }
    return str_replace($names, 'USER-REMOVED', str_replace($secrets, 'PASS-REMOVED', implode("\n", $lines)));
}
