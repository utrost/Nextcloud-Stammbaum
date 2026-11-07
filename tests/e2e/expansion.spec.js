import { test, expect } from '@playwright/test';
import { editable } from '../../src/domain.js';
import { login, tree, person, relative, good, request, grant, key } from './helpers.js';

const graphPath = (tr, focus, expand = [], mode = 'family') => {
  const q = new URLSearchParams({ focus: focus.id, mode, up: '0', down: '0' });
  for (const p of expand) q.append('expand[]', p.id);
  return `/trees/${tr.id}/graph?${q}`;
};
async function chain(page, count = 4) {
  const tr = await tree(page),
    people = [];
  for (let i = 0; i < count; i++) {
    people.push(await person(page, tr.id, `Generation ${i}`));
    if (i) expect((await relative(page, tr.id, people[i - 1], people[i])).status).toBe(201);
  }
  return { tr, people };
}
const ids = (g) => g.people.map((p) => p.id).sort();
async function openZero(page, tr, focus) {
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&focus=${focus.id}`);
  await expect(page.locator('.person-node')).not.toHaveCount(0);
  await page.getByLabel('Generations up', { exact: true }).fill('0');
  await page.getByLabel('Generations up', { exact: true }).press('Tab');
  await page.getByLabel('Generations down', { exact: true }).fill('0');
  await page.getByLabel('Generations down', { exact: true }).press('Tab');
  await expect(page.locator('.person-node')).toHaveCount(1);
  await page.locator('.person-node').click();
  await expect(page.locator('.person-heading h2')).toContainText(focus.givenName);
}
const transform = (page) => page.locator('.graph-world').evaluate((e) => e.style.transform);

test.beforeEach(async ({ page }) => login(page));

test('ordered expansions add exactly one mode-appropriate neighborhood without writing genealogy', async ({
  page,
}) => {
  const {
    tr,
    people: [a, b, c, d],
  } = await chain(page);
  const partner = await person(page, tr.id, 'Partner');
  expect((await relative(page, tr.id, b, partner, 'partner')).status).toBe(201);
  const before = await good(page, `/trees/${tr.id}`);
  const base = await good(page, graphPath(tr, a));
  expect(ids(base)).toEqual([a.id]);
  expect(base.boundaryIds).toEqual([a.id]);
  const one = await good(page, graphPath(tr, a, [a]));
  expect(ids(one)).toEqual([a.id, b.id].sort());
  expect(one.boundaryIds).toEqual([b.id]);
  const two = await good(page, graphPath(tr, a, [a, b]));
  expect(ids(two)).toEqual([a.id, b.id, c.id, partner.id].sort());
  expect(two.expansions).toEqual([a.id, b.id]);
  expect(two.people).toHaveLength(new Set(ids(two)).size);
  expect(two.relations).toHaveLength(new Set(two.relations.map((r) => r.id)).size);
  expect(await good(page, graphPath(tr, a, [a, b]))).toEqual(two);
  expect(ids(await good(page, graphPath(tr, a, [a, b], 'descendants')))).toEqual([a.id, b.id, c.id].sort());
  expect(ids(await good(page, graphPath(tr, d, [d, c], 'ancestors')))).toEqual([d.id, c.id, b.id].sort());
  expect((await request(page, graphPath(tr, a, [b, a]))).status).toBe(404);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(before.revision);
  expect((await good(page, `/trees/${tr.id}/people`)).items).toHaveLength(5);
});

test('hidden bridges and contexts produce no boundary signal; foreign, missing and inaccessible anchors are unavailable', async ({
  page,
  browser,
}) => {
  const tr = await tree(page),
    a = await person(page, tr.id, 'Visible'),
    hidden = await person(page, tr.id, 'Hidden', { visibility: 'management' }),
    beyond = await person(page, tr.id, 'Beyond');
  await relative(page, tr.id, a, hidden);
  await relative(page, tr.id, hidden, beyond);
  await good(
    page,
    `/trees/${tr.id}/contexts`,
    'POST',
    {
      label: 'Private bridge',
      members: [a, hidden, beyond].map((p) => ({ personId: p.id, role: 'child' })),
      requestId: key(),
    },
    201,
  );
  await grant(page, tr.id, 'stb_viewer');
  const context = await browser.newContext(),
    viewer = await context.newPage();
  await login(viewer, 'stb_viewer');
  const g = await good(viewer, graphPath(tr, a));
  expect(ids(g)).toEqual([a.id]);
  expect(g.boundaryIds).toEqual([]);
  expect(g.limited).toBe(false);
  expect(await good(viewer, graphPath(tr, a, [a]))).toMatchObject({
    people: g.people,
    boundaryIds: [],
    relations: [],
    contexts: [],
  });
  const otherTree = await tree(page),
    foreign = await person(page, otherTree.id, 'Foreign');
  for (const anchor of [hidden, beyond, foreign, { id: 'missing' }]) {
    const response = await request(viewer, graphPath(tr, a, [anchor]));
    expect(response.status).toBe(404);
    expect(JSON.stringify(response.body)).not.toContain(anchor.id);
  }
  await context.close();
});

test('family expansion follows authorized contexts one neighborhood at a time without invented edges', async ({
  page,
}) => {
  const {
      tr,
      people: [a, b],
    } = await chain(page, 2),
    c = await person(page, tr.id, 'Context sibling'),
    d = await person(page, tr.id, 'Next context');
  const context = async (members) =>
    good(
      page,
      `/trees/${tr.id}/contexts`,
      'POST',
      {
        label: 'Ordered siblings',
        members: members.map((p) => ({ personId: p.id, role: 'child' })),
        requestId: key(),
      },
      201,
    );
  await context([c, b]);
  await context([c, d]);
  const first = await good(page, graphPath(tr, a, [a, b]));
  expect(ids(first)).toEqual([a.id, b.id, c.id].sort());
  expect(first.contexts).toHaveLength(1);
  expect(first.contexts[0].members.map((m) => m.personId)).toEqual([c.id, b.id]);
  expect(first.boundaryIds).toContain(c.id);
  expect(first.relations).toHaveLength(1);
  const second = await good(page, graphPath(tr, a, [a, b, c]));
  expect(ids(second)).toEqual([a.id, b.id, c.id, d.id].sort());
  expect(second.contexts).toHaveLength(2);
  expect(second.relations).toHaveLength(1);
});

test('twenty expansions are accepted, malformed lists rejected, and the UI offers an explicit reset at the limit', async ({
  page,
}) => {
  test.setTimeout(120000);
  const { tr, people } = await chain(page, 22),
    before = await good(page, `/trees/${tr.id}`);
  const g = await good(page, graphPath(tr, people[0], people.slice(0, 20), 'descendants'));
  expect(g.people).toHaveLength(21);
  expect(g.expansions).toHaveLength(20);
  expect(g.boundaryIds).toContain(people[20].id);
  for (const path of [
    graphPath(tr, people[0], people.slice(0, 21)),
    graphPath(tr, people[0], [people[0], people[0]]),
    graphPath(tr, people[0]) + '&expand=bad',
    graphPath(tr, people[0]) + '&expand[x]=bad',
  ])
    expect((await request(page, path)).status).toBe(422);
  await openZero(page, tr, people[0]);
  await page.getByRole('button', { name: 'Text view', exact: true }).click();
  for (let i = 0; i < 20; i++) {
    await page.locator(`.graph-text [data-person-id="${people[i].id}"]`).click();
    await expect(page.locator('.person-heading h2')).toContainText(people[i].givenName);
    await page.getByRole('button', { name: 'Expand relatives', exact: true }).click();
    await expect(page.locator('.graph-text [data-person-id]')).toHaveCount(i + 2);
  }
  await page.locator(`.graph-text [data-person-id="${people[20].id}"]`).click();
  await expect(page.getByRole('button', { name: 'Expand relatives', exact: true })).toBeDisabled();
  await expect(page.locator('.expansion-toolbar')).toContainText('20 / 20');
  await expect(page.locator('.expansion-toolbar [role=status]')).toContainText('limit reached');
  await page.getByRole('button', { name: 'Reset local expansions', exact: true }).click();
  await expect(page.locator('.graph-text [data-person-id]')).toHaveCount(1);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(before.revision);
});

test('diagram and text expansion preserve selection and camera; Previous branch restores the expanded branch', async ({
  page,
}) => {
  const {
    tr,
    people: [a, b, c],
  } = await chain(page);
  await openZero(page, tr, a);
  await page.getByRole('region', { name: 'Family diagram', exact: true }).focus();
  await page.keyboard.press('ArrowLeft');
  const camera = await transform(page);
  await page.getByRole('button', { name: 'Expand relatives', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('.person-node')).toHaveCount(2);
  expect(await transform(page)).toBe(camera);
  await expect(page.locator('.person-node.focus')).toContainText(a.givenName);
  await expect(page.locator('.person-heading h2')).toContainText(a.givenName);
  await page.getByRole('button', { name: 'Text view', exact: true }).click();
  await page.locator(`.graph-text [data-person-id="${b.id}"]`).click();
  await page.getByRole('button', { name: 'Expand relatives', exact: true }).click();
  await expect(page.locator('.graph-text [data-person-id]')).toHaveCount(3);
  await expect(page.locator(`.graph-text [data-person-id="${b.id}"]`)).toBeFocused();
  await page.locator(`.graph-text [data-person-id="${c.id}"]`).click();
  await page.getByRole('button', { name: 'Show family branch', exact: true }).click();
  await expect(page.locator('.graph-text [data-person-id]')).toHaveCount(1);
  await expect(page.locator('.expansion-toolbar')).not.toContainText('2 / 20');
  await page.getByRole('button', { name: 'Previous branch', exact: false }).click();
  await expect(page.locator('.graph-text [data-person-id]')).toHaveCount(3);
  await expect(page.locator('.expansion-toolbar')).toContainText('2 / 20');
  await expect(page.locator('.person-heading h2')).toContainText(c.givenName);
  await page.getByRole('button', { name: 'Diagram', exact: true }).click();
  await expect(page.locator('.person-node.focus')).toContainText(a.givenName);
  expect(await transform(page)).toBe(camera);
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.getByRole('button', { name: 'Fit', exact: true }).click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: `test-results/expanded-family-${width}.png`, fullPage: true });
  }
});

test('a failed expansion clears the drawing and retries the same intent in text view', async ({ page }) => {
  const {
    tr,
    people: [a],
  } = await chain(page);
  await openZero(page, tr, a);
  await page.getByRole('button', { name: 'Text view', exact: true }).click();
  const pattern = `**/trees/${tr.id}/graph?*`,
    attempts = [];
  await page.route(pattern, (route) => {
    attempts.push(route.request().url());
    return route.fulfill({ status: 503, json: { message: 'Simulated graph outage' } });
  });
  await page.getByRole('button', { name: 'Expand relatives', exact: true }).click();
  await expect(page.locator('.graph-error')).toContainText('Simulated graph outage');
  await expect(page.locator('.graph-shell')).toHaveCount(0);
  await expect(page.locator('.expansion-toolbar')).toContainText('1 / 20');
  await expect(page.locator('.graph-error button')).toBeFocused();
  await page.unroute(pattern);
  const response = page.waitForResponse((r) => r.url() === attempts[0]);
  await page.getByRole('button', { name: 'Retry', exact: true }).click();
  await response;
  await expect(page.locator('.graph-text [data-person-id]')).toHaveCount(2);
  await expect(page.locator('.graph-error')).toHaveCount(0);
});

test('a delayed expansion response cannot replace a newly focused branch', async ({ page }) => {
  const {
    tr,
    people: [a, b],
  } = await chain(page);
  await openZero(page, tr, a);
  await page.getByRole('button', { name: 'Expand relatives', exact: true }).click();
  await expect(page.locator('.person-node')).toHaveCount(2);
  await page.locator(`.person-node[data-person-id="${b.id}"]`).click();
  let release;
  const gate = new Promise((resolve) => (release = resolve));
  const pattern = `**/trees/${tr.id}/graph?*`;
  await page.route(pattern, async (route) => {
    const u = new URL(route.request().url());
    if (u.searchParams.get('focus') === a.id && u.searchParams.getAll('expand[]').length === 2) {
      const response = await route.fetch();
      await gate;
      return route.fulfill({ response });
    }
    return route.continue();
  });
  const started = page.waitForRequest((r) => new URL(r.url()).searchParams.getAll('expand[]').length === 2);
  await page.getByRole('button', { name: 'Expand relatives', exact: true }).click();
  await started;
  await page.getByRole('button', { name: 'Show family branch', exact: true }).click();
  await expect(page.locator('.person-node.focus')).toContainText(b.givenName);
  const finished = page.waitForResponse((r) => new URL(r.url()).searchParams.getAll('expand[]').length === 2);
  release();
  await (await finished).finished();
  await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
  await expect(page.locator('.person-node')).toHaveCount(1);
  await expect(page.locator('.person-node.focus')).toContainText(b.givenName);
});

test('expanding a partially displayed context restores its omitted parent without creating a relationship', async ({
  page,
}) => {
  const tr = await tree(page),
    a = await person(page, tr.id, 'Child'),
    p = await person(page, tr.id, 'Context parent');
  await good(
    page,
    `/trees/${tr.id}/contexts`,
    'POST',
    {
      label: 'Partial group',
      members: [
        { personId: p.id, role: 'parent' },
        { personId: a.id, role: 'child' },
      ],
      requestId: key(),
    },
    201,
  );
  const base = await good(page, graphPath(tr, a));
  expect(ids(base)).toEqual([a.id]);
  expect(base.boundaryIds).toEqual([a.id]);
  const expanded = await good(page, graphPath(tr, a, [a]));
  expect(ids(expanded)).toEqual([a.id, p.id].sort());
  expect(expanded.contexts).toHaveLength(1);
  expect(expanded.contexts[0].members.map((m) => m.personId)).toEqual([p.id, a.id]);
  expect(expanded.relations).toEqual([]);
});

test('repeated ancestors and nonbiological loops remain unique through expansion', async ({ page }) => {
  const tr = await tree(page),
    root = await person(page, tr.id, 'Root'),
    left = await person(page, tr.id, 'Left'),
    right = await person(page, tr.id, 'Right'),
    shared = await person(page, tr.id, 'Shared ancestor');
  for (const [parent, child] of [
    [left, root],
    [right, root],
    [shared, left],
    [shared, right],
  ])
    expect((await relative(page, tr.id, parent, child)).status).toBe(201);
  expect((await relative(page, tr.id, root, shared, 'child', 'foster')).status).toBe(201);
  const before = await good(page, `/trees/${tr.id}`);
  const g = await good(page, graphPath(tr, root, [root, left, right, shared], 'ancestors'));
  expect(ids(g)).toEqual([root.id, left.id, right.id, shared.id].sort());
  expect(g.relations).toHaveLength(5);
  expect(g.boundaryIds).toEqual([]);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(before.revision);
});

test('context groups remain globally capped across expansions', async ({ page }) => {
  const {
    tr,
    people: [a, b],
  } = await chain(page, 2);
  for (let i = 0; i < 21; i++)
    await good(
      page,
      `/trees/${tr.id}/contexts`,
      'POST',
      {
        label: `Group ${String(i).padStart(2, '0')}`,
        members: [{ personId: (i === 20 ? b : a).id, role: 'child' }],
        requestId: key(),
      },
      201,
    );
  const g = await good(page, graphPath(tr, a, [a, b]));
  expect(g.contexts).toHaveLength(20);
  expect(g.limited).toBe(true);
  expect(ids(g)).toEqual([a.id, b.id].sort());
});

test('opening a named view leaves the previous expansion depths and camera intact in branch history', async ({
  page,
}) => {
  const {
    tr,
    people: [a, b, c, d],
  } = await chain(page);
  await good(
    page,
    `/trees/${tr.id}/views`,
    'POST',
    {
      revision: 1,
      label: 'Another ancestor view',
      focus: d.id,
      selected: c.id,
      mode: 'ancestors',
      up: 1,
      down: 0,
      camera: { x: 17, y: 29, zoom: 0.8 },
      requestId: key(),
    },
    201,
  );
  await openZero(page, tr, a);
  await page.getByRole('button', { name: 'Expand relatives', exact: true }).click();
  await expect(page.locator('.person-node')).toHaveCount(2);
  const camera = await transform(page);
  await page.locator('.saved-views summary').click();
  await page
    .getByRole('combobox', { name: 'Saved views', exact: true })
    .selectOption({ label: 'Another ancestor view' });
  await page.getByRole('button', { name: 'Open view', exact: true }).click();
  await expect(page.locator('.person-node.focus')).toContainText(d.givenName);
  await expect(page.getByLabel('Generations up', { exact: true })).toHaveValue('1');
  await page.getByRole('button', { name: 'Previous branch', exact: false }).click();
  await expect(page.locator('.person-node.focus')).toContainText(a.givenName);
  await expect(page.locator('.person-node')).toHaveCount(2);
  await expect(page.getByLabel('Generations up', { exact: true })).toHaveValue('0');
  await expect(page.getByRole('combobox', { name: 'Family', exact: true })).toHaveValue('family');
  await expect(page.locator('.expansion-toolbar')).toContainText('1 / 20');
  expect(await transform(page)).toBe(camera);
});

test('a viewer expands by keyboard on a German phone layout without gaining genealogy editing rights', async ({
  page,
  browser,
}) => {
  const {
    tr,
    people: [a],
  } = await chain(page);
  await grant(page, tr.id, 'stb_viewer');
  const before = await good(page, `/trees/${tr.id}`);
  const ctx = await browser.newContext(),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  try {
    await openZero(viewer, tr, a);
    await viewer.getByLabel('Language', { exact: true }).selectOption('de');
    await expect.poll(async () => (await good(viewer, '/preferences')).locale).toBe('de');
    await viewer.setViewportSize({ width: 320, height: 1000 });
    const button = viewer.getByRole('button', { name: 'Verwandte einblenden', exact: true });
    await button.focus();
    await viewer.keyboard.press('Enter');
    await expect(viewer.locator('.person-node')).toHaveCount(2);
    await expect(viewer.locator('.expansion-toolbar')).toContainText('Lokale Erweiterungen: 1 / 20');
    await expect(viewer.getByRole('button', { name: 'Person bearbeiten', exact: true })).toHaveCount(0);
    await viewer.getByRole('button', { name: 'Textansicht', exact: true }).click();
    await expect(viewer.locator('.graph-text [data-person-id]')).toHaveCount(2);
    expect(await viewer.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await viewer.screenshot({ path: 'test-results/expanded-viewer-de-320.png', fullPage: true });
    expect((await good(page, `/trees/${tr.id}`)).revision).toBe(before.revision);
  } finally {
    const pref = await good(viewer, '/preferences');
    await good(viewer, '/preferences', 'PUT', { revision: pref.revision, locale: 'en' });
    await ctx.close();
  }
});

test('expansion authority is rechecked after a visible bridge becomes private', async ({ page, browser }) => {
  const {
    tr,
    people: [a, b],
  } = await chain(page);
  await grant(page, tr.id, 'stb_viewer');
  const ctx = await browser.newContext(),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  expect((await good(viewer, graphPath(tr, a, [a, b]))).people).toHaveLength(3);
  const current = await good(page, `/trees/${tr.id}/people/${b.id}`);
  await good(page, `/trees/${tr.id}/people/${b.id}`, 'PUT', {
    ...editable(current),
    visibility: 'management',
    revision: current.revision,
  });
  expect((await request(viewer, graphPath(tr, a, [a, b]))).status).toBe(404);
  const base = await good(viewer, graphPath(tr, a));
  expect(ids(base)).toEqual([a.id]);
  expect(base.boundaryIds).toEqual([]);
  expect((await good(page, graphPath(tr, a, [a, b]))).people).toHaveLength(3);
  await ctx.close();
});
