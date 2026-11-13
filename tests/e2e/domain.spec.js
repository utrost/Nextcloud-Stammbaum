import { test, expect } from '@playwright/test';
import { login, request, good, tree, person, grant, relative, key, fixture, endpoint } from './helpers.js';
test.beforeEach(async ({ page }) => login(page));
test('tree creation is idempotent and administrator outside tree has no access', async ({
  page,
  browser,
}) => {
  const b = { name: 'Fictional idempotent tree', description: '', requestId: key() };
  const t = await good(page, '/trees', 'POST', b, 201),
    again = await good(page, '/trees', 'POST', b, 201);
  expect(again.id).toBe(t.id);
  expect(t.role).toBe('owner');
  expect((await request(page, '/trees', 'POST', { ...b, name: 'Changed' })).status).toBe(409);
  const ctx = await browser.newContext({ baseURL: fixture.baseURL });
  const outsider = await ctx.newPage();
  await login(outsider, 'stb_outsider');
  expect((await request(outsider, `/trees/${t.id}`)).status).toBe(404);
  expect((await good(outsider, '/trees')).items.some((x) => x.id === t.id)).toBe(false);
  await ctx.close();
});
test('record visibility excludes search, shared home, graph and direct lookup', async ({ page, browser }) => {
  const t = await tree(page),
    a = await person(page, t.id, 'Public'),
    hidden = await person(page, t.id, 'Private', { visibility: 'management' }),
    b = await person(page, t.id, 'VisibleChild');
  expect((await relative(page, t.id, a, hidden)).status).toBe(201);
  expect((await relative(page, t.id, hidden, b)).status).toBe(201);
  const current = await good(page, `/trees/${t.id}`);
  await good(page, `/trees/${t.id}`, 'PUT', {
    name: t.name,
    description: '',
    revision: current.revision,
    homeId: hidden.id,
  });
  await grant(page, t.id, 'stb_viewer');
  const ctx = await browser.newContext({ baseURL: fixture.baseURL }),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  const people = await good(viewer, `/trees/${t.id}/people`);
  expect(people.items.map((x) => x.id)).not.toContain(hidden.id);
  expect((await good(viewer, `/trees/${t.id}/people?query=Private`)).items).toHaveLength(0);
  expect((await request(viewer, `/trees/${t.id}/people/${hidden.id}`)).status).toBe(404);
  expect((await good(viewer, `/trees/${t.id}`)).homeId).toBeNull();
  expect((await good(viewer, '/trees')).items.find((x) => x.id === t.id).homeId).toBeNull();
  const graph = await good(viewer, `/trees/${t.id}/graph?focus=${a.id}`);
  expect(graph.people.map((x) => x.id)).toEqual([a.id]);
  expect(graph.relations).toHaveLength(0);
  expect(
    (await request(viewer, `/trees/${t.id}/people`, 'POST', { givenName: 'Denied', requestId: key() }))
      .status,
  ).toBe(403);
  await ctx.close();
});
test('person writes retain previous assertions and reject stale versions', async ({ page }) => {
  const t = await tree(page),
    p = await person(page, t.id, 'Ada', { birthDate: { kind: 'literal', value: '03/04' } });
  const edited = await good(page, `/trees/${t.id}/people/${p.id}`, 'PUT', {
    givenName: 'Adelheid',
    familyName: 'Example',
    birthDate: { kind: 'exact', value: '1890' },
    revision: p.revision,
  });
  expect(
    (
      await request(page, `/trees/${t.id}/people/${p.id}`, 'PUT', {
        givenName: 'Lost edit',
        revision: p.revision,
      })
    ).status,
  ).toBe(412);
  const claims = (await good(page, `/trees/${t.id}/people/${p.id}/claims`)).items;
  expect(claims.filter((x) => x.kind === 'name')).toHaveLength(2);
  expect(claims.find((x) => x.value?.value === '03/04').preferred).toBe(false);
  const older = claims.find((x) => x.kind === 'name' && x.value.givenName === 'Ada');
  const assessed = await good(page, `/trees/${t.id}/people/${p.id}/claims/assess`, 'POST', {
    claimId: older.id,
    revision: edited.revision,
    assessment: 'disputed',
  });
  const preferred = await good(page, `/trees/${t.id}/people/${p.id}/claims/prefer`, 'POST', {
    claimId: older.id,
    revision: assessed.revision,
  });
  expect(preferred.givenName).toBe('Ada');
  expect(
    (await good(page, `/trees/${t.id}/people/${p.id}/claims`)).items.find((x) => x.id === older.id)
      .assessment,
  ).toBe('disputed');
  expect((await good(page, `/trees/${t.id}/people/${p.id}/history`)).items.length).toBeGreaterThanOrEqual(3);
});
test('calendar validation, unnamed confirmation and unknown defaults', async ({ page }) => {
  const t = await tree(page);
  expect((await request(page, `/trees/${t.id}/people`, 'POST', { requestId: key() })).status).toBe(422);
  const unnamed = await good(
    page,
    `/trees/${t.id}/people`,
    'POST',
    { requestId: key(), confirmUnnamed: true },
    201,
  );
  expect(unnamed.givenName).toBe('');
  expect(unnamed.livingStatus).toBe('unknown');
  expect(unnamed.birthDate.value).toBe('');
  for (const value of ['1900-02-29', '2020-13', '0000'])
    expect(
      (
        await request(page, `/trees/${t.id}/people`, 'POST', {
          givenName: 'Invalid',
          birthDate: { kind: 'exact', value },
          requestId: key(),
        })
      ).status,
    ).toBe(422);
  const leap = await person(page, t.id, 'Leap', { birthDate: { kind: 'exact', value: '2000-02-29' } });
  expect(leap.birthDate.value).toBe('2000-02-29');
});
test('biological cycles, duplicates and cross-tree edges fail; relative creation rolls back', async ({
  page,
}) => {
  const t = await tree(page),
    a = await person(page, t.id, 'Ancestor'),
    b = await person(page, t.id, 'Child'),
    c = await person(page, t.id, 'Grandchild');
  expect((await relative(page, t.id, a, b)).status).toBe(201);
  expect((await relative(page, t.id, b, c)).status).toBe(201);
  expect((await relative(page, t.id, c, a)).status).toBe(409);
  expect((await relative(page, t.id, a, b)).status).toBe(409);
  expect((await relative(page, t.id, a, a)).status).toBe(422);
  const otherTree = await tree(page),
    foreign = await person(page, otherTree.id, 'Foreign');
  const current = await good(page, `/trees/${t.id}/people/${a.id}`);
  expect(
    (
      await request(page, `/trees/${t.id}/people/${a.id}/relations`, 'POST', {
        revision: current.revision,
        personId: foreign.id,
        targetRevision: foreign.revision,
        direction: 'child',
        parentType: 'biological',
        requestId: key(),
      })
    ).status,
  ).toBe(404);
  const before = (await good(page, `/trees/${t.id}/people`)).items.length;
  expect(
    (
      await request(page, `/trees/${t.id}/people/${a.id}/relations`, 'POST', {
        revision: current.revision,
        person: { givenName: 'Rolled back' },
        direction: 'child',
        parentType: 'invalid',
        requestId: key(),
      })
    ).status,
  ).toBe(422);
  expect((await good(page, `/trees/${t.id}/people`)).items).toHaveLength(before);
  expect((await relative(page, t.id, c, a, 'child', 'adoptive')).status).toBe(201);
  const graph = await good(page, `/trees/${t.id}/graph?focus=${a.id}&up=8&down=8`);
  expect(new Set(graph.people.map((p) => p.id)).size).toBe(graph.people.length);
});
test('delete and restore preserve person without reconnecting old relationships', async ({ page }) => {
  const t = await tree(page),
    a = await person(page, t.id, 'Parent'),
    b = await person(page, t.id, 'Child');
  expect((await relative(page, t.id, a, b)).status).toBe(201);
  const currentTree = await good(page, `/trees/${t.id}`);
  await good(page, `/trees/${t.id}`, 'PUT', {
    name: currentTree.name,
    description: currentTree.description,
    revision: currentTree.revision,
    homeId: b.id,
  });
  let p = await good(page, `/trees/${t.id}/people/${b.id}`);
  await good(page, `/trees/${t.id}/people/${b.id}/delete`, 'POST', { revision: p.revision, confirmed: true });
  expect((await request(page, `/trees/${t.id}/people/${b.id}`)).status).toBe(404);
  expect((await good(page, `/trees/${t.id}`)).homeId).toBeNull();
  const changed = await good(page, `/trees/${t.id}/people/${a.id}`, 'PUT', {
    givenName: 'Updated Parent',
    familyName: 'Example',
    revision: (await good(page, `/trees/${t.id}/people/${a.id}`)).revision,
  });
  p = (await good(page, `/trees/${t.id}/people?deleted=true`)).items.find((x) => x.id === b.id);
  await good(page, `/trees/${t.id}/people/${b.id}/restore`, 'POST', {
    revision: p.revision,
    confirmed: true,
  });
  expect((await good(page, `/trees/${t.id}/people/${b.id}/relations`)).items).toHaveLength(0);
  expect((await good(page, `/trees/${t.id}/people/${a.id}`)).givenName).toBe(changed.givenName);
  expect((await relative(page, t.id, a, b)).status).toBe(201);
});
test('last owner and manager privilege boundaries survive stale membership updates', async ({
  page,
  browser,
}) => {
  const t = await tree(page),
    owner = (await good(page, `/trees/${t.id}/members`)).items[0];
  expect(
    (
      await request(page, `/trees/${t.id}/members`, 'POST', {
        userId: owner.userId,
        revision: owner.revision,
        role: 'viewer',
        remove: true,
      })
    ).status,
  ).toBe(409);
  await grant(page, t.id, 'stb_manager', 'manager');
  await grant(page, t.id, 'stb_editor', 'editor');
  const ctx = await browser.newContext({ baseURL: fixture.baseURL }),
    manager = await ctx.newPage();
  await login(manager, 'stb_manager');
  expect(
    (
      await request(manager, `/trees/${t.id}/members`, 'POST', {
        userId: 'stb_viewer',
        revision: 0,
        role: 'owner',
      })
    ).status,
  ).toBe(403);
  const editor = (await good(manager, `/trees/${t.id}/members`)).items.find((x) => x.userId === 'stb_editor');
  await good(manager, `/trees/${t.id}/members`, 'POST', {
    ...{ userId: editor.userId, revision: editor.revision },
    role: 'viewer',
    exportAllowed: true,
  });
  expect(
    (
      await request(manager, `/trees/${t.id}/members`, 'POST', {
        userId: editor.userId,
        revision: editor.revision,
        role: 'editor',
      })
    ).status,
  ).toBe(412);
  await ctx.close();
});
test('invitations require review and explicit single-use acceptance', async ({ page, browser }) => {
  const t = await tree(page),
    invite = await good(
      page,
      `/trees/${t.id}/invitations`,
      'POST',
      { role: 'viewer', exportAllowed: false },
      201,
    );
  const ctx = await browser.newContext({ baseURL: fixture.baseURL }),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  expect((await request(viewer, `/trees/${t.id}`)).status).toBe(404);
  expect((await good(viewer, '/invitations/review', 'POST', { token: invite.token })).treeName).toBe(t.name);
  expect((await request(viewer, `/trees/${t.id}`)).status).toBe(404);
  expect((await good(viewer, '/invitations/accept', 'POST', { token: invite.token })).role).toBe('viewer');
  expect((await request(viewer, '/invitations/accept', 'POST', { token: invite.token })).status).toBe(404);
  await ctx.close();
});
test('widening visibility does not expose private historic assertions or search wording', async ({
  page,
  browser,
}) => {
  const t = await tree(page),
    p = await person(page, t.id, 'SecretEarlier', { visibility: 'management' });
  await good(page, `/trees/${t.id}/people/${p.id}`, 'PUT', {
    givenName: 'Released',
    familyName: 'Example',
    visibility: 'members',
    confirmRelease: true,
    revision: p.revision,
  });
  await grant(page, t.id, 'stb_viewer');
  const ctx = await browser.newContext({ baseURL: fixture.baseURL }),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  const all = await good(viewer, `/trees/${t.id}/people/${p.id}/claims`);
  expect(JSON.stringify(all)).not.toContain('SecretEarlier');
  expect(JSON.stringify(await good(viewer, `/trees/${t.id}/people/${p.id}/history`))).not.toContain(
    'SecretEarlier',
  );
  expect((await good(viewer, `/trees/${t.id}/people?query=SecretEarlier`)).items).toHaveLength(0);
  await ctx.close();
});
test('mutations require CSRF even with an authenticated browser session', async ({ page }) => {
  const r = await page.request.post(endpoint + '/trees', { data: { name: 'Denied CSRF', requestId: key() } });
  expect([401, 403, 412]).toContain(r.status());
});
