import { test, expect } from '@playwright/test';
import { login, tree, good, request, key, endpoint, fixture, grant } from './helpers.js';
const pdf = Buffer.from(
  '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n',
);
async function source(page, name, bytes = pdf, user = fixture.user) {
  const token = await page.evaluate(() => window.OC.requestToken);
  const url = `/remote.php/dav/files/${encodeURIComponent(user)}/${encodeURIComponent(name)}`;
  const r = await page.request.put(url, { headers: { requesttoken: token }, data: bytes });
  expect(r.status()).toBe(201);
  return { url, token };
}
async function select(page, tid, name) {
  return good(page, `/trees/${tid}/media/select-file`, 'POST', { path: '/' + name });
}
async function copy(page, tid, selected, extra = {}) {
  return request(page, `/trees/${tid}/media/import-file`, 'POST', {
    fileId: selected.id,
    etag: selected.etag,
    requestId: key(),
    ...extra,
  });
}
test('Files import preserves originals, rejects stale selection and isolates source permissions', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    name = `fictional-${key()}.pdf`,
    { url, token } = await source(page, name);
  try {
    const selected = await select(page, tr.id, name);
    const id = key(),
      first = await copy(page, tr.id, selected, { requestId: id });
    expect(first.status, JSON.stringify(first.body)).toBe(201);
    expect((await copy(page, tr.id, selected, { requestId: id })).body.id).toBe(first.body.id);
    expect(
      await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${first.body.id}/original`)).body(),
    ).toEqual(pdf);
    expect(await (await page.request.get(url)).body()).toEqual(pdf);
    await grant(page, tr.id, 'stb_editor', 'editor');
    const ctx = await browser.newContext(),
      other = await ctx.newPage();
    await login(other, 'stb_editor');
    expect((await copy(other, tr.id, selected)).status).toBe(404);
    await ctx.close();
    const changed = await page.request.put(url, {
      headers: { requesttoken: token },
      data: Buffer.concat([pdf, Buffer.from('\nchanged')]),
    });
    expect(changed.ok()).toBeTruthy();
    expect((await copy(page, tr.id, selected)).status).toBe(409);
    expect((await good(page, `/trees/${tr.id}/media`)).items).toHaveLength(1);
    await page.request.delete(url, { headers: { requesttoken: token } });
    expect((await copy(page, tr.id, selected)).status).toBe(404);
    expect(
      await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${first.body.id}/original`)).body(),
    ).toEqual(pdf);
  } finally {
    await page.request.delete(url, { headers: { requesttoken: token } });
  }
});
test('Files selection rejects non-media and traversal, and checks tree editing authority', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    name = `fictional-${key()}.txt`,
    { url, token } = await source(page, name, Buffer.from('not media'));
  try {
    expect(
      (await request(page, `/trees/${tr.id}/media/select-file`, 'POST', { path: '/' + name })).status,
    ).toBe(422);
    expect(
      (await request(page, `/trees/${tr.id}/media/select-file`, 'POST', { path: '/../' + name })).status,
    ).toBe(404);
    await grant(page, tr.id, 'stb_viewer', 'viewer');
    const ctx = await browser.newContext(),
      viewer = await ctx.newPage();
    await login(viewer, 'stb_viewer');
    expect(
      (await request(viewer, `/trees/${tr.id}/media/select-file`, 'POST', { path: '/' + name })).status,
    ).toBe(403);
    await ctx.close();
  } finally {
    await page.request.delete(url, { headers: { requesttoken: token } });
  }
});
test('native Files picker confirms a private PDF copy with responsive keyboard-accessible controls', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    name = `fictional-${key()}.pdf`,
    { url, token } = await source(page, name);
  try {
    await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
    await page.getByRole('button', { name: 'Choose from Nextcloud Files', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByRole('dialog').getByText(name, { exact: true }).click();
    await page
      .getByRole('dialog')
      .getByRole('button', { name: /choose|select/i })
      .click();
    await expect(page.getByRole('button', { name: 'Import private copy', exact: true })).toBeVisible();
    await expect(page.getByText('A private copy will be stored', { exact: false })).toBeVisible();
    for (const width of [1440, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
      ).toBeTruthy();
      await page.screenshot({ path: `test-results/media-files-${width}.png`, fullPage: true });
    }
    const button = page.getByRole('button', { name: 'Import private copy', exact: true });
    await button.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.media-card')).toContainText(name);
    expect(
      await (
        await page.request.get(
          await page.getByRole('link', { name: 'Download original', exact: true }).getAttribute('href'),
        )
      ).body(),
    ).toEqual(pdf);
  } finally {
    await page.request.delete(url, { headers: { requesttoken: token } });
  }
});

test('read-only shared images can be copied; revoked shares cannot be reused and originals stay independent', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    name = `fictional-shared-${key()}.png`;
  const bytes = Buffer.from(
    await page.evaluate(() => {
      const c = document.createElement('canvas');
      c.width = 80;
      c.height = 60;
      const x = c.getContext('2d');
      x.fillStyle = '#357760';
      x.fillRect(0, 0, 80, 60);
      return c.toDataURL().split(',')[1];
    }),
    'base64',
  );
  const { url, token } = await source(page, name, bytes);
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  let shareId;
  try {
    await grant(page, tr.id, 'stb_editor', 'editor');
    const shared = await page.request.post('/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json', {
      headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
      form: { path: '/' + name, shareType: '0', shareWith: 'stb_editor', permissions: '1' },
    });
    expect(shared.ok()).toBeTruthy();
    const body = await shared.json();
    expect(body.ocs.meta.status).toBe('ok');
    shareId = body.ocs.data.id;
    await login(editor, 'stb_editor');
    const selected = await select(editor, tr.id, name),
      imported = await copy(editor, tr.id, selected);
    expect(imported.status, JSON.stringify(imported.body)).toBe(201);
    expect(
      await (
        await editor.request.get(`${endpoint}/trees/${tr.id}/media/${imported.body.id}/original`)
      ).body(),
    ).toEqual(bytes);
    expect(
      (await editor.request.get(`${endpoint}/trees/${tr.id}/media/${imported.body.id}/preview`)).status(),
    ).toBe(200);
    await page.request.delete(`/ocs/v2.php/apps/files_sharing/api/v1/shares/${shareId}?format=json`, {
      headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
    });
    expect((await copy(editor, tr.id, selected)).status).toBe(404);
    expect(await (await page.request.get(url)).body()).toEqual(bytes);
    expect(
      await (
        await editor.request.get(`${endpoint}/trees/${tr.id}/media/${imported.body.id}/original`)
      ).body(),
    ).toEqual(bytes);
  } finally {
    if (shareId)
      await page.request.delete(`/ocs/v2.php/apps/files_sharing/api/v1/shares/${shareId}?format=json`, {
        headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
      });
    await ctx.close();
    await page.request.delete(url, { headers: { requesttoken: token } });
  }
});

test('Files copies validate bytes even when source metadata claims an image', async ({ page }) => {
  await login(page);
  const tr = await tree(page),
    name = `fictional-invalid-${key()}.png`,
    { url, token } = await source(page, name, Buffer.from('<svg><script>invalid</script></svg>'));
  try {
    // Read the source ID/etag through DAV, bypassing the UI filter just as an untrusted client can.
    const prop = await page.request.fetch(url, {
      method: 'PROPFIND',
      headers: { requesttoken: token, Depth: '0' },
      data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/><d:getetag/></d:prop></d:propfind>',
    });
    const xml = await prop.text();
    const id = xml.match(/<oc:fileid>(\d+)<\/oc:fileid>/)[1];
    const etag = xml
      .match(/<d:getetag>(.*?)<\/d:getetag>/)[1]
      .replaceAll('&quot;', '')
      .replaceAll('"', '');
    const result = await copy(page, tr.id, { id: Number(id), etag });
    expect(result.status, JSON.stringify(result.body)).toBe(422);
    expect((await good(page, `/trees/${tr.id}/media`)).items).toHaveLength(0);
  } finally {
    await page.request.delete(url, { headers: { requesttoken: token } });
  }
});
