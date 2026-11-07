import { test, expect } from '@playwright/test';
import { login, tree, person, grant, good, request, key, fixture } from './helpers.js';
const note = (page, tr, p, extra = {}) =>
  good(
    page,
    `/trees/${tr.id}/records`,
    'POST',
    {
      originId: p.id,
      category: 'note',
      type: 'note',
      title: 'Fictional note',
      text: 'Original note',
      participants: [{ personId: p.id, role: 'subject' }],
      requestId: key(),
      ...extra,
    },
    201,
  );
const body = (r) => ({
  ...Object.fromEntries(
    [
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
    ].map((k) => [k, r[k]]),
  ),
  participants: r.participants.map(({ personId, role }) => ({ personId, role })),
  confirmedParticipants: r.participants.map((p) => p.personId),
});

test('note formatting has accessible lists and deliberate safe links while private biography stays hidden', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Notes');
  await grant(page, tr.id, 'stb_viewer');
  const publicNote = await note(page, tr, p, {
    text: '**Strong** *Emphasis*\n\n- First unordered\n- Second unordered\n\n1. First ordered\n2. Second ordered\n\n[Archive](https://invalid.example/archive)\n\n[Bad](javascript:alert(1)) <img src="https://invalid.example/tracker"> ![remote](https://invalid.example/image)',
  });
  const secret = await note(page, tr, p, {
    title: 'Restricted biography',
    text: 'Restricted biography wording',
    visibility: 'management',
  });
  await good(page, `/trees/${tr.id}/records/${secret.id}/prefer`, 'POST', { revision: secret.revision });
  const ctx = await browser.newContext({ baseURL: fixture.baseURL }),
    viewer = await ctx.newPage();
  try {
    await login(viewer, 'stb_viewer');
    const external = [];
    viewer.on('request', (r) => {
      if (r.url().includes('invalid.example')) external.push(r.url());
    });
    await viewer.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
    await viewer.getByRole('tab', { name: 'Notes', exact: true }).click();
    const text = viewer.locator('.safe-text');
    await expect(text).toHaveCount(1);
    await expect(text.locator('strong')).toHaveText('Strong');
    await expect(text.locator('em')).toHaveText('Emphasis');
    await expect(text.locator('ul li')).toHaveCount(2);
    await expect(text.locator('ol li')).toHaveCount(2);
    await expect(text.locator('a')).toHaveCount(1);
    await expect(text.locator('a')).toHaveAttribute('rel', 'noopener noreferrer');
    await expect(text.locator('a')).toHaveAttribute('href', 'https://invalid.example/archive');
    await expect(text.locator('img,script,iframe')).toHaveCount(0);
    await expect(viewer.locator('#stammbaum-app')).not.toContainText('Restricted biography');
    await expect(viewer.getByText('Preferred biography', { exact: true })).toHaveCount(0);
    expect(external).toEqual([]);
    expect((await request(viewer, `/trees/${tr.id}/records/${secret.id}`)).status).toBe(404);
    expect((await good(viewer, `/trees/${tr.id}/records?person=${p.id}`)).items.map((r) => r.id)).toEqual([
      publicNote.id,
    ]);
  } finally {
    await ctx.close();
  }
});

test('a stale note save keeps its draft until explicit comparison and preserves original wording', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Conflict'),
    n = await note(page, tr, p);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.getByRole('tab', { name: 'Notes', exact: true }).click();
  await page.getByRole('button', { name: 'Edit record', exact: true }).click();
  const d = page.locator('dialog[open]');
  await d.getByLabel('Note text', { exact: true }).fill('My retained draft');
  await good(page, `/trees/${tr.id}/records/${n.id}`, 'PUT', { ...body(n), text: 'Another editor wording' });
  await d.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(d.getByLabel('Note text', { exact: true })).toHaveValue('My retained draft');
  await expect(d.getByRole('button', { name: 'Load current version', exact: true })).toBeVisible();
  expect((await good(page, `/trees/${tr.id}/records/${n.id}`)).text).toBe('Another editor wording');
  await d.getByRole('button', { name: 'Load current version', exact: true }).click();
  await expect(d).toContainText('Another editor wording');
  await expect(d.getByLabel('Note text', { exact: true })).toHaveValue('My retained draft');
  const current = await good(page, `/trees/${tr.id}/records/${n.id}`);
  expect(current.original.text).toBe('Original note');
  page.once('dialog', (dialog) => dialog.accept());
  await d.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(d).toHaveCount(0);
  expect((await good(page, `/trees/${tr.id}/records/${n.id}`)).text).toBe('Another editor wording');
});
