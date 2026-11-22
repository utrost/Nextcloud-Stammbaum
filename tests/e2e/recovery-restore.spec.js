import { test, expect } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { editable } from '../../src/domain.js';
import { endpoint, fixture, good, key, login, person, request, tree } from './helpers.js';
import { captureRecoveryBackup, recoveryTarget, sourcePhp, verifyRecoverySource } from './restore-fixture.js';

const pdf = readFileSync('tests/fixtures/media-two-pages.pdf');
const gedcom = readFileSync('tests/fixtures/family.ged');
const digest = (bytes) => createHash('sha256').update(bytes).digest('hex');
const exportWorker = [
  '-r',
  "define('OC_CONSOLE',true); require '/var/www/html/lib/base.php';" +
    '$app = new \\OCA\\Stammbaum\\AppInfo\\Application();' +
    '$exports = $app->getContainer()->get(\\OCA\\Stammbaum\\Service\\Exports::class);' +
    'for ($i = 0; $i < 20 && $exports->work() !== null; $i++) {}' +
    '$store = $app->getContainer()->get(\\OCA\\Stammbaum\\Service\\Store::class);' +
    '$states = array_map(fn($id) => $store->one("SELECT state FROM *PREFIX*stb_exports WHERE id = ?", [$id])["state"], array_slice($argv, 1));' +
    'echo json_encode($states);',
];

async function upload(page, path, file, fields = {}) {
  const response = await page.request.post(endpoint + path, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: { ...fields, file },
  });
  expect(response.status(), await response.text()).toBe(201);
  return response.json();
}

async function queueExport(page, path) {
  const preview = await good(page, `${path}/preview`, 'POST', { options: {} });
  return good(page, path, 'POST', { ...preview, requestId: key(), acknowledged: true }, 201);
}

function runImportWorker(php, id) {
  for (let attempt = 0; attempt < 20; attempt++) {
    if (php('occ', 'stammbaum:process-imports').includes(id)) return;
  }
  throw new Error('The import worker did not reach the recovery fixture run');
}

async function verifyOriginal(page, path, bytes, checksum) {
  const response = await page.request.get(endpoint + path);
  expect(response.status()).toBe(200);
  const body = await response.body();
  expect(body.equals(bytes)).toBe(true);
  expect(digest(body)).toBe(checksum);
}

test('disposable installation restore preserves research and revokes snapshotted authority', async ({
  page,
  browser,
}) => {
  test.skip(process.env.STAMMBAUM_RECOVERY_EXERCISE !== '1', 'Run separately from other fixture tests');
  test.setTimeout(300_000);
  const images = verifyRecoverySource();
  const restored = recoveryTarget(images);
  const sourceVisitor = await browser.newContext({ baseURL: fixture.baseURL });
  const restoredContexts = [];
  const sourceExports = [];
  let sourceImport;
  let backup;
  try {
    await login(page);
    const familyTree = await tree(page);
    const family = `/trees/${familyTree.id}`;
    const importTree = `/trees/${(await tree(page)).id}`;
    await good(page, `${family}/members`, 'POST', {
      userId: 'stb_editor',
      role: 'editor',
      exportAllowed: true,
      revision: 0,
    });
    const p = await person(page, familyTree.id, 'Mira', { visibility: 'members', livingStatus: 'deceased' });
    const personPath = `${family}/people/${p.id}`;
    const saved = await good(page, personPath, 'PUT', {
      ...editable(p),
      givenName: 'Miriam',
      revision: p.revision,
    });
    const history = await good(page, `${personPath}/history`);
    expect(history.items).toEqual(
      expect.arrayContaining([expect.objectContaining({ action: 'person_updated' })]),
    );
    const medium = await upload(
      page,
      `${family}/media`,
      { name: 'fictional-letter.pdf', mimeType: 'application/pdf', buffer: pdf },
      { requestId: key(), title: 'Fictional letter', personId: p.id, visibility: 'members' },
    );
    expect(medium.assignments).toEqual(expect.arrayContaining([expect.objectContaining({ personId: p.id })]));
    expect(medium.sha256).toBe(digest(pdf));
    const mediaPath = `${family}/media/${medium.id}`;
    await verifyOriginal(page, `${mediaPath}/original`, pdf, medium.sha256);
    const members = (await good(page, `${family}/members`)).items;
    const invite = await good(page, `${family}/invitations`, 'POST', { role: 'viewer' }, 201);
    const visitor = await sourceVisitor.newPage();
    await login(visitor, 'stb_outsider');
    const review = await good(visitor, '/invitations/review', 'POST', { token: invite.token });
    expect(review.role).toBe('viewer');

    const exportsPath = `${family}/exports`;
    const ready = await queueExport(page, exportsPath);
    sourceExports.push(`${exportsPath}/${ready.id}`);
    sourcePhp(...exportWorker);
    expect((await good(page, `${exportsPath}/${ready.id}`)).state).toBe('ready');
    const readyDownload = await page.request.get(`${endpoint}${exportsPath}/${ready.id}/gedcom`);
    expect(readyDownload.status()).toBe(200);
    expect(await readyDownload.text()).toContain('Miriam');
    const queued = await queueExport(page, exportsPath);
    sourceExports.push(`${exportsPath}/${queued.id}`);
    expect(queued.state).toBe('queued');
    const importRun = await upload(page, `${importTree}/imports`, {
      name: 'fictional-family.ged',
      mimeType: 'text/plain',
      buffer: gedcom,
    });
    const importPath = `${importTree}/imports/${importRun.id}`;
    sourceImport = importPath;
    expect(importRun.status).toBe('queued');
    await verifyOriginal(page, `${importPath}/original`, gedcom, importRun.sha256);
    runImportWorker(sourcePhp, importRun.id);
    const importReview = await good(page, importPath);
    expect(importReview.status).toBe('ready');
    const activation = await good(page, `${importPath}/confirm`, 'POST', {
      revision: importReview.revision,
      treeRevision: importReview.treeRevision,
      sha256: importReview.sha256,
      planHash: importReview.planHash,
      acknowledgeReview: true,
    });
    expect(activation.status).toBe('commit_queued');

    backup = await test.step('Capture a consistent database and Nextcloud volume backup', () =>
      captureRecoveryBackup(images));
    await test.step('Discard an interrupted restore before starting HTTP', async () => {
      const interrupted = recoveryTarget(images);
      try {
        await expect(
          interrupted.restore(backup, {
            afterDatabase: () => {
              throw new Error('Injected interruption after database load');
            },
          }),
        ).rejects.toThrow('Injected interruption after database load');
        expect(interrupted.hasWebContainer()).toBe(false);
      } finally {
        interrupted.cleanup();
      }
    });
    const baseURL = await test.step('Restore into fresh storage and invalidate authority before HTTP', () =>
      restored.restore(backup));
    const ownerContext = await browser.newContext({ baseURL });
    restoredContexts.push(ownerContext);
    const owner = await ownerContext.newPage();
    await expect
      .poll(
        async () => {
          try {
            const response = await owner.request.get('/status.php', { timeout: 3_000 });
            const status = await response.json();
            return response.ok() && status.installed && !status.maintenance && !status.needsDbUpgrade;
          } catch {
            return false;
          }
        },
        { timeout: 30_000 },
      )
      .toBe(true);
    await login(owner);
    const editorContext = await browser.newContext({ baseURL });
    restoredContexts.push(editorContext);
    const editor = await editorContext.newPage();
    await login(editor, 'stb_editor');
    const outsiderContext = await browser.newContext({ baseURL });
    restoredContexts.push(outsiderContext);
    const outsider = await outsiderContext.newPage();
    await login(outsider, 'stb_outsider');

    await test.step('Verify restored records, original bytes and access boundaries', async () => {
      const restoredMembers = (await good(owner, `${family}/members`)).items;
      expect(restoredMembers).toEqual(
        members.map((member) => ({ ...member, revision: member.revision + 1 })),
      );
      expect(await good(owner, personPath)).toEqual(saved);
      expect(await good(owner, `${personPath}/history`)).toEqual(history);
      expect((await good(editor, family)).role).toBe('editor');
      expect((await good(editor, mediaPath)).assignments).toEqual(medium.assignments);
      await verifyOriginal(editor, `${mediaPath}/original`, pdf, medium.sha256);
      await verifyOriginal(owner, `${importPath}/original`, gedcom, importRun.sha256);
      expect((await request(outsider, family)).status).toBe(404);
      expect((await outsider.request.get(`${endpoint}${mediaPath}/original`)).status()).toBe(404);
    });
    await test.step('Reject restored authority and allow fresh work', async () => {
      for (const action of ['review', 'accept']) {
        expect(
          (await request(outsider, `/invitations/${action}`, 'POST', { token: invite.token })).status,
        ).toBe(404);
      }
      const oldDownload = `${exportsPath}/${ready.id}/gedcom`;
      expect((await request(owner, oldDownload)).status).toBe(409);
      expect((await good(owner, importPath)).status).toBe('commit_queued');
      runImportWorker(restored.php, importRun.id);
      expect(await good(owner, importPath)).toMatchObject({
        status: 'failed',
        failureCode: 'authority_changed',
      });
      expect((await good(owner, `${importTree}/people`)).items).toEqual([]);
      expect(JSON.parse(restored.php(...exportWorker, ready.id, queued.id))).toEqual(['invalid', 'invalid']);
      for (const job of [ready, queued]) {
        expect(await good(owner, `${exportsPath}/${job.id}`)).toMatchObject({
          state: 'invalid',
          report: null,
        });
        expect((await request(owner, `${exportsPath}/${job.id}/gedcom`)).status).toBe(409);
      }
      const freshInvite = await good(owner, `${family}/invitations`, 'POST', { role: 'viewer' }, 201);
      expect((await good(outsider, '/invitations/review', 'POST', { token: freshInvite.token })).role).toBe(
        'viewer',
      );
      await good(outsider, '/invitations/accept', 'POST', { token: freshInvite.token });
      expect((await good(outsider, family)).role).toBe('viewer');
      const freshExport = await queueExport(owner, exportsPath);
      restored.php(...exportWorker);
      expect((await good(owner, `${exportsPath}/${freshExport.id}`)).state).toBe('ready');
      expect((await owner.request.get(`${endpoint}${exportsPath}/${freshExport.id}/gedcom`)).status()).toBe(
        200,
      );
    });
  } finally {
    try {
      // Do not leave the source's seeded jobs queued for subsequent fixture tests.
      if (sourceImport) {
        const { revision } = await good(page, sourceImport);
        await good(page, `${sourceImport}/cancel`, 'POST', { revision });
      }
      for (const path of sourceExports) {
        const { state } = await good(page, path);
        if (['queued', 'running'].includes(state)) await good(page, `${path}/cancel`, 'POST', {});
      }
    } finally {
      try {
        await Promise.all([sourceVisitor, ...restoredContexts].map((context) => context.close()));
      } finally {
        try {
          restored.cleanup();
        } finally {
          backup?.cleanup();
        }
      }
    }
  }
});
