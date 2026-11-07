import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { fixtureDatabase } from './server-fixture.js';
import { login, tree, person, good, key, request } from './helpers.js';
import { emptyPerson } from '../../src/domain.js';
function insert(table, columns, rows) {
  for (let at = 0; at < rows.length; at += 40) {
    const slice = rows.slice(at, at + 40);
    fixtureDatabase(
      `INSERT INTO *PREFIX*${table} (${columns.join(',')}) VALUES ${slice.map(() => `(${columns.map(() => '?').join(',')})`).join(',')}`,
      slice.flat(),
    );
  }
}

test('large fictional family remains bounded at 200 people, 400 neighbors and 20 context groups', async ({
  page,
}) => {
  test.setTimeout(90000);
  await login(page);
  const tr = await tree(page),
    root = await person(page, tr.id, 'Root'),
    now = Math.floor(Date.now() / 1000),
    children = [];
  for (let i = 0; i < 450; i++) {
    const id = randomUUID(),
      data = { ...emptyPerson(), givenName: `Child ${String(i).padStart(3, '0')}`, familyName: 'Fictional' };
    delete data.confirmUnnamed;
    delete data.confirmRelease;
    children.push({ id, data });
  }
  insert(
    'stb_people',
    [
      'id',
      'tree_id',
      'given_name',
      'family_name',
      'search_text',
      'data_json',
      'visibility',
      'revision',
      'deleted',
      'created_at',
      'updated_at',
    ],
    children.map(({ id, data }) => [
      id,
      tr.id,
      data.givenName,
      data.familyName,
      data.givenName.toLowerCase(),
      JSON.stringify(data),
      'members',
      1,
      0,
      now,
      now,
    ]),
  );
  insert(
    'stb_relations',
    ['id', 'tree_id', 'source_id', 'target_id', 'kind', 'parent_type', 'revision', 'active', 'created_at'],
    children.map(({ id }) => [randomUUID(), tr.id, root.id, id, 'parent', 'biological', 1, 1, now]),
  );
  const contexts = Array.from({ length: 21 }, (_, i) => ({
    id: randomUUID(),
    child: children[i].id,
    label: `Group ${i}`,
  }));
  insert(
    'stb_contexts',
    ['id', 'tree_id', 'label', 'visibility', 'relation_ids', 'revision', 'created_at', 'updated_at'],
    contexts.map((c) => [c.id, tr.id, c.label, 'members', '[]', 1, now, now]),
  );
  insert(
    'stb_context_people',
    ['id', 'context_id', 'person_id', 'role', 'description', 'position'],
    contexts.flatMap((c) => [
      [randomUUID(), c.id, root.id, 'parent', '', 0],
      [randomUUID(), c.id, c.child, 'child', '', 1],
    ]),
  );
  const started = Date.now(),
    graph = await good(page, `/trees/${tr.id}/graph?focus=${root.id}&up=8&down=8`);
  expect(graph.people).toHaveLength(200);
  expect(new Set(graph.people.map((p) => p.id)).size).toBe(200);
  expect(graph.relations.length).toBeLessThanOrEqual(1000);
  expect(graph.contexts).toHaveLength(20);
  expect(graph.limited).toBe(true);
  const expanded = await good(page, `/trees/${tr.id}/graph?focus=${root.id}&up=0&down=0&expand[]=${root.id}`);
  expect(expanded.people).toHaveLength(200);
  expect(new Set(expanded.people.map((p) => p.id)).size).toBe(200);
  expect(expanded.contexts).toHaveLength(20);
  expect(expanded.relations.length).toBeLessThanOrEqual(1000);
  expect(expanded.limited).toBe(true);
  expect((await good(page, `/trees/${tr.id}/people`)).items).toHaveLength(50);
  test.info().annotations.push({ type: 'bounded-graph-ms', description: String(Date.now() - started) });
  await page.setViewportSize({ width: 320, height: 1000 });
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${root.id}`);
  await expect(page.locator('.person-node')).toHaveCount(200);
  await expect(page.locator('.graph-shell .feedback')).toBeVisible();
  await page.getByRole('button', { name: 'Fit', exact: true }).click();
  expect(
    await page.locator('.graph-viewport').evaluate((viewport) => {
      const bounds = viewport.getBoundingClientRect();
      return [...viewport.querySelectorAll('.person-node')].every((node) => {
        const r = node.getBoundingClientRect();
        return (
          r.left >= bounds.left && r.right <= bounds.right && r.top >= bounds.top && r.bottom <= bounds.bottom
        );
      });
    }),
  ).toBe(true);
  await page.getByRole('button', { name: 'Text view', exact: true }).click();
  await expect(page.locator('.graph-text button')).toHaveCount(200);
});

test('deletion review detects shared dependencies and rejects a changed tree before applying the reviewed action', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Review'),
    q = await person(page, tr.id, 'Other');
  await good(
    page,
    `/trees/${tr.id}/contexts`,
    'POST',
    { label: 'Shared context', members: [{ personId: p.id }, { personId: q.id }], requestId: key() },
    201,
  );
  const impact = await good(page, `/trees/${tr.id}/people/${p.id}/deletion-impact`);
  expect(impact.contexts).toBe(1);
  expect(impact.connections).toBe(0);
  await person(page, tr.id, 'Changed tree');
  const stale = await request(page, `/trees/${tr.id}/people/${p.id}/delete`, 'POST', {
    revision: impact.revision,
    treeRevision: impact.treeRevision,
    confirmed: true,
  });
  expect(stale.status).toBe(412);
  expect((await good(page, `/trees/${tr.id}/people/${p.id}`)).id).toBe(p.id);
});

test('dense expanded family returns at most 1000 unique relationship lines', async ({ page }) => {
  test.setTimeout(90000);
  await login(page);
  const tr = await tree(page),
    people = [],
    now = Math.floor(Date.now() / 1000);
  for (let i = 0; i < 46; i++) people.push(await person(page, tr.id, `Connected ${i}`));
  const edges = [];
  for (let i = 0; i < people.length; i++)
    for (let j = i + 1; j < people.length; j++)
      edges.push([randomUUID(), tr.id, people[i].id, people[j].id, 'partner', 'unknown', 1, 1, now]);
  insert(
    'stb_relations',
    ['id', 'tree_id', 'source_id', 'target_id', 'kind', 'parent_type', 'revision', 'active', 'created_at'],
    edges,
  );
  const before = await good(page, `/trees/${tr.id}`);
  const g = await good(
    page,
    `/trees/${tr.id}/graph?focus=${people[0].id}&up=0&down=0&expand[]=${people[0].id}`,
  );
  expect(g.people).toHaveLength(46);
  expect(g.relations).toHaveLength(1000);
  expect(new Set(g.relations.map((r) => r.id)).size).toBe(1000);
  expect(g.limited).toBe(true);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(before.revision);
});
