# Managed NUT configuration

Since 2026.11.12 the plugin owns the NUT configuration, in the style of pfSense. It assembles complete
`nut.conf`, `ups.conf`, `upsd.conf`, `upsd.users`, `upsmon.conf` and `upssched.conf` files from its settings
on every write, instead of patching numbered lines with `sed`. Users who need more add free-text custom
directives, one field per file. The manual configuration modes (`MANUAL=enable|onlyups`) and the raw
configuration editor are gone.

All of it lives in one PHP file, `include/nut_managed.php`. Callers:

- `scripts/managed_config write`, run by `rc.nut write_config` (so on every start, stop and restart) and by
  the package `doinst.sh`.
- `include/nut_validate.php`, the `#include` hook of the settings form. Unraid's `/update.php` loads it
  before writing `nut-dw.cfg`. The settings page also calls it over AJAX to show errors before submitting.
- `scripts/managed_config scan`, run by Auto Config.

## Settings model

Settings stay in `/boot/config/plugins/nut-dw/nut-dw.cfg` as `KEY="value"` lines, written by `/update.php`
and sourced by the bash scripts. Values that `default.cfg` lacks fall back to its defaults.

| File | Generated from | Custom field |
| --- | --- | --- |
| nut.conf | `MODE` (`slave` is written as `netclient`) | `CUSTOM_NUT` |
| ups.conf | `NAME`, `DRIVER`/`SERIAL`, `PORT`, `DEBLEVEL`, and for snmp-ups also `POLL`, `COMMUNITY`, `SNMPVER`, `SNMPMIB` | `CUSTOM_UPSGLOBAL` (before the section), `CUSTOM_UPS` (inside it) |
| upsd.conf | `LISTEN` in netserver mode, otherwise `127.0.0.1` | `CUSTOM_UPSD` |
| upsd.users | `MONUSER`/`MONPASS` (upsmon master); `SLAVEUSER`/`SLAVEPASS` (upsmon slave, netserver only); `ADMINUSER`/`ADMINPASS` (SET, FSD, all instant commands, only if configured) | `CUSTOM_USERS` |
| upsmon.conf | `NAME@IPADDR`, `MONUSER`/`MONPASS`, `UPSKILL` (power-down flag), `DEBLEVELMON`, plus the fixed shutdown command, notify command and notify flags | `CUSTOM_UPSMON` |
| upssched.conf | nothing | `CUSTOM_UPSSCHED` |

Slave mode runs neither a driver nor upsd. In that mode `ups.conf`, `upsd.conf` and `upsd.users` contain
only the header, and their custom fields are hidden and ignored.

There is no default admin user. `admin/adminpass` is gone. The admin user appears in `upsd.users` only after
the user enters both a username and a password. The NUT Commands page sends commands with these stored
credentials, which never reach the browser. Without credentials, the page is disabled with an explanation.

upsmon roles stay `master`/`slave`. NUT 2.8.x also accepts `primary`/`secondary`, but the still-offered
legacy 2.7.4 backend accepts only the old words, and 2.8.x prints no deprecation warning for them.

## Generation rules

Each file opens with a header comment that marks it as generated. The managed directives follow, then the
custom field under a `# Custom directives` comment. Custom lines are appended verbatim, after carriage
returns and trailing blank lines are stripped.

A custom directive must not override a managed one. Managed keys are whatever the generator emits for the
current settings: the generator runs once without custom text, its output is tokenised, and each custom line
is checked against those keys. Keys compare case-insensitively. The upsmon directives `MONITOR`,
`NOTIFYFLAG` and `NOTIFYMSG` are keyed together with their target, so `NOTIFYFLAG LOWBATT` is allowed and
`NOTIFYFLAG ONBATT` is a conflict. A directive the settings do not currently emit, for example
`debug_min` while the driver debug level is "Default", may go in the custom field. Further rules:

- The `ups.conf` fields may not open another section, because only one UPS is supported.
- The `upsd.users` field must start with a `[user]` header. Otherwise its directives would join the last
  generated user. It may not redefine a generated user.
- Custom text must be printable ASCII and must tokenise cleanly. NUT drops other bytes and rejects
  unterminated quotes and `#` inside quotes.

## Escaping

Free-text values (port, SNMP community, passwords) are written in double quotes, with `\`, `"` and `#`
escaped by a backslash, which matches NUT's `parseconf` rules. The tokenizer in `nut_managed.php` copies the
same state machine. The tests check that values round-trip.

Validation runs on every value in the settings file. Line breaks, control characters, `"`, `\`, `$` and
backticks are rejected, because the file is sourced by bash and parsed by `parse_ini_file`. Passwords and the
multi-line custom fields are stored base64-encoded, so they avoid both concerns.

Passwords must also be printable ASCII without spaces, `#`, `"`, `\` or `=`. The config files could quote
these characters, but upsmon and upscmd send `PASSWORD <pw>` unquoted over the network protocol, and upsd
would split or truncate the value. Names (UPS, users) are limited to `[A-Za-z0-9_.-]`. A forum report showed
`ippon_ups` being truncated, so underscores and dots are now accepted.

## Files and permissions

The generator writes to `/boot/config/plugins/nut-dw/ups/`, the existing flash copy, with temp-file plus
rename writes. A file whose content changed is first copied to `/boot/config/plugins/nut-dw/backup/<file>.bak`.
`rc.nut` then copies the whole flash directory to `/etc/nut` under `umask 027` and sets `root:nut` and `0640`
(the directory `0750`), as before. User files such as `xnut-notify-hooks.sh` and `xnut-nospam.conf` also
live in that flash directory. With the editor gone, users edit them there.

## Migration and import

On the first `managed_config write` after an upgrade, the import runs when the flash directory holds NUT
files without the generated header and no import report exists:

1. The old files and `nut-dw.cfg` are copied once to `backup/legacy/`.
2. Each file is tokenised. Recognised directives become settings. Everything else, comments excepted, goes
   verbatim into the custom field of that file. A recognised directive that repeats, or whose value the
   settings cannot express (an unknown MIB, `SHUTDOWNCMD` other than poweroff, `NOTIFYFLAG ONBATT` with other
   flags), also goes into the custom field.
3. Legacy `write_config` rewrote the GUI-managed lines from the settings at every start. For the managed
   values, the old settings therefore stay authoritative, unless the old manual mode left that file alone:
   `onlyups` takes the `ups.conf` values from the file, and `enable` takes everything from the files. Admin
   user, listen addresses and custom content always come from the files. The mode always comes from the
   settings, because `rc.nut` started services by the settings' mode and ignored `nut.conf`.
4. Special cases. `admin/adminpass` with exactly the default rights is dropped. Another user that has only
   the admin rights becomes `ADMINUSER`. Extra UPS sections and redefined users land in the custom fields.
   In standalone mode a note explains that upsd is now local-only and no longer has the slave user. In
   non-slave modes `IPADDR` becomes `127.0.0.1`.
5. The new settings are written to `nut-dw.cfg`, with `MANUAL` removed, and the notes go to
   `import-report.json`.

Whatever cannot be expressed lands in a custom field, where validation reports it as a conflict. The import
loses nothing, and the user resolves every conflict by editing a field.

## Conflict reporting and activation blocking

While `import-report.json` exists without `accepted`, the import is pending. `managed_config write` then
leaves the old files alone and returns success, so NUT keeps running, and keeps starting after reboots, with
the configuration it had before the upgrade. The settings page shows a red banner with the import notes, and
lists every current validation error on the page.

Applying the settings form with NUT stopped (the fields are disabled while NUT runs, as before) sends them
through validation. On errors, `nut_validate.php` sets `$save = false` and removes `#command`, so
`/update.php` neither writes the settings nor runs the start or stop script, and the page shows the errors in
a dialog. A successful save marks the report `accepted`. The following `write_config` generates the files,
which pushes the old ones into `backup/*.bak`, and deletes the report.

Outside an import, invalid settings, for example after a manual edit of `nut-dw.cfg`, make
`managed_config write` exit 1 without touching any file. `rc.nut start`, `restart` and `reload` then stop:
`start` marks the start as failed for the GUI, and `restart` keeps the running services. Saving with the
service set to stop is never blocked, because the settings are not posted while NUT runs.

## Rollback

`backup/legacy/` holds the configuration exactly as it was before the import, and `backup/*.bak` holds the
previous generated files. To roll back to an older plugin version:

1. Install the older plugin.
2. Copy `backup/legacy/nut-dw.cfg` over `nut-dw.cfg` and `backup/legacy/*` into `ups/`.
3. Restart NUT.

## Other changes in this release

- The rc.6 hooks (`restart_udev` after the last `remount,ro` line, `shutdown` after `# Now halt`) are
  checked after insertion. If an anchor is missing, `rc.nut` logs an error to the console and to syslog
  instead of silently skipping the UPS power-off.
- Auto Config no longer rewrites `ups.conf`. It applies the first detected device to the driver and port
  settings and puts the remaining detected options into `CUSTOM_UPS`.
