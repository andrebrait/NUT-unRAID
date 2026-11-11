// @ts-check
const fs = require('node:fs');
const path = require('node:path');
const {
  test, expect, root, sudo, b64, setCfg, flashChecksums, upsFiles, startUpsmon, settled, apply, applyRejected,
  WORK, REPO, FLASH, FIXTURES, SETTINGS, NUT_FILES, MARK, BACKEND,
} = require('./helpers');

const field = (page, key) => page.locator(`textarea[data-field="${key}"]`);

test('Unraid\'s installpkg runs the plugin package\'s install script', () => {
  // harness.py builds the package as package.yml does and installs it with the root's upgradepkg; installpkg
  // skips doinst.sh (and the description) when the members are named "./install/..." instead of "install/..."
  const log = sudo(['cat', path.join(WORK, 'install.log')]);
  expect(log).toMatch(/Executing install script for nut-plugin-/);
  expect(log).toContain('# NUT v3 (Network UPS Tools)');
});

test.describe('NUT Settings', () => {
  test('loads without JavaScript errors and labels the backends for Unraid 7.3.4+', async ({ page }) => {
    await page.goto(SETTINGS);
    await expect(page.locator('#nut-settings')).toBeVisible();
    await expect(page.locator('#BACKEND option[value="default"]')).toHaveText('default (2.8.5+ with security fixes)');
    await expect(page.locator('#BACKEND option[value="preview"]')).toHaveText('preview (same as default)');
    await expect(page.locator('dt:text-is("Network UPS Tools Backend:") + dd')).toHaveText(BACKEND);
    await settled(page);
  });

  test('writes custom directives after the managed lines of each file', async ({ page }) => {
    const custom = {
      CUSTOM_NUT: 'ALLOW_NO_DEVICE=true',
      CUSTOM_UPSGLOBAL: 'maxretry = 3',
      CUSTOM_UPS: 'vendorid = 051d',
      CUSTOM_UPSD: 'MAXAGE 25',
      CUSTOM_USERS: '[homeassistant]\n\tpassword = hapass\n\tupsmon slave',
      CUSTOM_UPSMON: 'NOTIFYFLAG LOWBATT SYSLOG+EXEC',
      CUSTOM_UPSSCHED: 'CMDSCRIPT /etc/nut/upssched-cmd',
    };
    await page.goto(SETTINGS);
    for (const [key, text] of Object.entries(custom)) {
      await expect(field(page, key)).toBeVisible();
      await field(page, key).fill(text);
    }
    await apply(page);

    const files = upsFiles();
    for (const name of NUT_FILES) expect(files[name].startsWith(MARK), name).toBe(true);
    const block = (key) => `# Custom directives\n${custom[key]}\n`;
    expect(files['nut.conf']).toMatch(new RegExp(`\nMODE=standalone\n${esc(block('CUSTOM_NUT'))}$`));
    expect(files['ups.conf']).toMatch(new RegExp(
      `\n# Custom global directives\nmaxretry = 3\n\n\\[ups\\]\n\tdriver = usbhid-ups\n\tport = "auto"\n${esc(block('CUSTOM_UPS'))}$`));
    expect(files['upsd.conf']).toMatch(new RegExp(`\nLISTEN 127\\.0\\.0\\.1\n${esc(block('CUSTOM_UPSD'))}$`));
    expect(files['upsd.users']).toMatch(new RegExp(`\n\tupsmon master\n${esc(block('CUSTOM_USERS'))}$`));
    expect(files['upsmon.conf']).toMatch(new RegExp(`\nNOTIFYFLAG REPLBATT SYSLOG\\+EXEC\n${esc(block('CUSTOM_UPSMON'))}$`));
    expect(files['upssched.conf']).toMatch(new RegExp(`\n${esc(block('CUSTOM_UPSSCHED'))}$`));

    // the reloaded page shows what was saved
    for (const [key, text] of Object.entries(custom)) await expect(field(page, key)).toHaveValue(`${text}\n`);
  });

  test('rejects a custom directive that overrides a managed one and writes nothing', async ({ page }) => {
    const conflict = 'Custom nut.conf directives, line 1: "MODE" is set from the settings above and cannot be overridden here.';
    await page.goto(SETTINGS);
    const before = flashChecksums();
    await field(page, 'CUSTOM_NUT').fill('MODE=standalone');
    await applyRejected(page, conflict);
    expect(flashChecksums()).toEqual(before);

    // /update.php refuses the same settings when the page's own check is bypassed
    let output = '';
    await page.route('**/update.php', async (route) => {
      const response = await route.fetch();
      output = await response.text();
      await route.fulfill({ response, body: output });
    });
    const reloaded = page.waitForEvent('load', { timeout: 90_000 });
    await page.evaluate(() => {
      /** @type {any} */ (window).encData();
      /** @type {HTMLFormElement} */ (document.getElementById('nut-settings')).submit();
    });
    await reloaded;
    expect(output).toContain('NUT settings were NOT saved');
    expect(output).toContain(conflict.replace(/"/g, '\\"'));
    expect(flashChecksums()).toEqual(before);
  });

  test('rejects a password with a space', async ({ page }) => {
    await page.goto(SETTINGS);
    const before = flashChecksums();
    await page.locator('#MONPASS').fill('bad pass');
    await applyRejected(page, 'NUT Monitor Password: use printable ASCII characters without spaces');
    await expect(page.locator('#monpass-htext')).toBeVisible();
    expect(flashChecksums()).toEqual(before);
  });

  test('rejects a UPS name with "/" and accepts "ippon_ups"', async ({ page }) => {
    await page.goto(SETTINGS);
    const before = flashChecksums();
    const name = page.locator('#NAME');
    // typing is filtered by the field's mask...
    await name.fill('');
    await name.pressSequentially('my/ups');
    await expect(name).toHaveValue('myups');
    // ...and a value that gets past it is rejected by the validation
    await name.blur();
    await name.evaluate((input) => { /** @type {HTMLInputElement} */ (input).value = 'my/ups'; });
    await applyRejected(page, 'UPS Name: use 1-64 letters, digits, "_", "." or "-".');
    expect(flashChecksums()).toEqual(before);

    await name.fill('ippon_ups');
    await expect(name).toHaveValue('ippon_ups');
    await apply(page);
    await expect(name).toHaveValue('ippon_ups');
    const files = upsFiles();
    expect(files['ups.conf']).toContain('\n[ippon_ups]\n\tdriver = usbhid-ups\n');
    expect(files['upsmon.conf']).toContain('\nMONITOR ippon_ups@127.0.0.1 1 monuser ');
  });
});

test.describe('Import of a previous configuration', () => {
  /** Put a hand-made configuration on the flash drive and run what rc.nut runs on start. */
  function stageLegacy(name) {
    const dir = path.join(REPO, 'tests/fixtures', name);
    for (const file of fs.readdirSync(dir)) {
      root.write(file === 'nut3.cfg' ? `${FLASH}/nut3.cfg` : `${FLASH}/ups/${file}`, fs.readFileSync(path.join(dir, file), 'utf8'));
    }
    root.sh('/usr/bin/php /usr/local/emhttp/plugins/nut3/scripts/managed_config write');
    expect(root.exists(`${FLASH}/import-report.json`)).toBe(true);
  }
  const banner = (page) => page.locator('div.red-text', { hasText: 'Your previous NUT configuration was imported' });
  const problems = (page) => page.locator('div.red-text', { hasText: 'These settings cannot be applied' });

  test('shows the import banner and Apply accepts the imported settings as they are', async ({ page }) => {
    stageLegacy('legacy-default');
    const legacyUsers = root.read(`${FLASH}/ups/upsd.users`);
    await page.goto(SETTINGS);
    await expect(banner(page)).toBeVisible();
    await expect(banner(page)).toContainText('default admin user');
    await expect(banner(page)).toContainText('Standalone mode now keeps upsd local');
    await expect(problems(page)).toHaveCount(0);

    // Unraid disables Apply until a field changes; a pending import must be applicable as it is
    await expect(page.locator('#btnApply')).toBeEnabled();
    await apply(page);
    await expect(banner(page)).toHaveCount(0);
    expect(root.exists(`${FLASH}/import-report.json`)).toBe(false);
    const files = upsFiles();
    for (const name of NUT_FILES) expect(files[name].startsWith(MARK), name).toBe(true);
    expect(root.read(`${FLASH}/backup/upsd.users.bak`)).toBe(legacyUsers);
    expect(root.read(`${FLASH}/backup/legacy/upsd.users`)).toBe(legacyUsers);
  });

  test('lists import conflicts and applies once they are resolved', async ({ page }) => {
    stageLegacy('legacy-custom');
    await page.goto(SETTINGS);
    await expect(banner(page)).toContainText('more than one UPS section');
    await expect(banner(page)).toContainText('Manual configuration mode');
    await expect(problems(page).locator('li')).toHaveCount(6);

    // the user fixes one conflict; Apply still reports the others and writes nothing
    await field(page, 'CUSTOM_UPS').fill('vendorid = "051D"\ndesc = "Rack UPS 1"');
    const before = flashChecksums();
    await applyRejected(page, 'user [remote] is generated from the settings above', '"NOTIFYFLAG" is set from the settings above');
    expect(flashChecksums()).toEqual(before);

    await field(page, 'CUSTOM_USERS').fill('[homeassistant]\npassword = ha\nupsmon slave');
    await field(page, 'CUSTOM_UPSMON').fill('NOTIFYFLAG LOWBATT SYSLOG+EXEC\nFINALDELAY 10');
    await apply(page);
    await expect(banner(page)).toHaveCount(0);
    await expect(problems(page)).toHaveCount(0);
    expect(root.exists(`${FLASH}/import-report.json`)).toBe(false);
    const files = upsFiles();
    expect(files['ups.conf']).toMatch(/\[rack\]\n[^[]*# Custom directives\nvendorid = "051D"\ndesc = "Rack UPS 1"\n$/);
    expect(files['upsd.users']).toMatch(/# Custom directives\n\[homeassistant\]\npassword = ha\nupsmon slave\n$/);
    expect(files['upsmon.conf']).toMatch(/# Custom directives\nNOTIFYFLAG LOWBATT SYSLOG\+EXEC\nFINALDELAY 10\n$/);
  });
});

test.describe('NUT Commands', () => {
  test('without an admin user the commands are disabled', async ({ page }) => {
    startUpsmon();
    await page.goto('/Settings/NUTcommands');
    await expect(page.locator('#nutcmds .red-text')).toContainText('No NUT Admin credentials are configured, so commands are disabled.');
    await expect(page.locator('#nutcmdstbl tbody tr')).toHaveCount(3);
    for (const box of await page.locator('.nutcmdconfirmbox').all()) await expect(box).toBeDisabled();
  });

  test('with an admin user the commands are listed and sent with the stored credentials', async ({ page }) => {
    setCfg({ ADMINUSER: 'upsadmin', ADMINPASS: b64('Adm1nPass') });
    startUpsmon();
    await page.goto('/Settings/NUTcommands');
    await expect(page.locator('#nutcmds')).toContainText('Commands are sent as the NUT Admin user upsadmin');
    const rows = page.locator('#nutcmdstbl tbody tr');
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(0)).toContainText('beeper.disable');
    await expect(rows.nth(0)).toContainText('Disable the UPS beeper');

    const quickTest = rows.filter({ hasText: 'test.battery.start.quick' });
    await quickTest.locator('.nutcmdconfirmbox').check();
    await quickTest.locator('.nutcmdbutton').click();
    await expect(page.locator('.sweet-alert')).toContainText('The backend driver has responded with the following message');
    expect(root.read(`${FIXTURES}/upscmd.log`)).toContain('-u upsadmin -p Adm1nPass ups@127.0.0.1 test.battery.start.quick\n');
    expect(await page.content()).not.toContain('Adm1nPass');
  });
});

test('UPS data is shown as text in the status table, the details and the footer', async ({ page }) => {
  const payload = (n) => `<img src=x onerror=window.__nutXss=${n}>`;
  root.write(`${FIXTURES}/upsc.txt`, [
    'battery.charge: 45',
    'battery.runtime: 600',
    `ups.mfr: ${payload(1)}`,
    'ups.model: <script>window.__nutXss=2</script>',
    `ups.status: OB DISCHRG ${payload(3)}`,
    `ups.alarm: ${payload(4)}`,
    'ups.load: 50',
    'ups.realpower.nominal: 900',
  ].join('\n') + '\n');
  setCfg({ FOOTER: 'enable' });
  startUpsmon();
  const injected = [];
  page.on('request', (request) => { if (new URL(request.url()).pathname.endsWith('/x')) injected.push(request.url()); });

  await page.goto(SETTINGS);
  await expect(page.locator('#nut_status')).toContainText(payload(1));
  await expect(page.locator('#nut_status')).toContainText('<script>window.__nutXss=2</script>');
  await expect(page.locator('#nut_summary')).toContainText('<img');
  await expect(page.locator('#nut_summary .nutalarmrow')).toContainText(payload(4));

  await page.locator('#nut_battery').hover();
  await expect(page.locator('.tooltipster-base')).toContainText('<img');
  await page.mouse.move(0, 0);
  await page.locator('#nut_alarm').hover();
  await expect(page.locator('.tooltipster-base').last()).toContainText(payload(4));
  await settled(page);

  const injectedElements = '#nut_summary img, #nut_status img, #nut_status script, #nut_footer img, .tooltipster-base img';
  await expect(page.locator(injectedElements)).toHaveCount(0);
  expect(await page.evaluate(() => /** @type {any} */ (window).__nutXss)).toBeUndefined();
  expect(injected).toEqual([]);
});

function esc(text) {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
