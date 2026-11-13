import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { login, tree, person, grant, good, request, key, endpoint } from './helpers.js';
import { editable } from '../../src/domain.js';
const pdf = readFileSync('tests/fixtures/media-two-pages.pdf');
async function upload(page, tid, title = 'Fictional document', image = false) {
  const bytes = image
    ? Buffer.from(
        await page.evaluate(() => {
          const c = document.createElement('canvas');
          c.width = 200;
          c.height = 140;
          const x = c.getContext('2d');
          x.fillStyle = '#d7e7dc';
          x.fillRect(0, 0, 200, 140);
          x.fillStyle = '#316956';
          x.fillRect(50, 30, 100, 90);
          return c.toDataURL('image/png').split(',')[1];
        }),
        'base64',
      )
    : pdf;
  const response = await page.request.post(`${endpoint}/trees/${tid}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      title,
      requestId: key(),
      file: {
        name: image ? 'fictional.png' : 'fictional.pdf',
        mimeType: image ? 'image/png' : 'application/pdf',
        buffer: bytes,
      },
    },
  });
  expect(response.status(), await response.text()).toBe(201);
  return response.json();
}
const save = (page, tid, m, metadata, extra = {}) =>
  good(page, `/trees/${tid}/media/${m.id}`, 'PUT', {
    title: m.title,
    revision: m.revision,
    metadata,
    ...extra,
  });
const event = (page, tid, p, extra = {}) =>
  good(
    page,
    `/trees/${tid}/records`,
    'POST',
    {
      originId: p.id,
      category: 'event',
      type: 'custom',
      title: 'Fictional reunion',
      text: 'An afternoon at the lake.',
      date: { kind: 'about', value: '1935' },
      place: 'Old village',
      participants: [{ personId: p.id, role: 'subject' }],
      confirmedParticipants: [p.id],
      requestId: key(),
      ...extra,
    },
    201,
  );

test('metadata preserves uncertain dates, captions, originals and crops; stale/invalid updates fail atomically', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    m = await upload(page, tr.id, 'Old family letter');
  const e = await event(page, tr.id, p);
  const d = {
    ...m.metadata,
    description: 'A fictional letter',
    date: { kind: 'between', value: '1930', end: '1935' },
    people: [{ name: 'Aunt Else', personId: '', association: 'mentioned', page: '2' }],
    event: 'Summer reunion',
    eventId: e.id,
    place: 'Old village',
    source: 'Family collection',
    credit: 'Fictional fixture',
  };
  const changed = await save(page, tr.id, m, d);
  expect(changed.metadata.people[0].personId).toBe('');
  expect(changed.metadata.date).toEqual(d.date);
  expect(changed.metadata.linkedEvent.id).toBe(e.id);
  const linked = await save(page, tr.id, changed, { ...d, people: [{ ...d.people[0], personId: p.id }] });
  expect(linked.metadata.people[0].name).toBe('Aunt Else');
  expect(linked.metadata.people[0].person.givenName).toBe('Alma');
  expect((await good(page, `/trees/${tr.id}/media?person=${p.id}`)).items[0].id).toBe(m.id);
  expect((await good(page, `/trees/${tr.id}/media?event=${e.id}`)).items[0].id).toBe(m.id);
  expect(
    (
      await request(page, `/trees/${tr.id}/media/${m.id}`, 'PUT', {
        title: m.title,
        revision: m.revision,
        metadata: d,
      })
    ).status,
  ).toBe(412);
  for (const invalid of [
    { ...d, date: { kind: 'between', value: '1940', end: '1930' } },
    { ...d, people: Array(33).fill(d.people[0]) },
    { ...d, eventId: key() },
  ]) {
    const r = await request(page, `/trees/${tr.id}/media/${m.id}`, 'PUT', {
      title: m.title,
      revision: linked.revision,
      metadata: invalid,
    });
    expect([404, 422]).toContain(r.status);
  }
  expect((await good(page, `/trees/${tr.id}/media/${m.id}`)).revision).toBe(linked.revision);
  expect(await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/original`)).body()).toEqual(
    pdf,
  );
  expect(linked.sha256).toBe(m.sha256);
  const image = await upload(page, tr.id, 'Portrait with caption', true);
  const assigned = await save(page, tr.id, image, image.metadata, {
    assignments: [
      {
        personId: p.id,
        purpose: 'portrait',
        preferred: true,
        crop: { x: 0.25, y: 0, width: 0.5, height: 1 },
      },
    ],
  });
  const described = await save(page, tr.id, assigned, {
    ...image.metadata,
    description: 'Caption added separately',
  });
  expect(described.assignments).toEqual(assigned.assignments);
  expect(described.sha256).toBe(image.sha256);
});

test('metadata filters apply before pagination and browsing does not change tree revisions', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  const first = await upload(page, tr.id, 'Oldest match');
  await save(page, tr.id, first, {
    ...first.metadata,
    date: { kind: 'about', value: '1910' },
    people: [{ name: 'Else Example', personId: '', association: 'mentioned' }],
    place: '100% Village',
    event: 'Reunion',
  });
  for (let i = 0; i < 26; i++) await upload(page, tr.id, `Later item ${i}`);
  const before = await good(page, `/trees/${tr.id}`);
  expect((await good(page, `/trees/${tr.id}/media`)).items).toHaveLength(25);
  expect(
    (
      await good(page, `/trees/${tr.id}/media?personQuery=Else&eventQuery=Reunion&place=100%25&type=pdf`)
    ).items.map((m) => m.id),
  ).toEqual([first.id]);
  expect((await good(page, `/trees/${tr.id}/media?missing=1`)).nextPage).toBe(1);
  expect((await good(page, `/trees/${tr.id}/media?missing=1&page=1`)).items).toHaveLength(1);
  expect((await good(page, `/trees/${tr.id}/media?type=image`)).items).toEqual([]);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await expect(page.locator('.media-card')).toHaveCount(25);
  await page.getByRole('checkbox', { name: 'Missing details' }).check();
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await expect(page.locator('.media-card')).toHaveCount(25);
  await page.getByRole('button', { name: 'Load more', exact: true }).click();
  await expect(page.locator('.media-card')).toHaveCount(26);
  await page.getByLabel('Named person', { exact: true }).fill('Else');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await expect(page.locator('.media-card')).toHaveCount(0);
  await page.getByRole('button', { name: 'Reset filters', exact: true }).click();
  await page.getByLabel('Named person', { exact: true }).fill('Else');
  await page.getByLabel('Place filter', { exact: true }).fill('100% Village');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await expect(page.locator('.media-card')).toHaveCount(1);
  await expect(page.locator('.media-card')).toContainText('Oldest match');
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(before.revision);
});

test('hidden people and event participants protect metadata, suggestions, filters and cached previews', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Secret', { visibility: 'management' });
  await grant(page, tr.id, 'stb_editor', 'editor');
  const e = await event(page, tr.id, p),
    a = await upload(page, tr.id),
    b = await upload(page, tr.id);
  await save(page, tr.id, a, {
    ...a.metadata,
    people: [{ name: 'Secret caption', personId: p.id, association: 'mentioned' }],
  });
  await save(page, tr.id, b, { ...b.metadata, eventId: e.id });
  await page.request.get(`${endpoint}/trees/${tr.id}/media/${b.id}/preview`);
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  await login(editor, 'stb_editor');
  expect((await good(editor, `/trees/${tr.id}/media?personQuery=Secret`)).items).toEqual([]);
  expect((await good(editor, `/trees/${tr.id}/media`)).items).toEqual([]);
  expect((await good(editor, `/trees/${tr.id}/media-events?query=Fictional`)).items).toEqual([]);
  expect((await request(editor, `/trees/${tr.id}/media?event=${e.id}`)).status).toBe(404);
  for (const m of [a, b])
    for (const suffix of ['', '/preview', '/original', '/preview-info'])
      expect((await editor.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}${suffix}`)).status()).toBe(
        404,
      );
  await ctx.close();
});

test('person deletion retains captions privately and restoration does not resurrect descriptive links', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    m = await upload(page, tr.id);
  const linked = await save(page, tr.id, m, {
    ...m.metadata,
    people: [{ personId: p.id, name: 'Aunt Alma', association: 'mentioned' }],
  });
  await good(page, `/trees/${tr.id}/people/${p.id}/delete`, 'POST', {
    revision: p.revision,
    confirmed: true,
  });
  const after = await good(page, `/trees/${tr.id}/media/${m.id}`);
  expect(after.metadata.people[0]).toMatchObject({ personId: '', name: 'Aunt Alma' });
  expect(after.visibility).toBe('management');
  expect(after.revision).toBe(linked.revision + 1);
  await good(page, `/trees/${tr.id}/people/${p.id}/restore`, 'POST', {
    revision: p.revision + 1,
    confirmed: true,
  });
  expect((await good(page, `/trees/${tr.id}/media?person=${p.id}`)).items).toEqual([]);
});

test('deletion review counts shared-event media and restore does not revive its link or expose the original', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    q = await person(page, tr.id, 'Bruno'),
    e = await event(page, tr.id, p, {
      participants: [
        { personId: p.id, role: 'subject' },
        { personId: q.id, role: 'subject' },
      ],
      confirmedParticipants: [p.id, q.id],
    }),
    m = await upload(page, tr.id);
  await save(
    page,
    tr.id,
    m,
    { ...m.metadata, eventId: e.id },
    { visibility: 'members', confirmRelease: true },
  );
  await grant(page, tr.id, 'stb_editor', 'editor');
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  await login(editor, 'stb_editor');
  expect((await request(editor, `/trees/${tr.id}/people/${p.id}/deletion-impact`)).status).toBe(403);
  const review = await good(page, `/trees/${tr.id}/people/${p.id}/deletion-impact`);
  expect(review.records).toBe(1);
  expect(review.media).toBe(1);
  await good(page, `/trees/${tr.id}/people/${p.id}/delete`, 'POST', {
    revision: review.revision,
    treeRevision: review.treeRevision,
    confirmed: true,
  });
  const after = await good(page, `/trees/${tr.id}/media/${m.id}`);
  expect(after.metadata.eventId).toBe('');
  expect(after.visibility).toBe('management');
  expect(await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/original`)).body()).toEqual(
    pdf,
  );
  expect((await good(editor, `/trees/${tr.id}/media`)).items).toEqual([]);
  expect((await editor.request.get(`${endpoint}/trees/${tr.id}/media/${m.id}/original`)).status()).toBe(404);
  await good(page, `/trees/${tr.id}/people/${p.id}/restore`, 'POST', {
    revision: review.revision + 1,
    confirmed: true,
  });
  expect((await good(page, `/trees/${tr.id}/media?event=${e.id}`)).items).toEqual([]);
  expect((await good(page, `/trees/${tr.id}/media/${m.id}`)).metadata.eventId).toBe('');
  await ctx.close();
});

test('metadata editor and responsive viewer support names, dates, event/person links, filters and discard', async ({
  page,
}) => {
  test.setTimeout(120000);
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    e = await event(page, tr.id, p),
    m = await upload(page, tr.id, 'Summer by the lake', true);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await page.getByRole('button', { name: 'Open preview', exact: true }).click();
  await page.getByRole('button', { name: 'Edit details', exact: true }).click();
  const d = page.getByRole('dialog');
  await d.getByLabel('Description', { exact: true }).fill('An afternoon with family and friends.');
  await d.getByLabel('Date form', { exact: true }).selectOption('about');
  await d.getByLabel('Date or wording', { exact: true }).fill('1935');
  await d.getByRole('button', { name: 'Add named person', exact: true }).click();
  await d.getByLabel('Recorded name', { exact: true }).fill('Aunt Alma');
  await d.getByRole('button', { name: 'Link to tree person', exact: true }).click();
  await d.getByLabel('Find tree person', { exact: true }).fill('Alma');
  await d.getByRole('button', { name: 'Search', exact: true }).click();
  await d.getByRole('button', { name: 'Alma Example', exact: true }).click();
  await d.getByRole('button', { name: 'Add named person', exact: true }).click();
  await d.getByLabel('Recorded name', { exact: true }).nth(1).fill('Neighbour Else');
  await d.getByRole('button', { name: 'Link existing event', exact: true }).click();
  await d.getByLabel('Find event', { exact: true }).fill('reunion');
  await d.getByRole('button', { name: 'Search', exact: true }).click();
  await d.getByRole('button', { name: e.title, exact: true }).click();
  await d.getByLabel('Historical place', { exact: true }).fill('Old village');
  await d.getByLabel('Source description', { exact: true }).fill('Family album, page 12');
  await d.getByLabel('Credit or rights', { exact: true }).fill('Fictional photograph');
  await d.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(d).toHaveCount(0);
  await expect(page.locator('.media-card')).toContainText('≈ 1935');
  await page.getByLabel('Named person', { exact: true }).fill('Neighbour');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await expect(page.locator('.media-card')).toHaveCount(1);
  await page.getByRole('button', { name: 'Open preview', exact: true }).click();
  await expect(page.locator('.viewer-details')).toContainText('Neighbour Else');
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    await expect(d.locator('.viewer-stage img')).toBeVisible();
    expect(await d.evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await d.screenshot({ path: `test-results/media-metadata-${width}.png` });
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await d.getByRole('button', { name: e.title, exact: true }).click();
  await expect(page.locator('.media-event-context')).toContainText(e.title);
  await expect(page.locator('.media-card')).toHaveCount(1);
  await page.getByRole('button', { name: 'Back to media library', exact: true }).click();
  await page.getByRole('button', { name: 'Open preview', exact: true }).click();
  await d.getByRole('button', { name: 'Alma Example', exact: true }).click();
  await expect(page.locator('.details')).toContainText('Alma Example');
  await page.locator('.details').getByRole('tab', { name: 'Media', exact: true }).click();
  await expect(page.locator('.media-card')).toContainText('Summer by the lake');
  await page.locator('.media-card').scrollIntoViewIfNeeded();
  await expect(page.locator('.media-card img')).toBeVisible();
  await page.getByRole('button', { name: 'Edit media', exact: true }).click();
  await d.getByLabel('Description', { exact: true }).fill('Discard this');
  page.once('dialog', (dialog) => dialog.accept());
  await d.getByRole('button', { name: 'Cancel', exact: true }).click();
  expect((await good(page, `/trees/${tr.id}/media/${m.id}`)).metadata.description).toBe(
    'An afternoon with family and friends.',
  );
});

test('viewer closes after metadata dependency revocation and failed filters clear results for retry', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    m = await upload(page, tr.id);
  await save(page, tr.id, m, {
    ...m.metadata,
    people: [{ name: 'Alma', personId: p.id, association: 'mentioned', page: '2' }],
  });
  await grant(page, tr.id, 'stb_editor', 'editor');
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  await login(editor, 'stb_editor');
  await editor.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await editor.getByRole('button', { name: 'Open preview', exact: true }).click();
  await expect(editor.locator('.viewer-details')).toContainText('Alma');
  await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
    ...editable(p),
    revision: p.revision,
    visibility: 'management',
  });
  await editor.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(editor.getByRole('dialog')).toHaveCount(0);
  await expect(editor.locator('.media-card')).toHaveCount(0);
  await ctx.close();
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await expect(page.locator('.media-card')).toHaveCount(1);
  await page.route('**/media?**', (route) =>
    route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Temporarily unavailable' }),
    }),
  );
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await expect(page.locator('.media-card')).toHaveCount(0);
  await expect(page.getByRole('alert')).toContainText('Temporarily unavailable');
  await page.unroute('**/media?**');
  await page.getByRole('button', { name: 'Reload library', exact: true }).click();
  await expect(page.locator('.media-card')).toHaveCount(1);
});

test('metadata draft survives stale saves and explicit reconciliation; viewer cannot edit', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    m = await upload(page, tr.id);
  await grant(page, tr.id, 'stb_viewer', 'viewer');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await page.getByRole('button', { name: 'Edit media', exact: true }).click();
  const d = page.getByRole('dialog');
  await d.getByLabel('Description', { exact: true }).fill('My local caption');
  await save(page, tr.id, m, { ...m.metadata, description: 'Concurrent caption' });
  await d.getByRole('button', { name: 'Save', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(d.getByRole('alert')).toContainText('Someone changed');
  await expect(d.getByLabel('Description', { exact: true })).toHaveValue('My local caption');
  await d.getByRole('button', { name: 'Load current version', exact: true }).click();
  await expect(d.locator('.media-conflict')).toContainText('Concurrent caption');
  await d.getByRole('button', { name: 'I compared both versions; keep my draft', exact: true }).click();
  await page.setViewportSize({ width: 320, height: 1000 });
  expect(await d.evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
  await d.screenshot({ path: 'test-results/media-metadata-editor-320.png' });
  await d.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(d).toHaveCount(0);
  const saved = await good(page, `/trees/${tr.id}/media/${m.id}`);
  expect(saved.metadata.description).toBe('My local caption');
  const ctx = await browser.newContext(),
    viewer = await ctx.newPage();
  await login(viewer, 'stb_viewer');
  expect(
    (
      await request(viewer, `/trees/${tr.id}/media/${m.id}`, 'PUT', {
        title: m.title,
        revision: saved.revision,
        metadata: m.metadata,
      })
    ).status,
  ).toBe(403);
  await viewer.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await viewer.getByRole('button', { name: 'Open preview', exact: true }).click();
  await expect(viewer.getByRole('dialog')).toContainText('My local caption');
  await expect(viewer.getByRole('button', { name: 'Edit details', exact: true })).toHaveCount(0);
  await ctx.close();
});
