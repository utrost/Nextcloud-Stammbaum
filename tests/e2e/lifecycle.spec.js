import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { fixtureDatabase } from './server-fixture.js';
import { login, tree, grant, good, request, key, fixture, person } from './helpers.js';
const occ = (...args) =>
  execFileSync('docker', ['exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ', ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
function add(uid) {
  execFileSync(
    'docker',
    [
      'exec',
      '-e',
      'OC_PASS',
      '-u',
      'www-data',
      'stammbaum-iteration-nc',
      'php',
      'occ',
      'user:add',
      '--password-from-env',
      uid,
    ],
    { env: { ...process.env, OC_PASS: fixture.password }, stdio: ['ignore', 'pipe', 'pipe'] },
  );
}

test('revoked invitation cannot be reviewed or accepted; leave removes access immediately', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  await grant(page, tr.id, 'stb_viewer');
  const invite = await good(page, `/trees/${tr.id}/invitations`, 'POST', { role: 'editor' }, 201);
  const list = await good(page, `/trees/${tr.id}/invitations`);
  expect(list.items).toHaveLength(1);
  expect(JSON.stringify(list)).not.toContain(invite.token);
  await good(page, `/trees/${tr.id}/invitations/${list.items[0].id}/revoke`, 'POST', {});
  expect((await request(page, '/invitations/review', 'POST', { token: invite.token })).status).toBe(404);
  const ctx = await browser.newContext(),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  const own = await good(viewer, `/trees/${tr.id}`);
  await good(viewer, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_viewer',
    remove: true,
    revision: own.membershipRevision,
  });
  expect((await request(viewer, `/trees/${tr.id}`)).status).toBe(404);
  await ctx.close();
});

test('Nextcloud disable and password events revoke invitation authority; recreated account does not inherit membership', async ({
  page,
  browser,
}) => {
  test.setTimeout(90000);
  const uid = 'stb_life_' + key().slice(0, 8);
  let ctx;
  try {
    add(uid);
    await login(page);
    const tr = await tree(page);
    await grant(page, tr.id, uid, 'manager');
    ctx = await browser.newContext();
    const member = await ctx.newPage();
    await login(member, uid);
    const before = await good(member, `/trees/${tr.id}`),
      invite = await good(member, `/trees/${tr.id}/invitations`, 'POST', { role: 'viewer' }, 201);
    occ('user:disable', uid);
    occ('user:enable', uid);
    expect((await request(page, '/invitations/review', 'POST', { token: invite.token })).status).toBe(404);
    await ctx.close();
    ctx = await browser.newContext();
    const again = await ctx.newPage();
    await login(again, uid);
    expect((await good(again, `/trees/${tr.id}`)).membershipRevision).toBeGreaterThan(
      before.membershipRevision,
    );
    const homePerson = await person(page, tr.id, 'Account home');
    const authored = await person(again, tr.id, 'Retained authorship');
    await good(again, `/trees/${tr.id}/views/home`, 'PUT', { revision: 1, homeId: homePerson.id });
    await good(again, `/trees/${tr.id}/connections`);
    const incarnation = fixtureDatabase(
      'SELECT incarnation FROM *PREFIX*stb_connection_actors WHERE actor_id = ?',
      [uid],
      true,
    )[0].incarnation;
    const linkId = crypto.randomUUID();
    fixtureDatabase(
      'INSERT INTO *PREFIX*stb_contact_links (id, tree_id, person_id, actor_id, book_key, card_uri, card_uid, card_hash, request_id, member_revision, created_at, updated_at, status, source_revision, account_incarnation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [
        linkId,
        tr.id,
        homePerson.id,
        uid,
        'fictional-book',
        'fictional.vcf',
        'fictional-card',
        '0'.repeat(64),
        key(),
        (await good(again, `/trees/${tr.id}`)).membershipRevision,
        Math.floor(Date.now() / 1000),
        Math.floor(Date.now() / 1000),
        'linked',
        homePerson.revision,
        incarnation,
      ],
    );
    const second = await good(again, `/trees/${tr.id}/invitations`, 'POST', { role: 'viewer' }, 201);
    execFileSync(
      'docker',
      [
        'exec',
        '-e',
        'OC_PASS',
        '-u',
        'www-data',
        'stammbaum-iteration-nc',
        'php',
        'occ',
        'user:resetpassword',
        '--password-from-env',
        uid,
      ],
      { env: { ...process.env, OC_PASS: fixture.password }, stdio: ['ignore', 'pipe', 'pipe'] },
    );
    expect((await request(page, '/invitations/review', 'POST', { token: second.token })).status).toBe(404);
    occ('user:delete', uid);
    expect((await good(page, `/trees/${tr.id}/people/${authored.id}`)).id).toBe(authored.id);
    expect(
      (await good(page, `/trees/${tr.id}/people/${authored.id}/history`)).items.some(
        (entry) => entry.actorId === uid,
      ),
    ).toBe(true);
    expect(
      fixtureDatabase('SELECT id FROM *PREFIX*stb_contact_links WHERE actor_id = ?', [uid], true),
    ).toEqual([]);
    expect(
      fixtureDatabase('SELECT actor_id FROM *PREFIX*stb_connection_actors WHERE actor_id = ?', [uid], true),
    ).toEqual([]);
    expect(
      fixtureDatabase('SELECT user_id FROM *PREFIX*stb_preferences WHERE user_id = ?', [uid], true),
    ).toEqual([]);
    add(uid);
    await ctx.close();
    ctx = await browser.newContext();
    const recreated = await ctx.newPage();
    await login(recreated, uid);
    expect((await good(recreated, `/trees/${tr.id}/connections`)).items).toEqual([]);
    expect((await request(recreated, `/trees/${tr.id}`)).status).toBe(404);
    expect((await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === uid).active).toBe(
      false,
    );
  } finally {
    if (ctx) await ctx.close();
    try {
      occ('user:delete', uid);
    } catch {}
  }
});
