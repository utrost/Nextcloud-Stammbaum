import { test, expect } from '@playwright/test';
import { login, tree, person, grant, good, fixture } from './helpers.js';

test.beforeEach(async ({ page }) => login(page));

test('an inaccessible copied focus and selection fall back to a visible branch without leaking IDs', async ({
  page,
  browser,
}) => {
  const tr = await tree(page),
    hidden = await person(page, tr.id, 'Private focus', { visibility: 'management' }),
    visible = await person(page, tr.id, 'Visible fallback');
  await grant(page, tr.id, 'stb_viewer');
  const context = await browser.newContext({ baseURL: fixture.baseURL }),
    viewer = await context.newPage();
  await login(viewer, 'stb_viewer');
  await viewer.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${hidden.id}&person=${hidden.id}`);
  await expect(viewer.locator('.person-node.focus')).toContainText('Visible fallback');
  await expect(viewer.locator('.details')).toHaveCount(0);
  expect(await viewer.locator('body').innerText()).not.toContain('Private focus');
  expect(new URL(viewer.url()).hash).not.toContain(hidden.id);
  expect(new URL(viewer.url()).hash).toContain(visible.id);
  await context.close();
});

test('refocusing centers at the old zoom; branch return and relative creation preserve the old camera', async ({
  page,
}) => {
  const tr = await tree(page),
    a = await person(page, tr.id, 'Alma'),
    b = await person(page, tr.id, 'Bruno');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${a.id}&person=${b.id}`);
  await expect(page.locator('.person-node.focus')).toContainText('Alma Example');
  const viewport = page.getByRole('region', { name: 'Family diagram' });
  await viewport.focus();
  await viewport.press('+');
  await viewport.press('ArrowRight');
  const camera = () => page.locator('.graph-world').evaluate((e) => e.style.transform);
  const before = await camera();
  const zoom = /scale\(([^)]+)\)/.exec(before)?.[1];
  await page.locator('.details').getByRole('button', { name: 'Show family branch' }).click();
  await expect(page.locator('.person-node.focus')).toContainText('Bruno Example');
  expect(await camera()).toContain(`scale(${zoom})`);
  const distance = await page.locator('.person-node.focus').evaluate((node) => {
    const n = node.getBoundingClientRect();
    const v = node.closest('.graph-viewport').getBoundingClientRect();
    return Math.hypot(
      n.left + n.width / 2 - (v.left + v.width / 2),
      n.top + n.height / 2 - (v.top + v.height / 2),
    );
  });
  expect(distance).toBeLessThan(3);
  await page.getByRole('button', { name: 'Previous branch' }).click();
  await expect(page.locator('.person-node.focus')).toContainText('Alma Example');
  await expect(page.locator('.person-heading h2')).toHaveText('Bruno Example');
  await expect.poll(camera).toBe(before);
  await page.locator('.details').getByRole('button', { name: 'Add relative' }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Given name').fill('Clara');
  await dialog.getByLabel('Family name').fill('Example');
  await dialog.getByRole('button', { name: 'Connect', exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.locator('.person-heading h2')).toHaveText('Clara Example');
  await expect(page.locator('.person-node.focus')).toContainText('Alma Example');
  await expect.poll(camera).toBe(before);
  expect((await good(page, `/trees/${tr.id}/people`)).items).toHaveLength(3);
  await page.locator('.details').getByRole('button', { name: 'Edit person' }).click();
  const edit = page.getByRole('dialog');
  await edit.getByLabel('Display note').fill('Fictional research note');
  await edit.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(edit).toHaveCount(0);
  await expect(page.locator('.person-heading h2')).toHaveText('Clara Example');
  await expect(page.locator('.person-node.focus')).toContainText('Alma Example');
  await expect.poll(camera).toBe(before);
});
