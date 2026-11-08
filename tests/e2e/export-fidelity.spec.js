import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { login, tree, person, good, key, fixture, endpoint } from './helpers.js';
import { editable } from '../../src/domain.js';
function php(code) {
  return execFileSync('docker', [
    'exec',
    '-u',
    'www-data',
    fixture.container,
    'php',
    '-r',
    "define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$app=new \\OCA\\Stammbaum\\AppInfo\\Application();$s=$app->getContainer()->get(\\OCA\\Stammbaum\\Service\\Store::class);" +
      code,
  ]).toString();
}
test('export keeps an active unassessed alternative but never resurrects a supported superseded preferred name', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  let p = await person(page, tr.id, 'OldName', { livingStatus: 'deceased' });
  try {
    const claims = (await good(page, `/trees/${tr.id}/people/${p.id}/claims`)).items;
    const name = claims.find((c) => c.kind === 'name');
    p = await good(page, `/trees/${tr.id}/people/${p.id}/claims/assess`, 'POST', {
      revision: p.revision,
      claimId: name.id,
      assessment: 'supported',
    });
    p = await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
      ...editable(p),
      revision: p.revision,
      givenName: 'CurrentName',
    });
    const payload = Buffer.from(JSON.stringify({ tree: tr.id, person: p.id })).toString('base64');
    php(
      `$p=json_decode(base64_decode('${payload}'),true);$s->write(function()use($s,$p){$s->insert('stb_claims',['id'=>\\OCA\\Stammbaum\\Service\\Store::id(),'tree_id'=>$p['tree'],'person_id'=>$p['person'],'kind'=>'name','value_json'=>json_encode(['givenName'=>'ActiveAlternative','familyName'=>'Example']),'assessment'=>'unassessed','visibility'=>'members','preferred'=>0,'created_at'=>time(),'actor_id'=>'stb_owner']);$s->audit($p['tree'],'stb_owner','person_updated',$p['person'],['givenName'=>'ActiveAlternative','familyName'=>'Example'],'management');$s->bump($p['tree']);});`,
    );
    const context = await good(
      page,
      `/trees/${tr.id}/contexts`,
      'POST',
      { label: 'Fictional source context', members: [{ personId: p.id, role: 'child' }], requestId: key() },
      201,
    );
    const source = await good(
      page,
      `/trees/${tr.id}/sources`,
      'POST',
      { title: 'Context source', requestId: key() },
      201,
    );
    await good(
      page,
      `/trees/${tr.id}/citations`,
      'POST',
      {
        sourceId: source.id,
        quotation: 'Current context quotation',
        targets: [{ kind: 'context', targetId: context.id, effect: 'reference' }],
        requestId: key(),
      },
      201,
    );
    const review = await good(page, `/trees/${tr.id}/exports/preview`, 'POST', { options: {} });
    const job = await good(
      page,
      `/trees/${tr.id}/exports`,
      'POST',
      { ...review, acknowledged: true, requestId: key() },
      201,
    );
    php(
      '$e=$app->getContainer()->get(\\OCA\\Stammbaum\\Service\\Exports::class);for($i=0;$i<20;$i++){if($e->work()===null)break;}',
    );
    expect((await good(page, `/trees/${tr.id}/exports/${job.id}`)).state).toBe('ready');
    const ged = await (await page.request.get(`${endpoint}/trees/${tr.id}/exports/${job.id}/gedcom`)).text();
    expect(ged).toContain('CurrentName');
    expect(ged).toContain('ActiveAlternative');
    expect(ged).not.toContain('OldName');
    expect(ged).toContain('Current context quotation');
    expect(ged.replace(/\r?\n\d+ CONC /g, '')).toContain('Fictional source context');
  } finally {
    php(`$s->execute('DELETE FROM *PREFIX*stb_exports WHERE tree_id = ?',['${tr.id}']);`);
  }
});
