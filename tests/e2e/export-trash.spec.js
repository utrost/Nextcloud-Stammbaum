import { editable } from '../../src/domain.js';
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import {
  login,
  tree as createTree,
  person,
  good,
  request,
  key,
  endpoint,
  fixture,
  grant,
} from './helpers.js';
const createdTrees = [];
async function tree(page) {
  const tr = await createTree(page);
  createdTrees.push(tr.id);
  return tr;
}
test.afterEach(() => {
  const payload = Buffer.from(JSON.stringify(createdTrees.splice(0))).toString('base64');
  execFileSync('docker', [
    'exec',
    '-u',
    'www-data',
    fixture.container,
    'php',
    '-r',
    `define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$db=\\OCP\\Server::get(\\OCP\\IDBConnection::class);foreach(json_decode(base64_decode('${payload}'),true) as $tree){$db->executeStatement('DELETE FROM *PREFIX*stb_exports WHERE tree_id = ?',[$tree]);}`,
  ]);
});
const pdf = readFileSync('tests/fixtures/media-two-pages.pdf');
function work() {
  execFileSync(
    'docker',
    [
      'exec',
      '-u',
      'www-data',
      fixture.container,
      'php',
      '-r',
      "define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$app=new \\OCA\\Stammbaum\\AppInfo\\Application();$service=$app->getContainer()->get(\\OCA\\Stammbaum\\Service\\Exports::class);for($i=0;$i<20;$i++){if($service->work()===null)break;}",
    ],
    { stdio: 'pipe' },
  );
}
async function upload(page, tid, visibility) {
  const requestId = key();
  const result = await page.request.post(`${endpoint}/trees/${tid}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      title: 'Fictional letter',
      requestId,
      ...(visibility ? { visibility } : {}),
      file: { name: 'letter.pdf', mimeType: 'application/pdf', buffer: pdf },
    },
  });
  expect(result.status(), await result.text()).toBe(201);
  return { medium: await result.json(), requestId };
}
async function preview(page, tid, options = {}) {
  return good(page, `/trees/${tid}/exports/preview`, 'POST', { options });
}
async function create(page, tid, options = {}) {
  const p = await preview(page, tid, options);
  return good(page, `/trees/${tid}/exports`, 'POST', { ...p, acknowledged: true, requestId: key() }, 201);
}
test('media trash retains bytes, rejects stale review and cannot revive through upload retry', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  const { medium: m, requestId } = await upload(page, tr.id);
  let impact = await good(page, `/trees/${tr.id}/media/${m.id}/impact`);
  await person(page, tr.id, 'Fictional');
  expect((await request(page, `/trees/${tr.id}/media/${m.id}/delete`, 'POST', impact)).status).toBe(412);
  impact = await good(page, `/trees/${tr.id}/media/${m.id}/impact`);
  await good(page, `/trees/${tr.id}/media/${m.id}/delete`, 'POST', impact);
  for (const suffix of ['', '/original', '/preview', '/preview-info'])
    expect((await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}${suffix}`)).status()).toBe(404);
  expect((await good(page, `/trees/${tr.id}/media`)).items).toHaveLength(0);
  const trash = await good(page, `/trees/${tr.id}/media-trash`);
  expect(trash.items[0].size).toBe(pdf.length);
  const retry = await page.request.post(`${endpoint}/trees/${tr.id}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      title: 'Fictional letter',
      requestId,
      file: { name: 'letter.pdf', mimeType: 'application/pdf', buffer: pdf },
    },
  });
  expect(retry.status()).toBe(404);
  await good(page, `/trees/${tr.id}/media/${m.id}/restore`, 'POST', { revision: trash.items[0].revision });
  expect(await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/original`)).body()).toEqual(
    pdf,
  );
  await grant(page, tr.id, 'stb_editor', 'editor');
  const context = await browser.newContext();
  const editor = await context.newPage();
  await login(editor, 'stb_editor');
  expect((await request(editor, `/trees/${tr.id}/media-trash`)).status).toBe(403);
  expect((await request(editor, `/trees/${tr.id}/media/${m.id}/impact`)).status).toBe(403);
  await context.close();
});
test('export defaults filter private and living records, packages originals and invalidates after edit', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Alma', {
    livingStatus: 'deceased',
    birthDate: { kind: 'about', value: '1900' },
  });
  await person(page, tr.id, 'LivingSecret', { livingStatus: 'living' });
  await person(page, tr.id, 'UnknownSecret');
  await person(page, tr.id, 'PrivateSecret', { livingStatus: 'deceased', visibility: 'management' });
  await upload(page, tr.id, 'members');
  const review = await preview(page, tr.id);
  expect(review.counts.people).toBe(1);
  expect(review.counts.media).toBe(1);
  const job = await create(page, tr.id, { media: true });
  work();
  const result = await good(page, `/trees/${tr.id}/exports/${job.id}`);
  expect(result.state).toBe('ready');
  const ged = await (await page.request.get(`${endpoint}/trees/${tr.id}/exports/${job.id}/gedcom`)).text();
  expect(ged).toContain('Alma');
  expect(ged).toContain('ABT 1900');
  expect(ged).not.toMatch(/LivingSecret|UnknownSecret|PrivateSecret/);
  const zip = await page.request.get(`${endpoint}/trees/${tr.id}/exports/${job.id}/package`);
  expect(zip.status()).toBe(200);
  expect((await zip.body()).subarray(0, 2).toString()).toBe('PK');
  await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
    ...editable(p),
    revision: p.revision,
    givenName: 'Edited Alma',
  });
  expect((await good(page, `/trees/${tr.id}/exports/${job.id}`)).state).toBe('invalid');
  expect((await page.request.get(`${endpoint}/trees/${tr.id}/exports/${job.id}/gedcom`)).status()).toBe(409);
  const stale = await request(page, `/trees/${tr.id}/exports`, 'POST', {
    ...review,
    acknowledged: true,
    requestId: key(),
  });
  expect(stale.status).toBe(412);
  const cancelled = await create(page, tr.id);
  await good(page, `/trees/${tr.id}/exports/${cancelled.id}/cancel`, 'POST');
  work();
  expect((await good(page, `/trees/${tr.id}/exports/${cancelled.id}`)).state).toBe('cancelled');
});
test('export UI reviews privacy and keyboard actions at desktop and phone widths', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  await person(page, tr.id, 'Fictional Alma', { livingStatus: 'deceased' });
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=exports`);
  await expect(page.getByRole('heading', { name: 'Export current research' })).toBeVisible();
  await page.getByRole('button', { name: 'Review export', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Included research' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Create export', exact: true })).toBeDisabled();
  await page
    .getByRole('checkbox', { name: 'I reviewed the included research and export limitations.' })
    .check();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/export-review-${width}.png`, fullPage: true });
  }
  await page.getByRole('button', { name: 'Create export', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByText('Queued', { exact: true })).toBeVisible();
  work();
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Download GEDCOM' })).toBeVisible();
});

test('export grant revocation is permanent, results are requester-only and expiry denies downloads', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  await person(page, tr.id, 'Deceased fixture', { livingStatus: 'deceased' });
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_viewer',
    role: 'viewer',
    exportAllowed: true,
    revision: 0,
  });
  const context = await browser.newContext();
  const viewer = await context.newPage();
  await login(viewer, 'stb_viewer');
  expect(
    (await request(viewer, `/trees/${tr.id}/exports/preview`, 'POST', { options: { management: true } }))
      .status,
  ).toBe(403);
  const job = await create(viewer, tr.id);
  work();
  expect((await good(viewer, `/trees/${tr.id}/exports/${job.id}`)).state).toBe('ready');
  expect((await request(page, `/trees/${tr.id}/exports/${job.id}`)).status).toBe(404);
  let members = await good(page, `/trees/${tr.id}/members`);
  let m = members.items.find((m) => m.userId === 'stb_viewer');
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_viewer',
    role: 'viewer',
    exportAllowed: false,
    revision: m.revision,
  });
  expect((await request(viewer, `/trees/${tr.id}/exports/${job.id}`)).status).toBe(403);
  members = await good(page, `/trees/${tr.id}/members`);
  m = members.items.find((m) => m.userId === 'stb_viewer');
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_viewer',
    role: 'viewer',
    exportAllowed: true,
    revision: m.revision,
  });
  expect((await good(viewer, `/trees/${tr.id}/exports/${job.id}`)).state).toBe('invalid');
  const expiring = await create(viewer, tr.id);
  work();
  expect(expiring.id).toMatch(/^[a-f0-9-]{36}$/);
  execFileSync('docker', [
    'exec',
    '-u',
    'www-data',
    fixture.container,
    'php',
    '-r',
    `define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$db=\\OCP\\Server::get(\\OCP\\IDBConnection::class);$db->executeStatement('UPDATE *PREFIX*stb_exports SET expires_at = ? WHERE id = ?',[time(),'${expiring.id}']);`,
  ]);
  expect((await good(viewer, `/trees/${tr.id}/exports/${expiring.id}`)).state).toBe('expired');
  expect(
    (await viewer.request.get(`${endpoint}/trees/${tr.id}/exports/${expiring.id}/gedcom`)).status(),
  ).toBe(409);
  await context.close();
});

test('media removal review and restore are keyboard reachable on a narrow screen', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  await upload(page, tr.id);
  await page.setViewportSize({ width: 320, height: 1000 });
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await page.getByRole('button', { name: 'Move medium to trash', exact: true }).click();
  const review = page.getByRole('region', { name: 'Review media removal' });
  await expect(review).toBeFocused();
  await page.screenshot({ path: 'test-results/media-trash-review-320.png', fullPage: true });
  await review.getByRole('button', { name: 'Move medium to trash' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('.media-card')).toHaveCount(0);
  await page.getByRole('button', { name: 'Media trash', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Restore medium' })).toBeVisible();
  await page.getByRole('button', { name: 'Restore medium' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('.media-card')).toHaveCount(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
