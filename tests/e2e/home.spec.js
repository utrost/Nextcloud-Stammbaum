import { test, expect } from '@playwright/test';
import { login, tree, person, grant, good, request, key, fixture, endpoint } from './helpers.js';
import { fixtureDatabase } from './server-fixture.js';
const views = (tr) => `/trees/${tr.id}/views`;
async function home(page, tr, p, state) {
  state ||= await good(page, views(tr));
  return good(page, views(tr) + '/home', 'PUT', { revision: state.revision, homeId: p?.id || null });
}
async function shared(page, tr, p) {
  const current = await good(page, `/trees/${tr.id}`);
  return good(page, `/trees/${tr.id}`, 'PUT', {
    revision: current.revision,
    name: current.name,
    description: current.description,
    homeId: p?.id || null,
  });
}
async function open(page, tr, extra = '') {
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}${extra}`);
  await expect(page.locator('.page-header h1')).toHaveText(tr.name);
  await expect(page.locator('.graph-shell')).toBeVisible();
}
async function saved(page, tr, p, q = null) {
  const state = await good(page, views(tr));
  return good(
    page,
    views(tr),
    'POST',
    {
      revision: state.revision,
      requestId: key(),
      label: 'Remembered research',
      focus: p.id,
      selected: q?.id || '',
      mode: 'family',
      up: 3,
      down: 2,
      camera: { x: 123, y: 67, zoom: 0.8 },
    },
    201,
  );
}
test.beforeEach(async ({ page }) => {
  await login(page);
  const p = await good(page, '/preferences');
  if (p.locale !== 'en') {
    await good(page, '/preferences', 'PUT', { locale: 'en', revision: p.revision });
    await page.reload();
  }
});
test('viewer personal home stays independent of shared home, tree edits and other accounts', async ({
  page,
  browser,
}) => {
  const tr = await tree(page),
    a = await person(page, tr.id, 'Shared'),
    b = await person(page, tr.id, 'Personal'),
    c = await person(page, tr.id, 'New shared');
  await shared(page, tr, a);
  await grant(page, tr.id, 'stb_viewer');
  const context = await browser.newContext(),
    viewer = await context.newPage();
  await login(viewer, 'stb_viewer');
  const pref = await good(viewer, '/preferences');
  await good(viewer, '/preferences', 'PUT', { locale: 'en', revision: pref.revision });
  await open(viewer, tr, `&person=${b.id}`);
  await expect(viewer.locator('.person-node.focus')).toContainText('Shared Example');
  const before = await good(page, `/trees/${tr.id}`);
  await viewer.getByRole('button', { name: 'Set as personal home', exact: true }).click();
  await expect(viewer.locator('.personal-home')).toContainText('Personal Example');
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(before.revision);
  expect((await good(page, views(tr))).home).toBe(null);
  await shared(page, tr, c);
  await open(viewer, tr);
  await expect(viewer.locator('.person-node.focus')).toContainText('Personal Example');
  await expect(viewer.getByRole('button', { name: 'Edit person', exact: true })).toHaveCount(0);
  await expect(viewer.getByRole('button', { name: 'Set as shared home', exact: true })).toHaveCount(0);
  await viewer.getByRole('region', { name: 'Family diagram' }).focus();
  await viewer.keyboard.press('ArrowLeft');
  const camera = await viewer.locator('.graph-world').getAttribute('style');
  await viewer.getByRole('button', { name: 'Clear personal home', exact: true }).click();
  await expect(viewer.locator('.personal-home')).toContainText('Follow shared home');
  expect(await viewer.locator('.graph-world').getAttribute('style')).toBe(camera);
  await viewer.getByRole('button', { name: 'Home person', exact: true }).click();
  await expect(viewer.locator('.person-node.focus')).toContainText('New shared Example');
  await viewer.getByRole('button', { name: /Previous branch/ }).click();
  await expect(viewer.locator('.person-node.focus')).toContainText('Personal Example');
  expect(await viewer.locator('.graph-world').getAttribute('style')).toBe(camera);
  await context.close();
});
test('saved opening focus and camera take precedence while Home uses personal then shared home', async ({
  page,
}) => {
  const tr = await tree(page),
    a = await person(page, tr.id, 'Saved'),
    b = await person(page, tr.id, 'Personal'),
    c = await person(page, tr.id, 'Shared');
  await shared(page, tr, c);
  await home(page, tr, b);
  const state = await saved(page, tr, a, b);
  await open(page, tr);
  await expect(page.locator('.person-node.focus')).toContainText('Saved Example');
  await expect(page.locator('.person-heading h2')).toHaveText('Personal Example');
  await expect(page.locator('.graph-world')).toHaveAttribute(
    'style',
    /translate\(123px, 67px\) scale\(0.8\)/,
  );
  const camera = await page.locator('.graph-world').getAttribute('style');
  await page
    .getByRole('tablist', { name: 'Tree workspace' })
    .getByRole('tab', { name: 'People', exact: true })
    .click();
  await page
    .getByRole('tablist', { name: 'Tree workspace' })
    .getByRole('tab', { name: 'Family', exact: true })
    .click();
  expect(await page.locator('.graph-world').getAttribute('style')).toBe(camera);
  await page.getByRole('button', { name: 'Home person', exact: true }).click();
  await expect(page.locator('.person-node.focus')).toContainText('Personal Example');
  await page.getByRole('button', { name: 'Clear personal home', exact: true }).click();
  await expect(page.locator('.personal-home')).toContainText('Follow shared home');
  const cleared = await good(page, views(tr));
  expect(cleared.items).toEqual(state.items);
  expect(cleared.openingView).toEqual(state.openingView);
  await page.getByRole('button', { name: 'Home person', exact: true }).click();
  await expect(page.locator('.person-node.focus')).toContainText('Shared Example');
  await page.locator('.person-node.focus').click();
  await page.getByRole('button', { name: 'Clear shared home', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Home person', exact: true })).toBeDisabled();
});
test('inaccessible personal and saved pointers fall back without exposing names or IDs', async ({
  page,
  browser,
}) => {
  const tr = await tree(page),
    p = await person(page, tr.id, 'Hidden home', { visibility: 'management' }),
    q = await person(page, tr.id, 'Visible fallback');
  await shared(page, tr, q);
  await grant(page, tr.id, 'stb_manager', 'manager');
  const context = await browser.newContext(),
    member = await context.newPage();
  await login(member, 'stb_manager');
  await home(member, tr, p);
  await saved(member, tr, p);
  const m = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === 'stb_manager');
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: m.userId,
    role: 'viewer',
    revision: m.revision,
    exportAllowed: false,
  });
  const filtered = await good(member, views(tr));
  expect(filtered.home).toBe(null);
  expect(filtered.openingView).toBe(null);
  expect(filtered.items).toEqual([]);
  expect(JSON.stringify(filtered)).not.toContain(p.id);
  expect(JSON.stringify(filtered)).not.toContain('Hidden home');
  await open(member, tr);
  await expect(member.locator('.person-node.focus')).toContainText('Visible fallback');
  expect(
    (await request(member, views(tr) + '/home', 'PUT', { revision: filtered.revision, homeId: p.id })).status,
  ).toBe(404);
  const other = await tree(page),
    foreign = await person(page, other.id, 'Foreign');
  expect(
    (await request(member, views(tr) + '/home', 'PUT', { revision: filtered.revision, homeId: foreign.id }))
      .status,
  ).toBe(404);
  await shared(page, tr, null);
  await open(member, tr);
  await expect(member.locator('.person-node.focus')).toContainText('Visible fallback');
  await context.close();
});
test('concurrent preference writes have one winner and a stale tab preserves its proposed home for explicit retry', async ({
  page,
  context,
  browser,
}) => {
  const tr = await tree(page),
    a = await person(page, tr.id, 'Alma'),
    b = await person(page, tr.id, 'Bruno');
  const state = await good(page, views(tr));
  const independent = await browser.newContext();
  const parallel = await independent.newPage();
  await login(parallel);
  const results = await Promise.all([
    request(page, views(tr) + '/home', 'PUT', { revision: state.revision, homeId: a.id }),
    request(parallel, views(tr) + '/home', 'PUT', { revision: state.revision, homeId: b.id }),
  ]);
  await independent.close();
  expect(results.map((r) => r.status).sort()).toEqual([200, 412]);
  const winner = results.find((r) => r.status === 200).body;
  expect((await good(page, views(tr))).home).toEqual(winner.home);
  await home(page, tr, b);
  await open(page, tr, `&person=${a.id}`);
  const second = await context.newPage();
  await open(second, tr, `&person=${b.id}`);
  await home(second, tr, b);
  await page.getByRole('button', { name: 'Set as personal home', exact: true }).click();
  await expect(page.locator('.preference-conflict')).toContainText('Alma Example');
  expect((await good(page, views(tr))).home.id).toBe(b.id);
  await page.getByRole('button', { name: 'Load current preferences for comparison', exact: true }).click();
  await expect(page.locator('.preference-conflict')).toContainText('Bruno Example');
  await expect(page.locator('.preference-conflict')).toContainText('Alma Example');
  await page.getByRole('button', { name: 'Apply my preference to this version', exact: true }).click();
  await expect(page.locator('.preference-conflict')).toHaveCount(0);
  expect((await good(page, views(tr))).home.id).toBe(a.id);
  await second.close();
});
test('legacy saved views survive their first migration into transactional preferences', async ({ page }) => {
  const tr = await tree(page),
    p = await person(page, tr.id, 'Legacy');
  const legacy = {
    revision: 7,
    items: [
      {
        id: key(),
        label: 'Legacy view',
        focus: p.id,
        selected: '',
        mode: 'family',
        up: 3,
        down: 2,
        camera: { x: 12, y: 34, zoom: 1 },
      },
    ],
  };
  fixtureDatabase('INSERT INTO *PREFIX*preferences (userid,appid,configkey,configvalue) VALUES (?,?,?,?)', [
    fixture.user,
    'stammbaum',
    'views:' + tr.id,
    JSON.stringify(legacy),
  ]);
  const before = await good(page, views(tr));
  expect(before.items).toEqual(legacy.items);
  expect(before.revision).toBe(7);
  const after = await home(page, tr, p, before);
  expect(after.items).toEqual(legacy.items);
  expect(after.revision).toBe(8);
  fixtureDatabase(
    'UPDATE *PREFIX*preferences SET configvalue = ? WHERE userid = ? AND appid = ? AND configkey = ?',
    [JSON.stringify({ revision: 1, items: [] }), fixture.user, 'stammbaum', 'views:' + tr.id],
  );
  expect((await good(page, views(tr))).items).toEqual(legacy.items);
});

test('failed home saves preserve the proposal, block pending navigation and require an explicit retry', async ({
  page,
}) => {
  const tr = await tree(page),
    p = await person(page, tr.id, 'Unsent home');
  await open(page, tr, `&person=${p.id}`);
  let release;
  const gate = new Promise((resolve) => (release = resolve));
  await page.route(`**/api/v1/trees/${tr.id}/views/home`, async (route) => {
    await gate;
    await route.fulfill({ status: 503, json: { message: 'Simulated preference failure' } });
  });
  await page.getByRole('button', { name: 'Set as personal home', exact: true }).click();
  await page
    .getByRole('tablist', { name: 'Tree workspace' })
    .getByRole('tab', { name: 'People', exact: true })
    .click();
  await expect(page.locator('#workspace-tab-family')).toHaveAttribute('aria-selected', 'true');
  release();
  await expect(page.locator('.preference-conflict')).toContainText('Unsent home Example');
  expect((await good(page, views(tr))).home).toBe(null);
  await page.unroute(`**/api/v1/trees/${tr.id}/views/home`);
  await page.getByRole('button', { name: 'Retry same operation', exact: true }).click();
  await expect(page.locator('.personal-home')).toContainText('Unsent home Example');
});

test('personal-home writes require membership, CSRF, an explicit value and a current revision', async ({
  page,
  browser,
}) => {
  const tr = await tree(page),
    p = await person(page, tr.id, 'Private preference');
  const missing = await request(page, views(tr) + '/home', 'PUT', { homeId: p.id });
  expect(missing.status).toBe(428);
  expect((await request(page, views(tr) + '/home', 'PUT', { revision: 1 })).status).toBe(422);
  const csrf = await page.request.put(endpoint + views(tr) + '/home', {
    data: { revision: 1, homeId: p.id },
  });
  expect(csrf.status()).toBe(412);
  const ctx = await browser.newContext(),
    outsider = await ctx.newPage();
  await login(outsider, 'stb_outsider');
  expect((await request(outsider, views(tr))).status).toBe(404);
  expect((await request(outsider, views(tr) + '/home', 'PUT', { revision: 1, homeId: p.id })).status).toBe(
    404,
  );
  expect((await good(page, views(tr))).home).toBe(null);
  await ctx.close();
});

test('retrying a saved view after losing a successful response does not create a duplicate', async ({
  page,
}) => {
  const tr = await tree(page),
    p = await person(page, tr.id, 'Retry view');
  await open(page, tr, `&person=${p.id}`);
  await page.locator('.saved-views summary').click();
  await page.getByLabel('View name', { exact: true }).fill('One research view');
  const attempts = [];
  await page.route(`**/api/v1/trees/${tr.id}/views`, async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    attempts.push(route.request().postDataJSON());
    if (attempts.length === 1) {
      const response = await route.fetch();
      expect(response.status()).toBe(201);
      return route.abort('failed');
    }
    return route.continue();
  });
  await page.getByRole('button', { name: 'Save current view', exact: true }).click();
  await expect(page.locator('.preference-conflict')).toContainText('One research view');
  const committed = await good(page, views(tr));
  expect(committed.items).toHaveLength(1);
  await page.getByRole('button', { name: 'Retry same operation', exact: true }).click();
  await expect(page.locator('.preference-conflict')).toHaveCount(0);
  const retried = await good(page, views(tr));
  expect(retried).toEqual(committed);
  expect(attempts).toHaveLength(2);
  expect(attempts[1]).toEqual(attempts[0]);
});

test('a late preference response cannot replace the home of a subsequently opened tree', async ({ page }) => {
  const first = await tree(page),
    a = await person(page, first.id, 'First home');
  const second = await tree(page),
    b = await person(page, second.id, 'Second home');
  await home(page, first, a);
  await home(page, second, b);
  let release;
  const gate = new Promise((resolve) => (release = resolve));
  await page.route(`**/api/v1/trees/${first.id}/views`, async (route) => {
    const response = await route.fetch();
    await gate;
    await route.fulfill({ response });
  });
  const started = page.waitForRequest((request) => request.url().endsWith(views(first)));
  await page.goto(`/index.php/apps/stammbaum/#tree=${first.id}`);
  await started;
  await page.locator('.tree-nav-item').filter({ hasText: second.name }).click();
  await expect(page.locator('.page-header h1')).toHaveText(second.name);
  await expect(page.locator('.personal-home')).toContainText('Second home Example');
  const finished = page.waitForResponse((response) => response.url().endsWith(views(first)));
  release();
  await (await finished).finished();
  await page.evaluate(
    () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
  );
  await expect(page.locator('.personal-home')).toContainText('Second home Example');
  await page.getByRole('button', { name: 'Home person', exact: true }).click();
  await expect(page.locator('.person-node.focus')).toContainText('Second home Example');
});

test('failed tree startup retries the requested tree without stale home data or a false empty result', async ({
  page,
}) => {
  const first = await tree(page),
    a = await person(page, first.id, 'Earlier home');
  const second = await tree(page),
    b = await person(page, second.id, 'Requested home');
  await home(page, first, a);
  await home(page, second, b);
  for (const suffix of ['views', 'people?*']) {
    await open(page, first);
    const pattern = `**/api/v1/trees/${second.id}/${suffix}`;
    await page.route(pattern, (route) =>
      route.fulfill({ status: 503, json: { message: 'Simulated startup failure' } }),
    );
    await page.locator('.tree-nav-item').filter({ hasText: second.name }).click();
    await expect(page.locator('main > [role=alert]')).toContainText('Simulated startup failure');
    await expect(page.locator('.personal-home')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'No people yet', exact: true })).toHaveCount(0);
    await page.unroute(pattern);
    await page.locator('main > [role=alert]').getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(page.locator('.page-header h1')).toHaveText(second.name);
    await expect(page.locator('.personal-home')).toContainText('Requested home Example');
  }
});
