import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { login, tree, person, good, request, key, endpoint } from './helpers.js';

test('timeline event gallery opens linked PDFs and ignores an obsolete filtered response', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Alma');
  const e = await good(
    page,
    `/trees/${tr.id}/records`,
    'POST',
    {
      originId: p.id,
      category: 'event',
      type: 'custom',
      title: 'Fictional archive visit',
      text: 'A recorded visit.',
      participants: [{ personId: p.id, role: 'subject' }],
      confirmedParticipants: [p.id],
      requestId: key(),
    },
    201,
  );
  const response = await page.request.post(`${endpoint}/trees/${tr.id}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      title: 'Visit correspondence',
      requestId: key(),
      file: {
        name: 'fictional.pdf',
        mimeType: 'application/pdf',
        buffer: readFileSync('tests/fixtures/media-two-pages.pdf'),
      },
    },
  });
  expect(response.status()).toBe(201);
  const m = await response.json();
  await good(page, `/trees/${tr.id}/media/${m.id}`, 'PUT', {
    title: m.title,
    revision: m.revision,
    metadata: {
      ...m.metadata,
      eventId: e.id,
      people: [{ name: 'Archivist Else', personId: '', association: 'mentioned', page: '2' }],
    },
  });
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.locator('.details').getByRole('tab', { name: 'Timeline', exact: true }).click();
  await page.getByRole('button', { name: 'Event media', exact: true }).click();
  await expect(page.locator('.media-event-context')).toContainText(e.title);
  await expect(page.locator('.media-card')).toContainText(m.title);
  await page.getByRole('button', { name: 'Open preview', exact: true }).click();
  const d = page.getByRole('dialog');
  await expect(d.locator('.viewer-details')).toContainText('Archivist Else');
  await d.getByRole('button', { name: 'Next page', exact: true }).click();
  await expect(d.locator('.viewer-pages')).toContainText('2 / 2');
  await page.keyboard.press('Escape');
  await expect(d).toHaveCount(0);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  await expect(page.locator('.media-card')).toHaveCount(1);
  const all = await good(page, `/trees/${tr.id}/media`);
  let release, arrived;
  const gate = new Promise((resolve) => {
    release = resolve;
  });
  const seen = new Promise((resolve) => {
    arrived = resolve;
  });
  await page.route('**/media?**', async (route) => {
    const url = new URL(route.request().url());
    if (url.searchParams.get('personQuery') !== 'obsolete' || url.searchParams.get('person'))
      return route.continue();
    arrived();
    await gate;
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(all) });
  });
  await page.getByLabel('Named person', { exact: true }).fill('obsolete');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await seen;
  // This identity has no direct assignment or descriptive person link, only an event.
  await page.getByRole('button', { name: 'Filter by tree person', exact: true }).click();
  await page.getByLabel('Find tree person', { exact: true }).fill('Alma');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await page.getByRole('button', { name: 'Alma Example', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Apply filters', exact: true })).toBeEnabled();
  const finished = page.waitForResponse(
    (r) => r.url().includes('personQuery=obsolete') && !new URL(r.url()).searchParams.get('person'),
  );
  release();
  await finished;
  await expect(page.locator('.media-card')).toHaveCount(0);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
  // Cross-tree event IDs are unavailable even to the owner of both trees.
  const other = await tree(page);
  expect((await request(page, `/trees/${other.id}/media?event=${e.id}`)).status).toBe(404);
});
