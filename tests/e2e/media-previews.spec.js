import { test, expect } from '@playwright/test';
import { editable } from '../../src/domain.js';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { login, tree, person, grant, good, request, key, endpoint } from './helpers.js';
const pdf = readFileSync('tests/fixtures/media-two-pages.pdf');
async function upload(page, tid, bytes, name, extra = {}) {
  const r = await page.request.post(`${endpoint}/trees/${tid}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      requestId: key(),
      file: { name, mimeType: name.endsWith('.pdf') ? 'application/pdf' : 'image/png', buffer: bytes },
      ...extra,
    },
  });
  expect(r.status(), await r.text()).toBe(201);
  return r.json();
}
async function png(page) {
  return Buffer.from(
    await page.evaluate(() => {
      const c = document.createElement('canvas');
      c.width = 1800;
      c.height = 1200;
      const x = c.getContext('2d');
      x.fillStyle = '#21664f';
      x.fillRect(0, 0, 1800, 1200);
      x.fillStyle = '#ffd994';
      x.fillRect(300, 200, 500, 700);
      return c.toDataURL().split(',')[1];
    }),
    'base64',
  );
}
function cache(action, id) {
  if (!/^[a-f0-9]{64}$/.test(id)) throw new Error('invalid cache identity');
  const code = `define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$f=\\OCP\\Server::get(\\OCP\\Files\\AppData\\IAppDataFactory::class)->get('stammbaum')->getFolder('media-previews-v1');try{$v=$f->getFile('${id}');${action === 'delete' ? "$v->delete();echo 'null';" : action === 'corrupt' ? "$v->putContent('corrupt');echo 'null';" : "echo json_encode(['mtime'=>$v->getMTime(),'etag'=>$v->getETag(),'size'=>$v->getSize()]);"}}catch(\\OCP\\Files\\NotFoundException $e){echo 'null';}`;
  return JSON.parse(
    execFileSync('docker', ['exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', '-r', code], {
      encoding: 'utf8',
    }),
  );
}
const cacheKey = (m, size = 320, crop = null, page = 1) =>
  createHash('sha256')
    .update(JSON.stringify(['raster-v2', m.sha256, m.mime, crop, size, page]))
    .digest('hex');

test('image derivatives are bounded, cached, regenerated and permission checked on warm hits', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Media cache'),
    bytes = await png(page),
    m = await upload(page, tr.id, bytes, 'fictional-cache.png', { personId: p.id });
  const rev = (await good(page, `/trees/${tr.id}`)).revision;
  const previewUrl = (size) => `${endpoint}/trees/${tr.id}/media/${m.id}/preview?size=${size}`;
  const url = previewUrl(320);
  const first = await page.request.get(url);
  expect(first.status()).toBe(200);
  const raster = await first.body();
  expect(raster.readUInt32BE(16)).toBe(320);
  expect(raster.readUInt32BE(20)).toBe(213);
  const state = cache('read', cacheKey(m));
  expect(state).not.toBeNull();
  expect(await (await page.request.get(url)).body()).toEqual(raster);
  expect(cache('read', cacheKey(m))).toEqual(state);
  cache('corrupt', cacheKey(m));
  expect(await (await page.request.get(url)).body()).toEqual(raster);
  cache('delete', cacheKey(m));
  expect(await (await page.request.get(url)).body()).toEqual(raster);
  cache('delete', cacheKey(m));
  const concurrent = await Promise.all(Array.from({ length: 6 }, () => page.request.get(url)));
  expect(concurrent.some((r) => r.status() === 200)).toBeTruthy();
  for (const response of concurrent) {
    expect([200, 503]).toContain(response.status());
    if (response.status() === 200) expect(await response.body()).toEqual(raster);
  }
  expect(await (await page.request.get(url)).body()).toEqual(raster);
  const large = await page.request.get(previewUrl(1600));
  expect((await large.body()).readUInt32BE(16)).toBe(1600);
  expect((await page.request.get(previewUrl(9000))).status()).toBe(422);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(rev);
  expect(await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/original`)).body()).toEqual(
    bytes,
  );
  await grant(page, tr.id, 'stb_viewer', 'viewer');
  const ctx = await browser.newContext(),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  expect((await viewer.request.get(url)).status()).toBe(200);
  await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
    ...editable(p),
    revision: p.revision,
    visibility: 'management',
  });
  for (const suffix of ['/preview?size=320', '/preview-info', '/original'])
    expect((await viewer.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}${suffix}`)).status()).toBe(
      404,
    );
  await ctx.close();
});

test('PDF page thumbnails and larger pages render without modifying originals or revisions', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    m = await upload(page, tr.id, pdf, 'fictional-evidence.pdf');
  const rev = (await good(page, `/trees/${tr.id}`)).revision;
  expect((await good(page, `/trees/${tr.id}/media/${m.id}/preview-info`)).pages).toBe(2);
  const base = `${endpoint}/trees/${tr.id}/media/${m.id}`;
  for (const size of [320, 600, 1600]) {
    const r = await page.request.get(`${base}/preview?size=${size}`);
    expect(r.status(), await r.text()).toBe(200);
    expect(r.headers()['content-type']).toContain('image/png');
    expect(r.headers()['cache-control']).toContain('no-store');
    const data = await r.body();
    expect(Math.max(data.readUInt32BE(16), data.readUInt32BE(20))).toBe(size);
  }
  expect((await page.request.get(`${base}/preview?page=2`)).status()).toBe(200);
  expect((await page.request.get(`${base}/preview?page=3`)).status()).toBe(404);
  expect(await (await page.request.get(`${base}/original`)).body()).toEqual(pdf);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(rev);
});

test('encrypted, malformed and over-page-limit PDFs keep downloads and offer a clean fallback', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  for (const name of ['media-encrypted.pdf', 'media-too-many-pages.pdf', 'malformed.pdf']) {
    const bytes =
        name === 'malformed.pdf'
          ? Buffer.from('%PDF-1.4\ninvalid document\n%%EOF\n')
          : readFileSync('tests/fixtures/' + name),
      m = await upload(page, tr.id, bytes, name);
    expect((await request(page, `/trees/${tr.id}/media/${m.id}/preview-info`)).status).toBe(422);
    expect((await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/preview`)).status()).toBe(422);
    expect(
      await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/original`)).body(),
    ).toEqual(bytes);
  }
  const large = await upload(
    page,
    tr.id,
    readFileSync('tests/fixtures/media-large-page.pdf'),
    'large-page.pdf',
  );
  const r = await page.request.get(`${endpoint}/trees/${tr.id}/media/${large.id}/preview?size=320`);
  expect([200, 422]).toContain(r.status());
  if (r.status() === 200)
    expect(
      Math.max((await r.body()).readUInt32BE(16), (await r.body()).readUInt32BE(20)),
    ).toBeLessThanOrEqual(320);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  const card = page
    .locator('.media-card')
    .filter({ has: page.getByRole('heading', { name: 'malformed.pdf', exact: true }) });
  await card.getByRole('button', { name: 'Open preview', exact: true }).click();
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('Preview unavailable');
  await expect(
    page.getByRole('dialog').getByRole('link', { name: 'Download original', exact: true }),
  ).toBeVisible();
});

test('PDF viewer supports keyboard page navigation, zoom, mobile layouts and focus return', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  await upload(page, tr.id, pdf, 'Fictional family evidence.pdf');
  let external = 0;
  page.on('request', (r) => {
    if (r.url().includes('example.invalid')) external++;
  });
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await expect(page.locator('.media-card img')).toBeVisible();
  const open = page.getByRole('button', { name: 'Open preview', exact: true });
  await open.focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog');
  await expect(dialog.locator('img')).toBeVisible();
  await dialog.getByRole('button', { name: 'Next page', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(dialog.locator('.viewer-toolbar [role=status]')).toContainText('Page 2 / 2');
  await expect(dialog.locator('img')).toBeVisible();
  await dialog.getByLabel('Zoom', { exact: true }).selectOption('150');
  expect(await dialog.locator('.viewer-stage').evaluate((e) => e.scrollWidth > e.clientWidth)).toBeTruthy();
  await dialog.getByLabel('Zoom', { exact: true }).selectOption('100');
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({ path: `test-results/pdf-viewer-${width}.png`, fullPage: true });
  }
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(open).toBeFocused();
  expect(external).toBe(0);
});

test('image viewer and warm gallery clear displayed pixels when access is revoked', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    m = await upload(page, tr.id, await png(page), 'Fictional image.png');
  await grant(page, tr.id, 'stb_viewer', 'viewer');
  const ctx = await browser.newContext(),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  await viewer.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await expect(viewer.locator('.media-card img')).toBeVisible();
  await viewer.getByRole('button', { name: 'Open preview', exact: true }).click();
  await expect(viewer.getByRole('dialog').locator('img')).toBeVisible();
  await viewer.screenshot({ path: 'test-results/image-viewer.png', fullPage: true });
  await good(page, `/trees/${tr.id}/media/${m.id}`, 'PUT', {
    title: m.title,
    revision: m.revision,
    visibility: 'management',
    assignments: [],
  });
  await viewer.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(viewer.getByRole('dialog')).toHaveCount(0);
  await expect(viewer.locator('.media-card')).toHaveCount(0);
  await expect(viewer.locator('img[src^="blob:"]')).toHaveCount(0);
  await ctx.close();
});
