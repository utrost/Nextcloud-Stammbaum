import { test, expect } from '@playwright/test';
import { login, tree, person, grant, good, request, key, fixture } from './helpers.js';
import { editable } from '../../src/domain.js';
import { fixtureDatabase } from './server-fixture.js';

test('a signed-out recipient can sign in, review and accept a single-use invitation', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  const invite = await good(
    page,
    `/trees/${tr.id}/invitations`,
    'POST',
    {
      role: 'viewer',
      exportAllowed: false,
    },
    201,
  );
  const context = await browser.newContext({ baseURL: fixture.baseURL });
  try {
    const invited = await context.newPage();
    const leakedReferrers = [];
    invited.on('request', (r) => {
      if (r.url().includes('/apps/stammbaum/api/v1/') && r.headers()['referer']?.includes(invite.token)) {
        leakedReferrers.push(r.url());
      }
    });
    await invited.goto(`/index.php/apps/stammbaum/?invite=${invite.token}`);
    await expect(invited.locator('#user')).toBeVisible();
    await invited.locator('#user').fill('stb_viewer');
    await invited.locator('#password').fill(fixture.password);
    await invited
      .locator('form[name="login"]')
      .getByRole('button', { name: /log in/i })
      .click();
    await expect(invited.getByRole('heading', { name: new RegExp(tr.name) })).toBeVisible();
    expect(invited.url()).not.toContain(invite.token);
    expect(leakedReferrers).toEqual([]);
    await invited.setViewportSize({ width: 390, height: 844 });
    await invited.screenshot({ path: 'test-results/core-invitation-phone.png' });
    await invited.setViewportSize({ width: 1440, height: 1000 });
    expect((await request(invited, `/trees/${tr.id}`)).status).toBe(404);
    await invited.getByRole('button', { name: 'Accept invitation' }).click();
    await expect(invited.getByRole('heading', { name: tr.name })).toBeVisible();
    expect((await good(invited, `/trees/${tr.id}`)).role).toBe('viewer');
    expect((await request(invited, '/invitations/accept', 'POST', { token: invite.token })).status).toBe(404);
  } finally {
    await context.close();
  }
});

test('issuer demotion permanently invalidates an outstanding invitation', async ({ page, browser }) => {
  await login(page);
  const tr = await tree(page);
  await grant(page, tr.id, 'stb_manager', 'manager');
  const context = await browser.newContext({ baseURL: fixture.baseURL });
  try {
    const manager = await context.newPage();
    await login(manager, 'stb_manager');
    expect((await request(manager, `/trees/${tr.id}/invitations`, 'POST', { role: 'owner' })).status).toBe(
      403,
    );
    const invite = await good(manager, `/trees/${tr.id}/invitations`, 'POST', { role: 'editor' }, 201);
    const m = (await good(page, `/trees/${tr.id}/members`)).items.find((x) => x.userId === 'stb_manager');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: m.userId,
      revision: m.revision,
      role: 'editor',
      exportAllowed: false,
    });
    expect((await request(page, '/invitations/review', 'POST', { token: invite.token })).status).toBe(404);
  } finally {
    await context.close();
  }
});

test('an expired invitation cannot be reviewed or accepted', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  const invite = await good(page, `/trees/${tr.id}/invitations`, 'POST', { role: 'viewer' }, 201);
  const listed = (await good(page, `/trees/${tr.id}/invitations`)).items;
  fixtureDatabase('UPDATE *PREFIX*stb_invites SET expires_at = ? WHERE id = ?', [
    Math.floor(Date.now() / 1000) - 1,
    listed[0].id,
  ]);
  expect((await request(page, '/invitations/review', 'POST', { token: invite.token })).status).toBe(404);
  expect((await request(page, '/invitations/accept', 'POST', { token: invite.token })).status).toBe(404);
});

test('simultaneous invitation acceptance grants only one account', async ({ page, browser }) => {
  await login(page);
  const tr = await tree(page);
  const invite = await good(page, `/trees/${tr.id}/invitations`, 'POST', { role: 'editor' }, 201);
  const contexts = await Promise.all([
    browser.newContext({ baseURL: fixture.baseURL }),
    browser.newContext({ baseURL: fixture.baseURL }),
  ]);
  try {
    const candidates = await Promise.all(contexts.map((context) => context.newPage()));
    await login(candidates[0], 'stb_viewer');
    await login(candidates[1], 'stb_editor');
    const replies = await Promise.all(
      candidates.map((candidate) =>
        request(candidate, '/invitations/accept', 'POST', { token: invite.token }),
      ),
    );
    expect(replies.map((r) => r.status).sort()).toEqual([200, 404]);
    const members = (await good(page, `/trees/${tr.id}/members`)).items.filter((m) => m.active);
    expect(members.filter((m) => ['stb_viewer', 'stb_editor'].includes(m.userId))).toHaveLength(1);
  } finally {
    await Promise.all(contexts.map((context) => context.close()));
  }
});

test('concurrent owner departures retain an active owner', async ({ page, browser }) => {
  await login(page);
  const tr = await tree(page);
  await grant(page, tr.id, 'stb_manager', 'owner');
  const context = await browser.newContext({ baseURL: fixture.baseURL });
  try {
    const secondOwner = await context.newPage();
    await login(secondOwner, 'stb_manager');
    const first = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === fixture.user);
    const second = (await good(secondOwner, `/trees/${tr.id}/members`)).items.find(
      (m) => m.userId === 'stb_manager',
    );
    const replies = await Promise.all([
      request(page, `/trees/${tr.id}/members`, 'POST', {
        userId: first.userId,
        revision: first.revision,
        remove: true,
      }),
      request(secondOwner, `/trees/${tr.id}/members`, 'POST', {
        userId: second.userId,
        revision: second.revision,
        remove: true,
      }),
    ]);
    expect(replies.map((r) => r.status).sort()).toEqual([200, 409]);
  } finally {
    await context.close();
  }
});

test('person values retain research assertions without creating duplicates on unchanged saves', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Fictional', {
    sex: 'other',
    livingStatus: 'unknown',
    birthPlace: 'Old place',
  });
  expect(p.sex).toBe('other');
  expect(p.livingStatus).toBe('unknown');
  expect((await good(page, `/trees/${tr.id}/people/${p.id}/relations`)).items).toEqual([]);
  expect((await good(page, `/trees/${tr.id}/records?person=${p.id}`)).items).toEqual([]);
  const path = `/trees/${tr.id}/people/${p.id}`;
  const before = (await good(page, `${path}/claims`)).items;
  const oldPlace = before.find((claim) => claim.kind === 'birth_place');
  const source = await good(
    page,
    `/trees/${tr.id}/sources`,
    'POST',
    {
      title: 'Fictional register',
      type: 'Register',
      requestId: key(),
    },
    201,
  );
  const cited = await good(
    page,
    `/trees/${tr.id}/citations`,
    'POST',
    {
      sourceId: source.id,
      targets: [{ kind: 'claim', targetId: oldPlace.id, effect: 'supports' }],
      requestId: key(),
    },
    201,
  );
  const unchanged = await good(page, path, 'PUT', { ...editable(p), revision: p.revision });
  expect((await good(page, `${path}/claims`)).items.map((c) => c.id).sort()).toEqual(
    before.map((c) => c.id).sort(),
  );
  const cleared = await good(page, path, 'PUT', {
    ...editable(unchanged),
    birthPlace: '',
    revision: unchanged.revision,
  });
  expect(cleared.birthPlace).toBe('');
  const placeClaims = (await good(page, `${path}/claims`)).items.filter((c) => c.kind === 'birth_place');
  expect(placeClaims).toHaveLength(1);
  expect(placeClaims[0].value).toBe('Old place');
  expect(placeClaims[0].preferred).toBe(false);
  expect(
    (await good(page, `/trees/${tr.id}/citations?person=${p.id}`)).items.map((item) => item.id),
  ).toContain(cited.id);
  expect((await good(page, `/trees/${tr.id}/citations/${cited.id}`)).targets[0].targetId).toBe(oldPlace.id);
  expect((await request(page, path, 'PUT', { ...editable(p), revision: p.revision })).status).toBe(412);
});

test('search pages authorized names and alternatives without changing the selected family branch', async ({
  page,
  browser,
}) => {
  test.setTimeout(120000);
  await login(page);
  const tr = await tree(page);
  const focus = await person(page, tr.id, 'Focus');
  const other = await person(page, tr.id, 'EarlierAlias');
  await good(page, `/trees/${tr.id}/people/${other.id}`, 'PUT', {
    ...editable(other),
    givenName: 'Current',
    revision: other.revision,
  });
  const privatePerson = await person(page, tr.id, 'Paged Secret', { visibility: 'management' });
  const twins = [await person(page, tr.id, 'Twin'), await person(page, tr.id, 'Twin')];
  for (let i = 0; i < 52; i++) await person(page, tr.id, 'Paged' + String(i).padStart(2, '0'));
  await grant(page, tr.id, 'stb_viewer');
  const context = await browser.newContext({ baseURL: fixture.baseURL });
  try {
    const viewer = await context.newPage();
    await login(viewer, 'stb_viewer');
    const first = await good(viewer, `/trees/${tr.id}/people?query=paged&page=0`);
    const second = await good(viewer, `/trees/${tr.id}/people?query=paged&page=1`);
    expect(first.items).toHaveLength(50);
    expect(first.nextPage).toBe(1);
    expect(second.items).toHaveLength(2);
    expect(second.nextPage).toBeNull();
    expect([...first.items, ...second.items].some((p) => p.id === privatePerson.id)).toBe(false);
    expect((await good(viewer, `/trees/${tr.id}/people?query=earlieralias`)).items.map((p) => p.id)).toEqual([
      other.id,
    ]);
    expect((await good(viewer, `/trees/${tr.id}/people?query=twin`)).items.map((p) => p.id).sort()).toEqual(
      twins.map((p) => p.id).sort(),
    );
    expect((await request(viewer, `/trees/${tr.id}/people/${privatePerson.id}`)).status).toBe(404);
    await viewer.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=family&focus=${focus.id}`);
    await viewer.getByRole('tab', { name: 'People', exact: true }).click();
    await viewer.getByRole('searchbox', { name: 'Search people' }).fill('Current');
    await expect(viewer.locator(`.person-row[data-person-id="${other.id}"]`)).toBeVisible();
    await viewer.locator(`.person-row[data-person-id="${other.id}"]`).click();
    await viewer.getByRole('tab', { name: 'Family', exact: true }).click();
    await expect(viewer.getByText('Outside this branch')).toBeVisible();
    expect((await good(viewer, `/trees/${tr.id}/graph?focus=${focus.id}`)).focusId).toBe(focus.id);
  } finally {
    await context.close();
  }
});

test('revocation during search clears previously displayed person results', async ({ page, browser }) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Revoked search result');
  await grant(page, tr.id, 'stb_viewer');
  const context = await browser.newContext({ baseURL: fixture.baseURL });
  try {
    const viewer = await context.newPage();
    await login(viewer, 'stb_viewer');
    await viewer.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people`);
    await expect(viewer.locator(`.person-row[data-person-id="${p.id}"]`)).toBeVisible();
    const member = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: member.userId,
      revision: member.revision,
      role: member.role,
      remove: true,
    });
    await viewer.getByRole('searchbox', { name: 'Search people' }).fill('Revoked');
    await expect(viewer.locator('.people-toolbar + .feedback.error')).toBeVisible();
    await expect(viewer.locator(`.person-row[data-person-id="${p.id}"]`)).toHaveCount(0);
    await expect(viewer.getByRole('heading', { name: 'Revoked search result Example' })).toHaveCount(0);
  } finally {
    await context.close();
  }
});

test('an expired Nextcloud session clears genealogy and offers host sign-in', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Visible until sign-out');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&person=${p.id}`);
  await expect(page.getByRole('heading', { name: 'Visible until sign-out Example' })).toBeVisible();
  await page.context().clearCookies();
  await page.locator('.tree-nav-item').filter({ hasText: tr.name }).click();
  // Nextcloud itself may redirect the whole browser before the app receives the API reply.
  await expect(page.locator('#user')).toBeVisible();
  await expect(page.getByText('Visible until sign-out Example')).toHaveCount(0);
  await expect(page.locator('#stammbaum-app')).toHaveCount(0);
});

test('an API 401 clears cached genealogy and exposes an explicit sign-in action', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Hidden after 401');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&person=${p.id}`);
  await expect(page.getByRole('heading', { name: 'Hidden after 401 Example' })).toBeVisible();
  await page.route(`**/api/v1/trees/${tr.id}`, (route) =>
    route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'unauthenticated', message: 'Sign in to continue.' }),
    }),
  );
  await page.locator('.tree-nav-item').filter({ hasText: tr.name }).click();
  await expect(page.getByRole('button', { name: 'Sign in again' })).toBeVisible();
  await expect(page.getByText('Hidden after 401 Example')).toHaveCount(0);
});
