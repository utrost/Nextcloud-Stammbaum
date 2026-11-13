import { test, expect } from '@playwright/test';
import { createHash } from 'node:crypto';
import { login, tree, person, grant, good, request, key, endpoint } from './helpers.js';
async function png(page) {
  return Buffer.from(
    await page.evaluate(() => {
      const c = document.createElement('canvas');
      c.width = 80;
      c.height = 60;
      const x = c.getContext('2d');
      x.fillStyle = '#24734b';
      x.fillRect(0, 0, 80, 60);
      x.fillStyle = '#f4c76c';
      x.fillRect(20, 10, 30, 40);
      return c.toDataURL('image/png').split(',')[1];
    }),
    'base64',
  );
}
async function upload(page, tid, bytes, extra = {}) {
  const token = await page.evaluate(() => window.OC.requestToken);
  const r = await page.request.post(`${endpoint}/trees/${tid}/media`, {
    headers: { requesttoken: token },
    multipart: {
      requestId: key(),
      file: { name: 'fictional.png', mimeType: 'image/png', buffer: bytes },
      ...extra,
    },
  });
  return { status: r.status(), body: await r.json() };
}
const edit = (m) => ({
  title: m.title,
  visibility: m.visibility,
  revision: m.revision,
  assignments: m.assignments.map(({ personId, purpose, crop, preferred }) => ({
    personId,
    purpose,
    crop,
    preferred,
  })),
});

test('original bytes remain unchanged across portrait crops, retries and detachment; previews are safe derivatives', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    bytes = await png(page),
    id = key();
  const up = await upload(page, tr.id, bytes, { personId: p.id, requestId: id });
  expect(up.status, JSON.stringify(up.body)).toBe(201);
  const m = up.body;
  expect(m.sha256).toBe(createHash('sha256').update(bytes).digest('hex'));
  expect((await upload(page, tr.id, bytes, { personId: p.id, requestId: id })).body.id).toBe(m.id);
  const crop = await good(page, `/trees/${tr.id}/media/${m.id}`, 'PUT', {
    ...edit(m),
    assignments: [
      {
        personId: p.id,
        purpose: 'portrait',
        preferred: true,
        crop: { x: 0.25, y: 0, width: 0.5, height: 1 },
      },
    ],
  });
  const original = await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/original`);
  expect(original.status()).toBe(200);
  expect(await original.body()).toEqual(bytes);
  expect(original.headers()['content-disposition']).toContain('attachment');
  expect(original.headers()['cache-control']).toContain('no-store');
  const preview = await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/preview?person=${p.id}`);
  expect(preview.status()).toBe(200);
  expect(preview.headers()['content-type']).toContain('image/png');
  expect((await preview.body()).equals(bytes)).toBe(false);
  expect((await request(page, `/trees/${tr.id}/media/${m.id}`, 'PUT', edit(m))).status).toBe(412);
  await good(page, `/trees/${tr.id}/media/${m.id}`, 'PUT', { ...edit(crop), assignments: [] });
  expect((await good(page, `/trees/${tr.id}/media?person=${p.id}`)).items).toEqual([]);
  expect((await good(page, `/trees/${tr.id}/media`)).items).toHaveLength(1);
  expect(await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/original`)).body()).toEqual(
    bytes,
  );
});

test('shared private media never leaks metadata, originals or previews to ordinary members', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Public'),
    q = await person(page, tr.id, 'Secret', { visibility: 'management' });
  await grant(page, tr.id, 'stb_editor', 'editor');
  const up = await upload(page, tr.id, await png(page), { personId: p.id, visibility: 'members' });
  expect(up.status, JSON.stringify(up.body)).toBe(201);
  const m = up.body;
  await good(page, `/trees/${tr.id}/media/${m.id}`, 'PUT', {
    ...edit(m),
    assignments: [...edit(m).assignments, { personId: q.id, purpose: 'attachment' }],
  });
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  await login(editor, 'stb_editor');
  expect((await good(editor, `/trees/${tr.id}/media?person=${p.id}`)).items).toEqual([]);
  for (const suffix of ['', '/original', '/preview'])
    expect((await editor.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}${suffix}`)).status()).toBe(
      404,
    );
  expect((await request(editor, `/trees/${tr.id}/media/${m.id}`, 'PUT', edit(m))).status).toBe(404);
  await ctx.close();
});

test('upload visibility defaults follow the uploader role and remain enforced on direct URLs', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    bytes = await png(page);
  await grant(page, tr.id, 'stb_editor', 'editor');
  const ownerUpload = await upload(page, tr.id, bytes);
  expect(ownerUpload.status, JSON.stringify(ownerUpload.body)).toBe(201);
  expect(ownerUpload.body.visibility).toBe('management');
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  await login(editor, 'stb_editor');
  expect((await good(editor, `/trees/${tr.id}/media`)).items).toEqual([]);
  for (const suffix of ['', '/original', '/preview'])
    expect(
      (await editor.request.get(`${endpoint}/trees/${tr.id}/media/${ownerUpload.body.id}${suffix}`)).status(),
    ).toBe(404);
  const editorUpload = await upload(editor, tr.id, bytes);
  expect(editorUpload.status, JSON.stringify(editorUpload.body)).toBe(201);
  expect(editorUpload.body.visibility).toBe('members');
  expect((await good(editor, `/trees/${tr.id}/media`)).items.map((m) => m.id)).toEqual([
    editorUpload.body.id,
  ]);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await page.getByLabel('Choose original', { exact: true }).setInputFiles({
    name: 'fictional-visibility.png',
    mimeType: 'image/png',
    buffer: bytes,
  });
  await expect(page.getByRole('combobox', { name: 'Visibility' })).toHaveValue('management');
  await ctx.close();
});

test('uploads reject disguised active content, over-limit images and files; malformed PDFs retain original downloads with an unavailable preview', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  expect(
    (
      await upload(
        page,
        tr.id,
        Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
      )
    ).status,
  ).toBe(422);
  const oversized = await png(page);
  oversized.writeUInt32BE(16001, 16);
  expect((await upload(page, tr.id, oversized)).status).toBe(422);
  const tooManyPixels = await png(page);
  tooManyPixels.writeUInt32BE(4001, 16);
  tooManyPixels.writeUInt32BE(4000, 20);
  expect((await upload(page, tr.id, tooManyPixels)).status).toBe(422);
  expect((await upload(page, tr.id, Buffer.alloc(10485761, 65))).status).toBe(413);
  const bytes = Buffer.from(
      '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n',
    ),
    pdf = await upload(page, tr.id, bytes, {
      file: { name: 'fictional.pdf', mimeType: 'application/pdf', buffer: bytes },
    });
  expect(pdf.status, JSON.stringify(pdf.body)).toBe(201);
  const disguised = await upload(page, tr.id, bytes, {
    file: { name: 'disguised.png', mimeType: 'image/png', buffer: bytes },
  });
  expect(disguised.status, JSON.stringify(disguised.body)).toBe(201);
  expect(disguised.body.mime).toBe('application/pdf');
  const r = await page.request.get(`${endpoint}/trees/${tr.id}/media/${pdf.body.id}/original`);
  expect(r.headers()['content-disposition']).toContain('attachment');
  expect(r.headers()['x-content-type-options']).toBe('nosniff');
  expect(await r.body()).toEqual(bytes);
  expect((await page.request.get(`${endpoint}/trees/${tr.id}/media/${pdf.body.id}/preview`)).status()).toBe(
    422,
  );
});

test('browser uploads an original, selects a portrait crop, downloads unchanged bytes and resets the crop', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Portrait'),
    bytes = await png(page);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.locator('.details').getByRole('tab', { name: 'Media', exact: true }).click();
  await page
    .getByLabel('Choose original', { exact: true })
    .setInputFiles({ name: 'fictional-portrait.png', mimeType: 'image/png', buffer: bytes });
  await page.getByRole('button', { name: 'Upload original', exact: true }).click();
  await expect(page.locator('.media-card')).toContainText('fictional-portrait.png');
  await page.getByRole('button', { name: 'Edit media', exact: true }).click();
  const d = page.getByRole('dialog');
  await d.getByLabel('Purpose', { exact: true }).selectOption('portrait');
  await d.getByRole('checkbox', { name: 'Preferred portrait', exact: true }).check();
  await d.getByLabel('Left (%)', { exact: true }).fill('25');
  await d.getByLabel('Width (%)', { exact: true }).fill('50');
  await d.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.person-portrait')).toBeVisible();
  await expect(page.locator('.person-portrait')).toHaveJSProperty('naturalWidth', 40);
  const url = await page.getByRole('link', { name: 'Download original', exact: true }).getAttribute('href');
  expect(await (await page.request.get(url)).body()).toEqual(bytes);
  await page.getByRole('button', { name: 'Edit media', exact: true }).click();
  await d.getByRole('button', { name: 'Reset crop', exact: true }).click();
  await d.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.person-portrait')).toHaveJSProperty('naturalWidth', 80);
  await page.screenshot({ path: 'test-results/media-desktop.png', fullPage: true });
});
