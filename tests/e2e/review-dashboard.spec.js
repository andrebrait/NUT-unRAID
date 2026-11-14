// @ts-check
// The footer and the dashboard tile as a user sees them: nutFooter.page's footer poller and js/nut-dw.js.
const { test, expect, root, setCfg, startUpsmon, FIXTURES, SETTINGS } = require('./helpers');

const upsc = (...lines) => root.write(`${FIXTURES}/upsc.txt`, lines.join('\n') + '\n');

// the footer polls every second (the poll time is the larger of this and Unraid's page refresh setting)
const footerOn = () => {
  setCfg({ FOOTER: 'enable', REFRESH: 'enable', INTERVAL: '1' });
  startUpsmon();
};

for (const motion of ['reduce', 'no-preference']) {
  test(`the footer follows the UPS while the battery icon is charging (prefers-reduced-motion: ${motion})`, async ({ page }) => {
    upsc('battery.charge: 50', 'ups.status: OL CHRG');
    footerOn();
    await page.emulateMedia({ reducedMotion: motion });
    await page.goto(SETTINGS);
    const footer = page.locator('#nut_footer');
    await expect(footer).toContainText('50');

    // the case is only what it claims to be if the icon animates exactly when motion is allowed
    const icon = footer.locator('i.fa-battery-charging');
    await expect(icon).toHaveCount(1);
    const animation = await icon.evaluate((el) => getComputedStyle(el, '::before').animationName);
    if (motion === 'reduce') expect(animation).toBe('none');
    else expect(animation).not.toBe('none');

    upsc('battery.charge: 80', 'ups.status: OL CHRG');
    await expect(footer).toContainText('80', { timeout: 30_000 });
  });
}

test('a footer poll resumed in a visible tab is the only one in flight', async ({ page }) => {
  upsc('battery.charge: 100', 'ups.status: OL');
  footerOn();
  let polls = 0;
  let gate = Promise.resolve();
  let release = () => {};
  await page.route(/\/plugins\/nut3\/include\/nut_footer\.php/, async (route) => {
    polls++;
    await gate;
    await route.continue();
  });
  const idle = () => expect.poll(() => page.evaluate(() => /** @type {any} */ (window).jQuery.active)).toBe(0);
  const tab = (hidden) => page.evaluate((hidden) => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    document.dispatchEvent(new Event('visibilitychange'));
  }, hidden);

  // the page's timers are driven by hand from here on, so the poll interval does not matter
  await page.clock.install();
  await page.goto(SETTINGS);
  await expect(page.locator('#nut_footer')).toContainText('100');
  await page.clock.pauseAt(new Date(Date.now() + 1000));
  await idle();
  const before = polls;

  await tab(true);
  await page.clock.runFor(60_000);
  await page.waitForTimeout(500);
  expect(polls, 'a hidden tab makes no request').toBe(before);

  gate = new Promise((resolve) => { release = () => resolve(undefined); });
  await tab(false);
  await expect.poll(() => polls, { message: 'the tab shows again: the footer polls at once' }).toBe(before + 1);
  await page.clock.runFor(60_000);
  await page.waitForTimeout(500);
  expect(polls, 'no second poll while the resumed one is still waiting for its answer').toBe(before + 1);

  release();
  await idle();
  await page.clock.runFor(60_000);
  await expect.poll(() => polls, { message: 'after the answer the polling carries on' }).toBeGreaterThan(before + 1);
});

// the dashboard tile of Unraid 6.12+ (class) and the legacy one (id) both get the alarm bell
const tiles = [
  { root: 'tblUPSNUTDash', alarm: '<span class="nutalarms"></span>', alarmBox: '.nutalarms' },
  { root: 'db-box1', alarm: '<span id="nutalarms"></span>', alarmBox: '#nutalarms' },
];

for (const { root: id, alarm, alarmBox } of tiles) {
  test(`the dashboard tile #${id} shows alarms and other UPSes, and nothing stale after an error`, async ({ page }) => {
    const cell = (cls, text) => `<td class='${cls}'>${text}</td>`;
    const good = {
      success: {
        response: `<tr>${[
          cell('green-text', 'Online'), cell('green-text', '100&thinsp;%'), cell('green-text', '00:30:30'),
          cell('green-text', '900&thinsp;W'), cell('green-text', '207&thinsp;W'), cell('green-text', '23&thinsp;%'), '<td>-</td>',
        ].join('')}</tr>`,
        upsline: "<span class='nut-oth'><strong>backup</strong> <span class='green-text'>Online 100&thinsp;%</span></span> ",
        alarms: ['Replace battery'],
      },
    };
    await page.goto(SETTINGS);
    await page.evaluate(({ id, alarm }) => /** @type {any} */ (window).jQuery('body').append(
      `<div id="${id}"><span class="nut_status"></span><span class="nut_bcharge"></span><span class="nut_timeleft"></span>`
      + '<span class="nut_nompower"></span><span class="nut_loadpct"></span><div class="nut_others" style="display:none"></div>'
      + `<span>Status: ${alarm}<i class="nutdsicon fa fa-question-circle black-text"></i></span><span class="nutdcc"></span></div>`,
    ), { id, alarm });
    const render = (data) => page.evaluate(({ id, data }) => /** @type {any} */ (window).nutRenderDash(`#${id}`, data), { id, data });
    const tile = page.locator(`#${id}`);
    const others = tile.locator('.nut_others');
    const bell = page.locator(alarmBox);

    await render(good);
    await expect(tile.locator('.nut_status')).toHaveText('Online');
    await expect(others).toBeVisible();
    await expect(others).toContainText('backup');
    await expect(bell).toContainText('UPS alarm active');

    await render({ error: { response: 'boom' } });
    await expect(tile.locator('.nut_status')).toContainText('Error (check logs)');
    await expect(others).toBeHidden();
    await expect(others).toBeEmpty();
    await expect(bell).toBeHidden();
    await expect(bell).toBeEmpty();

    await render(good);
    await expect(others).toContainText('backup');
    await expect(bell).toContainText('UPS alarm active');
  });
}
