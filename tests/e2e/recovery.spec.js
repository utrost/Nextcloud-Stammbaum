import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { fixtureDatabase } from './server-fixture.js';
import { login, tree, grant, good, key, endpoint, request } from './helpers.js';
const pdf = Buffer.from(
  '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n',
);
async function upload(page, tid, requestId) {
  const token = await page.evaluate(() => window.OC.requestToken);
  const r = await page.request.post(`${endpoint}/trees/${tid}/media`, {
    headers: { requesttoken: token },
    multipart: { requestId, file: { name: 'fictional.pdf', mimeType: 'application/pdf', buffer: pdf } },
  });
  return { status: r.status(), body: await r.json() };
}

test('interrupted original remains accounted for and cannot publish after grant revocation and regrant', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  await grant(page, tr.id, 'stb_editor', 'editor');
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  await login(editor, 'stb_editor');
  const requestId = key(),
    up = await upload(editor, tr.id, requestId);
  expect(up.status, JSON.stringify(up.body)).toBe(201);
  fixtureDatabase('UPDATE *PREFIX*stb_media SET status = ? WHERE id = ?', ['pending', up.body.id]);
  try {
    let m = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === 'stb_editor');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: m.userId,
      revision: m.revision,
      remove: true,
    });
    m = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === 'stb_editor');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: m.userId,
      revision: m.revision,
      role: 'editor',
    });
    const retry = await upload(editor, tr.id, requestId);
    expect(retry.status, JSON.stringify(retry.body)).toBe(409);
    expect(retry.body.error).toBe('upload_authority_changed');
    expect(
      (await editor.request.get(`${endpoint}/trees/${tr.id}/media/${up.body.id}/original`)).status(),
    ).toBe(404);
    fixtureDatabase('UPDATE *PREFIX*stb_media SET size = ? WHERE id = ?', [1073741824, up.body.id]);
    const full = await upload(page, tr.id, key());
    expect(full.status, JSON.stringify(full.body)).toBe(507);
  } finally {
    fixtureDatabase('UPDATE *PREFIX*stb_media SET size = ? WHERE id = ?', [pdf.length, up.body.id]);
    await ctx.close();
  }
});

test('post-recovery command revokes old invitation links while preserving current tree membership and originals', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    invite = await good(page, `/trees/${tr.id}/invitations`, 'POST', { role: 'viewer' }, 201),
    m = await good(page, `/trees/${tr.id}`),
    up = await upload(page, tr.id, key());
  expect(up.status).toBe(201);
  execFileSync(
    'docker',
    ['exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ', 'stammbaum:invalidate-authority'],
    { stdio: ['ignore', 'pipe', 'pipe'] },
  );
  expect((await request(page, '/invitations/review', 'POST', { token: invite.token })).status).toBe(404);
  const current = await good(page, `/trees/${tr.id}`);
  expect(current.role).toBe('owner');
  expect(current.membershipRevision).toBeGreaterThan(m.membershipRevision);
  expect(
    await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${up.body.id}/original`)).body(),
  ).toEqual(pdf);
});
