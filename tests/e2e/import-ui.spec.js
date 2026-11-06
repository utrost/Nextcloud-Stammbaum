import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { login, tree, good, endpoint } from './helpers.js';
const processImports = () =>
  execFileSync('docker', [
    'exec',
    '-u',
    'www-data',
    'stammbaum-iteration-nc',
    'php',
    'occ',
    'stammbaum:process-imports',
  ]);
test('compact import review retains diagnostics, deliberate activation and unchanged original download', async ({
  page,
}) => {
  await login(page);
  const pref = await good(page, '/preferences');
  if (pref.locale !== 'en') {
    await good(page, '/preferences', 'PUT', { locale: 'en', revision: pref.revision });
    await page.reload();
  }
  const tr = await tree(page);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=imports`);
  await page.getByLabel('GEDCOM file', { exact: true }).setInputFiles('tests/fixtures/family.ged');
  await page.getByRole('button', { name: 'Upload for review', exact: true }).click();
  await expect(page.locator('.import-review')).toContainText('Waiting for analysis');
  await expect(page.locator('.import-upload-disclosure')).not.toHaveAttribute('open');
  processImports();
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await expect(page.locator('.import-review')).toContainText('Ready for review');
  expect((await page.locator('.import-counts').boundingBox()).height).toBeLessThan(190);
  await expect(page.getByRole('button', { name: 'Confirm import', exact: true })).toBeDisabled();
  await expect(page.locator('.import-review')).toContainText('warnings');
  await page.locator('.import-review').scrollIntoViewIfNeeded();
  await page.screenshot({ path: 'test-results/import-ready-desktop.png' });
  await page.setViewportSize({ width: 1440, height: 1800 });
  await expect(page.locator('.import-review')).toHaveScreenshot('import-ready.png', {
    animations: 'disabled',
    style: '#stammbaum-app { font-family: "Noto Sans", sans-serif !important; }',
    maxDiffPixelRatio: 0.003,
  });
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    const review = await page.locator('.import-review').boundingBox(),
      runs = await page.locator('.import-runs').boundingBox();
    expect(review.y).toBeLessThan(runs.y);
    await page.locator('.import-review-heading').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `test-results/import-ready-${width}.png` });
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  const run = (await good(page, `/trees/${tr.id}/imports`)).items[0];
  const original = await page.request.get(`${endpoint}/trees/${tr.id}/imports/${run.id}/original`);
  expect(original.status()).toBe(200);
  const bytes = await original.body();
  await page.getByRole('checkbox', { name: /I reviewed this file/ }).check();
  await page.getByRole('button', { name: 'Confirm import', exact: true }).click();
  await expect(page.locator('.import-review')).toContainText('Waiting for activation');
  processImports();
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await expect(page.locator('.import-review')).toContainText('Import completed');
  expect(
    await (await page.request.get(`${endpoint}/trees/${tr.id}/imports/${run.id}/original`)).body(),
  ).toEqual(bytes);
  await expect(page.getByRole('button', { name: 'Confirm import', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Open imported family', exact: true }).click();
  await expect(page.locator('.person-node')).toHaveCount(3);
});
test('import response fixtures render all statuses, long names and incomplete diagnostics at narrow widths', async ({
  page,
}) => {
  test.setTimeout(120000);
  await login(page);
  const tr = await tree(page);
  const sample = {
    id: 'visual-run',
    revision: 1,
    originalName: 'Linden family archive — annotated household register and research extracts.ged',
    createdAt: 1700000000,
    profile: 'nc-gedcom-551-alpha2',
    sha256: 'a'.repeat(64),
    planHash: 'b'.repeat(64),
    encoding: 'UTF-8',
    report: {
      counts: { people: 3, relations: 2, contexts: 1, sources: 1, citations: 1, records: 2 },
      errors: 0,
      warnings: 1,
      complete: true,
      issues: [{ severity: 'warning', line: 42, code: 'vendor_extension' }],
      preview: [{ givenName: 'Ada', familyName: 'Linden', visibility: 'members' }],
    },
  };
  let run;
  await page.route(`**/api/v1/trees/${tr.id}/imports**`, (route) => {
    const url = new URL(route.request().url());
    return route.fulfill({
      status: 200,
      json: url.pathname.endsWith('/imports') ? { items: [run], nextPage: null } : run,
    });
  });
  for (const locale of ['en', 'de']) {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.getByLabel('Language', { exact: true }).selectOption(locale);
    await expect.poll(async () => (await good(page, '/preferences')).locale).toBe(locale);
    for (const status of [
      'ready',
      'blocked',
      'queued',
      'analyzing',
      'commit_queued',
      'activating',
      'failed',
      'cancelled',
      'committed',
    ]) {
      run = { ...sample, status, failureCode: status === 'failed' ? 'authority_changed' : null };
      if (status === 'blocked')
        run.report = {
          ...sample.report,
          complete: false,
          errors: 201,
          warnings: 0,
          issues: Array.from({ length: 200 }, (_, i) => ({
            severity: 'error',
            line: i + 1,
            code: 'unsupported_standard_field',
          })),
        };
      if (['queued', 'analyzing'].includes(status)) run.report = null;
      await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=imports`);
      await page.locator('.import-run').click();
      await expect(page.locator('.import-status')).toHaveClass(new RegExp(status));
      await expect(page.locator('.import-review h3')).toHaveText(sample.originalName);
      expect(await page.locator('.import-confirm').count()).toBe(status === 'ready' ? 1 : 0);
      if (status === 'blocked') {
        await expect(page.locator('.import-review')).toContainText(
          locale === 'en' ? 'incomplete' : 'unvollständig',
        );
        await expect(page.locator('.import-issues li')).toHaveCount(200);
      }
      for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await page.locator('.import-review-heading').scrollIntoViewIfNeeded();
        await page.screenshot({ path: `test-results/import-${locale}-${status}-${width}.png` });
      }
    }
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.getByLabel('Language', { exact: true }).selectOption('en');
  await expect.poll(async () => (await good(page, '/preferences')).locale).toBe('en');
});
