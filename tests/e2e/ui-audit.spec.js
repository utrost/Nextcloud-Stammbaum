import { test, expect } from '@playwright/test';
import { login, tree, person, good, grant, key } from './helpers.js';
const uploadFile = {
  name: 'fictional.png',
  mimeType: 'image/png',
  buffer: Buffer.from('fictional selection only'),
};
const mainTabs = (page) => page.getByRole('tablist', { name: 'Tree workspace' });
async function openTree(page, t, view = 'family') {
  await page.goto(`/index.php/apps/stammbaum/#tree=${t.id}&view=${view}`);
  await expect(page.locator('.page-header h1')).toHaveText(t.name);
}
async function answer(page, accept, action) {
  const dialog = page.waitForEvent('dialog');
  const pending = action();
  const d = await dialog;
  expect(d.message()).toMatch(/Discard unsaved changes|Ungespeicherte Änderungen/);
  await (accept ? d.accept() : d.dismiss());
  await pending;
}
async function language(page, value) {
  await page.getByLabel('Language', { exact: true }).selectOption(value);
  await expect.poll(async () => (await good(page, '/preferences')).locale).toBe(value);
}
test.beforeEach(async ({ page }) => {
  await login(page);
  const pref = await good(page, '/preferences');
  if (pref.locale !== 'en') {
    await good(page, '/preferences', 'PUT', { locale: 'en', revision: pref.revision });
    await page.reload();
    await expect(page.locator('#stammbaum-app .brand')).toBeVisible();
  }
});

test('file selections survive declined main and person tab navigation; accepted discard clears them', async ({
  page,
}) => {
  const t = await tree(page),
    p = await person(page, t.id, 'Draft');
  await openTree(page, t);
  for (const name of ['Media', 'Imports']) {
    await mainTabs(page).getByRole('tab', { name, exact: true }).click();
    const file = page.locator('main input[type=file]');
    await file.setInputFiles(name === 'Imports' ? 'tests/fixtures/family.ged' : uploadFile);
    await answer(page, false, () => mainTabs(page).getByRole('tab', { name: 'People', exact: true }).click());
    await expect(mainTabs(page).getByRole('tab', { name, exact: true })).toHaveAttribute(
      'aria-selected',
      'true',
    );
    expect(await file.evaluate((e) => e.files.length)).toBe(1);
    await answer(page, true, () => mainTabs(page).getByRole('tab', { name: 'People', exact: true }).click());
    await mainTabs(page).getByRole('tab', { name, exact: true }).click();
    expect(await file.evaluate((e) => e.files.length)).toBe(0);
  }
  await page.goto(`/index.php/apps/stammbaum/#tree=${t.id}&view=people&person=${p.id}`);
  const details = page.getByRole('tablist', { name: 'Person details' });
  await details.getByRole('tab', { name: 'Media', exact: true }).click();
  const file = page.locator('#person-panel input[type=file]');
  await file.setInputFiles(uploadFile);
  await answer(page, false, () => details.getByRole('tab', { name: 'Notes', exact: true }).click());
  expect(await file.evaluate((e) => e.files.length)).toBe(1);
  await answer(page, true, () => details.getByRole('tab', { name: 'Notes', exact: true }).click());
  await expect(details.getByRole('tab', { name: 'Notes', exact: true })).toHaveAttribute(
    'aria-selected',
    'true',
  );
});

test('pending media and import uploads block tab navigation and keep delayed failures visible', async ({
  page,
}) => {
  const t = await tree(page);
  await openTree(page, t);
  for (const name of ['Media', 'Imports']) {
    await mainTabs(page).getByRole('tab', { name, exact: true }).click();
    await page
      .locator('main input[type=file]')
      .setInputFiles(name === 'Imports' ? 'tests/fixtures/family.ged' : uploadFile);
    let release, arrive;
    const pending = new Promise((r) => (release = r)),
      arrived = new Promise((r) => (arrive = r));
    const pattern = `**/api/v1/trees/${t.id}/${name.toLowerCase()}`;
    await page.route(pattern, async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      arrive();
      await pending;
      await route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Fictional delayed failure' }),
      });
    });
    try {
      await page
        .getByRole('button', {
          name: name === 'Imports' ? 'Upload for review' : 'Upload original',
          exact: true,
        })
        .click();
      await arrived;
      await mainTabs(page).getByRole('tab', { name: 'People', exact: true }).click();
      await expect(mainTabs(page).getByRole('tab', { name, exact: true })).toHaveAttribute(
        'aria-selected',
        'true',
      );
      release();
      await expect(page.locator('#workspace-panel [role=alert]')).toContainText('Fictional delayed failure');
      await answer(page, true, () =>
        mainTabs(page).getByRole('tab', { name: 'People', exact: true }).click(),
      );
    } finally {
      release();
      await page.unroute(pattern);
    }
  }
});

test('new-tree Escape and Cancel protect drafts and restore focus after deliberate discard', async ({
  page,
}) => {
  const opener = page.locator('.new-tree-button'),
    d = page.locator('dialog[open]');
  await opener.click();
  await d.getByLabel('Name', { exact: true }).fill('Fictional unsaved tree');
  await d.getByLabel('Description', { exact: true }).fill('Keep this research description');
  await answer(page, false, () => page.keyboard.press('Escape'));
  await expect(d.getByLabel('Name', { exact: true })).toHaveValue('Fictional unsaved tree');
  await answer(page, false, () => d.getByRole('button', { name: 'Cancel', exact: true }).click());
  await expect(d.getByLabel('Description', { exact: true })).toHaveValue('Keep this research description');
  await answer(page, true, () => d.getByRole('button', { name: 'Cancel', exact: true }).click());
  await expect(opener).toBeFocused();
  await opener.click();
  await expect(d.getByLabel('Name', { exact: true })).toHaveValue('');
  await page.keyboard.press('Escape');
  await expect(d).toHaveCount(0);
});

test('tree details protect hash and tree navigation; saves and accepted discard clear dirty state', async ({
  page,
}) => {
  const t = await tree(page),
    other = await tree(page);
  await openTree(page, t, 'manage');
  const description = page.locator('form.panel').getByLabel('Description', { exact: true });
  await description.fill('Unsaved description');
  await answer(page, false, () => page.evaluate((id) => (location.hash = `#tree=${id}&view=people`), t.id));
  await expect(description).toHaveValue('Unsaved description');
  await answer(page, false, () => page.evaluate((id) => (location.hash = `#tree=${id}`), other.id));
  await expect(page.locator('.page-header h1')).toHaveText(t.name);
  await answer(page, true, () => page.evaluate((id) => (location.hash = `#tree=${id}&view=people`), t.id));
  await expect(page.getByRole('searchbox')).toBeVisible();
  await mainTabs(page).getByRole('tab', { name: 'Manage', exact: true }).click();
  await expect(description).toHaveValue(t.description);
  await description.fill('  Saved description  ');
  await page.locator('form.panel').getByRole('button', { name: 'Save', exact: true }).click();
  await expect(description).toHaveValue('Saved description');
  await expect(page.locator('form.panel').getByRole('button', { name: 'Save', exact: true })).toBeEnabled();
  await mainTabs(page).getByRole('tab', { name: 'People', exact: true }).click();
  await expect(page.getByRole('searchbox')).toBeVisible();
  await language(page, 'de');
  await page.getByRole('tab', { name: 'Verwalten', exact: true }).click();
  await page.locator('form.panel textarea').fill('Ungespeichert');
  await answer(page, false, () => page.getByRole('tab', { name: 'Personen', exact: true }).click());
  await expect(page.locator('form.panel textarea')).toHaveValue('Ungespeichert');
  await answer(page, true, () => page.getByRole('tab', { name: 'Personen', exact: true }).click());
  await language(page, 'en');
});

test('main and person tablists support manual keyboard activation and named panels', async ({
  page,
  browser,
}) => {
  const t = await tree(page),
    p = await person(page, t.id, 'Keyboard');
  await grant(page, t.id, 'stb_viewer');
  await openTree(page, t);
  const tabs = mainTabs(page);
  await tabs.getByRole('tab', { name: 'Family', exact: true }).focus();
  await page.keyboard.press('ArrowRight');
  await expect(tabs.getByRole('tab', { name: 'People', exact: true })).toBeFocused();
  await expect(tabs.getByRole('tab', { name: 'Family', exact: true })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  await page.keyboard.press('Enter');
  await expect(page.getByRole('tabpanel', { name: 'People', exact: true })).toBeVisible();
  await expect(tabs.locator('[tabindex="0"]')).toHaveCount(1);
  await page.keyboard.press('Tab');
  await expect(page.locator('#workspace-panel')).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('End');
  await expect(tabs.getByRole('tab', { name: 'Manage', exact: true })).toBeFocused();
  await page.keyboard.press('Space');
  await expect(page.getByRole('tabpanel', { name: 'Manage', exact: true })).toBeVisible();
  await page.keyboard.press('Home');
  await page.keyboard.press('ArrowLeft');
  await expect(tabs.getByRole('tab', { name: 'Manage', exact: true })).toBeFocused();
  await page.goto(`/index.php/apps/stammbaum/#tree=${t.id}&person=${p.id}&view=people`);
  const details = page.getByRole('tablist', { name: 'Person details' });
  await details.getByRole('tab', { name: 'Connections', exact: true }).focus();
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('tabpanel', { name: 'History', exact: true })).toBeVisible();
  await expect(details.locator('[tabindex="0"]')).toHaveCount(1);
  const context = await browser.newContext(),
    viewer = await context.newPage();
  try {
    await login(viewer, 'stb_viewer');
    await openTree(viewer, t);
    await language(viewer, 'de');
    await viewer.setViewportSize({ width: 320, height: 844 });
    const vt = viewer.getByRole('tablist', { name: 'Stammbaum-Arbeitsbereich' });
    await expect(vt.getByRole('tab')).toHaveCount(5);
    await vt.getByRole('tab', { name: 'Familie', exact: true }).focus();
    await viewer.keyboard.press('End');
    await viewer.keyboard.press('Enter');
    await expect(viewer.getByRole('tabpanel', { name: 'Verbindungen', exact: true })).toBeVisible();
    await viewer.setViewportSize({ width: 1440, height: 1000 });
    await language(viewer, 'en');
  } finally {
    await context.close();
  }
});

test('maximum-length unbroken source titles remain readable in phone lists and details', async ({ page }) => {
  const t = await tree(page),
    title = ('Fictional' + 'VeryLongArchiveIdentifier'.repeat(10)).slice(0, 250);
  await good(page, `/trees/${t.id}/sources`, 'POST', { title, requestId: key() }, 201);
  for (const width of [320, 390]) {
    await page.setViewportSize({ width, height: 844 });
    await openTree(page, t, 'sources');
    const link = page.getByRole('button', { name: title, exact: true });
    await expect(link).toBeVisible();
    expect(
      await link.evaluate(
        (e) => e.scrollWidth <= e.clientWidth + 1 && e.getBoundingClientRect().right <= innerWidth,
      ),
    ).toBe(true);
    await link.click();
    await expect(page.locator('.evidence-panel h2')).toHaveText(title);
    expect(await page.locator('.evidence-panel').evaluate((e) => e.scrollWidth <= e.clientWidth + 1)).toBe(
      true,
    );
    await page.screenshot({ path: `test-results/source-title-${width}.png`, fullPage: true });
    await mainTabs(page).getByRole('tab', { name: 'People', exact: true }).click();
  }
});

test('a late management refresh never overwrites newly typed tree details', async ({ page }) => {
  const t = await tree(page);
  await openTree(page, t);
  let release, arrive;
  const pending = new Promise((r) => (release = r)),
    arrived = new Promise((r) => (arrive = r));
  const pattern = `**/api/v1/trees/${t.id}/members`;
  await page.route(pattern, async (route) => {
    arrive();
    await pending;
    await route.continue();
  });
  try {
    await mainTabs(page).getByRole('tab', { name: 'Manage', exact: true }).click();
    await arrived;
    const form = page.locator('form.panel');
    await form.getByLabel('Name', { exact: true }).fill('Fictional revised name');
    await form.getByLabel('Description', { exact: true }).fill('Typed during the management refresh');
    release();
    await expect(form.getByRole('button', { name: 'Save', exact: true })).toBeEnabled();
    await expect(form.getByLabel('Name', { exact: true })).toHaveValue('Fictional revised name');
    await expect(form.getByLabel('Description', { exact: true })).toHaveValue(
      'Typed during the management refresh',
    );
    await answer(page, false, () => mainTabs(page).getByRole('tab', { name: 'People', exact: true }).click());
    await expect(form.getByLabel('Description', { exact: true })).toHaveValue(
      'Typed during the management refresh',
    );
  } finally {
    release();
    await page.unroute(pattern);
  }
});
