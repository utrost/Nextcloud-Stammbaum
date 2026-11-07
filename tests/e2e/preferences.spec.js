import { test, expect } from '@playwright/test';
import { login, tree, person, grant, good, request, key } from './helpers.js';

test('saved views are personal, versioned and hidden when current record visibility no longer permits them', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Private', { visibility: 'management' });
  await grant(page, tr.id, 'stb_manager', 'manager');
  const ctx = await browser.newContext(),
    manager = await ctx.newPage();
  await login(manager, 'stb_manager');
  const state = await good(manager, `/trees/${tr.id}/views`);
  const saved = await good(
    manager,
    `/trees/${tr.id}/views`,
    'POST',
    {
      label: 'Private saved view',
      focus: p.id,
      mode: 'family',
      up: 3,
      down: 2,
      camera: { x: 125, y: 60, zoom: 0.7 },
      revision: state.revision,
      requestId: key(),
    },
    201,
  );
  expect(saved.items).toHaveLength(1);
  expect((await good(page, `/trees/${tr.id}/views`)).items).toEqual([]);
  const member = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === 'stb_manager');
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: member.userId,
    revision: member.revision,
    exportAllowed: false,
    role: 'viewer',
  });
  expect((await good(manager, `/trees/${tr.id}/views`)).items).toEqual([]);
  expect(
    (
      await request(manager, `/trees/${tr.id}/views`, 'POST', {
        label: 'No access',
        focus: p.id,
        revision: saved.revision,
        requestId: key(),
      })
    ).status,
  ).toBe(404);
  await ctx.close();
});

test('named view restores focus, zoom and selection; language preference persists without changing authored text', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    q = await person(page, tr.id, 'Bruno');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${p.id}&person=${q.id}`);
  await page.locator('.saved-views summary').click();
  await page.getByLabel('View name', { exact: true }).fill('My research view');
  await page.getByRole('button', { name: 'Save current view', exact: true }).click();
  await expect(page.getByRole('option', { name: 'My research view', exact: true })).toBeAttached();
  await page.locator('.details').getByRole('button', { name: 'Show family branch', exact: true }).click();
  await page
    .getByRole('combobox', { name: 'Saved views', exact: true })
    .selectOption({ label: 'My research view' });
  await page.getByRole('button', { name: 'Open view', exact: true }).click();
  await expect(page.locator('.person-node.focus')).toContainText('Alma Example');
  await expect(page.locator('.person-heading h2')).toHaveText('Bruno Example');
  const locale = await good(page, '/preferences');
  await good(page, '/preferences', 'PUT', { locale: 'de', revision: locale.revision });
  await page.reload();
  await expect(page.getByRole('tab', { name: 'Familie', exact: true })).toBeVisible();
  await expect(page.locator('.person-heading h2')).toHaveText('Bruno Example');
  const updated = await good(page, '/preferences');
  await good(page, '/preferences', 'PUT', { locale: 'en', revision: updated.revision });
});

test('hash navigation cannot silently discard an evidence draft', async ({ page }) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=sources`);
  await page.getByRole('button', { name: 'New source', exact: false }).click();
  const d = page.getByRole('dialog');
  await d.getByLabel('Title', { exact: true }).fill('Preserve this draft');
  page.once('dialog', (dialog) => dialog.dismiss());
  await page.evaluate((hash) => (location.hash = hash), `tree=${tr.id}&view=people&person=${p.id}`);
  await expect(d.getByLabel('Title', { exact: true })).toHaveValue('Preserve this draft');
  page.once('dialog', (dialog) => dialog.accept());
  await d.getByRole('button', { name: 'Cancel', exact: true }).click();
});
