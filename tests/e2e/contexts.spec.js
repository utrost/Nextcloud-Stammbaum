import { test, expect } from '@playwright/test';
import { login, tree, person, grant, good, request, key, relative } from './helpers.js';
const create = (page, tid, members, extra = {}) =>
  good(
    page,
    `/trees/${tid}/contexts`,
    'POST',
    {
      label: 'Fictional siblings',
      members: members.map((p) => ({ personId: p.id, role: 'child' })),
      requestId: key(),
      ...extra,
    },
    201,
  );
const edit = (c) => ({
  label: c.label,
  visibility: c.visibility,
  revision: c.revision,
  members: c.members.map(({ personId, role, description }) => ({ personId, role, description })),
  relationIds: c.relations.map((r) => r.id),
});

test('ordered siblings with unknown parents appear in the family projection without fabricated edges', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    q = await person(page, tr.id, 'Bruno');
  const c = await create(page, tr.id, [q, p]);
  expect(c.members.map((m) => m.personId)).toEqual([q.id, p.id]);
  expect(c.relations).toEqual([]);
  const graph = await good(page, `/trees/${tr.id}/graph?focus=${p.id}`);
  expect(graph.people.map((p) => p.id).sort()).toEqual([p.id, q.id].sort());
  expect(graph.relations).toEqual([]);
  expect(graph.contexts[0].members.map((m) => m.personId)).toEqual([q.id, p.id]);
  const reversed = await good(page, `/trees/${tr.id}/contexts/${c.id}`, 'PUT', {
    ...edit(c),
    members: [...edit(c).members].reverse(),
  });
  expect(reversed.members.map((m) => m.personId)).toEqual([p.id, q.id]);
  expect((await request(page, `/trees/${tr.id}/contexts/${c.id}`, 'PUT', edit(c))).status).toBe(412);
  expect(
    (
      await request(page, `/trees/${tr.id}/contexts`, 'POST', {
        label: 'Duplicate',
        members: [{ personId: p.id }, { personId: p.id }],
        requestId: key(),
      })
    ).status,
  ).toBe(422);
});

test('contexts hide all private dependencies and discard inactive connection references only on save', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Public'),
    q = await person(page, tr.id, 'Private', { visibility: 'management' });
  await grant(page, tr.id, 'stb_viewer');
  const rel = await relative(page, tr.id, p, q);
  expect(rel.status).toBe(201);
  const c = await create(page, tr.id, [p, q], { relationIds: [rel.body.id] });
  const ctx = await browser.newContext(),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  expect((await good(viewer, `/trees/${tr.id}/contexts?person=${p.id}`)).items).toEqual([]);
  expect((await request(viewer, `/trees/${tr.id}/contexts/${c.id}`)).status).toBe(404);
  expect((await good(viewer, `/trees/${tr.id}/graph?focus=${p.id}`)).people.map((x) => x.id)).toEqual([p.id]);
  await ctx.close();
  await good(page, `/trees/${tr.id}/relations/${rel.body.id}/remove`, 'POST', {
    revision: rel.body.revision,
  });
  const historical = await good(page, `/trees/${tr.id}/contexts/${c.id}`);
  expect(historical.relations[0].active).toBe(false);
  const updated = await good(page, `/trees/${tr.id}/contexts/${c.id}`, 'PUT', edit(historical));
  expect(updated.relations).toEqual([]);
});

test('context editor preserves explicit order and does not create parents', async ({ page }) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma');
  await person(page, tr.id, 'Bruno');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.getByRole('button', { name: 'New context', exact: false }).click();
  const d = page.getByRole('dialog');
  await d.getByLabel('Label', { exact: true }).fill('Siblings without known parents');
  await d.getByLabel('Find another person', { exact: true }).fill('Bruno');
  await d.getByRole('button', { name: 'Search people', exact: true }).click();
  await d.getByRole('button', { name: 'Bruno Example', exact: true }).click();
  await d.getByRole('button', { name: 'Move up Bruno Example', exact: true }).click();
  await d.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.context-members li').first()).toContainText('Bruno Example');
  expect((await good(page, `/trees/${tr.id}/graph?focus=${p.id}`)).relations).toEqual([]);
});
