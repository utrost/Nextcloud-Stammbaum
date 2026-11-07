import { test, expect } from '@playwright/test';
import { login, tree, person, relative, good, fixture } from './helpers.js';
test('Fit includes a dense family on a narrow screen and its overview can be saved', async ({ page }) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Family focus');
  for (let i = 1; i <= 14; i++) {
    const child = await person(page, tr.id, `Child ${String(i).padStart(2, '0')}`);
    expect((await relative(page, tr.id, p, child)).status).toBe(201);
  }
  await page.setViewportSize({ width: 320, height: 1000 });
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${p.id}`);
  await expect(page.locator('.person-node')).toHaveCount(15);
  await page.getByRole('button', { name: 'Fit', exact: true }).click();
  const clipped = await page.locator('.graph-viewport').evaluate((viewport) => {
    const v = viewport.getBoundingClientRect();
    return [...viewport.querySelectorAll('.person-node')].filter((node) => {
      const n = node.getBoundingClientRect();
      return n.left < v.left || n.right > v.right || n.top < v.top || n.bottom > v.bottom;
    }).length;
  });
  expect(clipped).toBe(0);
  await page.locator('.saved-views summary').click();
  await page.getByLabel('View name', { exact: true }).fill('Dense overview');
  await page.getByRole('button', { name: 'Save current view', exact: true }).click();
  await expect(page.getByRole('option', { name: 'Dense overview', exact: true })).toBeAttached();
  const state = await good(page, `/trees/${tr.id}/views`);
  expect(state.openingView.camera.zoom).toBeLessThan(0.2);
  await page.getByRole('button', { name: 'Center focus at 100%', exact: true }).click();
  expect(
    await page.locator('.person-node.focus').evaluate((e) => Math.round(e.getBoundingClientRect().width)),
  ).toBe(220);
  await page.getByRole('button', { name: 'Text view', exact: true }).click();
  await expect(page.locator('.graph-text button')).toHaveCount(15);
  expect(new Set(await page.locator('.graph-text button').allTextContents()).size).toBe(15);
});

test('two-finger gestures zoom around their midpoint without selecting or changing genealogy', async ({
  browser,
}) => {
  const context = await browser.newContext({
    baseURL: fixture.baseURL,
    hasTouch: true,
    viewport: { width: 1440, height: 1000 },
  });
  const page = await context.newPage();
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Touch focus');
  await page.setViewportSize({ width: 390, height: 1000 });
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${p.id}`);
  await expect(page.locator('.person-node')).toHaveCount(1);
  // A hash-only navigation can leave the old graph mounted while the new tree loads.
  await expect(page.locator('.page-header h1')).toHaveText(tr.name);
  await expect(page.locator(`.person-node[data-person-id="${p.id}"]`)).toBeVisible();
  await page.locator('.graph-viewport').scrollIntoViewIfNeeded();
  const node = await page.locator('.person-node').boundingBox();
  const x = node.x + node.width / 2,
    y = node.y + node.height / 2;
  const cdp = await context.newCDPSession(page);
  const points = (distance) => [
    { x: x - distance, y, id: 1 },
    { x: x + distance, y, id: 2 },
  ];
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: points(30) });
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: points(50) });
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await expect
    .poll(async () => (await page.locator('.person-node').boundingBox()).width)
    .toBeGreaterThan(node.width * 1.4);
  const after = await page.locator('.person-node').boundingBox();
  expect(Math.abs(after.x + after.width / 2 - x)).toBeLessThan(2);
  expect(Math.abs(after.y + after.height / 2 - y)).toBeLessThan(2);
  await expect(page.locator('.details')).toHaveCount(0);
  expect((await good(page, `/trees/${tr.id}/people`)).items).toHaveLength(1);
  await page.locator('.person-node').tap();
  await expect(page.locator('.person-heading h2')).toHaveText('Touch focus Example');
  await context.close();
});
