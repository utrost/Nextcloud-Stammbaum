import { test, expect } from '@playwright/test';
import { login, tree, person, grant, good, request, relative, key, fixture, endpoint } from './helpers.js';

async function memberPage(browser, user) {
  const context = await browser.newContext({ baseURL: fixture.baseURL });
  const page = await context.newPage();
  await login(page, user);
  return { context, page };
}

test('native entry requires login and an unavailable startup offers an actionable retry', async ({
  page,
  browser,
}) => {
  const anonymous = await browser.newContext({ baseURL: fixture.baseURL });
  const entry = await anonymous.newPage();
  await entry.goto('/index.php/apps/stammbaum/');
  await expect(entry.locator('#user')).toBeVisible();
  await expect(entry.locator('#stammbaum-app')).toHaveCount(0);
  await anonymous.close();
  await login(page);
  let fail = true;
  await page.route('**/apps/stammbaum/api/v1/trees', async (route) => {
    if (fail && route.request().method() === 'GET')
      return route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Loading failed. Please try again.' }),
      });
    return route.continue();
  });
  await page.goto('/index.php/apps/stammbaum/');
  await expect(page.getByRole('alert')).toContainText('Loading failed');
  fail = false;
  await page.getByRole('alert').getByRole('button', { name: 'Retry', exact: true }).click();
  await expect(page.getByRole('alert')).toHaveCount(0);
  await expect(page.locator('.brand')).toBeVisible();
});

test('duplicate tree names retain independent identities and metadata permissions on an empty tree', async ({
  page,
  browser,
}) => {
  await login(page);
  const first = await tree(page);
  const second = await good(page, '/trees', 'POST', { name: first.name, requestId: key() }, 201);
  expect(first.id).not.toBe(second.id);
  expect(second.role).toBe('owner');
  expect(
    (await good(page, '/trees')).items
      .filter((t) => t.name === first.name)
      .map((t) => t.id)
      .sort(),
  ).toEqual([first.id, second.id].sort());
  await grant(page, first.id, 'stb_manager', 'manager');
  await grant(page, first.id, 'stb_viewer');
  const manager = await memberPage(browser, 'stb_manager');
  const viewer = await memberPage(browser, 'stb_viewer');
  try {
    const current = await good(manager.page, `/trees/${first.id}`);
    const changed = await good(manager.page, `/trees/${first.id}`, 'PUT', {
      name: first.name,
      description: 'Manager revision',
      revision: current.revision,
    });
    expect(changed.description).toBe('Manager revision');
    expect(
      (
        await request(page, `/trees/${first.id}`, 'PUT', {
          name: first.name,
          description: 'Stale',
          revision: current.revision,
        })
      ).status,
    ).toBe(412);
    expect(
      (
        await request(viewer.page, `/trees/${first.id}`, 'PUT', {
          name: 'Denied',
          revision: changed.revision,
        })
      ).status,
    ).toBe(403);
    expect((await request(viewer.page, `/trees/${second.id}`)).status).toBe(404);
    expect((await good(viewer.page, '/trees')).items.some((t) => t.id === second.id)).toBe(false);
    await viewer.page.goto(`/index.php/apps/stammbaum/#tree=${first.id}`);
    await expect(viewer.page.getByRole('button', { name: 'New person', exact: false })).toHaveCount(0);
    await expect(viewer.page.getByRole('tab', { name: 'Imports', exact: true })).toHaveCount(0);
    await manager.page.goto(`/index.php/apps/stammbaum/#tree=${first.id}`);
    await expect(
      manager.page.getByRole('button', { name: 'New person', exact: false }).first(),
    ).toBeVisible();
    await expect(manager.page.getByRole('tab', { name: 'Imports', exact: true })).toBeVisible();
    expect((await good(page, `/trees/${first.id}/people`)).items).toEqual([]);
    await page.goto(`/index.php/apps/stammbaum/#tree=${second.id}`);
    for (const id of [first.id, second.id])
      await expect(page.locator(`.stb-nav button`).filter({ hasText: id.slice(0, 6) })).toHaveCount(1);
  } finally {
    await manager.context.close();
    await viewer.context.close();
  }
});

test('all explicit parent kinds and partnership episodes retain people when connections are removed', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    child = await person(page, tr.id, 'Child');
  for (const type of ['unknown', 'biological', 'adoptive', 'foster', 'step', 'other']) {
    const parent = await person(page, tr.id, `Parent ${type}`);
    expect((await relative(page, tr.id, parent, child, 'child', type)).status).toBe(201);
  }
  const partner = await person(page, tr.id, 'Partner');
  const first = await relative(page, tr.id, child, partner, 'partner');
  expect(first.status).toBe(201);
  const p = await good(page, `/trees/${tr.id}/people/${child.id}`),
    q = await good(page, `/trees/${tr.id}/people/${partner.id}`);
  const body = {
    personId: q.id,
    revision: p.revision,
    targetRevision: q.revision,
    direction: 'partner',
    requestId: key(),
  };
  expect((await request(page, `/trees/${tr.id}/people/${p.id}/relations`, 'POST', body)).status).toBe(409);
  const second = await good(
    page,
    `/trees/${tr.id}/people/${p.id}/relations`,
    'POST',
    { ...body, confirmEpisode: true, requestId: key() },
    201,
  );
  expect(second.id).not.toBe(first.body.id);
  const anotherPartner = await person(page, tr.id, 'Another partner');
  const otherEpisode = await relative(page, tr.id, child, anotherPartner, 'partner');
  expect(otherEpisode.status).toBe(201);
  await good(page, `/trees/${tr.id}/relations/${first.body.id}/remove`, 'POST', {
    revision: first.body.revision,
  });
  const relations = (await good(page, `/trees/${tr.id}/people/${p.id}/relations`)).items;
  expect(
    relations
      .filter((r) => r.kind === 'partner')
      .map((r) => r.id)
      .sort(),
  ).toEqual([second.id, otherEpisode.body.id].sort());
  expect(relations.filter((r) => r.kind === 'parent')).toHaveLength(6);
  expect((await good(page, `/trees/${tr.id}/people`)).items).toHaveLength(9);
  expect((await good(page, `/trees/${tr.id}/people/${p.id}`)).livingStatus).toBe('unknown');
  expect((await good(page, `/trees/${tr.id}/records?person=${p.id}`)).items).toEqual([]);
  expect((await relative(page, tr.id, child, child)).status).toBe(422);
});

test('simultaneous disjoint-endpoint parent writes cannot jointly close a biological cycle', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  await grant(page, tr.id, 'stb_editor', 'editor');
  const editor = await memberPage(browser, 'stb_editor');
  try {
    const a = await person(page, tr.id, 'A'),
      b = await person(page, tr.id, 'B'),
      c = await person(page, tr.id, 'C'),
      d = await person(page, tr.id, 'D');
    expect((await relative(page, tr.id, a, b)).status).toBe(201);
    expect((await relative(page, tr.id, c, d)).status).toBe(201);
    const rows = await Promise.all([a, b, c, d].map((p) => good(page, `/trees/${tr.id}/people/${p.id}`)));
    const connect = (client, from, to) =>
      request(client, `/trees/${tr.id}/people/${from.id}/relations`, 'POST', {
        personId: to.id,
        revision: from.revision,
        targetRevision: to.revision,
        direction: 'child',
        parentType: 'biological',
        requestId: key(),
      });
    const results = await Promise.all([
      connect(page, rows[1], rows[2]),
      connect(editor.page, rows[3], rows[0]),
    ]);
    expect(results.map((r) => r.status).sort()).toEqual([201, 409]);
    expect(results.find((r) => r.status === 409).body.error).toBe('ancestry_cycle');
    const graph = await good(page, `/trees/${tr.id}/graph?focus=${a.id}&up=8&down=8`);
    expect(graph.people).toHaveLength(4);
    expect(graph.relations).toHaveLength(3);
  } finally {
    await editor.context.close();
  }
});

test('overlapping half-sibling contexts preserve independent order under simultaneous edits', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  await grant(page, tr.id, 'stb_editor', 'editor');
  const editor = await memberPage(browser, 'stb_editor');
  try {
    const [parent, a, b, c] = await Promise.all(
      ['Parent', 'A', 'B', 'C'].map((name) => person(page, tr.id, name)),
    );
    for (const child of [a, b, c]) expect((await relative(page, tr.id, parent, child)).status).toBe(201);
    const left = await person(page, tr.id, 'Left parent');
    const right = await person(page, tr.id, 'Right parent');
    expect((await relative(page, tr.id, left, a)).status).toBe(201);
    for (const child of [b, c]) expect((await relative(page, tr.id, right, child)).status).toBe(201);
    const create = (children) =>
      good(
        page,
        `/trees/${tr.id}/contexts`,
        'POST',
        {
          label: 'Fictional context',
          members: [
            { personId: parent.id, role: 'parent' },
            ...children.map((p) => ({ personId: p.id, role: 'child' })),
          ],
          requestId: key(),
        },
        201,
      );
    const first = await create([a, b]),
      second = await create([b, c]);
    const before = (await good(page, `/trees/${tr.id}/graph?focus=${parent.id}`)).relations;
    const edit = (client, children, label) =>
      request(client, `/trees/${tr.id}/contexts/${first.id}`, 'PUT', {
        label,
        revision: first.revision,
        visibility: 'members',
        members: [
          { personId: parent.id, role: 'parent' },
          ...children.map((p) => ({ personId: p.id, role: 'child' })),
        ],
        relationIds: [],
      });
    const results = await Promise.all([
      edit(page, [b, a], 'First proposal'),
      edit(editor.page, [a, b], 'Second proposal'),
    ]);
    expect(results.map((r) => r.status).sort()).toEqual([200, 412]);
    const winner = results.find((r) => r.status === 200).body;
    expect((await good(page, `/trees/${tr.id}/contexts/${first.id}`)).members.map((m) => m.personId)).toEqual(
      winner.members.map((m) => m.personId),
    );
    expect(
      (await good(page, `/trees/${tr.id}/contexts/${second.id}`)).members.map((m) => m.personId),
    ).toEqual([parent.id, b.id, c.id]);
    expect((await good(page, `/trees/${tr.id}/graph?focus=${parent.id}`)).relations).toEqual(before);
  } finally {
    await editor.context.close();
  }
});

test('diagram pointer wheel and keyboard controls preserve genealogy and text relationship meaning', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Focus');
  for (const type of ['biological', 'adoptive', 'foster', 'step', 'other', 'unknown']) {
    const child = await person(page, tr.id, `Child ${type}`);
    expect((await relative(page, tr.id, p, child, 'child', type)).status).toBe(201);
  }
  const partner = await person(page, tr.id, 'Partner');
  expect((await relative(page, tr.id, p, partner, 'partner')).status).toBe(201);
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${p.id}`);
  await expect(page.locator('.person-node')).toHaveCount(8);
  await expect(page.locator('.person-node img')).toHaveCount(0);
  const world = page.locator('.graph-world'),
    viewport = page.getByRole('region', { name: 'Family diagram', exact: true });
  const camera = () => world.evaluate((e) => e.style.transform);
  const initial = await camera();
  await viewport.focus();
  await page.keyboard.press('ArrowRight');
  expect(await camera()).not.toBe(initial);
  const moved = await camera();
  await page.keyboard.press('+');
  expect(await camera()).not.toBe(moved);
  await page.keyboard.press('-');
  expect(await camera()).toBe(moved);
  await page.keyboard.press('Home');
  const fitted = await camera();
  const box = await viewport.boundingBox();
  await page.mouse.move(box.x + 10, box.y + 10);
  await page.mouse.down();
  await page.mouse.move(box.x + 55, box.y + 40, { steps: 4 });
  await page.mouse.up();
  expect(await camera()).not.toBe(fitted);
  const dragged = await camera();
  await page.mouse.wheel(0, -100);
  await expect.poll(camera).not.toBe(dragged);
  const styles = await page
    .locator('path.edge')
    .evaluateAll((edges) =>
      Object.fromEntries(
        edges.map((e) => [
          e.classList.contains('partner')
            ? 'partner'
            : [...e.classList].find((c) => !['edge', 'parent'].includes(c)),
          getComputedStyle(e).strokeDasharray,
        ]),
      ),
    );
  expect(styles.biological).not.toBe(styles.adoptive);
  expect(styles.adoptive).toBe(styles.foster);
  expect(styles.unknown).not.toBe(styles.biological);
  expect(styles.partner).toBe('none');
  await page.getByRole('button', { name: 'Text view', exact: true }).click();
  await expect(page.locator('.graph-text [data-person-id]')).toHaveCount(8);
  const lines = page.locator('.graph-text ul').nth(1).locator('li');
  await expect(lines).toHaveCount(7);
  for (const type of ['Biological', 'Adoptive', 'Foster', 'Step', 'Other', 'Unknown'])
    await expect(lines.filter({ hasText: `Parent (${type})` })).toHaveCount(1);
  await expect(lines.filter({ hasText: 'Partner' })).toHaveCount(1);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
});
