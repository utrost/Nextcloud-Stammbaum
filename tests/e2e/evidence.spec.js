import { test, expect } from '@playwright/test';
import { login, tree, person, grant, good, request, key } from './helpers.js';
const source = (page, tid, extra = {}) =>
  good(
    page,
    `/trees/${tid}/sources`,
    'POST',
    { title: 'Fictional parish register', type: 'Register', requestId: key(), ...extra },
    201,
  );
const citation = (page, tid, s, targets, extra = {}) =>
  good(
    page,
    `/trees/${tid}/citations`,
    'POST',
    {
      sourceId: s.id,
      locator: 'Page 12',
      quotation: 'Fictional recorded birth',
      targets,
      requestId: key(),
      ...extra,
    },
    201,
  );
const body = (c) =>
  Object.fromEntries(
    [
      'sourceId',
      'locator',
      'quotation',
      'url',
      'documentDate',
      'accessDate',
      'reliability',
      'reasoning',
      'visibility',
      'revision',
      'targets',
    ].map((k) => [
      k,
      k === 'targets' ? c.targets.map(({ kind, targetId, effect }) => ({ kind, targetId, effect })) : c[k],
    ]),
  );

test('shared citations retain independent target effects, reject stale writes, and detach without deleting evidence', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    q = await person(page, tr.id, 'Bruno'),
    s = await source(page, tr.id);
  const claims = (await good(page, `/trees/${tr.id}/people/${p.id}/claims`)).items;
  const c = await citation(page, tr.id, s, [
    { kind: 'person', targetId: q.id },
    { kind: 'claim', targetId: claims[0].id, effect: 'contradicts' },
  ]);
  expect(c.targets.find((x) => x.kind === 'claim').effect).toBe('contradicts');
  expect((await good(page, `/trees/${tr.id}/citations?person=${p.id}`)).items.map((x) => x.id)).toContain(
    c.id,
  );
  const detached = await good(page, `/trees/${tr.id}/citations/${c.id}`, 'PUT', { ...body(c), targets: [] });
  expect(detached.targets).toEqual([]);
  expect((await request(page, `/trees/${tr.id}/citations/${c.id}`, 'PUT', body(c))).status).toBe(412);
  expect((await good(page, `/trees/${tr.id}/sources/${s.id}`)).title).toBe(s.title);
  expect((await good(page, `/trees/${tr.id}/citations?person=${p.id}`)).items).toEqual([]);
  expect((await good(page, `/trees/${tr.id}/citations?source=${s.id}`)).items).toHaveLength(1);
  expect(
    (
      await request(page, `/trees/${tr.id}/citations`, 'POST', {
        sourceId: s.id,
        targets: [{ kind: 'person', targetId: p.id, effect: 'supports' }],
        requestId: key(),
      })
    ).status,
  ).toBe(422);
});

test('any hidden dependency hides the whole citation and prevents indirect shared-source edits', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Public'),
    q = await person(page, tr.id, 'Secret', { visibility: 'management' }),
    s = await source(page, tr.id);
  const c = await citation(page, tr.id, s, [
    { kind: 'person', targetId: p.id },
    { kind: 'person', targetId: q.id },
  ]);
  await grant(page, tr.id, 'stb_editor', 'editor');
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  await login(editor, 'stb_editor');
  expect((await request(editor, `/trees/${tr.id}/citations/${c.id}`)).status).toBe(404);
  const list = await good(editor, `/trees/${tr.id}/citations?person=${p.id}`);
  expect(list.items).toEqual([]);
  expect(list.nextPage).toBe(null);
  expect(
    (
      await request(editor, `/trees/${tr.id}/sources/${s.id}`, 'PUT', {
        title: 'Rewrite',
        revision: s.revision,
      })
    ).status,
  ).toBe(403);
  const hidden = await source(page, tr.id, { visibility: 'management', title: 'Hidden source' });
  expect((await good(editor, `/trees/${tr.id}/sources`)).items.map((x) => x.id)).not.toContain(hidden.id);
  expect((await request(editor, `/trees/${tr.id}/sources/${hidden.id}`)).status).toBe(404);
  await ctx.close();
});

test('evidence rejects foreign targets and active URLs; source and citation creation retries preserve identity', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    other = await tree(page),
    p = await person(page, other.id, 'Foreign'),
    requestId = key();
  const s = await source(page, tr.id, { requestId });
  expect((await source(page, tr.id, { requestId })).id).toBe(s.id);
  expect(
    (
      await request(page, `/trees/${tr.id}/sources`, 'POST', {
        title: 'Unsafe',
        url: 'javascript:alert(1)',
        requestId: key(),
      })
    ).status,
  ).toBe(422);
  expect(
    (
      await request(page, `/trees/${tr.id}/citations`, 'POST', {
        sourceId: s.id,
        targets: [{ kind: 'person', targetId: p.id }],
        requestId: key(),
      })
    ).status,
  ).toBe(404);
  const cid = key(),
    c = await citation(page, tr.id, s, [], { requestId: cid });
  expect((await citation(page, tr.id, s, [], { requestId: cid })).id).toBe(c.id);
});

test('source and citation UI creates, reuses and detaches evidence without losing the source', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=sources`);
  await page.getByRole('button', { name: 'New source', exact: false }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Title', { exact: true }).fill('Fictional church book');
  await dialog.getByLabel('Repository', { exact: true }).fill('Example archive');
  await dialog.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Fictional church book', exact: true })).toBeVisible();
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.getByRole('tab', { name: 'Evidence', exact: true }).click();
  await page.getByRole('button', { name: 'New citation', exact: false }).click();
  await dialog.getByLabel('Source', { exact: true }).selectOption({ label: 'Fictional church book' });
  await dialog.getByLabel('Locator', { exact: true }).fill('Entry 42');
  await dialog.getByLabel('Quotation', { exact: true }).fill('Fictional wording <script>alert(1)</script>');
  await dialog.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.citation-card')).toContainText('Entry 42');
  await expect(page.locator('.citation-card')).toContainText('<script>alert(1)</script>');
  await page.getByRole('button', { name: 'Edit citation', exact: true }).click();
  await dialog.getByRole('button', { name: /Detach Alma/ }).click();
  await dialog.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('No citations yet', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Reuse existing citation', exact: true }).click();
  await page.getByRole('button', { name: 'Reference from this person', exact: true }).click();
  await expect(page.locator('.citation-card')).toContainText('Alma Example');
  await page.screenshot({ path: 'test-results/evidence-desktop.png', fullPage: true });
});
