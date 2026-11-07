import { test, expect } from '@playwright/test';
import { login, good, tree, person, key } from './helpers.js';
test.beforeEach(async ({ page }) => login(page));
test('create a tree, person and relative through the UI; preserve focus and display uncertainty', async ({
  page,
}) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.locator('.new-tree-button').click();
  const treeDialog = page.locator('dialog[open]');
  const title = 'Fictional UI ' + key().slice(0, 6);
  await treeDialog.getByLabel('Name', { exact: true }).fill(title);
  await treeDialog.getByRole('button', { name: 'Create tree', exact: true }).click();
  await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
  await page
    .locator('.page-header')
    .getByRole('button', { name: /New person/ })
    .click();
  const editor = page.locator('dialog[open]');
  await editor.getByLabel('Given name', { exact: true }).fill('Ada');
  await editor.getByLabel('Family name', { exact: true }).fill('Fictional');
  await editor.locator('#birth-kind').selectOption('literal');
  await editor.locator('#birth-value').fill('03/04');
  await editor.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.person-heading h2')).toHaveText('Ada Fictional');
  await expect(page.locator('.life-dates')).toContainText('03/04');
  await page.getByRole('button', { name: 'Add relative', exact: true }).click();
  await editor.getByLabel('Given name', { exact: true }).fill('Clara');
  await editor.getByLabel('Family name', { exact: true }).fill('Fictional');
  await editor.locator('#relative-direction').selectOption('parent');
  await editor.locator('#parent-type').selectOption('adoptive');
  await editor.getByRole('button', { name: 'Connect', exact: true }).click();
  await expect(page.locator('.person-heading h2')).toHaveText('Clara Fictional');
  await expect(page.locator('.person-node')).toHaveCount(2);
  await expect(page.locator('.person-node.focus')).toContainText('Ada Fictional');
  await expect(page.locator('.edge.adoptive')).toHaveCount(1);
  await page.getByRole('button', { name: 'Text view', exact: true }).click();
  await expect(page.locator('.graph-text')).toContainText('Adoptive');
  await page.getByRole('button', { name: 'Show family branch', exact: true }).click();
  await page.getByRole('button', { name: 'Previous branch', exact: false }).click();
  await page.getByRole('button', { name: 'Diagram', exact: true }).click();
  await expect(page.locator('.person-node.focus')).toContainText('Ada Fictional');
  expect(errors).toEqual([]);
  await page.screenshot({ path: 'test-results/workspace-desktop.png', fullPage: true });
});
test('stale edit retains draft, shows comparison, and only explicit retry overwrites', async ({ page }) => {
  const t = await tree(page),
    p = await person(page, t.id, 'Original');
  await page.goto(`/index.php/apps/stammbaum/#tree=${t.id}&person=${p.id}&focus=${p.id}`);
  await expect(page.locator('.person-heading h2')).toHaveText('Original Example');
  await page.getByRole('button', { name: 'Edit person', exact: true }).click();
  const editor = page.locator('dialog[open]');
  await editor.getByLabel('Given name', { exact: true }).fill('My draft');
  await good(page, `/trees/${t.id}/people/${p.id}`, 'PUT', {
    givenName: 'Someone else',
    familyName: 'Example',
    revision: p.revision,
  });
  await editor.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(editor.getByRole('alert')).toContainText('Someone changed');
  await expect(editor.getByLabel('Given name', { exact: true })).toHaveValue('My draft');
  await editor.getByRole('button', { name: 'Load current version for comparison' }).click();
  await expect(editor.locator('table')).toContainText('Someone else Example');
  await editor.getByRole('button', { name: 'I compared the changes.', exact: false }).click();
  await editor.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.person-heading h2')).toHaveText('My draft Example');
});
test('language switching preserves editor values, literal dates and dialog focus', async ({ page }) => {
  const t = await tree(page);
  await page.goto(`/index.php/apps/stammbaum/#tree=${t.id}`);
  await page
    .locator('.page-header')
    .getByRole('button', { name: /New person/ })
    .click();
  const editor = page.locator('dialog[open]');
  await editor.locator('#given-name').fill('Literal draft');
  await editor.locator('#birth-kind').selectOption('literal');
  await editor.locator('#birth-value').fill('03/04');
  await editor.getByLabel('Language', { exact: true }).selectOption('de');
  await expect(editor.getByRole('heading')).toHaveText('Neue Person');
  await expect(editor.locator('#given-name')).toHaveValue('Literal draft');
  await expect(editor.locator('#birth-value')).toHaveValue('03/04');
  await editor.getByLabel('Language', { exact: true }).selectOption('en');
  await editor.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.person-heading h2')).toHaveText('Literal draft');
});
test('phone and narrow view have usable controls and no horizontal page overflow', async ({ page }) => {
  const t = await tree(page),
    p = await person(page, t.id, 'A very long fictional given name', {
      familyName: 'A very long fictional family name',
    });
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    await page.goto(`/index.php/apps/stammbaum/#tree=${t.id}&person=${p.id}&focus=${p.id}`);
    await expect(page.locator('.person-heading h2')).toContainText('A very long fictional');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(
      true,
    );
    const buttons = await page
      .locator('.detail-actions button')
      .evaluateAll((bs) => bs.map((b) => b.getBoundingClientRect().height));
    expect(buttons.every((h) => h >= 44)).toBe(true);
    await page.locator('.detail-actions').getByRole('button', { name: 'Edit person' }).click();
    const editor = page.locator('dialog[open]');
    await expect(editor.locator('#given-name')).toBeVisible();
    expect(await editor.evaluate((e) => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
    await editor.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.screenshot({ path: `test-results/workspace-${width}.png`, fullPage: true });
  }
});
test('failed people loading exposes retry without claiming an empty result', async ({ page }) => {
  const t = await tree(page);
  await person(page, t.id, 'RetryPerson');
  await page.goto(`/index.php/apps/stammbaum/#tree=${t.id}&view=people`);
  await expect(page.locator('.people-list')).toContainText('RetryPerson');
  await page.route('**/api/v1/trees/*/people?query=Broken*', (r) =>
    r.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Simulated failure' }),
    }),
  );
  await page.getByRole('searchbox').fill('Broken');
  await expect(page.locator('.work-area [role=alert]')).toContainText('Simulated failure');
  await expect(page.getByRole('heading', { name: 'No matching people' })).toHaveCount(0);
  await page.unroute('**/api/v1/trees/*/people?query=Broken*');
  await page.getByRole('searchbox').fill('RetryPerson');
  await expect(page.locator('.people-list')).toContainText('RetryPerson');
});
