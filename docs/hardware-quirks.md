# Hardware and Driver Quirks

Some UPS models have firmware quirks that produce false status flags or erratic readings under NUT. NUT v3 maintains a built-in list of hardware- and driver-specific workarounds in `include/nut_quirks.php`.

When a matching UPS and driver/backend are detected, applicable workarounds appear under **NUT Settings → Hardware Quirks**, enabled by default. You can opt out of any workaround individually by unchecking it (and click its info icon to see what it does and which `ups.conf` directives it adds). Any directive you write in **Custom ups.conf (UPS section) Directives** overrides the corresponding quirk directive. Driver options introduced in NUT 2.8.x are skipped on the legacy NUT 2.7.4 backend.

## APC Back-UPS BX series (`BX750MI`, `BX950MI`, `BX1200MI`, `BX1600MI`, `BX2200MI`) — `usbhid-ups`

**Quirk ID:** `apc_bx_mi_lbrb` (matched automatically on `usbhid-ups` with NUT 2.8.x for vendor `051d` and model matching `BX…MI`).

**Symptom:** Periodic spurious `LOWBATT` (`LB`) alerts (and occasionally `REPLBATT` (`RB`) or `OL DISCHRG`) while the UPS is online (`OL`) at full charge. Tracked upstream in [networkupstools/nut#2347](https://github.com/networkupstools/nut/issues/2347) and [networkupstools/nut#3623](https://github.com/networkupstools/nut/issues/3623).

**Plugin behavior:** NUT v3 automatically suppresses `LOWBATT` Unraid alerts, wall messages, and `LOWBATT` hooks that clear within 5 seconds while the UPS stays online (`OL`). In addition, when the `apc_bx_mi_lbrb` quirk is enabled (the default for matching hardware), NUT v3 adds the driver workaround below to `ups.conf` so `upsmon` syslog entries and remote NUT clients also stop seeing brief `LB` blips.

**Directives applied (`ups.conf`, NUT 2.8.x `usbhid-ups`):**

```ini
lbrb_log_delay_sec = 4
lbrb_log_delay_without_calibrating
```

- `lbrb_log_delay_sec = 4` delays reporting `LB` (and `RB` while `LB` is also active) until it persists for at least 4 seconds; a standalone `OL RB` without `LB` is still reported immediately, so this does not suppress every transient `REPLBATT` alert.
- `lbrb_log_delay_without_calibrating` applies that delay even though the BX series does not report itself as calibrating (keep the delay short, since with this flag it also delays a genuine low-battery transition).

If blips on your unit last slightly longer than 4 seconds, override `lbrb_log_delay_sec` in **Custom ups.conf (UPS section) Directives** (for example `lbrb_log_delay_sec = 5` or `10`). If the hardware `LB` flag remains unreliable, disable the quirk and use software thresholds in **Custom ups.conf (UPS section) Directives** instead. With `ignorelb`, NUT ignores the UPS's hardware low-battery flag completely and relies solely on the software threshold, so verify that `battery.charge` is reported accurately and choose a threshold that leaves enough time for a clean shutdown:

```ini
ignorelb
override.battery.charge.low = 20
```
