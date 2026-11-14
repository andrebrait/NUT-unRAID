<?php
/* Tests for several UPSes in the managed configuration, taken over from davidcoulson's multi-UPS setups:
 * additional ups.conf sections live in the "UPS section" custom directives, every one of them is monitored with
 * power value 0 so that only the UPS named in the settings can shut this system down, and a previous
 * multi-UPS configuration is imported without losing a section or a custom MONITOR line.
 * Run: php tests/test_david_multi.php */

require_once __DIR__ . '/../source/nut3/usr/local/emhttp/plugins/nut3/include/nut_managed.php';

$failures = 0;

function check($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

function has($haystack, $needle) {
    foreach ((array)$haystack as $line) {
        if (strpos($line, $needle) !== false) return true;
    }
    return false;
}

/* MONITOR lines of an upsmon.conf text: system => [power value, type] */
function monitors($text) {
    $found = [];
    foreach (nut_mc_parse($text) as $d) {
        if (strtoupper($d['words'][0] ?? '') === 'MONITOR') $found[$d['words'][1]] = [$d['words'][2], $d['words'][5]];
    }
    return $found;
}

/* sections of a ups.conf text: name => [directive => value] */
function sections($text) {
    $found = [];
    foreach (nut_mc_parse($text) as $d) {
        if ($d['header']) {
            $found[$d['section']] = [];
        } elseif ($d['section'] !== null && count($d['words']) === 3) {
            $found[$d['section']][strtolower($d['words'][0])] = $d['words'][2];
        }
    }
    return $found;
}

$b = 'base64_encode';
$defaults = nut_mc_settings([]);
$spare = "[spare]\ndriver = nutdrv_qx\nport = auto\n";

// ---- one UPS: nothing changes -----------------------------------------------------------------------------------
$single = nut_mc_generate($defaults);
check(monitors($single['upsmon.conf']) === ['ups@127.0.0.1' => ['1', 'master']], 'a single UPS is monitored by one MONITOR line, power value 1');
check(array_keys(sections($single['ups.conf'])) === ['ups'], 'a single UPS has one ups.conf section');
check(nut_mc_extra_ups($defaults) === [], 'no additional UPS by default');

// ---- generation -------------------------------------------------------------------------------------------------
$multi = array_merge($defaults, ['CUSTOM_UPS' => $b("desc = \"Rack\"\n[spare]\n\tdriver = nutdrv_qx\n\tport = auto\n\tdesc = \"Spare\"\n[third]\ndriver = snmp-ups\nport = 10.0.0.9\ncommunity = public\n")]);
check(nut_mc_validate($multi) === [], 'additional UPS sections are valid: ' . implode(' | ', nut_mc_validate($multi)));
check(nut_mc_extra_ups($multi) === ['spare', 'third'], 'additional UPS sections are listed in order');
$out = nut_mc_generate($multi);
$ups = sections($out['ups.conf']);
check(array_keys($ups) === ['ups', 'spare', 'third'], 'ups.conf holds the UPS and the additional sections');
check($ups['ups'] === ['driver' => 'usbhid-ups', 'port' => 'auto', 'desc' => 'Rack'], 'the custom directives before the first header stay in the UPS section');
check($ups['spare'] === ['driver' => 'nutdrv_qx', 'port' => 'auto', 'desc' => 'Spare'], 'the spare section holds its own directives only');
check($ups['third']['driver'] === 'snmp-ups' && $ups['third']['community'] === 'public', 'the third section is written as given');
$mon = monitors($out['upsmon.conf']);
check($mon === ['ups@127.0.0.1' => ['1', 'master'], 'spare@127.0.0.1' => ['0', 'slave'], 'third@127.0.0.1' => ['0', 'slave']],
    'the UPS named in the settings keeps power value 1 as master, the others are watched with power value 0 as slave');
check(strpos($out['upsmon.conf'], "MONITOR spare@127.0.0.1 0 monuser \"monpass\" slave\n") !== false, 'additional UPS MONITOR line uses the monitor account');
check(array_sum(array_column($mon, 0)) === 1, 'the power values add up to the single shutdown UPS');
$timed = nut_mc_generate(array_merge($multi, ['ONBATTDELAY' => '30', 'EARLYSTAGE' => '10']));
foreach (['ups', 'spare', 'third'] as $name) {
    check(strpos($timed['upssched.conf'], "AT ONBATT $name@127.0.0.1 START-TIMER alert:$name@127.0.0.1 30\n") !== false
        && strpos($timed['upssched.conf'], "AT ONBATT $name@127.0.0.1 START-TIMER early:$name@127.0.0.1 10\n") !== false, "upssched timers cover $name");
}

// ---- a custom MONITOR line replaces the generated one only if it certainly reaches the generated upsd ---------------
// Standalone mode generates LISTEN 127.0.0.1: only IPv4 on port 3493 is certain. localhost may resolve to ::1, where
// another daemon could answer, so it replaces nothing: the UPS stays watched and the custom line is kept next to the
// generated one, no local UPS is ever left unmonitored. rc.nut applies the same rules when it powers the UPSes off.
$own = array_merge($multi, ['CUSTOM_UPSMON' => $b("MONITOR spare@127.0.0.1 1 monuser pass master\n")]);
check(nut_mc_validate($own) === [], 'a custom MONITOR line for an additional UPS is valid: ' . implode(' | ', nut_mc_validate($own)));
$mon = monitors(nut_mc_generate($own)['upsmon.conf']);
check($mon === ['ups@127.0.0.1' => ['1', 'master'], 'third@127.0.0.1' => ['0', 'slave'], 'spare@127.0.0.1' => ['1', 'master']],
    'the custom MONITOR line replaces the generated one for that UPS only');
check(has(nut_mc_validate(array_merge($multi, ['CUSTOM_UPSMON' => $b("MONITOR ups@127.0.0.1 1 monuser pass master\n")])), '"MONITOR"'),
    'the MONITOR line of the shutdown UPS cannot be overridden');
foreach (["monitor spare@127.0.0.1 0 monuser pass slave\n", "MONITOR spare@127.0.0.1\n"] as $invalid) {
    $invalidCustom = array_merge($multi, ['CUSTOM_UPSMON' => $b($invalid)]);
    check(strpos(nut_mc_generate($invalidCustom)['upsmon.conf'], 'MONITOR spare@127.0.0.1 0 monuser "monpass" slave') !== false,
        'an invalid or mis-cased monitor must not suppress monitoring of the local UPS');
}
// MONITOR lines for the UPS spare in the generated upsmon.conf of $c when it holds the custom line for $system:
// 1 = only the custom line (it replaced the generated one), 2 = the generated watch-only line stays next to it
$lines = fn(array $c, $system) => substr_count(nut_mc_generate(array_merge($c, ['CUSTOM_UPSMON' => $b("MONITOR $system 1 monuser pass master\n")]))['upsmon.conf'], 'MONITOR spare@');
foreach (['spare@127.0.0.1', 'spare@127.0.0.1:3493', 'spare@[::ffff:127.0.0.1]', 'spare@[::FFFF:7f00:1]:3493', 'spare@[127.0.0.1]'] as $system) {
    check($lines($multi, $system) === 1, "standalone: $system replaces the generated monitor");
}
foreach (['spare@localhost', 'spare@LOCALHOST:3493', 'spare@[::1]', 'spare@::1', 'spare@127.0.0.1:3494', 'spare@localhost:3494', 'spare@[::1]:3494', 'spare@10.0.0.2'] as $system) {
    check($lines($multi, $system) === 2, "standalone: $system leaves the UPS watched by the generated monitor");
}
// netserver mode listens on the LISTEN setting, in its order: [::1] needs ::1 (or :: with no address after it, as
// 2.7.4 binds the lines last to first), localhost needs both families. Columns: 127.0.0.1, [::1], localhost.
$listening = [
    '0.0.0.0' => [1, 2, 2],
    '0.0.0.0 ::' => [1, 1, 1],
    ':: 0.0.0.0' => [1, 2, 2],
    '127.0.0.1 ::1' => [1, 1, 1],
    '127.0.0.1 192.168.1.2 ::' => [1, 1, 1],
    ':: 192.168.1.2 127.0.0.1' => [1, 2, 2],
];
foreach ($listening as $addresses => $expected) {
    $net = array_merge($multi, ['MODE' => 'netserver', 'LISTEN' => $addresses]);
    check(nut_mc_validate($net) === [], "netserver LISTEN $addresses is valid: " . implode(' | ', nut_mc_validate($net)));
    foreach (['spare@127.0.0.1', 'spare@[::1]', 'spare@localhost'] as $i => $system) {
        check($lines($net, $system) === $expected[$i], "netserver LISTEN $addresses: $system " . ($expected[$i] === 1 ? 'replaces' : 'does not replace') . ' the generated monitor');
    }
}

// the loopback families the generated upsd certainly listens on
$families = function ($mode, $listen = '0.0.0.0') {
    $found = array_keys(nut_mc_listen_families(['MODE' => $mode, 'LISTEN' => $listen]));
    sort($found);
    return $found;
};
check($families('standalone', '::') === [4] && $families('slave') === [], 'standalone mode listens on 127.0.0.1 only, slave mode runs no upsd');
foreach (['0.0.0.0' => [4], '127.0.0.1 192.168.1.2' => [4], '::1' => [6], '::' => [6], '0.0.0.0 ::' => [4, 6], ':: 0.0.0.0' => [4], '192.168.1.2 ::' => [6],
    ':: 192.168.1.2' => [], '::1 0.0.0.0' => [4, 6], '0:0:0:0:0:0:0:1 0.0.0.0' => [4, 6], ':: ::1' => [6], '::ffff:127.0.0.1' => [], 'localhost' => [], '192.168.1.2' => []] as $addresses => $expected) {
    check($families('netserver', $addresses) === $expected, "netserver LISTEN $addresses certainly listens on the loopback families [" . implode(',', $expected) . '], got [' . implode(',', $families('netserver', $addresses)) . ']');
}

// which systems certainly reach it: the default port and every family the host can resolve to
$v4 = [4 => true];
$v6 = [6 => true];
$both = [4 => true, 6 => true];
foreach ([
    'spare@127.0.0.1' => [true, false, true], 'spare@127.0.0.1:3493' => [true, false, true], 'spare@127.0.0.1:03493' => [true, false, true], 'spare@[127.0.0.1]:3493' => [true, false, true],
    'spare@[::ffff:127.0.0.1]' => [true, false, true], 'spare@[0:0:0:0:0:ffff:7f00:1]:3493' => [true, false, true],
    'spare@[::1]' => [false, true, true], 'spare@[::1]:3493' => [false, true, true], 'spare@[0:0:0:0:0:0:0:1]' => [false, true, true],
    'spare@localhost' => [false, false, true], 'spare@LocalHost:3493' => [false, false, true],
    'spare@127.0.0.1:3494' => [false, false, false], 'spare@localhost:3494' => [false, false, false], 'spare@[::1]:3494' => [false, false, false],
    'spare@::1' => [false, false, false], 'spare@::1:3493' => [false, false, false], 'spare@[::1' => [false, false, false], 'spare@::1]' => [false, false, false],
    'spare@[::ffff:192.168.1.5]' => [false, false, false], 'spare@[2001:db8::1]' => [false, false, false], 'spare@10.0.0.2' => [false, false, false], 'spare' => [false, false, false],
] as $system => [$onV4, $onV6, $onBoth]) {
    $got = [nut_mc_local_ups($system, $v4) === 'spare', nut_mc_local_ups($system, $v6) === 'spare', nut_mc_local_ups($system, $both) === 'spare'];
    check($got === [$onV4, $onV6, $onBoth] && nut_mc_local_ups($system, []) === null, "$system is certain on [IPv4, IPv6, both] = " . json_encode([$onV4, $onV6, $onBoth]) . ', got ' . json_encode($got));
}
// without the families any loopback spelling on any port is a loopback line (it tells the lines of another UPS apart)
check(nut_mc_local_ups('spare@127.0.0.1:3494') === 'spare' && nut_mc_local_ups('spare@::1') === 'spare' && nut_mc_local_ups('spare@localhost') === 'spare'
    && nut_mc_local_ups('spare@10.0.0.2:3493') === null && nut_mc_local_ups('spare@::1:3493') === null && nut_mc_local_ups('spare@[::1') === null && nut_mc_local_ups('spare@::1]') === null,
    'any loopback spelling counts as a loopback line, other hosts and malformed addresses never');
$elsewhere = array_merge($multi, ['CUSTOM_UPSMON' => $b("MONITOR spare@127.0.0.1:3494 1 monuser pass master\n")]);
check(nut_mc_validate($elsewhere) === [], 'a custom MONITOR line on another port is valid: ' . implode(' | ', nut_mc_validate($elsewhere)));
check(monitors(nut_mc_generate($elsewhere)['upsmon.conf']) === ['ups@127.0.0.1' => ['1', 'master'], 'spare@127.0.0.1' => ['0', 'slave'], 'third@127.0.0.1' => ['0', 'slave'],
    'spare@127.0.0.1:3494' => ['1', 'master']], 'a custom MONITOR line on another port does not replace the generated one');

// ---- validation -------------------------------------------------------------------------------------------------
$errors = fn($custom, array $more = []) => nut_mc_validate(array_merge($defaults, ['CUSTOM_UPS' => $b($custom)], $more));
check($errors($spare) === [], 'one additional section is valid');
check($errors("desc = \"Rack\"\n$spare") === [], 'a UPS directive before the first header is valid');
check(has($errors("port = /dev/ttyS0\n$spare"), '"port" is set from the settings above'), 'directives before the first header are still checked against the UPS settings');
check(has($errors("$spare$spare"), 'already defined'), 'a duplicate section is rejected');
check(has($errors("[ups]\ndriver = x\nport = y\n"), 'UPS Name setting'), 'a section named like the shutdown UPS is rejected');
check(has($errors("[sp@re]\ndriver = x\nport = y\n"), 'must use 1-64 letters'), 'an invalid section name is rejected');
check(has($errors("[two words]\ndriver = x\nport = y\n"), 'not a valid UPS section header'), 'a malformed section header is rejected');
check(has($errors("[spare]\ndriver = nutdrv_qx\n"), 'both a "driver" and a "port"') && has($errors("[spare]\nport = auto\n"), 'both a "driver" and a "port"'), 'a section needs a driver and a port');
check(has($errors("[spare]\ndriver = ../evil\nport = auto\n"), 'valid driver name'), 'a section driver may not be a path');
check(has(nut_mc_validate(array_merge($defaults, ['CUSTOM_UPSGLOBAL' => $b($spare)])), '[spare]'), 'sections are not accepted among the global directives');
check(has($errors($spare, ['CUSTOM_UPS' => 'not base64!']), 'corrupt'), 'a corrupt field is still rejected');

// ---- slave mode runs no driver: the field is ignored ------------------------------------------------------------
$slave = array_merge($defaults, ['MODE' => 'slave', 'NAME' => 'remote', 'IPADDR' => '10.0.0.5', 'CUSTOM_UPS' => $b("[spare]\ndriver = x\n")]);
check(nut_mc_extra_ups($slave) === [] && nut_mc_validate($slave) === [], 'slave mode ignores additional sections: ' . implode(' | ', nut_mc_validate($slave)));
check(array_keys(monitors(nut_mc_generate($slave)['upsmon.conf'])) === ['remote@10.0.0.5'] && array_keys(sections(nut_mc_generate($slave)['ups.conf'])) === [], 'slave mode monitors only the remote UPS');

// ---- import of a previous multi-UPS configuration ---------------------------------------------------------------
// the shutdown UPS is second in the file: its leftover directives must not end up under [spare]
$legacyUps = "maxretry = 3\n[spare]\n\tdriver = nutdrv_qx\n\tport = auto\n[rack]\n\tdriver = usbhid-ups\n\tport = auto\n\tvendorid = 051D\n";
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable', 'NAME' => 'rack'], [
    'ups.conf' => $legacyUps,
    'upsmon.conf' => "MONITOR rack@127.0.0.1 1 monuser pass master\nMONITOR spare@127.0.0.1 1 monuser pass master\nMINSUPPLIES 1\n",
]);
check($c['NAME'] === 'rack' && nut_mc_decode($c['CUSTOM_UPSGLOBAL']) === 'maxretry = 3', 'the UPS named in the settings stays the shutdown UPS');
check(nut_mc_decode($c['CUSTOM_UPS']) === "vendorid = 051D\n[spare]\ndriver = nutdrv_qx\nport = auto", 'leftover directives come before the additional sections');
check(nut_mc_decode($c['CUSTOM_UPSMON']) === "MONITOR spare@127.0.0.1 1 monuser pass master\nMINSUPPLIES 1", 'custom MONITOR and MINSUPPLIES lines are kept');
check(has($notes, 'more than one UPS section') && has($notes, '[spare]') && has($notes, 'power value 1'), 'the import reports the kept sections and the kept power value: ' . implode(' | ', $notes));
check(!has($notes, 'generated watch-only MONITOR'), 'a UPS with its own MONITOR line is not reported as generated');
check(nut_mc_validate($c) === [], 'the imported multi-UPS configuration is valid as it is: ' . implode(' | ', nut_mc_validate($c)));
$out = nut_mc_generate($c);
$ups = sections($out['ups.conf']);
check(array_keys($ups) === ['rack', 'spare'] && ($ups['rack']['vendorid'] ?? null) === '051D' && !isset($ups['spare']['vendorid']), 'the imported sections keep their own directives');
check(monitors($out['upsmon.conf']) === ['rack@127.0.0.1' => ['1', 'master'], 'spare@127.0.0.1' => ['1', 'master']], 'the imported MONITOR lines are kept, none is added twice');

// a legacy monitor of localhost is kept, but it does not replace the generated one: standalone upsd listens on 127.0.0.1 only
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable', 'NAME' => 'rack'], [
    'ups.conf' => $legacyUps,
    'upsmon.conf' => "MONITOR rack@127.0.0.1 1 monuser pass master\nMONITOR spare@localhost 1 monuser pass master\n",
]);
check(nut_mc_decode($c['CUSTOM_UPSMON']) === 'MONITOR spare@localhost 1 monuser pass master' && nut_mc_validate($c) === [], 'the localhost monitor is kept as a custom directive: ' . implode(' | ', nut_mc_validate($c)));
check(has($notes, 'generated watch-only MONITOR') && has($notes, 'for [spare]') && has($notes, 'localhost needs both IPv4 and IPv6') && has($notes, 'power value 1') && has($notes, 'may affect shutdown'), 'the import distinguishes the generated watcher from the custom power-bearing monitor: ' . implode(' | ', $notes));
check(monitors(nut_mc_generate($c)['upsmon.conf']) === ['rack@127.0.0.1' => ['1', 'master'], 'spare@127.0.0.1' => ['0', 'slave'], 'spare@localhost' => ['1', 'master']],
    'the UPS stays watched next to the kept localhost line');
// listening on both families makes the same line certain, it replaces the generated one
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable', 'NAME' => 'rack', 'MODE' => 'netserver'], [
    'ups.conf' => $legacyUps,
    'upsmon.conf' => "MONITOR rack@127.0.0.1 1 monuser pass master\nMONITOR spare@localhost 1 monuser pass master\n",
    'upsd.conf' => "LISTEN 0.0.0.0\nLISTEN ::\n",
]);
check($c['LISTEN'] === '0.0.0.0 ::' && !has($notes, 'generated watch-only MONITOR'), 'a localhost monitor is certain when upsd listens on IPv4 and IPv6: ' . implode(' | ', $notes));
check(monitors(nut_mc_generate($c)['upsmon.conf']) === ['rack@127.0.0.1' => ['1', 'master'], 'spare@localhost' => ['1', 'master']], 'it replaces the generated monitor in that case');

// no MONITOR line for the other UPS: it is generated with power value 0
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable', 'NAME' => 'rack'], [
    'ups.conf' => $legacyUps,
    'upsmon.conf' => "MONITOR rack@127.0.0.1 1 monuser pass master\n",
]);
check(has($notes, 'generated watch-only MONITOR') && has($notes, 'for [spare]') && nut_mc_validate($c) === [], 'an unmonitored section receives a generated watcher');
check(monitors(nut_mc_generate($c)['upsmon.conf']) === ['rack@127.0.0.1' => ['1', 'master'], 'spare@127.0.0.1' => ['0', 'slave']], 'an unmonitored section is generated with power value 0');

// a MONITOR line of another UPS on another port is kept as it is, and that UPS is still watched on the default port
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable', 'NAME' => 'rack'], [
    'ups.conf' => $legacyUps,
    'upsmon.conf' => "MONITOR rack@127.0.0.1 1 monuser pass master\nMONITOR spare@127.0.0.1:3494 1 observer obspass slave\n",
]);
check($c['MONUSER'] === 'monuser' && nut_mc_decode($c['CUSTOM_UPSMON']) === 'MONITOR spare@127.0.0.1:3494 1 observer obspass slave',
    'the MONITOR line on another port stays a custom directive, credentials included');
check(has($notes, 'generated watch-only MONITOR') && has($notes, 'for [spare]') && has($notes, 'power value 1') && nut_mc_validate($c) === [], 'the import reports the generated watch line and the kept power value: ' . implode(' | ', $notes));
check(monitors(nut_mc_generate($c)['upsmon.conf']) === ['rack@127.0.0.1' => ['1', 'master'], 'spare@127.0.0.1' => ['0', 'slave'], 'spare@127.0.0.1:3494' => ['1', 'slave']],
    'the UPS is watched on the default port next to the kept line');
// the same line on the default port replaces the generated one
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable', 'NAME' => 'rack'], [
    'ups.conf' => $legacyUps,
    'upsmon.conf' => "MONITOR rack@127.0.0.1 1 monuser pass master\nMONITOR spare@127.0.0.1:3493 0 observer obspass slave\n",
]);
check(!has($notes, 'generated watch-only MONITOR') && monitors(nut_mc_generate($c)['upsmon.conf']) === ['rack@127.0.0.1' => ['1', 'master'], 'spare@127.0.0.1:3493' => ['0', 'slave']],
    'a MONITOR line on the default port is not generated twice');
// alone, the other port's line must not turn into the shutdown UPS's monitor account
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable', 'NAME' => 'rack'], ['ups.conf' => $legacyUps, 'upsmon.conf' => "MONITOR spare@127.0.0.1:3494 0 observer obspass slave\n"]);
check($c['MONUSER'] === 'monuser' && has($notes, 'no MONITOR line was found') && nut_mc_decode($c['CUSTOM_UPSMON']) === 'MONITOR spare@127.0.0.1:3494 0 observer obspass slave',
    'the other port\'s MONITOR line does not replace the shutdown UPS\'s monitor account');

// the settings name no section: the first section takes over and the import says so
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable'], ['ups.conf' => "[a]\ndriver = nutdrv_qx\nport = auto\n[b]\ndriver = nutdrv_qx\nport = auto\n"]);
check($c['NAME'] === 'a' && has($notes, 'has no section') && has($notes, '[b]'), 'a settings name without a section is reported when the first section takes over: ' . implode(' | ', $notes));

// ---- custom credentials survive the import ----------------------------------------------------------------------
// the only MONITOR line watches the additional UPS with its own account: it must not become the shutdown UPS's monitor
$creds = [
    'ups.conf' => "[rack]\n\tdriver = usbhid-ups\n\tport = auto\n[spare]\n\tdriver = nutdrv_qx\n\tport = auto\n",
    'upsmon.conf' => "MONITOR spare@127.0.0.1 0 observer obspass slave\n",
    'upsd.users' => "[monuser]\n\tpassword = monpass\n\tupsmon master\n[observer]\n\tpassword = obspass\n\tupsmon slave\n",
];
[$c, $notes] = nut_mc_import(['MANUAL' => 'enable', 'NAME' => 'rack'], $creds);
check($c['MONUSER'] === 'monuser' && nut_mc_decode($c['MONPASS']) === 'monpass' && has($notes, 'no MONITOR line was found'), 'the additional UPS\'s MONITOR line does not replace the shutdown UPS\'s monitor account');
check(nut_mc_decode($c['CUSTOM_UPSMON']) === 'MONITOR spare@127.0.0.1 0 observer obspass slave', 'the additional UPS keeps its own MONITOR line');
check(nut_mc_decode($c['CUSTOM_USERS']) === "[observer]\npassword = obspass\nupsmon slave", 'standalone mode keeps the account that MONITOR line logs in with');
check(nut_mc_validate($c) === [], 'the imported accounts are valid: ' . implode(' | ', nut_mc_validate($c)));
$out = nut_mc_generate($c);
check(strpos($out['upsmon.conf'], "MONITOR spare@127.0.0.1 0 observer obspass slave\n") !== false && strpos($out['upsmon.conf'], 'MONITOR spare@127.0.0.1 0 monuser') === false,
    'the custom credentials are written and no second monitor is generated');
check(strpos($out['upsd.users'], "[observer]\npassword = obspass\nupsmon slave\n") !== false, 'the account exists in the generated upsd.users');
check(monitors($out['upsmon.conf']) === ['rack@127.0.0.1' => ['1', 'master'], 'spare@127.0.0.1' => ['0', 'slave']], 'the shutdown UPS keeps power value 1, the other stays a watch-only slave');
// netserver mode generates the slave account, so the same account moves into the settings
[$c] = nut_mc_import(['MANUAL' => 'enable', 'NAME' => 'rack', 'MODE' => 'netserver'], $creds);
check($c['SLAVEUSER'] === 'observer' && nut_mc_decode($c['SLAVEPASS']) === 'obspass' && $c['CUSTOM_USERS'] === '', 'netserver mode takes the account into the slave settings');
check(nut_mc_validate($c) === [] && strpos(nut_mc_generate($c)['upsd.users'], "[observer]\n\tpassword = \"obspass\"\n\tupsmon slave\n") !== false, 'netserver mode generates the account the MONITOR line logs in with');

// ---- Auto Config keeps the additional sections ------------------------------------------------------------------
$scanned = array_merge($defaults, ['CUSTOM_UPS' => $b("# the UPS\nvendorid = 0000\n# more\n$spare")]);
[$c, $msgs] = nut_mc_apply_scan($scanned, "[nutdev1]\n\tdriver = \"usbhid-ups\"\n\tport = \"auto\"\n\tvendorid = \"0764\"\n");
check(nut_mc_decode($c['CUSTOM_UPS']) === "vendorid = \"0764\"\n[spare]\ndriver = nutdrv_qx\nport = auto", 'the scan replaces the UPS directives and keeps the additional sections');
check(has($msgs, 'additional UPS sections') && nut_mc_validate($c) === [], 'the scan reports the kept sections and stays valid');
[$c] = nut_mc_apply_scan(array_merge($defaults, ['CUSTOM_UPS' => $b($spare)]), "[nutdev1]\n\tdriver = \"usbhid-ups\"\n\tport = \"auto\"\n");
check(nut_mc_decode($c['CUSTOM_UPS']) === trim($spare), 'a scan without extra details still keeps the additional sections');

// ---- deployment -------------------------------------------------------------------------------------------------
$dir = sys_get_temp_dir() . '/nut-multi-' . bin2hex(random_bytes(12));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create test sandbox');
nut_mc_write_cfg("$dir/nut3.cfg", $multi);
check(nut_mc_deploy($dir, $messages), 'multi-UPS settings are deployed: ' . implode(' | ', $messages));
check(array_keys(monitors(file_get_contents("$dir/ups/upsmon.conf"))) === ['ups@127.0.0.1', 'spare@127.0.0.1', 'third@127.0.0.1']
    && array_keys(sections(file_get_contents("$dir/ups/ups.conf"))) === ['ups', 'spare', 'third'], 'deployed files hold every UPS and its monitor');
exec('rm -rf ' . escapeshellarg($dir));

// a previous multi-UPS installation: imported, reviewed, then generated
$dir = sys_get_temp_dir() . '/nut-multi-legacy-' . bin2hex(random_bytes(12));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create test sandbox');
mkdir("$dir/ups", 0700);
file_put_contents("$dir/nut3.cfg", "MANUAL=\"enable\"\nNAME=\"rack\"\n");
file_put_contents("$dir/ups/ups.conf", $legacyUps);
file_put_contents("$dir/ups/upsmon.conf", "MONITOR rack@127.0.0.1 1 monuser pass master\n");
check(nut_mc_deploy($dir, $messages) && nut_mc_pending($dir) !== null && file_get_contents("$dir/ups/ups.conf") === $legacyUps, 'a previous multi-UPS configuration is imported and stays in use until reviewed');
check(nut_mc_accept($dir) && nut_mc_deploy($dir, $messages), 'the imported multi-UPS configuration can be applied: ' . implode(' | ', $messages));
check(array_keys(sections(file_get_contents("$dir/ups/ups.conf"))) === ['rack', 'spare']
    && monitors(file_get_contents("$dir/ups/upsmon.conf")) === ['rack@127.0.0.1' => ['1', 'master'], 'spare@127.0.0.1' => ['0', 'slave']], 'the applied files keep both UPSes, only the shutdown UPS counts');
exec('rm -rf ' . escapeshellarg($dir));

if ($failures) {
    fwrite(STDERR, "$failures check(s) failed.\n");
    exit(1);
}
echo "David multi-UPS integration passed.\n";
