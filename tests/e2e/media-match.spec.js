import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fixtureDatabase } from './server-fixture.js';
import { login, tree, good, request, key, endpoint, fixture, grant } from './helpers.js';

const gedcom = readFileSync('tests/fixtures/extended-family.ged');
const jpeg = readFileSync('tests/fixtures/exif-orientation-1.jpg');
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
async function importedReference(page, bytes = gedcom, givenName = 'Ada') {
  fixtureDatabase(
    'UPDATE *PREFIX*stb_imports SET created_at = created_at - 7200 WHERE actor_id = ? AND created_at > ?',
    [fixture.user, Math.floor(Date.now() / 1000) - 3600],
  );
  const tr = await tree(page);
  const upload = await page.request.post(`${endpoint}/trees/${tr.id}/imports`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: { file: { name: 'fictional-family.ged', mimeType: 'text/plain', buffer: bytes } },
  });
  expect(upload.status(), await upload.text()).toBe(201);
  const run = await upload.json();
  processImports();
  const ready = await good(page, `/trees/${tr.id}/imports/${run.id}`);
  expect(ready.status, JSON.stringify(ready.report)).toBe('ready');
  await good(page, `/trees/${tr.id}/imports/${run.id}/confirm`, 'POST', {
    revision: ready.revision,
    treeRevision: ready.treeRevision,
    sha256: ready.sha256,
    planHash: ready.planHash,
    acknowledgeReview: true,
  });
  processImports();
  const ada = (await good(page, `/trees/${tr.id}/people`)).items.find((p) => p.givenName === givenName);
  const record = (await good(page, `/trees/${tr.id}/records?person=${ada.id}`)).items.find(
    (r) => r.original?.title === 'Missing media reference',
  );
  expect(record).toBeTruthy();
  return { tr, ada, record };
}
async function file(page, name) {
  const token = await page.evaluate(() => window.OC.requestToken);
  const url = `/remote.php/dav/files/${fixture.user}/${name}`;
  expect((await page.request.put(url, { headers: { requesttoken: token }, data: jpeg })).status()).toBe(201);
  return { url, token };
}

test('a reviewed Files copy satisfies the exact imported FILE entry without changing its wording or source', async ({
  page,
  browser,
}) => {
  test.setTimeout(120000);
  await login(page);
  const { tr, ada, record } = await importedReference(page);
  const name = `fictional-match-${key()}.jpg`;
  const source = await file(page, name);
  try {
    const review = await good(page, `/trees/${tr.id}/media/reference/${record.id}`);
    expect(review.references).toEqual([
      expect.objectContaining({
        path: 'https://example.invalid/never-fetch.jpg',
        state: 'missing',
      }),
    ]);
    const selected = await good(page, `/trees/${tr.id}/media/select-file`, 'POST', { path: '/' + name });
    const payload = {
      fileId: selected.id,
      etag: selected.etag,
      requestId: key(),
      referenceRecordId: record.id,
      referenceField: review.references[0].fieldIndex,
      referenceRevision: review.revision,
      visibility: review.visibility,
    };
    await grant(page, tr.id, 'stb_viewer', 'viewer');
    const context = await browser.newContext(),
      viewer = await context.newPage();
    await login(viewer, 'stb_viewer');
    expect((await request(viewer, `/trees/${tr.id}/media/import-file`, 'POST', payload)).status).toBe(403);
    await context.close();
    const changed = await good(page, `/trees/${tr.id}/records/${record.id}`, 'PUT', {
      category: record.category,
      type: record.type,
      originId: record.originId,
      visibility: record.visibility,
      title: 'Reviewed media reference',
      text: record.text,
      date: record.date,
      place: record.place,
      ongoing: record.ongoing,
      fields: record.fields,
      participants: record.participants.map(({ personId, role }) => ({ personId, role })),
      revision: record.revision,
    });
    expect(changed.revision).toBe(record.revision + 1);
    expect((await request(page, `/trees/${tr.id}/media/import-file`, 'POST', payload)).status).toBe(412);
    expect((await good(page, `/trees/${tr.id}/media`)).items).toEqual([]);
    payload.referenceRevision = (await good(page, `/trees/${tr.id}/media/reference/${record.id}`)).revision;
    const before = (await good(page, `/trees/${tr.id}`)).revision;
    const first = await good(page, `/trees/${tr.id}/media/import-file`, 'POST', payload, 201);
    expect((await good(page, `/trees/${tr.id}`)).revision).toBe(before + 1);
    expect((await good(page, `/trees/${tr.id}/media/import-file`, 'POST', payload, 201)).id).toBe(first.id);
    expect(
      (await request(page, `/trees/${tr.id}/media/import-file`, 'POST', { ...payload, requestId: key() }))
        .status,
    ).toBe(409);
    const matched = await good(page, `/trees/${tr.id}/media/reference/${record.id}`);
    expect(matched.references[0]).toMatchObject({ state: 'matched', medium: { id: first.id } });
    const updated = await good(page, `/trees/${tr.id}/records/${record.id}`);
    expect(updated.fields.find((f) => f.label === 'Availability').value).toBe('Matched private copy');
    expect(updated.original.fields.find((f) => f.label === 'Availability').value).toContain('Missing:');
    expect(updated.original.fields.find((f) => f.label === 'FILE').value).toBe(review.references[0].path);
    expect(
      await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${first.id}/original`)).body(),
    ).toEqual(jpeg);
    expect(await (await page.request.get(source.url)).body()).toEqual(jpeg);
    expect(
      (await good(page, `/trees/${tr.id}/media?person=${ada.id}`)).items.some((m) => m.id === first.id),
    ).toBe(true);
    const impact = await good(page, `/trees/${tr.id}/media/${first.id}/impact`);
    await good(page, `/trees/${tr.id}/media/${first.id}/delete`, 'POST', impact);
    expect((await good(page, `/trees/${tr.id}/media/reference/${record.id}`)).references[0].state).toBe(
      'unavailable',
    );
    await good(page, `/trees/${tr.id}/media/${first.id}/restore`, 'POST', { revision: first.revision + 1 });
    expect((await good(page, `/trees/${tr.id}/media/reference/${record.id}`)).references[0].state).toBe(
      'matched',
    );
    const current = await good(page, `/trees/${tr.id}/media/${first.id}`);
    await good(page, `/trees/${tr.id}/media/${first.id}`, 'PUT', {
      revision: current.revision,
      title: current.title,
      visibility: 'management',
      assignments: current.assignments.map(({ personId, purpose, preferred, crop }) => ({
        personId,
        purpose,
        preferred,
        crop,
      })),
    });
    const deniedContext = await browser.newContext();
    const denied = await deniedContext.newPage();
    await login(denied, 'stb_viewer');
    expect((await request(denied, `/trees/${tr.id}/media/reference/${record.id}`)).status).toBe(404);
    expect((await denied.request.get(`${endpoint}/trees/${tr.id}/media/${first.id}/preview`)).status()).toBe(
      404,
    );
    await deniedContext.close();
    await page.request.delete(source.url, { headers: { requesttoken: source.token } });
    expect(
      await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${first.id}/original`)).body(),
    ).toEqual(jpeg);
    await good(page, `/trees/${tr.id}/people/${ada.id}/delete`, 'POST', {
      revision: ada.revision,
      confirmed: true,
    });
    await good(page, `/trees/${tr.id}/people/${ada.id}/restore`, 'POST', {
      revision: ada.revision + 1,
      confirmed: true,
    });
    expect((await good(page, `/trees/${tr.id}/media/reference/${record.id}`)).references[0].state).toBe(
      'missing',
    );
    expect((await good(page, `/trees/${tr.id}/media?person=${ada.id}`)).items).toEqual([]);
  } finally {
    await page.request.delete(source.url, { headers: { requesttoken: source.token } });
  }
});

test('deleting a shared-reference participant severs its media event link permanently', async ({ page }) => {
  await login(page);
  const { tr, ada, record } = await importedReference(
    page,
    readFileSync('tests/fixtures/shared-media.ged'),
    'Ari',
  );
  expect(record.category).toBe('event');
  expect(record.participants).toHaveLength(2);
  const name = `fictional-shared-${key()}.jpg`;
  const source = await file(page, name);
  try {
    const review = await good(page, `/trees/${tr.id}/media/reference/${record.id}`);
    const selected = await good(page, `/trees/${tr.id}/media/select-file`, 'POST', { path: '/' + name });
    const medium = await good(
      page,
      `/trees/${tr.id}/media/import-file`,
      'POST',
      {
        fileId: selected.id,
        etag: selected.etag,
        requestId: key(),
        referenceRecordId: record.id,
        referenceField: review.references[0].fieldIndex,
        referenceRevision: review.revision,
        visibility: review.visibility,
      },
      201,
    );
    expect(medium.metadata.eventId).toBe(record.id);
    await good(page, `/trees/${tr.id}/people/${ada.id}/delete`, 'POST', {
      revision: ada.revision,
      confirmed: true,
    });
    await good(page, `/trees/${tr.id}/people/${ada.id}/restore`, 'POST', {
      revision: ada.revision + 1,
      confirmed: true,
    });
    expect((await good(page, `/trees/${tr.id}/media/reference/${record.id}`)).references[0].state).toBe(
      'missing',
    );
    expect((await good(page, `/trees/${tr.id}/media/${medium.id}`)).metadata.eventId).toBe('');
    expect((await good(page, `/trees/${tr.id}/media?event=${record.id}`)).items).toEqual([]);
  } finally {
    await page.request.delete(source.url, { headers: { requesttoken: source.token } });
  }
});

test('the imported reference UI reviews a Files copy at desktop and phone widths, then resolves it by keyboard', async ({
  page,
}) => {
  test.setTimeout(120000);
  await login(page);
  const { tr, ada, record } = await importedReference(page);
  const name = `fictional-review-${key()}.jpg`;
  const source = await file(page, name);
  try {
    await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${ada.id}`);
    await page.locator('.details').getByRole('tab', { name: 'Details', exact: true }).click();
    const card = page.locator('.record-card').filter({ hasText: 'Missing media reference' });
    await expect(card.getByText('Original FILE reference', { exact: false })).toBeVisible();
    await card.getByRole('button', { name: 'Match a Nextcloud Files copy' }).click();
    await page.getByRole('dialog').getByText(name, { exact: true }).click();
    await page
      .getByRole('dialog')
      .getByRole('button', { name: /choose|select/i })
      .click();
    await expect(card).toContainText('https://example.invalid/never-fetch.jpg');
    await expect(card).toContainText('An unchanged private copy will consume Stammbaum quota');
    for (const width of [1440, 390, 320]) {
      await page.setViewportSize({ width, height: 950 });
      await card.locator('.missing-media-confirm').scrollIntoViewIfNeeded();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.screenshot({ path: `test-results/media-match-review-${width}.png`, fullPage: true });
    }
    const before = (await good(page, `/trees/${tr.id}`)).revision;
    const button = card.getByRole('button', { name: 'Import and match private copy' });
    await button.focus();
    await page.keyboard.press('Enter');
    await expect(card).toContainText('Matched private copy');
    expect((await good(page, `/trees/${tr.id}`)).revision).toBe(before + 1);
    expect((await good(page, `/trees/${tr.id}/media/reference/${record.id}`)).references[0].state).toBe(
      'matched',
    );
  } finally {
    await page.request.delete(source.url, { headers: { requesttoken: source.token } });
  }
});
