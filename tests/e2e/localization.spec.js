import { test, expect } from '@playwright/test';
import { login, tree, good } from './helpers.js';
test('German validation and upload feedback preserve authored research and rejected-file drafts', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  await page.getByLabel('Language', { exact: true }).selectOption('de');
  await expect.poll(async () => (await good(page, '/preferences')).locale).toBe('de');
  try {
    await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}`);
    await page
      .locator('.page-header')
      .getByRole('button', { name: /Neue Person/ })
      .click();
    const dialog = page.locator('dialog[open]');
    await dialog.locator('#given-name').fill('Rose');
    await dialog.locator('#family-name').fill('Winter');
    await dialog.locator('#birth-place').fill('Old English Place');
    await dialog.locator('#birth-kind').selectOption('between');
    await dialog.locator('#birth-value').fill('1840');
    await dialog.getByRole('button', { name: 'Speichern', exact: true }).click();
    await expect(dialog.getByRole('alert')).toHaveText(
      'Ein Datum zwischen zwei Zeitpunkten benötigt beide Grenzen.',
    );
    await expect(dialog.locator('#given-name')).toHaveValue('Rose');
    await dialog.locator('#birth-kind').selectOption('literal');
    await dialog.locator('#birth-value').fill('spring of an uncertain year');
    await dialog.getByRole('button', { name: 'Speichern', exact: true }).click();
    await expect(page.locator('.person-heading h2')).toHaveText('Rose Winter');
    await expect(page.locator('.life-dates')).toContainText('spring of an uncertain year');
    await expect(page.locator('.life-dates')).toContainText('Old English Place');
    await page.locator('#workspace-tab-media').click();
    const file = page.locator('.media-upload input[type=file]');
    await file.setInputFiles({
      name: 'fictional.svg',
      mimeType: 'image/svg+xml',
      buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
    });
    await expect(page.getByRole('option', { name: 'Alle Stammbaummitglieder', exact: true })).toBeAttached();
    await page.getByRole('button', { name: 'Original hochladen', exact: true }).click();
    await expect(page.locator('.media-panel [role=alert]')).toContainText(
      'SVG und ausführbare Inhalte werden nicht angenommen.',
    );
    expect(await file.evaluate((e) => e.files.length)).toBe(1);
    await page.setViewportSize({ width: 390, height: 1000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: 'test-results/german-validation-phone.png' });
  } finally {
    const state = await good(page, '/preferences');
    await good(page, '/preferences', 'PUT', { locale: 'en', revision: state.revision });
  }
});
