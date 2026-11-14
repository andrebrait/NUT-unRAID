// @ts-check
// NUT Details (data file link, failed requests) and the display settings (nominal power overrides).
const { test, expect, root, startUpsmon, settled, FLASH, SETTINGS } = require('./helpers');

const STATUS = /\/plugins\/nut3\/include\/nut_status\.php/;
const DEV_LINK = '/plugins/nut3/include/nut_status.php?diagsave=true&all=true';

/** The UPS links of the "All UPS devices" table, as nut_status.php renders them (names escaped for the attribute). */
const upsRows = (names) => names
  .map((ups) => `<tr><td><a href="#" class="nut-upssel" data-ups="${ups.replace(/&/g, '&amp;')}">${ups.replace(/&/g, '&amp;')}</a></td></tr>`)
  .join('');

/** A nut_status.php answer for the details page: the variables table says which UPS was asked for. */
const answer = (ups, names) => JSON.stringify({
  success: { response: '<tr><td>summary</td></tr>', allvars: `<tr><td colspan="4">variables of ${ups || 'the GUI UPS'}</td></tr>`, upsrows: upsRows(names) },
});

test.describe('NUT Details', () => {
  test('the UPS data file link exports the UPS that is selected, URL-encoded', async ({ page }) => {
    startUpsmon(); // the link is only offered while NUT runs
    const names = ['alpha', 'o&k x'];
    await page.route(STATUS, async (route) => {
      const params = new URL(route.request().url()).searchParams;
      if (params.get('all') !== 'true' || params.has('diagsave')) return route.fallback(); // the footer's and the file's own requests
      await route.fulfill({ contentType: 'application/json', body: answer(params.get('ups'), names) });
    });
    await page.goto(SETTINGS);
    const link = page.locator('#nut_dev_link');
    await expect(page.locator('#nut_status')).toContainText('variables of the GUI UPS');
    await expect(link).toHaveAttribute('href', DEV_LINK);

    await page.locator('.nut-upssel[data-ups="alpha"]').click();
    await expect(page.locator('#nut_status')).toContainText('variables of alpha');
    await expect(link).toHaveAttribute('href', `${DEV_LINK}&ups=alpha`);

    await page.locator('.nut-upssel[data-ups="o&k x"]').click();
    await expect(page.locator('#nut_status')).toContainText('variables of o&k x');
    await expect(link).toHaveAttribute('href', `${DEV_LINK}&ups=o%26k%20x`);

    // the endpoint takes the link as it is
    const file = await page.request.get(/** @type {string} */ (await link.getAttribute('href')));
    expect(file.status()).toBe(200);
    expect(file.headers()['content-disposition']).toContain('nut-ups.dev');
    await settled(page);
  });

  test('a request for the selected UPS that fails does not leave the previous UPS on screen', async ({ page }) => {
    const names = ['alpha', 'broken', 'slowbad'];
    let release;
    const released = new Promise((resolve) => { release = resolve; });
    await page.route(STATUS, async (route) => {
      const params = new URL(route.request().url()).searchParams;
      if (params.get('all') !== 'true') return route.fallback();
      const ups = params.get('ups');
      // 200 with a body that is no JSON: jQuery fails the request, the success callback never runs
      if (ups === 'broken') return route.fulfill({ contentType: 'text/html', body: '<html>gateway error</html>' });
      if (ups === 'slowbad') {
        await released;
        return route.fulfill({ contentType: 'text/html', body: '<html>gateway error</html>' });
      }
      await route.fulfill({ contentType: 'application/json', body: answer(ups, names) });
    });
    await page.goto(SETTINGS);
    const variables = page.locator('#nut_status');
    const summary = page.locator('#nut_summary');
    await page.locator('.nut-upssel[data-ups="alpha"]').click();
    await expect(variables).toContainText('variables of alpha');

    await page.locator('.nut-upssel[data-ups="broken"]').click();
    await expect(variables).toContainText('Failed to retrieve information');
    await expect(summary).toContainText('Failed to retrieve information');
    await expect(variables).not.toContainText('variables of alpha');

    // the error is not sticky
    await page.locator('.nut-upssel[data-ups="alpha"]').click();
    await expect(variables).toContainText('variables of alpha');
    await expect(variables).not.toContainText('Failed to retrieve information');

    // a slow failure for a UPS that is no longer selected does not replace the current one
    await page.locator('.nut-upssel[data-ups="slowbad"]').click();
    await page.locator('.nut-upssel[data-ups="alpha"]').click();
    await expect(variables).toContainText('variables of alpha');
    release();
    await settled(page);
    await expect(variables).toContainText('variables of alpha');
    await expect(variables).not.toContainText('Failed to retrieve information');
  });
});

test('negative nominal power overrides can be applied, values beyond +-1000000 are refused', async ({ page }) => {
  const posted = [];
  page.on('request', (request) => { if (request.url().includes('/update.php')) posted.push(request.postData() || ''); });
  await page.goto(SETTINGS);
  await page.locator('#POWER').selectOption('manual');
  await expect(page.locator('#POWER-SETTINGS')).toBeVisible();
  const apply = page.locator('#nut-display input[type="submit"][value="Apply"]');
  const limit = 'Enter a whole number from -1000000 to 1000000.';

  await page.locator('#POWERW').fill('-1000001');
  await page.locator('#POWERVA').fill('1000001');
  await apply.click();
  await expect(page.locator('#err-POWERW')).toHaveText(limit);
  await expect(page.locator('#err-POWERVA')).toHaveText(limit);
  expect(posted).toEqual([]);

  await page.locator('#POWERW').fill('-900');
  await page.locator('#POWERVA').fill('-1500');
  await apply.click();
  await expect.poll(() => posted.length).toBeGreaterThan(0);
  expect(posted[0]).toContain('POWERW=-900');
  expect(posted[0]).toContain('POWERVA=-1500');
  await expect.poll(() => root.read(`${FLASH}/nut3.cfg`)).toContain('POWERW="-900"');
  expect(root.read(`${FLASH}/nut3.cfg`)).toContain('POWERVA="-1500"');
});
