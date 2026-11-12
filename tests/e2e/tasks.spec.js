import { test, expect } from '@playwright/test';
import { login, request, good, tree, person, key } from './helpers.js';

test('reviewed person research task creates one CalDAV VTODO without editing the tree', async ({ page }) => {
  await login(page);
  const capability = await good(page, '/integrations/tasks/collections');
  test.skip(!capability.available, 'Optional Tasks app is not installed in this fixture.');
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.getByRole('button', { name: 'Create research task' }).click();
  const list = page.locator('.research-tasks select');
  await expect(list).toBeVisible();
  test.skip((await list.locator('option').count()) === 0, 'No VTODO-capable task list in this fixture.');
  const collection = await list.inputValue();
  await page.getByRole('textbox', { name: 'Research instruction' }).fill('Check fictional archive reference');
  await page.route('**/api/v1/trees/*/tasks/review', (route) => route.abort());
  await page.getByRole('button', { name: 'Review task' }).click();
  await expect(page.getByRole('alert')).toContainText('connection was interrupted');
  await expect(page.getByRole('textbox', { name: 'Research instruction' })).toHaveValue(
    'Check fictional archive reference',
  );
  await page.unroute('**/api/v1/trees/*/tasks/review');
  await page.getByRole('button', { name: 'Review task' }).click();
  await expect(page.getByText('Check fictional archive reference')).toBeVisible();
  await page.getByRole('checkbox', { name: 'I understand who may see this task' }).check();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 950 });
    await expect(page.getByRole('button', { name: 'Create task', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/research-task-review-${width}.png`, fullPage: true });
  }
  await page.getByRole('button', { name: 'Create task', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('.task-links li').filter({ hasText: 'Linked task' })).toBeVisible();
  const links = (await good(page, `/trees/${tr.id}/tasks?person=${p.id}`)).items;
  expect(links).toHaveLength(1);
  expect(links[0].status).toBe('linked');
  const dav = await page.evaluate(
    async ({ collection, id }) => {
      const root = `${window.OC?.getRootPath?.() || window.OC?.webroot || ''}/remote.php/dav/calendars/${encodeURIComponent(document.getElementById('stammbaum-app').dataset.userId)}`;
      const href = `${root}/${encodeURIComponent(collection)}/${id}.ics`;
      const original = await fetch(href, { credentials: 'same-origin' });
      const bytes = await original.text();
      const duplicate = await fetch(href, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'text/calendar; charset=utf-8',
          'If-None-Match': '*',
          requesttoken: window.OC.requestToken,
        },
        body: bytes,
      });
      return { status: original.status, bytes, duplicate: duplicate.status };
    },
    { collection, id: links[0].id },
  );
  expect(dav.status).toBe(200);
  expect(dav.bytes).toContain('BEGIN:VTODO');
  expect(dav.bytes).toContain(`UID:stb-${links[0].id}@stammbaum.nextcloud`);
  expect(dav.bytes.replace(/\r\n[ \t]/g, '')).toContain(`#tree=${tr.id}&view=people&person=${p.id}`);
  expect(dav.duplicate).toBe(412);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
  await expect(page.getByRole('link', { name: 'Open Tasks' })).toBeVisible();
});

test('task reviews deny missing export rights and stale or reused confirmations', async ({
  page,
  browser,
}) => {
  await login(page);
  const capability = await good(page, '/integrations/tasks/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional writable task calendar absent.');
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const payload = {
    personId: p.id,
    collection: capability.items[0].uri,
    title: 'Research Mira',
    note: '',
    due: null,
    includeLiving: false,
  };
  const initialTree = (await good(page, `/trees/${tr.id}`)).revision;
  const ctx = await browser.newContext();
  const viewer = await ctx.newPage();
  try {
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: false,
      revision: 0,
    });
    await login(viewer, 'stb_viewer');
    expect((await request(viewer, `/trees/${tr.id}/tasks/review`, 'POST', payload)).status).toBe(403);
    let members = await good(page, `/trees/${tr.id}/members`);
    let membership = members.items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: true,
      revision: membership.revision,
    });
    const viewerReview = await good(viewer, `/trees/${tr.id}/tasks/review`, 'POST', payload);
    const viewerReservation = await good(
      viewer,
      `/trees/${tr.id}/tasks`,
      'POST',
      {
        review: viewerReview,
        acknowledged: true,
        requestId: key(),
      },
      201,
    );
    members = await good(page, `/trees/${tr.id}/members`);
    membership = members.items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: false,
      revision: membership.revision,
    });
    expect(
      (await request(viewer, `/trees/${tr.id}/tasks/${viewerReservation.id}/complete`, 'POST')).status,
    ).toBe(404);
    members = await good(page, `/trees/${tr.id}/members`);
    membership = members.items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: true,
      revision: membership.revision,
    });
    expect((await good(viewer, `/trees/${tr.id}/tasks?person=${p.id}`)).items).toHaveLength(0);
    expect(
      (
        await request(viewer, `/trees/${tr.id}/tasks`, 'POST', {
          review: viewerReview,
          acknowledged: true,
          requestId: key(),
        })
      ).status,
    ).toBe(412);
    const unknown = await person(page, tr.id, 'Unknown');
    expect(
      (await request(page, `/trees/${tr.id}/tasks/review`, 'POST', { ...payload, personId: unknown.id }))
        .status,
    ).toBe(422);
    const hidden = await person(page, tr.id, 'Private', {
      livingStatus: 'deceased',
      visibility: 'management',
    });
    expect(
      (await request(page, `/trees/${tr.id}/tasks/review`, 'POST', { ...payload, personId: hidden.id }))
        .status,
    ).toBe(422);
    const explicitHidden = await good(page, `/trees/${tr.id}/tasks/review`, 'POST', {
      ...payload,
      personId: hidden.id,
      includeManagement: true,
    });
    expect(explicitHidden.includeManagement).toBe(true);
    const review = await good(page, `/trees/${tr.id}/tasks/review`, 'POST', payload);
    const id = key();
    const one = await good(
      page,
      `/trees/${tr.id}/tasks`,
      'POST',
      { review, acknowledged: true, requestId: id },
      201,
    );
    const two = await good(
      page,
      `/trees/${tr.id}/tasks`,
      'POST',
      { review, acknowledged: true, requestId: id },
      201,
    );
    expect(two.id).toBe(one.id);
    expect((await good(page, `/trees/${tr.id}`)).revision).toBeGreaterThan(initialTree); // Only fixture membership/person writes changed it.
    const current = await good(page, `/trees/${tr.id}/people/${p.id}`);
    await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
      givenName: current.givenName,
      familyName: current.familyName,
      livingStatus: current.livingStatus,
      sex: current.sex,
      visibility: current.visibility,
      displayNote: 'Fictional edit',
      revision: current.revision,
    });
    expect(
      (await request(page, `/trees/${tr.id}/tasks`, 'POST', { review, acknowledged: true, requestId: key() }))
        .status,
    ).toBe(412);
    expect((await request(page, `/trees/${tr.id}/tasks/${one.id}/complete`, 'POST')).status).toBe(412);
  } finally {
    await ctx.close();
  }
});
