import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFile, execFileSync } from 'node:child_process';
import { promisify } from 'node:util';
import { createHash } from 'node:crypto';
import { fixtureDatabase } from './server-fixture.js';
import { login, tree, person, grant, good, request, endpoint, key } from './helpers.js';
const sample = readFileSync('tests/fixtures/family.ged');
const path = (tr, run = null) => `/trees/${tr.id}/imports${run ? '/' + run.id : ''}`;
function work() {
  return execFileSync(
    'docker',
    ['exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ', 'stammbaum:process-imports'],
    { encoding: 'utf8' },
  );
}
const runProcess = promisify(execFile);
const workAsync = () =>
  runProcess('docker', [
    'exec',
    '-u',
    'www-data',
    'stammbaum-iteration-nc',
    'php',
    'occ',
    'stammbaum:process-imports',
  ]);
function replaceOriginal(id, bytes) {
  const payload = Buffer.from(JSON.stringify({ id, bytes: bytes.toString('base64') })).toString('base64');
  const php = `define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$p=json_decode(base64_decode('${payload}'),true);$f=\\OCP\\Server::get(\\OCP\\Files\\AppData\\IAppDataFactory::class)->get('stammbaum')->getFolder('imports')->getFile($p['id']);$f->putContent(base64_decode($p['bytes']));`;
  execFileSync('docker', ['exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', '-r', php]);
}
async function upload(page, tr, bytes = sample) {
  const response = await page.request.post(endpoint + path(tr), {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: { file: { name: 'fictional-family.ged', mimeType: 'text/plain', buffer: bytes } },
  });
  return { status: response.status(), body: await response.json() };
}
async function analyzed(page, tr, bytes = sample) {
  const up = await upload(page, tr, bytes);
  expect(up.status, JSON.stringify(up.body)).toBe(201);
  expect(up.body.status).toBe('queued');
  work();
  return good(page, path(tr, up.body));
}
const binding = (run) => ({
  revision: run.revision,
  treeRevision: run.treeRevision,
  sha256: run.sha256,
  planHash: run.planHash,
  acknowledgeReview: true,
});

test('stored alpha.1 runs keep their interpretation while new uploads use the corrected alpha.2 profile', async ({
  page,
}) => {
  await login(page);
  const legacyTree = await tree(page),
    up = await upload(page, legacyTree);
  expect(up.status).toBe(201);
  fixtureDatabase('UPDATE *PREFIX*stb_imports SET profile = ? WHERE id = ?', [
    'nc-gedcom-551-alpha1',
    up.body.id,
  ]);
  work();
  const legacy = await good(page, path(legacyTree, up.body));
  expect(legacy.status).toBe('ready');
  expect(legacy.profile).toBe('nc-gedcom-551-alpha1');
  expect(legacy.planHash).toBe('f8407f2363fcaff18d772d9506a4b3982f0071430901dac18a5d1737aa25048e');
  await confirm(page, legacyTree, legacy);
  work();
  expect((await good(page, path(legacyTree, legacy))).status).toBe('committed');
  const currentTree = await tree(page),
    calendar = await analyzed(
      page,
      currentTree,
      Buffer.from(sample.toString().replace('ABT 1840', '@#DJULIAN@ 12 FEB 1840')),
    );
  expect(calendar.profile).toBe('nc-gedcom-551-alpha2');
  expect(calendar.status).toBe('ready');
  expect(calendar.report.warnings).toBeGreaterThan(0);
  await confirm(page, currentTree, calendar);
  work();
  const ada = (await good(page, `/trees/${currentTree.id}/people`)).items.find((p) => p.givenName === 'Ada');
  expect(ada.birthDate).toEqual({ kind: 'literal', value: '@#DJULIAN@ 12 FEB 1840', end: '' });
  const blockedTree = await tree(page),
    blocked = await analyzed(
      page,
      blockedTree,
      Buffer.from(
        sample
          .toString()
          .replace('0 TRLR', '0 @N1@ NOTE Unlinked wording\n1 OCCU unsupported standard field\n0 TRLR'),
      ),
    );
  expect(blocked.status).toBe('blocked');
  expect(blocked.report.errors).toBe(1);
});
async function confirm(page, tr, run) {
  return good(page, path(tr, run) + '/confirm', 'POST', binding(run));
}
function rows(table, tid) {
  return fixtureDatabase(`SELECT * FROM *PREFIX*stb_${table} WHERE tree_id = ?`, [tid], true);
}
test.beforeEach(() => {
  // All imports in this disposable instance are fictional. Retain their bytes and terminal history,
  // but remove previous test-run scheduling/rate effects before testing the real limits anew.
  fixtureDatabase(
    "UPDATE *PREFIX*stb_imports SET status = 'cancelled', lease_token = '', lease_until = 0 WHERE status IN ('uploading','queued','analyzing','ready','commit_queued','activating')",
  );
  fixtureDatabase('UPDATE *PREFIX*stb_imports SET created_at = created_at - 7200');
});

test('review binds original and plan; atomic activation preserves genealogy and unchanged original across retries', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    run = await analyzed(page, tr);
  expect(run.status, JSON.stringify(run)).toBe('ready');
  expect(run.report.counts).toMatchObject({ people: 3, relations: 3, sources: 1, citations: 1, contexts: 1 });
  expect((await good(page, `/trees/${tr.id}/people`)).items).toEqual([]);
  expect(
    (await request(page, path(tr, run) + '/confirm', 'POST', { ...binding(run), sha256: '0'.repeat(64) }))
      .status,
  ).toBe(409);
  expect(
    (await request(page, path(tr, run) + '/confirm', 'POST', { ...binding(run), acknowledgeReview: false }))
      .status,
  ).toBe(409);
  const queued = await confirm(page, tr, run);
  expect(queued.status).toBe('commit_queued');
  expect((await confirm(page, tr, run)).id).toBe(run.id);
  expect((await good(page, `/trees/${tr.id}/people`)).items).toEqual([]);
  work();
  const done = await good(page, path(tr, run));
  expect(done.status, JSON.stringify(done)).toBe('committed');
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(run.treeRevision + 1);
  const people = (await good(page, `/trees/${tr.id}/people`)).items;
  expect(people).toHaveLength(3);
  const partner = rows('relations', tr.id).find((r) => r.kind === 'partner');
  expect(partner.source_id < partner.target_id).toBe(true);
  const left = people.find((p) => p.id === partner.source_id),
    right = people.find((p) => p.id === partner.target_id);
  expect(
    (
      await request(page, `/trees/${tr.id}/people/${left.id}/relations`, 'POST', {
        personId: right.id,
        revision: left.revision,
        targetRevision: right.revision,
        direction: 'partner',
        requestId: key(),
      })
    ).status,
  ).toBe(409);
  expect(people.find((p) => p.givenName === 'Robin').sex).toBe('unknown');
  expect(people.find((p) => p.givenName === 'Ada').birthDate).toEqual({
    kind: 'about',
    value: '1840',
    end: '',
  });
  expect(
    rows('relations', tr.id)
      .filter((r) => r.kind === 'parent')
      .every((r) => r.parent_type === 'adoptive'),
  ).toBe(true);
  const source = (await good(page, `/trees/${tr.id}/sources`)).items[0];
  expect(source.title).toBe('Fictional register');
  const maps = fixtureDatabase('SELECT * FROM *PREFIX*stb_import_maps WHERE run_id = ?', [run.id], true);
  for (const table of ['people', 'claims', 'sources', 'relations', 'contexts', 'records', 'citations']) {
    for (const row of rows(table, tr.id))
      expect(
        maps.some((m) => m.object_id === row.id),
        table + ' mapping',
      ).toBe(true);
  }
  const original = await page.request.get(endpoint + path(tr, run) + '/original');
  expect(await original.body()).toEqual(sample);
  expect(original.headers()['content-disposition']).toContain('attachment');
  expect(original.headers()['cache-control']).toContain('no-store');
  expect(done.sha256).toBe(createHash('sha256').update(sample).digest('hex'));
  expect((await upload(page, tr)).body.id).toBe(run.id);
  expect((await confirm(page, tr, run)).status).toBe('committed');
  work();
  expect(rows('people', tr.id)).toHaveLength(3);
  expect((await request(page, path(tr, done) + '/cancel', 'POST', { revision: done.revision })).status).toBe(
    409,
  );
  const structure = await good(page, path(tr, run) + '/structure');
  expect(structure.items).toHaveLength(sample.toString().trimEnd().split('\n').length);
  expect(structure.items[0]).toMatchObject({ ordinal: 1, byteOffset: 0, tag: 'HEAD' });
});

test('unsupported standard fields block activation; cancellation is durable and identical input never creates a second run', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    bytes = Buffer.from(sample.toString().replace('1 SEX F', '1 OCCU Unsupported occupation'));
  const run = await analyzed(page, tr, bytes);
  expect(run.status).toBe('blocked');
  expect(run.report.errors).toBe(1);
  expect((await request(page, path(tr, run) + '/confirm', 'POST', binding(run))).status).toBe(409);
  const cancelled = await good(page, path(tr, run) + '/cancel', 'POST', { revision: run.revision });
  expect(cancelled.status).toBe('cancelled');
  work();
  expect((await upload(page, tr, bytes)).body.id).toBe(run.id);
  expect((await good(page, `/trees/${tr.id}/people`)).items).toEqual([]);
  expect(await (await page.request.get(endpoint + path(tr, run) + '/original')).body()).toEqual(bytes);
});

test('manager-only reports, raw structure and originals; Nextcloud administrator has no genealogy bypass', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  await grant(page, tr.id, 'stb_viewer');
  await grant(page, tr.id, 'stb_editor', 'editor');
  const run = await analyzed(
    page,
    tr,
    Buffer.from(sample.toString().replace('1 NAME Ada /Linden/', '1 NAME Secret /Linden/')),
  );
  for (const user of ['stb_viewer', 'stb_editor', 'stb_outsider']) {
    const ctx = await browser.newContext(),
      other = await ctx.newPage();
    await login(other, user);
    for (const suffix of ['', '/structure', '/original']) {
      const response = await other.request.get(endpoint + path(tr, run) + suffix);
      expect(response.status()).toBe(user === 'stb_outsider' ? 404 : 403);
      expect(await response.text()).not.toContain('Secret');
    }
    expect((await request(other, path(tr))).status).toBe(user === 'stb_outsider' ? 404 : 403);
    expect((await upload(other, tr)).status).toBe(user === 'stb_outsider' ? 404 : 403);
    await ctx.close();
  }
});

test('confirmation fails after tree changes and workers reject revoked then regranted authority', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    run = await analyzed(page, tr);
  await person(page, tr.id, 'Existing');
  expect((await request(page, path(tr, run) + '/confirm', 'POST', binding(run))).body.error).toBe(
    'tree_not_empty',
  );
  const second = await tree(page);
  await grant(page, second.id, 'stb_manager', 'manager');
  const ctx = await browser.newContext(),
    manager = await ctx.newPage();
  await login(manager, 'stb_manager');
  const ready = await analyzed(manager, second);
  await confirm(manager, second, ready);
  let m = (await good(page, `/trees/${second.id}/members`)).items.find((m) => m.userId === 'stb_manager');
  await good(page, `/trees/${second.id}/members`, 'POST', {
    userId: m.userId,
    revision: m.revision,
    remove: true,
  });
  m = (await good(page, `/trees/${second.id}/members`)).items.find((m) => m.userId === 'stb_manager');
  await good(page, `/trees/${second.id}/members`, 'POST', {
    userId: m.userId,
    revision: m.revision,
    role: 'manager',
  });
  work();
  const failed = await good(page, path(second, ready));
  expect(failed.status, JSON.stringify(failed)).toBe('failed');
  expect(failed.failureCode).toBe('authority_changed');
  expect(rows('people', second.id)).toEqual([]);
  await ctx.close();
});

test('expired analysis and activation leases recover across worker processes without duplicates', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    up = await upload(page, tr);
  expect(up.status).toBe(201);
  fixtureDatabase(
    "UPDATE *PREFIX*stb_imports SET status = 'analyzing', lease_token = ?, lease_until = 1, attempts = 1 WHERE id = ?",
    [key(), up.body.id],
  );
  work();
  const ready = await good(page, path(tr, up.body));
  expect(ready.status, JSON.stringify(ready)).toBe('ready');
  await confirm(page, tr, ready);
  fixtureDatabase(
    "UPDATE *PREFIX*stb_imports SET status = 'activating', lease_token = ?, lease_until = 1, attempts = 1 WHERE id = ?",
    [key(), ready.id],
  );
  work();
  expect((await good(page, path(tr, ready))).status).toBe('committed');
  expect(rows('people', tr.id)).toHaveLength(3);
  expect(rows('history', tr.id).filter((h) => h.action === 'gedcom_imported')).toHaveLength(1);
  expect(rows('history', tr.id).filter((h) => h.action === 'person_imported')).toHaveLength(3);
});

test('cancellation fences an analyzing or queued activation run and never deletes its original', async ({
  page,
}) => {
  await login(page);
  const first = await tree(page),
    up = await upload(page, first);
  fixtureDatabase(
    "UPDATE *PREFIX*stb_imports SET status = 'analyzing', lease_token = ?, lease_until = 1 WHERE id = ?",
    [key(), up.body.id],
  );
  await good(page, path(first, up.body) + '/cancel', 'POST', { revision: up.body.revision });
  work();
  expect((await good(page, path(first, up.body))).status).toBe('cancelled');
  expect(rows('people', first.id)).toEqual([]);
  const second = await tree(page),
    ready = await analyzed(page, second),
    queued = await confirm(page, second, ready);
  await good(page, path(second, ready) + '/cancel', 'POST', { revision: queued.revision });
  work();
  expect((await good(page, path(second, ready))).status).toBe('cancelled');
  expect(rows('people', second.id)).toEqual([]);
  expect(await (await page.request.get(endpoint + path(second, ready) + '/original')).body()).toEqual(sample);
});

test('mid-activation database failure rolls back people, claims, mappings, audit and tree revision', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    ready = await analyzed(page, tr);
  const history = rows('history', tr.id);
  await confirm(page, tr, ready);
  // Fault injection is strictly confined to the disposable database, removed even on assertion failure.
  fixtureDatabase(
    "CREATE TRIGGER stb_test_import_failure BEFORE INSERT ON *PREFIX*stb_relations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fictional controlled failure'",
  );
  try {
    work();
  } finally {
    fixtureDatabase('DROP TRIGGER stb_test_import_failure');
  }
  const failed = await good(page, path(tr, ready));
  expect(failed.status).toBe('failed');
  expect(failed.failureCode).toBe('worker_failed');
  for (const table of ['people', 'claims', 'relations', 'sources', 'citations', 'records', 'contexts'])
    expect(rows(table, tr.id), table).toEqual([]);
  expect(rows('history', tr.id)).toEqual(history);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(ready.treeRevision);
  expect(
    fixtureDatabase('SELECT id FROM *PREFIX*stb_import_maps WHERE run_id = ?', [ready.id], true),
  ).toEqual([]);
  expect(await (await page.request.get(endpoint + path(tr, ready) + '/original')).body()).toEqual(sample);
});

test('worker refuses stale destinations and tampered review plans without partial activation', async ({
  page,
}) => {
  await login(page);
  const first = await tree(page),
    ready = await analyzed(page, first);
  await confirm(page, first, ready);
  await good(
    page,
    `/trees/${first.id}/sources`,
    'POST',
    { title: 'Standalone source', requestId: key() },
    201,
  );
  work();
  const stale = await good(page, path(first, ready));
  expect(stale.status).toBe('failed');
  expect(stale.failureCode).toBe('tree_not_empty');
  expect(rows('people', first.id)).toEqual([]);
  const second = await tree(page),
    ready2 = await analyzed(page, second);
  await confirm(page, second, ready2);
  fixtureDatabase('UPDATE *PREFIX*stb_imports SET plan_json = ? WHERE id = ?', ['{}', ready2.id]);
  work();
  expect((await good(page, path(second, ready2))).failureCode).toBe('plan_mismatch');
  expect(rows('people', second.id)).toEqual([]);
});

test('persistent rate and retained-original quota limits include failed or cancelled uploads', async ({
  page,
}) => {
  await login(page);
  let first, run;
  for (let i = 0; i < 5; i++) {
    const tr = await tree(page),
      up = await upload(page, tr);
    expect(up.status, JSON.stringify(up.body)).toBe(201);
    if (i === 0) {
      first = tr;
      run = up.body;
    }
    await good(page, path(tr, up.body) + '/cancel', 'POST', { revision: up.body.revision });
  }
  expect((await upload(page, first)).body.id).toBe(run.id);
  const next = await tree(page);
  expect((await upload(page, next)).status).toBe(429);
  fixtureDatabase('UPDATE *PREFIX*stb_imports SET created_at = created_at - 7200');
  fixtureDatabase('UPDATE *PREFIX*stb_imports SET size = ? WHERE id = ?', [536870912, run.id]);
  try {
    expect((await upload(page, next)).status).toBe(507);
  } finally {
    fixtureDatabase('UPDATE *PREFIX*stb_imports SET size = ? WHERE id = ?', [sample.length, run.id]);
  }
});

test('imported private family membership cannot leak through people, graph, records or evidence', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  await grant(page, tr.id, 'stb_viewer');
  const ready = await analyzed(
    page,
    tr,
    Buffer.from(sample.toString().replace('0 @F1@ FAM', '0 @F1@ FAM\n1 RESN privacy')),
  );
  expect(ready.status).toBe('ready');
  await confirm(page, tr, ready);
  work();
  const people = (await good(page, `/trees/${tr.id}/people`)).items;
  expect(people).toHaveLength(3);
  const ctx = await browser.newContext(),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  expect((await good(viewer, `/trees/${tr.id}/people`)).items).toEqual([]);
  for (const p of people) {
    expect((await request(viewer, `/trees/${tr.id}/people/${p.id}`)).status).toBe(404);
    expect((await request(viewer, `/trees/${tr.id}/graph?focus=${p.id}`)).status).toBe(404);
    expect((await request(viewer, `/trees/${tr.id}/records?person=${p.id}`)).status).toBe(404);
  }
  for (const c of rows('citations', tr.id))
    expect((await request(viewer, `/trees/${tr.id}/citations/${c.id}`)).status).toBe(404);
  await ctx.close();
});

test('browser upload and explicit review use the native background job; mobile layout stays within viewport', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=imports`);
  await expect(page.getByRole('heading', { name: 'GEDCOM import', exact: true })).toBeVisible();
  await page.getByLabel('GEDCOM file', { exact: true }).setInputFiles('tests/fixtures/family.ged');
  await page.getByRole('button', { name: 'Upload for review', exact: true }).click();
  await expect(page.getByRole('region', { name: 'Import review' })).toContainText('Waiting for analysis');
  const jobs = fixtureDatabase(
    'SELECT CAST(id AS CHAR) AS id FROM *PREFIX*jobs WHERE class = ?',
    ['OCA\\Stammbaum\\BackgroundJob\\ProcessImports'],
    true,
  );
  expect(jobs).toHaveLength(1);
  execFileSync('docker', [
    'exec',
    '-u',
    'www-data',
    'stammbaum-iteration-nc',
    'php',
    'occ',
    'background-job:execute',
    '--force-execute',
    String(jobs[0].id),
  ]);
  await expect(page.getByRole('button', { name: 'Confirm import', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Confirm import', exact: true })).toBeDisabled();
  expect(rows('people', tr.id)).toEqual([]);
  await page.getByRole('checkbox', { name: /I reviewed this file/ }).check();
  await page.getByRole('button', { name: 'Confirm import', exact: true }).click();
  work();
  await expect(page.getByRole('region', { name: 'Import review' })).toContainText('Import completed');
  await page.getByText('Preserved structure and verification', { exact: true }).click();
  await page.getByRole('button', { name: 'Show original structure', exact: true }).click();
  await expect(page.locator('.import-nodes')).toContainText('GEDC');
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(
      true,
    );
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.getByRole('button', { name: 'Open imported family', exact: true }).click();
  await expect(page.locator('.graph-shell')).toBeVisible();
  await expect(page.locator('#stammbaum-app')).toContainText('Ada Linden');
});

test('concurrent workers claim a run once and readers never see in-progress activation', async ({ page }) => {
  await login(page);
  const tr = await tree(page),
    up = await upload(page, tr);
  expect(up.status).toBe(201);
  await Promise.all([workAsync(), workAsync()]);
  const ready = await good(page, path(tr, up.body));
  expect(ready.status).toBe('ready');
  expect((await good(page, path(tr, ready) + '/structure')).items).toHaveLength(
    sample.toString().trimEnd().split('\n').length,
  );
  const queued = await confirm(page, tr, ready);
  fixtureDatabase(
    "CREATE TRIGGER stb_test_import_pause BEFORE INSERT ON *PREFIX*stb_people FOR EACH ROW BEGIN IF NEW.given_name = 'Robin' THEN DO GET_LOCK('stb_fixture_import_pause', 0); DO SLEEP(4); DO RELEASE_LOCK('stb_fixture_import_pause'); END IF; END",
  );
  let workers;
  try {
    workers = Promise.all([workAsync(), workAsync()]);
    await expect
      .poll(
        () => fixtureDatabase("SELECT IS_USED_LOCK('stb_fixture_import_pause') AS held", [], true)[0].held,
      )
      .not.toBeNull();
    // Ada and her claims have already been inserted inside the uncommitted transaction.
    expect((await good(page, `/trees/${tr.id}/people`)).items).toEqual([]);
    expect(rows('claims', tr.id)).toEqual([]);
    const cancellation = request(page, path(tr, queued) + '/cancel', 'POST', { revision: queued.revision });
    await workers;
    expect([409, 412]).toContain((await cancellation).status);
    expect((await good(page, path(tr, queued))).status).toBe('committed');
    expect(rows('people', tr.id)).toHaveLength(3);
    expect(rows('history', tr.id).filter((h) => h.action === 'gedcom_imported')).toHaveLength(1);
  } finally {
    if (workers) await workers;
    fixtureDatabase('DROP TRIGGER stb_test_import_pause');
  }
});

test('modified originals fail download and activation integrity checks', async ({ page }) => {
  await login(page);
  const tr = await tree(page),
    ready = await analyzed(page, tr);
  await confirm(page, tr, ready);
  replaceOriginal(ready.id, Buffer.from('fictional damage'));
  try {
    const original = await page.request.get(endpoint + path(tr, ready) + '/original');
    expect(original.status()).toBe(409);
    expect((await original.json()).error).toBe('original_mismatch');
    work();
    expect((await good(page, path(tr, ready))).failureCode).toBe('original_mismatch');
    expect(rows('people', tr.id)).toEqual([]);
  } finally {
    replaceOriginal(ready.id, sample);
  }
});

test('pending installation capacity is persistent and duplicate uploads bypass neither identity nor authority', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    up = await upload(page, tr);
  expect(up.status).toBe(201);
  const fake = [];
  try {
    for (let i = 0; i < 19; i++) {
      const id = crypto.randomUUID();
      fake.push(id);
      fixtureDatabase(
        "INSERT INTO *PREFIX*stb_imports (id, tree_id, actor_id, profile, sha256, status, report_json, plan_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'ready', '{}', '{}', 1, 1)",
        [
          id,
          tr.id,
          'fictional-capacity',
          'nc-gedcom-551-alpha1',
          createHash('sha256').update(id).digest('hex'),
        ],
      );
    }
    expect((await upload(page, tr)).body.id).toBe(up.body.id);
    const blocked = await upload(page, tr, Buffer.from(sample.toString().replace('Ada', 'Other')));
    expect(blocked.status).toBe(429);
    expect(blocked.body.error).toBe('import_capacity');
  } finally {
    for (const id of fake) fixtureDatabase('DELETE FROM *PREFIX*stb_imports WHERE id = ?', [id]);
  }
});

test('2,000-person profile boundary activates atomically and keeps reports and structure pages bounded', async ({
  page,
}) => {
  test.setTimeout(120000);
  await login(page);
  const tr = await tree(page);
  let text = '0 HEAD\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n';
  for (let i = 1; i <= 2000; i++) text += `0 @I${i}@ INDI\n1 NAME Fictional${i} /Capacity/\n`;
  text += '0 TRLR\n';
  const started = performance.now(),
    ready = await analyzed(page, tr, Buffer.from(text));
  expect(ready.status, JSON.stringify(ready.report)).toBe('ready');
  expect(ready.report.counts.people).toBe(2000);
  expect(ready.report.preview).toHaveLength(20);
  const structure = await good(page, path(tr, ready) + '/structure');
  expect(structure.items).toHaveLength(100);
  expect(structure.nextPage).toBe(1);
  await confirm(page, tr, ready);
  work();
  expect((await good(page, path(tr, ready))).status).toBe('committed');
  expect(
    Number(
      fixtureDatabase('SELECT COUNT(*) AS total FROM *PREFIX*stb_people WHERE tree_id = ?', [tr.id], true)[0]
        .total,
    ),
  ).toBe(2000);
  expect((await good(page, `/trees/${tr.id}/people`)).items).toHaveLength(50);
  test.info().annotations.push({
    type: 'capacity',
    description: `2,000-person import, analysis through activation: ${Math.round(performance.now() - started)} ms in disposable MariaDB fixture`,
  });
});
