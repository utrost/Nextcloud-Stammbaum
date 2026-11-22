import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { credentialAuthority } from './credential-fixture.js';
import { endpoint, fixture, good, grant, key, login, request, tree } from './helpers.js';
import { sourcePhp, sourcePhpWithInput } from './restore-fixture.js';
import { fixtureDatabase } from './server-fixture.js';

const path = '/tool-credentials';
const resolve = (secret, treeId, scope) => credentialAuthority(sourcePhpWithInput, secret, treeId, scope);

async function fictionalAccount(browser, use) {
  const uid = `stb_credential_${key().slice(0, 10)}`;
  execFileSync(
    'docker',
    [
      'exec',
      '-e',
      'OC_PASS',
      '-u',
      'www-data',
      fixture.container,
      'php',
      'occ',
      'user:add',
      '--password-from-env',
      uid,
    ],
    { env: { ...process.env, OC_PASS: fixture.password }, stdio: 'pipe' },
  );
  const context = await browser.newContext();
  try {
    sourcePhp('occ', 'user:setting', uid, 'core', 'lang', 'en');
    const page = await context.newPage();
    await login(page, uid);
    return await use(page, uid);
  } finally {
    await context.close();
    sourcePhp('occ', 'user:delete', uid);
  }
}

test('browser-issued tool credentials show the secret once and preserve only current selected grants', async ({
  page,
  browser,
}) => {
  test.setTimeout(100_000);
  await login(page);
  const first = await tree(page);
  const second = await tree(page);
  const later = await tree(page);
  await fictionalAccount(browser, async (editor, uid) => {
    await grant(page, first.id, uid, 'editor');
    await grant(page, second.id, uid);
    const command = {
      label: 'Fictional tool',
      scopes: ['tree.read', 'tree.write'],
      treeIds: [first.id, second.id],
      expiresInDays: 1,
      requestId: key(),
    };

    const missingCsrf = await editor.request.post(endpoint + path, { data: command });
    expect(missingCsrf.ok()).toBe(false);
    expect((await good(editor, path)).items).toEqual([]);
    const token = await good(editor, path, 'POST', command, 201);
    const { secret, ...safe } = token;
    expect(Boolean(secret?.match(/^stb_[a-f0-9]{64}$/))).toBe(true);
    expect(
      await good(editor, path, 'POST', { ...command, treeIds: [...command.treeIds].reverse() }, 201),
    ).toEqual(safe);
    expect((await request(editor, path, 'POST', { ...command, label: 'Different label' })).status).toBe(409);
    expect((await good(editor, path)).items).toEqual([safe]);
    const [stored] = fixtureDatabase(
      'SELECT secret_hash FROM *PREFIX*stb_tool_credentials WHERE id = ?',
      [token.id],
      true,
    );
    expect(stored.secret_hash).toBe(createHash('sha256').update(secret).digest('hex'));
    const mixedAuth = await editor.request.get(endpoint + path, {
      headers: { Authorization: `Bearer ${secret}` },
    });
    expect(mixedAuth.ok()).toBe(false);
    expect((await request(page, `${path}/${token.id}`, 'DELETE')).status).toBe(404);

    expect(resolve(secret, first.id, 'tree.write')).toEqual({
      status: 200,
      actorId: uid,
      credentialId: token.id,
    });
    expect(resolve(secret, second.id, 'tree.read').status).toBe(200);
    expect(resolve(secret, second.id, 'tree.write').status).toBe(403);
    await grant(page, later.id, uid, 'editor');
    expect(resolve(secret, later.id, 'tree.read').status).toBe(404);
    const membership = (await good(page, `/trees/${first.id}/members`)).items.find(
      (member) => member.userId === uid,
    );
    await good(page, `/trees/${first.id}/members`, 'POST', {
      userId: uid,
      role: 'manager',
      revision: membership.revision,
    });
    expect(resolve(secret, first.id, 'tree.read').status).toBe(404);
    expect(resolve(secret, second.id, 'tree.read').status).toBe(200);

    const writeOnly = await good(
      editor,
      path,
      'POST',
      { ...command, requestId: key(), scopes: ['tree.write'] },
      201,
    );
    expect(resolve(writeOnly.secret, first.id, 'tree.write').status).toBe(200);
    expect(resolve(writeOnly.secret, first.id, 'tree.read')).toEqual({
      status: 403,
      error: 'credential_scope',
    });
    await good(editor, `${path}/${token.id}`, 'DELETE');
    expect(resolve(secret, second.id, 'tree.read').status).toBe(401);
    expect(resolve(writeOnly.secret, first.id, 'tree.write').status).toBe(200);
    await good(editor, path, 'DELETE');
    expect((await good(editor, path)).items).toEqual([]);
    expect(resolve(writeOnly.secret, first.id, 'tree.write').status).toBe(401);
  });
});

test('expired, throttled and disabled account authority cannot be used as a tool credential', async ({
  browser,
}) => {
  test.setTimeout(75_000);
  await fictionalAccount(browser, async (page, uid) => {
    const family = await tree(page);
    const command = {
      label: 'Fictional tool',
      scopes: ['tree.read'],
      treeIds: [family.id],
      expiresInDays: 365,
      requestId: key(),
    };
    const token = await good(page, path, 'POST', command, 201);
    expect(token.expiresAt - token.createdAt).toBe(365 * 86400);
    const expired = await good(page, path, 'POST', { ...command, requestId: key() }, 201);
    fixtureDatabase('UPDATE *PREFIX*stb_tool_credentials SET expires_at = ? WHERE id = ?', [1, expired.id]);
    expect(resolve(expired.secret, family.id, 'tree.read').status).toBe(401);

    fixtureDatabase('UPDATE *PREFIX*stb_tool_issue_limits SET attempts = 20 WHERE actor_id = ?', [uid]);
    const limit = await page.request.post(endpoint + path, {
      headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
      data: { ...command, requestId: key() },
    });
    expect(limit.status()).toBe(429);
    expect(Number(limit.headers()['retry-after'])).toBeGreaterThan(0);
    expect(Number(limit.headers()['retry-after'])).toBeLessThanOrEqual(900);
    sourcePhp('occ', 'user:disable', uid);
    expect(resolve(token.secret, family.id, 'tree.read').status).toBe(401);
    sourcePhp('occ', 'user:enable', uid);
    expect(resolve(token.secret, family.id, 'tree.read').status).toBe(401);
  });
});
