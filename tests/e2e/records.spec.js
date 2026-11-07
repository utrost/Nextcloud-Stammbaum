import { test, expect } from '@playwright/test';
import { login, tree, person, grant, good, request, key } from './helpers.js';
const fields = [
  'originId',
  'category',
  'type',
  'title',
  'text',
  'date',
  'place',
  'ongoing',
  'fields',
  'visibility',
  'revision',
];
const body = (r) => ({
  ...Object.fromEntries(fields.map((k) => [k, r[k]])),
  participants: r.participants.map(({ personId, role }) => ({ personId, role })),
  confirmedParticipants: r.participants.map((p) => p.personId),
});
const record = (page, tid, p, extra = {}) =>
  good(
    page,
    `/trees/${tid}/records`,
    'POST',
    {
      originId: p.id,
      category: 'note',
      type: 'note',
      title: 'Fictional note',
      text: 'Recorded wording',
      participants: [{ personId: p.id, role: 'subject' }],
      requestId: key(),
      ...extra,
    },
    201,
  );

test('one shared event appears on both timelines and updates atomically with confirmation and stale-write protection', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    q = await person(page, tr.id, 'Bruno');
  const participants = [
    { personId: p.id, role: 'spouse' },
    { personId: q.id, role: 'spouse' },
  ];
  const event = await record(page, tr.id, p, {
    category: 'event',
    type: 'marriage',
    title: 'Fictional marriage',
    place: 'Original place',
    date: { kind: 'about', value: '1920' },
    participants,
    confirmedParticipants: [p.id, q.id],
  });
  for (const who of [p, q])
    expect((await good(page, `/trees/${tr.id}/records?person=${who.id}`)).items[0].id).toBe(event.id);
  expect(
    (
      await request(page, `/trees/${tr.id}/records/${event.id}`, 'PUT', {
        ...body(event),
        place: 'New place',
        confirmedParticipants: [p.id],
      })
    ).status,
  ).toBe(422);
  const updated = await good(page, `/trees/${tr.id}/records/${event.id}`, 'PUT', {
    ...body(event),
    place: 'New place',
  });
  expect(updated.original.place).toBe('Original place');
  expect(updated.date).toEqual({ kind: 'about', value: '1920', end: '' });
  expect((await good(page, `/trees/${tr.id}/records?person=${q.id}`)).items[0].place).toBe('New place');
  expect((await request(page, `/trees/${tr.id}/records/${event.id}`, 'PUT', body(event))).status).toBe(412);
  expect(
    (await request(page, `/trees/${tr.id}/records/${event.id}`, 'PUT', { ...body(updated), originId: q.id }))
      .status,
  ).toBe(422);
  expect(
    (
      await request(page, `/trees/${tr.id}/records/${event.id}`, 'PUT', {
        ...body(updated),
        ongoing: true,
        date: { kind: 'period', value: '1920', end: '1930' },
      })
    ).status,
  ).toBe(422);
  const detached = await good(page, `/trees/${tr.id}/records/${event.id}`, 'PUT', {
    ...body(updated),
    participants: [participants[0]],
  });
  expect(detached.participants).toHaveLength(1);
  expect((await good(page, `/trees/${tr.id}/records?person=${q.id}`)).items).toHaveLength(0);
});

test('confidential records and any hidden participant restrict all reads including citations; original private text remains hidden after release', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Public'),
    q = await person(page, tr.id, 'Private', { visibility: 'management' });
  await grant(page, tr.id, 'stb_editor', 'editor');
  const shared = await record(page, tr.id, p, {
    category: 'event',
    type: 'residence',
    participants: [
      { personId: p.id, role: 'resident' },
      { personId: q.id, role: 'resident' },
    ],
    confirmedParticipants: [p.id, q.id],
  });
  const contact = await record(page, tr.id, p, {
    category: 'contact',
    type: 'phone',
    fields: [{ label: 'Phone', value: '001234', unit: '' }],
  });
  expect(contact.visibility).toBe('management');
  const secret = await record(page, tr.id, p, { visibility: 'management', text: 'Secret original' });
  const released = await good(page, `/trees/${tr.id}/records/${secret.id}`, 'PUT', {
    ...body(secret),
    text: 'Released current wording',
    visibility: 'members',
    confirmRelease: true,
  });
  const src = await good(
    page,
    `/trees/${tr.id}/sources`,
    'POST',
    { title: 'Event evidence', requestId: key() },
    201,
  );
  const citation = await good(
    page,
    `/trees/${tr.id}/citations`,
    'POST',
    {
      sourceId: src.id,
      targets: [{ kind: 'record', targetId: shared.id, effect: 'supports' }],
      requestId: key(),
    },
    201,
  );
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  await login(editor, 'stb_editor');
  expect((await request(editor, `/trees/${tr.id}/records/${shared.id}`)).status).toBe(404);
  expect((await request(editor, `/trees/${tr.id}/citations/${citation.id}`)).status).toBe(404);
  expect((await good(editor, `/trees/${tr.id}/citations?person=${p.id}`)).items).toEqual([]);
  const list = await good(editor, `/trees/${tr.id}/records?person=${p.id}`);
  expect(list.items.map((r) => r.id)).toEqual([released.id]);
  expect(JSON.stringify(list)).not.toContain('Secret original');
  expect(list.items[0].original).toBe(null);
  expect(
    (
      await request(editor, `/trees/${tr.id}/records`, 'POST', {
        originId: p.id,
        category: 'contact',
        type: 'email',
        title: 'Contact',
        requestId: key(),
      })
    ).status,
  ).toBe(403);
  await ctx.close();
});

test('notes retain originals, biography preference is explicit and hidden shared evidence prevents record changes', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma');
  await grant(page, tr.id, 'stb_editor', 'editor');
  const a = await record(page, tr.id, p),
    b = await record(page, tr.id, p, { title: 'Second note' });
  await good(page, `/trees/${tr.id}/records/${a.id}/prefer`, 'POST', { revision: a.revision });
  await good(page, `/trees/${tr.id}/records/${b.id}/prefer`, 'POST', { revision: b.revision });
  expect(
    (await good(page, `/trees/${tr.id}/records?person=${p.id}`)).items
      .filter((r) => r.preferred)
      .map((r) => r.id),
  ).toEqual([b.id]);
  const source = await good(
    page,
    `/trees/${tr.id}/sources`,
    'POST',
    { title: 'Private evidence', visibility: 'management', requestId: key() },
    201,
  );
  await good(
    page,
    `/trees/${tr.id}/citations`,
    'POST',
    { sourceId: source.id, targets: [{ kind: 'record', targetId: a.id }], requestId: key() },
    201,
  );
  const ctx = await browser.newContext(),
    editor = await ctx.newPage();
  await login(editor, 'stb_editor');
  const current = await good(editor, `/trees/${tr.id}/records/${a.id}`);
  expect(
    (
      await request(editor, `/trees/${tr.id}/records/${a.id}`, 'PUT', {
        ...body(current),
        text: 'Unauthorized indirect change',
      })
    ).status,
  ).toBe(403);
  await ctx.close();
});

test('note formatting stays safe, biography is selectable, and event UI confirms shared participants', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma'),
    q = await person(page, tr.id, 'Bruno');
  const remote = [];
  page.on('request', (r) => {
    if (r.url().includes('invalid.example')) remote.push(r.url());
  });
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.getByRole('tab', { name: 'Notes', exact: true }).click();
  await page.getByRole('button', { name: 'New note', exact: false }).click();
  const d = page.getByRole('dialog');
  await d.getByLabel('Title', { exact: true }).fill('Fictional biography');
  await d
    .getByLabel('Note text', { exact: true })
    .fill(
      '**Known** *uncertain*\n\n<script>alert(1)</script> ![image](https://invalid.example/leak.png)\n\n[Archive](https://invalid.example/archive)',
    );
  await d.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.safe-text strong')).toHaveText('Known');
  await expect(page.locator('.safe-text')).toContainText('<script>alert(1)</script>');
  expect(await page.locator('.safe-text img,.safe-text script').count()).toBe(0);
  expect(remote).toEqual([]);
  await page.getByRole('button', { name: 'Use as biography', exact: true }).click();
  await expect(page.getByText('Preferred biography', { exact: true })).toBeVisible();
  await page.getByRole('tab', { name: 'Timeline', exact: true }).click();
  await page.getByRole('button', { name: 'New event', exact: false }).click();
  await d.getByLabel('Title', { exact: true }).fill('Fictional marriage');
  await d.getByLabel('Find another person', { exact: true }).fill('Bruno');
  await d.getByRole('button', { name: 'Search people', exact: true }).click();
  await d.getByRole('button', { name: 'Bruno Example', exact: true }).click();
  await d.getByRole('checkbox', { name: /I confirm/ }).check();
  await d.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.record-card')).toContainText('Bruno Example');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${q.id}`);
  await page.getByRole('tab', { name: 'Timeline', exact: true }).click();
  await expect(page.locator('.record-card')).toContainText('Fictional marriage');
});
