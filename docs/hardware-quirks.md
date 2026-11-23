# Hardware and Driver Quirks

Some UPS models have firmware quirks that produce false status flags or erratic readings under NUT. This page collects known hardware- and driver-specific workarounds for NUT v3.

Unless noted otherwise, add UPS-specific driver options under **NUT Settings → Custom ups.conf (UPS section) Directives** (stop NUT first, click **Apply**, then start NUT). Driver options introduced in NUT 2.8.x are not supported by the legacy NUT 2.7.4 backend; using them on 2.7.4 will prevent the driver from starting.

## APC Back-UPS BX series (`BX750MI`, `BX950MI`, `BX1200MI`, `BX1600MI`, `BX2200MI`) — `usbhid-ups`

**Symptom:** Periodic spurious `LOWBATT` (`LB`) alerts (and occasionally `REPLBATT` (`RB`) or `OL DISCHRG`) while the UPS is online (`OL`) at full charge. Tracked upstream in [networkupstools/nut#2347](https://github.com/networkupstools/nut/issues/2347) and [networkupstools/nut#3623](https://github.com/networkupstools/nut/issues/3623).

**Plugin behavior:** NUT v3 automatically suppresses `LOWBATT` Unraid alerts that clear within 5 seconds while the UPS stays online (`OL`). However, `upsmon` syslog entries and remote NUT clients still receive the raw `LB` flag unless it is filtered in the driver.

**Driver workaround (NUT 2.8.x `usbhid-ups`):** Add the following lines to **Custom ups.conf (UPS section) Directives**:

```ini
lbrb_log_delay_sec = 4
lbrb_log_delay_without_calibrating
```

- `lbrb_log_delay_sec = 4` ignores `LB` / `RB` flags while online unless they persist for at least 4 seconds.
- `lbrb_log_delay_without_calibrating` applies that delay even though the BX series does not report itself as calibrating.

If blips on your unit last longer than 4 seconds, increase `lbrb_log_delay_sec` (for example to `5` or `10`). If the hardware `LB` flag remains unreliable, replace those lines with software thresholds instead:

```ini
ignorelb
override.battery.charge.low = 20
```
