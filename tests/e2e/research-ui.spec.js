import { editable } from '../../src/domain.js';
import { test, expect } from '@playwright/test';
import { login, tree, person, good, relative, key, endpoint, grant } from './helpers.js';
const main = (page) => page.getByRole('tablist', { name: 'Tree workspace' });
const details = (page) => page.getByRole('tablist', { name: 'Person details' });
async function language(page, value) {
  await page.getByLabel('Language', { exact: true }).selectOption(value);
  await expect.poll(async () => (await good(page, '/preferences')).locale).toBe(value);
}
async function answer(page, accept, action) {
  const event = page.waitForEvent('dialog');
  const pending = action();
  const dialog = await event;
  expect(dialog.message()).toContain('Discard unsaved changes');
  await (accept ? dialog.accept() : dialog.dismiss());
  await pending;
}
async function research(page) {
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Ada', {
    familyName: 'Linden',
    livingStatus: 'deceased',
    birthDate: { kind: 'about', value: '1840' },
    birthPlace: 'Lindenau',
    deathDate: { kind: 'exact', value: '1912' },
    deathPlace: 'Leipzig',
    displayNote: 'Fictional research example · dates remain uncertain',
  });
  const partner = await person(page, tr.id, 'Emil', { familyName: 'Linden', livingStatus: 'deceased' });
  const parent = await person(page, tr.id, 'Clara', {
    familyName: 'Berg',
    livingStatus: 'deceased',
    birthDate: { kind: 'exact', value: '1810' },
  });
  const child = await person(page, tr.id, 'Johanna Elisabeth', {
    familyName: 'Linden-Winterfeld',
    livingStatus: 'deceased',
    birthDate: { kind: 'exact', value: '1870' },
  });
  expect((await relative(page, tr.id, p, partner, 'partner')).status).toBe(201);
  expect((await relative(page, tr.id, p, parent, 'parent')).status).toBe(201);
  expect((await relative(page, tr.id, p, child, 'child', 'adoptive')).status).toBe(201);
  const current = await good(page, `/trees/${tr.id}/people/${p.id}`);
  await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
    ...editable(current),
    revision: current.revision,
    birthDate: { kind: 'about', value: '1841' },
  });
  const source = await good(
    page,
    `/trees/${tr.id}/sources`,
    'POST',
    {
      title: 'Lindenau parish register · births, marriages and household annotations',
      type: 'Register',
      repository: 'Fictional town archive',
      requestId: key(),
    },
    201,
  );
  await good(
    page,
    `/trees/${tr.id}/citations`,
    'POST',
    {
      sourceId: source.id,
      locator: 'Volume II · page 42 · entry 17',
      quotation:
        'The register records Ada in the household of Clara Berg. The original wording gives an approximate year of birth; a later marginal annotation differs by one year. Both readings remain part of the research record.',
      reasoning:
        'The handwriting is clear, but the year in the later annotation needs comparison with independent evidence.',
      targets: [{ kind: 'person', targetId: p.id }],
      requestId: key(),
    },
    201,
  );
  for (const entry of [
    {
      category: 'event',
      type: 'marriage',
      title: 'Marriage in Lindenau',
      date: { kind: 'about', value: '1868' },
      place: 'Lindenau',
      participants: [
        { personId: p.id, role: 'spouse' },
        { personId: partner.id, role: 'spouse' },
      ],
      confirmedParticipants: [p.id, partner.id],
    },
    {
      category: 'note',
      type: 'note',
      title: 'Research notes',
      text: '**Two birth years remain recorded.**\n\nCompare the parish register with the household list before preferring one account.',
      participants: [{ personId: p.id, role: 'subject' }],
    },
  ])
    await good(page, `/trees/${tr.id}/records`, 'POST', { originId: p.id, ...entry, requestId: key() }, 201);
  const bytes = Buffer.from(
    await page.evaluate(() => {
      const c = document.createElement('canvas');
      c.width = 100;
      c.height = 120;
      const x = c.getContext('2d');
      x.fillStyle = '#e5d7b8';
      x.fillRect(0, 0, 100, 120);
      x.fillStyle = '#766047';
      x.font = '60px sans-serif';
      x.fillText('A', 28, 82);
      return c.toDataURL().split(',')[1];
    }),
    'base64',
  );
  const response = await page.request.post(`${endpoint}/trees/${tr.id}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      requestId: key(),
      personId: p.id,
      file: { name: 'fictional-initial.png', mimeType: 'image/png', buffer: bytes },
    },
  });
  expect(response.status()).toBe(201);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${p.id}&person=${p.id}`);
  await expect(page.locator('.person-heading h2')).toHaveText('Ada Linden');
  await expect(page.locator('.person-node')).toHaveCount(4);
  return { tr, p, partner, child };
}
test.beforeEach(async ({ page }) => {
  await login(page);
  const pref = await good(page, '/preferences');
  if (pref.locale !== 'en') {
    await good(page, '/preferences', 'PUT', { locale: 'en', revision: pref.revision });
    await page.reload();
  }
});
test('expanded research retains camera, selection, evidence and drafts while exposing comparison context', async ({
  page,
}) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  const { p } = await research(page);
  await page.getByRole('region', { name: 'Family diagram' }).focus();
  await page.keyboard.press('ArrowLeft');
  const camera = await page.locator('.graph-world').getAttribute('style');
  await details(page).getByRole('tab', { name: 'Evidence', exact: true }).click();
  await expect(page.locator('.citation-card')).toContainText('Volume II');
  await page.getByRole('button', { name: 'Expand person research' }).click();
  await expect(page.locator('.work-area')).toBeHidden();
  await expect(page.locator('.research-context')).toContainText('1840');
  await expect(page.locator('.research-context')).toContainText('1841');
  await expect(page.locator('.research-context')).toContainText('Emil Linden');
  await expect(page.locator('.research-context .relation-row')).toContainText([
    'Clara Berg',
    'Emil Linden',
    'Johanna Elisabeth',
  ]);
  expect((await page.locator('#person-panel').boundingBox()).width).toBeGreaterThan(500);
  await page.screenshot({ path: 'test-results/research-evidence-desktop.png' });
  await details(page).getByRole('tab', { name: 'Timeline', exact: true }).click();
  await expect(page.locator('.record-card')).toContainText('Marriage in Lindenau');
  await expect(page.locator('.record-card')).toContainText('Emil Linden');
  await details(page).getByRole('tab', { name: 'Notes', exact: true }).click();
  await expect(page.locator('.safe-text')).toContainText('Two birth years');
  await details(page).getByRole('tab', { name: 'Media', exact: true }).click();
  await expect(page.locator('.media-card')).toBeVisible();
  const file = page.locator('#person-panel input[type=file]');
  await file.setInputFiles({
    name: 'pending.png',
    mimeType: 'image/png',
    buffer: Buffer.from('selection only'),
  });
  await page.getByRole('button', { name: 'Back to family workspace' }).click();
  expect(await file.evaluate((e) => e.files.length)).toBe(1);
  expect(await page.locator('.graph-world').getAttribute('style')).toBe(camera);
  await expect(page.getByRole('button', { name: 'Expand person research' })).toBeFocused();
  await answer(page, false, () => page.getByRole('button', { name: 'Close details', exact: true }).click());
  await expect(page.locator('.person-heading h2')).toHaveText('Ada Linden');
  await answer(page, false, () => page.locator('.person-node').filter({ hasText: 'Emil Linden' }).click());
  expect(await file.evaluate((e) => e.files.length)).toBe(1);
  await answer(page, true, () => page.locator('.person-node').filter({ hasText: 'Emil Linden' }).click());
  await expect(page.locator('.person-heading h2')).toHaveText('Emil Linden');
  await expect(page.locator('.person-node.focus')).toContainText('Ada Linden');
  expect(new URL(page.url()).hash).toContain(`focus=${p.id}`);
  await page.getByRole('button', { name: 'Close details', exact: true }).click();
  await expect(page.locator('.person-node').filter({ hasText: 'Emil Linden' })).toBeFocused();
  expect(errors).toEqual([]);
});
test('family and research layouts remain readable in English and German across desktop and narrow screens', async ({
  page,
}) => {
  test.setTimeout(120000);
  await research(page);
  for (const locale of ['en', 'de']) {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await language(page, locale);
    for (const width of [1440, 1024, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      await page.locator('.research-toggle').click();
      await page.locator('#person-tab-evidence').click();
      await expect(page.locator('.citation-card')).toContainText('Volume II');
      expect(await page.locator('.details').evaluate((e) => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
      await page.screenshot({ path: `test-results/research-${locale}-${width}.png` });
      await page.locator('.research-toggle').click();
      await expect
        .poll(async () => {
          const node = await page.locator('.person-node.focus').boundingBox();
          const viewport = await page.locator('.graph-viewport').boundingBox();
          return Math.abs(node.x + node.width / 2 - viewport.x - viewport.width / 2);
        })
        .toBeLessThan(2);
      expect(await page.locator('.edge.partner').evaluate((e) => getComputedStyle(e).strokeDasharray)).toBe(
        'none',
      );
      expect(
        await page.locator('.edge.adoptive').evaluate((e) => getComputedStyle(e).strokeDasharray),
      ).not.toBe('none');
      await page.locator('.graph-shell').scrollIntoViewIfNeeded();
      await page.screenshot({ path: `test-results/family-${locale}-${width}.png` });
    }
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await language(page, 'en');
});

test('native light and dark themes keep the family and research controls readable', async ({ page }) => {
  await research(page);
  for (const theme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme: theme });
    const background = await page
      .locator('#stammbaum-app')
      .evaluate((e) => getComputedStyle(e).getPropertyValue('--color-main-background').trim());
    expect(background).toBe(theme === 'dark' ? '#171717' : '#ffffff');
    await page.locator('.graph-shell').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `test-results/family-${theme}.png` });
    await page.locator('.research-toggle').click();
    await page.locator('#person-tab-evidence').click();
    await expect(page.locator('.citation-card')).toContainText('Volume II');
    await page.screenshot({ path: `test-results/research-${theme}.png` });
    await page.locator('.research-toggle').click();
  }
});

test('native visual baselines cover family, expanded evidence and narrow screens', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1800 });
  await research(page);
  const options = {
    animations: 'disabled',
    style: '#stammbaum-app { font-family: "Noto Sans", sans-serif !important; }',
    maxDiffPixels: 100,
  };
  for (const theme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme: theme });
    await expect(page.locator('.graph-shell')).toHaveScreenshot(`family-${theme}.png`, options);
  }
  await page.emulateMedia({ colorScheme: 'light' });
  await page.locator('.research-toggle').click();
  await page.locator('#person-tab-evidence').click();
  await expect(page.locator('.citation-card')).toContainText('Volume II');
  await expect(page.locator('.details')).toHaveScreenshot('research-evidence.png', options);
  await page.setViewportSize({ width: 390, height: 1000 });
  await expect(page.locator('.citation-card')).toHaveScreenshot('research-evidence-phone.png', options);
  await page.locator('.research-toggle').click();
  await page.evaluate(() => document.activeElement?.blur());
  await page.mouse.move(0, 0);
  await expect(page.locator('.graph-shell')).toHaveScreenshot('family-phone.png', options);
});

test('expanded viewer research excludes private assertions and hidden relatives', async ({
  page,
  browser,
}) => {
  const tr = await tree(page),
    p = await person(page, tr.id, 'Confidential original', { visibility: 'management' }),
    secret = await person(page, tr.id, 'Confidential relative', { visibility: 'management' });
  const released = await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
    ...editable(p),
    givenName: 'Public research',
    visibility: 'members',
    confirmRelease: true,
    revision: p.revision,
  });
  expect((await relative(page, tr.id, released, secret)).status).toBe(201);
  await grant(page, tr.id, 'stb_viewer');
  const context = await browser.newContext(),
    viewer = await context.newPage();
  await login(viewer, 'stb_viewer');
  const locale = await good(viewer, '/preferences');
  await good(viewer, '/preferences', 'PUT', { locale: 'en', revision: locale.revision });
  await viewer.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${p.id}&person=${p.id}`);
  await expect(viewer.locator('.person-heading h2')).toHaveText('Public research Example');
  await viewer.getByRole('button', { name: 'Expand person research', exact: true }).click();
  await expect(viewer.locator('.research-context')).toContainText('Public research Example');
  await expect(viewer.locator('#stammbaum-app')).not.toContainText('Confidential');
  await expect(viewer.getByRole('button', { name: 'Edit person', exact: true })).toHaveCount(0);
  await expect(viewer.getByRole('button', { name: 'Delete person', exact: true })).toHaveCount(0);
  await context.close();
});
