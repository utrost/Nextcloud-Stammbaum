import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fixtureDatabase } from './server-fixture.js';
import { login, tree, person, good, request, key, endpoint, fixture } from './helpers.js';

const source = readFileSync('tests/fixtures/family.ged');
const cleanup = [];
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
const processExports = () =>
  execFileSync('docker', [
    'exec',
    '-u',
    'www-data',
    'stammbaum-iteration-nc',
    'php',
    '-r',
    "define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$app=new \\OCA\\Stammbaum\\AppInfo\\Application();$app->getContainer()->get(\\OCA\\Stammbaum\\Service\\Exports::class)->work();",
  ]);

test.afterEach(() => {
  for (const id of cleanup.splice(0)) {
    fixtureDatabase('DELETE FROM *PREFIX*stb_exports WHERE tree_id = ?', [id]);
    fixtureDatabase(
      "DELETE FROM *PREFIX*stb_claims WHERE tree_id = ? AND actor_id = 'stb_capacity_fixture'",
      [id],
    );
    fixtureDatabase("DELETE FROM *PREFIX*stb_people WHERE tree_id = ? AND given_name LIKE 'Capacity %'", [
      id,
    ]);
  }
});

test('the actual 50,000-object projection admits the boundary and rejects one more object', async ({
  page,
}) => {
  test.setTimeout(240000);
  await login(page);
  const tr = await tree(page);
  cleanup.push(tr.id);
  const p = await person(page, tr.id, 'Fictional object limit', { livingStatus: 'deceased' });
  const existing = Number(
    fixtureDatabase('SELECT COUNT(*) AS n FROM *PREFIX*stb_claims WHERE tree_id = ?', [tr.id], true)[0].n,
  );
  const payload = Buffer.from(
    JSON.stringify({ tree: tr.id, person: p.id, count: 49999 - existing }),
  ).toString('base64');
  const code = `define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$v=json_decode(base64_decode('${payload}'),true);$db=\\OCP\\Server::get(\\OCP\\IDBConnection::class);$db->beginTransaction();try{for($i=0;$i<$v['count'];$i++){$db->executeStatement('INSERT INTO *PREFIX*stb_claims (id,tree_id,person_id,kind,value_json,assessment,visibility,preferred,created_at,actor_id) VALUES (?,?,?,?,?,?,?,?,?,?)',[substr($v['tree'],0,8).'-'.str_pad((string)$i,27,'0',STR_PAD_LEFT),$v['tree'],$v['person'],'name','{"givenName":"Alternative","familyName":"Example"}','supported','members',1,time(),'stb_capacity_fixture']);}$db->commit();echo $v['count'];}catch(\\Throwable $e){$db->rollBack();throw $e;}`;
  execFileSync('docker', ['exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', '-r', code], {
    timeout: 180000,
  });
  const atLimit = await good(page, `/trees/${tr.id}/exports/preview`, 'POST', { options: {} });
  expect(Object.values(atLimit.counts).reduce((sum, n) => sum + n, 0)).toBe(50000);
  fixtureDatabase(
    'INSERT INTO *PREFIX*stb_claims (id,tree_id,person_id,kind,value_json,assessment,visibility,preferred,created_at,actor_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
    [
      key(),
      tr.id,
      p.id,
      'name',
      '{"givenName":"Extra","familyName":"Example"}',
      'supported',
      'members',
      1,
      Math.floor(Date.now() / 1000),
      'stb_capacity_fixture',
    ],
  );
  expect((await request(page, `/trees/${tr.id}/exports/preview`, 'POST', { options: {} })).status).toBe(413);
  expect((await good(page, `/trees/${tr.id}/exports`)).items).toEqual([]);
});

test('cancellation at the final publication checkpoint leaves no downloadable artifact', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  cleanup.push(tr.id);
  await person(page, tr.id, 'Fictional cancelled export', { livingStatus: 'deceased' });
  const pdf = readFileSync('tests/fixtures/media-two-pages.pdf');
  const upload = await page.request.post(`${endpoint}/trees/${tr.id}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      visibility: 'members',
      requestId: key(),
      file: { name: 'fictional.pdf', mimeType: 'application/pdf', buffer: pdf },
    },
  });
  expect(upload.status()).toBe(201);
  const review = await good(page, `/trees/${tr.id}/exports/preview`, 'POST', { options: { media: true } });
  const job = await good(
    page,
    `/trees/${tr.id}/exports`,
    'POST',
    { ...review, acknowledged: true, requestId: key() },
    201,
  );
  const payload = Buffer.from(JSON.stringify({ tree: tr.id, id: job.id, actor: fixture.user })).toString(
    'base64',
  );
  const code = `define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$v=json_decode(base64_decode('${payload}'),true);$app=new \\OCA\\Stammbaum\\AppInfo\\Application();$s=$app->getContainer()->get(\\OCA\\Stammbaum\\Service\\Exports::class);$s->work(function()use($s,$v){$s->cancel($v['tree'],$v['id'],$v['actor']);});$f=\\OCP\\Server::get(\\OCP\\Files\\AppData\\IAppDataFactory::class)->get('stammbaum')->getFolder('exports-v1');echo json_encode(['ged'=>$f->fileExists($v['id'].'.ged'),'json'=>$f->fileExists($v['id'].'.json'),'zip'=>$f->fileExists($v['id'].'.zip')]);`;
  const result = JSON.parse(
    execFileSync('docker', ['exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', '-r', code], {
      encoding: 'utf8',
    }),
  );
  expect(result).toEqual({ ged: false, json: false, zip: false });
  expect((await good(page, `/trees/${tr.id}/exports/${job.id}`)).state).toBe('cancelled');
  expect((await page.request.get(`${endpoint}/trees/${tr.id}/exports/${job.id}/gedcom`)).status()).toBe(409);
});

async function uploadImport(page, treeId, bytes) {
  const response = await page.request.post(`${endpoint}/trees/${treeId}/imports`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: { file: { name: 'fictional.ged', mimeType: 'text/plain', buffer: bytes } },
  });
  expect(response.status(), await response.text()).toBe(201);
  return response.json();
}

test('current-state export can be reviewed and imported into a fresh tree within the supported profile', async ({
  page,
}) => {
  test.setTimeout(120000);
  // All existing runs in this isolated test instance are fictional. Keep their
  // history while removing earlier test-run scheduling effects.
  fixtureDatabase(
    'UPDATE *PREFIX*stb_imports SET created_at = created_at - 7200 WHERE actor_id = ? AND created_at > ?',
    [fixture.user, Math.floor(Date.now() / 1000) - 3600],
  );
  await login(page);
  const original = await tree(page);
  cleanup.push(original.id);
  const upload = await uploadImport(page, original.id, source);
  processImports();
  const ready = await good(page, `/trees/${original.id}/imports/${upload.id}`);
  expect(ready.status, JSON.stringify(ready.report)).toBe('ready');
  await good(page, `/trees/${original.id}/imports/${upload.id}/confirm`, 'POST', {
    revision: ready.revision,
    treeRevision: ready.treeRevision,
    sha256: ready.sha256,
    planHash: ready.planHash,
    acknowledgeReview: true,
  });
  processImports();
  const before = (await good(page, `/trees/${original.id}/people`)).items;
  expect(before).toHaveLength(3);
  const review = await good(page, `/trees/${original.id}/exports/preview`, 'POST', {
    options: { living: true, management: true },
  });
  const job = await good(
    page,
    `/trees/${original.id}/exports`,
    'POST',
    {
      ...review,
      acknowledged: true,
      requestId: key(),
    },
    201,
  );
  processExports();
  expect((await good(page, `/trees/${original.id}/exports/${job.id}`)).state).toBe('ready');
  const exported = await (
    await page.request.get(`${endpoint}/trees/${original.id}/exports/${job.id}/gedcom`)
  ).body();
  expect(exported.toString()).toContain('1 _STB_PARTNER Y');
  expect(exported.toString()).not.toContain('1 MARR');
  const destination = await tree(page);
  cleanup.push(destination.id);
  const second = await uploadImport(page, destination.id, exported);
  processImports();
  const reviewed = await good(page, `/trees/${destination.id}/imports/${second.id}`);
  expect(reviewed.status, JSON.stringify(reviewed.report)).toBe('ready');
  await good(page, `/trees/${destination.id}/imports/${second.id}/confirm`, 'POST', {
    revision: reviewed.revision,
    treeRevision: reviewed.treeRevision,
    sha256: reviewed.sha256,
    planHash: reviewed.planHash,
    acknowledgeReview: true,
  });
  processImports();
  const after = (await good(page, `/trees/${destination.id}/people`)).items;
  expect(after.map((p) => p.givenName).sort()).toEqual(before.map((p) => p.givenName).sort());
  expect(
    fixtureDatabase(
      'SELECT COUNT(*) AS n FROM *PREFIX*stb_relations WHERE tree_id = ?',
      [destination.id],
      true,
    )[0].n,
  ).toBe(3);
  expect(
    fixtureDatabase(
      "SELECT COUNT(*) AS n FROM *PREFIX*stb_relations WHERE tree_id = ? AND kind = 'partner'",
      [destination.id],
      true,
    )[0].n,
  ).toBe(1);
  const sources = (await good(page, `/trees/${destination.id}/sources`)).items;
  expect(sources).toHaveLength(1);
  expect(sources[0].description).toBe('Test evidence only');
});

test('export review enforces the 2,000-person boundary without admitting an oversized job', async ({
  page,
}) => {
  test.setTimeout(120000);
  await login(page);
  const tr = await tree(page);
  cleanup.push(tr.id);
  const prototype = await person(page, tr.id, 'Fictional seed', { livingStatus: 'deceased' });
  const payload = Buffer.from(JSON.stringify({ tree: tr.id, id: prototype.id })).toString('base64');
  const code = `define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$p=json_decode(base64_decode('${payload}'),true);$app=new \\OCA\\Stammbaum\\AppInfo\\Application();$s=$app->getContainer()->get(\\OCA\\Stammbaum\\Service\\Store::class);$s->write(function()use($s,$p){$r=$s->one('SELECT * FROM *PREFIX*stb_people WHERE id = ?',[$p['id']]);for($i=1;$i<=2000;$i++){$r['id']=\\OCA\\Stammbaum\\Service\\Store::id();$r['given_name']='Capacity '.$i;$r['search_text']='capacity '.$i;$s->insert('stb_people',array_diff_key($r,['deleted'=>true]));}});`;
  // Fixture rows are scoped to this fictional tree and removed in afterEach.
  execFileSync('docker', ['exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', '-r', code]);
  const full = await request(page, `/trees/${tr.id}/exports/preview`, 'POST', { options: {} });
  expect(full.status).toBe(413);
  expect((await good(page, `/trees/${tr.id}/exports`)).items).toEqual([]);
  fixtureDatabase("DELETE FROM *PREFIX*stb_people WHERE tree_id = ? AND given_name = 'Capacity 2000'", [
    tr.id,
  ]);
  const limit = await good(page, `/trees/${tr.id}/exports/preview`, 'POST', { options: {} });
  expect(limit.counts.people).toBe(2000);
});

test('packaged media cannot exceed 64 MiB and twenty queued jobs block admission', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  cleanup.push(tr.id);
  await person(page, tr.id, 'Fictional capacity', { livingStatus: 'deceased' });
  const pdf = readFileSync('tests/fixtures/media-two-pages.pdf');
  const uploaded = await page.request.post(`${endpoint}/trees/${tr.id}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      title: 'Fictional capacity document',
      visibility: 'members',
      requestId: key(),
      file: { name: 'capacity.pdf', mimeType: 'application/pdf', buffer: pdf },
    },
  });
  expect(uploaded.status()).toBe(201);
  const medium = await uploaded.json();
  try {
    // Only the disposable fixture's accounting row is altered. Original bytes are untouched.
    fixtureDatabase('UPDATE *PREFIX*stb_media SET size = ? WHERE id = ?', [67108865, medium.id]);
    expect(
      (await request(page, `/trees/${tr.id}/exports/preview`, 'POST', { options: { media: true } })).status,
    ).toBe(413);
    expect((await good(page, `/trees/${tr.id}/exports/preview`, 'POST', { options: {} })).counts.media).toBe(
      1,
    );
  } finally {
    fixtureDatabase('UPDATE *PREFIX*stb_media SET size = ? WHERE id = ?', [pdf.length, medium.id]);
  }
  const review = await good(page, `/trees/${tr.id}/exports/preview`, 'POST', { options: {} });
  const now = Math.floor(Date.now() / 1000);
  for (let i = 0; i < 20; i++) {
    fixtureDatabase(
      "INSERT INTO *PREFIX*stb_exports (id,tree_id,actor_id,state,tree_revision,actor_revision,created_at,updated_at,expires_at,options_json,report_json) VALUES (?,?,?,'queued',?,?,?,?,?,?,?)",
      [key(), tr.id, `stb_capacity_${i}`, review.revision, 1, now, now, now + 86400, '{}', '{}'],
    );
  }
  expect(
    (
      await request(page, `/trees/${tr.id}/exports`, 'POST', {
        ...review,
        acknowledged: true,
        requestId: key(),
      })
    ).status,
  ).toBe(429);
  expect((await good(page, `/trees/${tr.id}/exports`)).items).toEqual([]);
});
