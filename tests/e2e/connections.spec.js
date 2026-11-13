import { test, expect } from '@playwright/test';
import { editable } from '../../src/domain.js';
import { login, good, request, tree, person, grant, key, fixture } from './helpers.js';
import { fixtureDatabase } from './server-fixture.js';

async function event(page, treeId, sourceId) {
  const capability = await good(page, '/integrations/calendar/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional writable Calendar absent.');
  const review = await good(page, `/trees/${treeId}/calendar/review`, 'POST', {
    personId: sourceId,
    collection: capability.items[0].uri,
    mode: 'appointment',
    date: '2027-01-15',
    title: 'Fictional archive visit',
    location: '',
  });
  const created = await good(
    page,
    `/trees/${treeId}/calendar`,
    'POST',
    {
      review,
      requestId: key(),
      acknowledged: true,
    },
    201,
  );
  return { ...created, collection: capability.items[0].uri };
}

async function dav(page, connection, method = 'GET', body, etag) {
  return page.evaluate(
    async ({ connection, method, body, etag }) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      const url = `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(connection.collection)}/${connection.id}.ics`;
      const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          ...(etag ? { 'If-Match': etag } : {}),
          ...(method !== 'GET' ? { requesttoken: window.OC.requestToken } : {}),
          ...(method === 'PUT' ? { 'Content-Type': 'text/calendar; charset=utf-8' } : {}),
        },
        ...(body ? { body } : {}),
      });
      return { status: response.status, etag: response.headers.get('ETag'), text: await response.text() };
    },
    { connection, method, body, etag },
  );
}

test('personal connections reconcile edits and deletion without changing genealogy', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  let p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const result = await event(page, tr.id, p.id);
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  const first = await good(page, `/trees/${tr.id}/connections`);
  expect(first.items.find((x) => x.id === result.id)).toMatchObject({
    kind: 'calendar',
    status: 'linked',
    source: { id: p.id },
    createdByApp: true,
  });
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);

  await grant(page, tr.id, 'stb_viewer');
  const ctx = await browser.newContext({ baseURL: fixture.baseURL });
  const viewer = await ctx.newPage();
  try {
    await login(viewer, 'stb_viewer');
    const other = await good(viewer, `/trees/${tr.id}/connections`);
    expect(other.items).toEqual([]);
    expect(
      (await request(viewer, `/trees/${tr.id}/connections/calendar/${result.id}/refresh`, 'POST')).status,
    ).toBe(404);
  } finally {
    await ctx.close();
  }

  p = await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
    ...editable(p),
    givenName: 'Mira Changed',
    revision: p.revision,
  });
  const changed = await good(page, `/trees/${tr.id}/connections/calendar/${result.id}/refresh`, 'POST');
  expect(changed.status).toBe('source-changed');
  expect(changed.source.label).toContain('Mira Changed');
  await good(page, `/trees/${tr.id}/people/${p.id}/delete`, 'POST', {
    revision: p.revision,
    confirmed: true,
  });
  expect(
    fixtureDatabase('SELECT status FROM *PREFIX*stb_calendar_links WHERE id = ?', [result.id], true)[0]
      .status,
  ).toBe('unavailable');
  const hidden = await good(page, `/trees/${tr.id}/connections/calendar/${result.id}/refresh`, 'POST');
  expect(hidden.status).toBe('unavailable');
  expect(hidden.source).toBeUndefined();
  expect(JSON.stringify(hidden)).not.toContain('Mira');
  await good(page, `/trees/${tr.id}/people/${p.id}/restore`, 'POST', {
    revision: p.revision + 1,
    confirmed: true,
  });
  const restored = await good(page, `/trees/${tr.id}/connections/calendar/${result.id}/refresh`, 'POST');
  expect(restored.status).toBe('unavailable');
  expect(restored.source).toBeUndefined();
  const beforeUnlink = (await good(page, `/trees/${tr.id}`)).revision;
  await good(page, `/trees/${tr.id}/connections/calendar/${result.id}`, 'DELETE');
  const unlinked = await good(page, `/trees/${tr.id}/connections/calendar/${result.id}/refresh`, 'POST');
  expect(unlinked.status).toBe('unlinked');
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(beforeUnlink);
});

test('expired pending receipt becomes uncertain and local tombstones are pruned without tree edits', async ({
  page,
}) => {
  await login(page);
  const capability = await good(page, '/integrations/tasks/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional writable Tasks list absent.');
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const review = await good(page, `/trees/${tr.id}/tasks/review`, 'POST', {
    personId: p.id,
    collection: capability.items[0].uri,
    title: 'Fictional research',
    note: '',
    due: null,
  });
  const reserved = await good(
    page,
    `/trees/${tr.id}/tasks`,
    'POST',
    {
      review,
      acknowledged: true,
      requestId: key(),
    },
    201,
  );
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  fixtureDatabase('UPDATE *PREFIX*stb_task_links SET created_at = ? WHERE id = ?', [
    Math.floor(Date.now() / 1000) - 301,
    reserved.id,
  ]);
  const uncertain = (await good(page, `/trees/${tr.id}/connections`)).items.find((x) => x.id === reserved.id);
  expect(uncertain.status).toBe('outcome-unknown');
  await good(page, `/trees/${tr.id}/connections/task/${reserved.id}`, 'DELETE');
  fixtureDatabase('UPDATE *PREFIX*stb_task_links SET updated_at = ? WHERE id = ?', [
    Math.floor(Date.now() / 1000) - 31 * 86400,
    reserved.id,
  ]);
  expect((await good(page, `/trees/${tr.id}/connections`)).items.some((x) => x.id === reserved.id)).toBe(
    false,
  );
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
});

test('an old app-created item cannot acquire a retroactive removal baseline', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const connection = await event(page, tr.id, p.id);
  const original = await dav(page, connection);
  fixtureDatabase('UPDATE *PREFIX*stb_calendar_links SET created_at = ? WHERE id = ?', [
    Math.floor(Date.now() / 1000) - 61,
    connection.id,
  ]);
  const late = await request(page, `/trees/${tr.id}/connections/calendar/${connection.id}/etag`, 'POST', {
    etag: original.etag,
  });
  expect(late.status).toBe(412);
  expect(
    (await good(page, `/trees/${tr.id}/connections`)).items.find((x) => x.id === connection.id)
      .canRemoveExternal,
  ).toBe(false);
  expect((await dav(page, connection)).status).toBe(200);
});

test('remote success with a missing local completion reconciles the same UID without a second write', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const capability = await good(page, '/integrations/calendar/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional writable Calendar absent.');
  const review = await good(page, `/trees/${tr.id}/calendar/review`, 'POST', {
    personId: p.id,
    collection: capability.items[0].uri,
    mode: 'appointment',
    date: '2027-01-16',
    title: 'Fictional archive visit',
    location: '',
  });
  const requestId = key();
  const created = await good(
    page,
    `/trees/${tr.id}/calendar`,
    'POST',
    { review, requestId, acknowledged: true },
    201,
  );
  const connection = { id: created.id, collection: review.collection };
  const original = await dav(page, connection);
  fixtureDatabase("UPDATE *PREFIX*stb_calendar_links SET status = 'pending' WHERE id = ?", [created.id]);
  const recovered = await good(
    page,
    `/trees/${tr.id}/calendar`,
    'POST',
    { review, requestId, acknowledged: true },
    201,
  );
  expect(recovered).toEqual(created);
  expect((await dav(page, connection)).etag).toBe(original.etag);
  expect(
    (await good(page, `/trees/${tr.id}/connections`)).items.filter((x) => x.id === created.id),
  ).toHaveLength(1);
});

test('Connections workspace gives keyboard-reachable refresh and local unlink at narrow sizes', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  await event(page, tr.id, p.id);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=connections`);
  await expect(page.getByRole('heading', { name: 'My app connections' })).toBeVisible();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 950 });
    await expect(page.getByRole('button', { name: 'Refresh status' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/connections-${width}.png`, fullPage: true });
  }
  await page.getByRole('button', { name: 'Refresh status' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toContainText('Connection status refreshed.');
  await page.getByRole('button', { name: 'Open source' }).click();
  await expect(page.getByRole('tab', { name: 'People' })).toHaveAttribute('aria-selected', 'true');
  await page
    .getByRole('tablist', { name: 'Tree workspace' })
    .getByRole('tab', { name: 'Connections' })
    .click();
  const blockedList = (url) =>
    url.pathname.endsWith(`/trees/${tr.id}/connections`) && url.searchParams.has('page');
  await page.route(blockedList, (route) =>
    route.fulfill({ status: 403, contentType: 'application/json', body: '{"message":"Access changed."}' }),
  );
  await page.getByRole('button', { name: 'Refresh list' }).click();
  await expect(page.getByRole('alert')).toContainText('Access changed.');
  await expect(page.getByText('Mira', { exact: false })).toHaveCount(0);
  await page.unroute(blockedList);
  await page.getByRole('button', { name: 'Retry' }).click();
  await expect(page.getByRole('button', { name: 'Unlink locally' })).toBeVisible();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Unlink locally' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByText('Unlinked locally')).toBeVisible();
});

test('reviewed conditional removal deletes only an unchanged app-created event', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const connection = await event(page, tr.id, p.id);
  const original = await dav(page, connection);
  expect(original.status).toBe(200);
  await good(page, `/trees/${tr.id}/connections/calendar/${connection.id}/etag`, 'POST', {
    etag: original.etag,
  });
  const review = await good(
    page,
    `/trees/${tr.id}/connections/calendar/${connection.id}/review-removal`,
    'POST',
  );
  expect(review.etag).toBe(original.etag);
  expect(
    (
      await request(page, `/trees/${tr.id}/connections/calendar/${connection.id}/complete-removal`, 'POST', {
        review,
        acknowledged: true,
      })
    ).status,
  ).toBe(409);
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=connections`);
  await page.getByRole('button', { name: 'Review external removal' }).click();
  await expect(page.getByRole('heading', { name: 'Remove created external item' })).toBeVisible();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 950 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/connection-removal-${width}.png`, fullPage: true });
  }
  await page.getByRole('checkbox', { name: 'I understand the external item will be deleted' }).check();
  await page.getByRole('button', { name: 'Delete external item' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByText('Unlinked locally')).toBeVisible();
  expect((await dav(page, connection)).status).toBe(404);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
});

test('external modification blocks conditional removal and preserves the item', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const connection = await event(page, tr.id, p.id);
  const original = await dav(page, connection);
  await good(page, `/trees/${tr.id}/connections/calendar/${connection.id}/etag`, 'POST', {
    etag: original.etag,
  });
  const changedText = original.text.replace(/SUMMARY:[^\r\n]*/, 'SUMMARY:Edited in Calendar');
  const changed = await dav(page, connection, 'PUT', changedText, original.etag);
  expect([200, 204], changed.text.slice(0, 400)).toContain(changed.status);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=connections`);
  await page.getByRole('button', { name: 'Review external removal' }).click();
  await expect(page.getByRole('alert')).toContainText('The external item changed');
  await expect(page.getByRole('button', { name: 'Delete external item' })).toHaveCount(0);
  expect(
    (await good(page, `/trees/${tr.id}/connections/calendar/${connection.id}/refresh`, 'POST')).status,
  ).toBe('destination-changed');
  expect((await dav(page, connection)).status).toBe(200);
});

test('revocation hides source details, keeps opaque cleanup, and regrant never revives the old link', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Private', { livingStatus: 'deceased' });
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_editor',
    role: 'editor',
    exportAllowed: true,
    revision: 0,
  });
  const ctx = await browser.newContext({ baseURL: fixture.baseURL });
  const editor = await ctx.newPage();
  try {
    await login(editor, 'stb_editor');
    const connection = await event(editor, tr.id, p.id);
    const before = await good(editor, `/trees/${tr.id}/connections`);
    expect(before.items.find((x) => x.id === connection.id)?.source?.label).toContain('Private');
    const member = (await good(page, `/trees/${tr.id}/members`)).items.find((x) => x.userId === 'stb_editor');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_editor',
      role: 'editor',
      revision: member.revision,
      remove: true,
    });
    const revoked = await good(editor, `/trees/${tr.id}/connections`);
    const opaque = revoked.items.find((x) => x.id === connection.id);
    expect(opaque.status).toBe('authority-invalidated');
    expect(opaque.source).toBeUndefined();
    expect(JSON.stringify(opaque)).not.toContain('Private');
    const old = (await good(page, `/trees/${tr.id}/members`)).items.find((x) => x.userId === 'stb_editor');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_editor',
      role: 'editor',
      exportAllowed: true,
      revision: old.revision,
    });
    const regranted = await good(
      editor,
      `/trees/${tr.id}/connections/calendar/${connection.id}/refresh`,
      'POST',
    );
    expect(regranted.status).toBe('authority-invalidated');
    expect(regranted.source).toBeUndefined();
    await good(editor, `/trees/${tr.id}/connections/calendar/${connection.id}`, 'DELETE');
    expect(
      (await good(editor, `/trees/${tr.id}/connections/calendar/${connection.id}/refresh`, 'POST')).status,
    ).toBe('unlinked');
  } finally {
    await ctx.close();
  }
});

test('restricting and later releasing a source does not reactivate its prior connection', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  let p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_editor',
    role: 'editor',
    exportAllowed: true,
    revision: 0,
  });
  const ctx = await browser.newContext({ baseURL: fixture.baseURL });
  const editor = await ctx.newPage();
  try {
    await login(editor, 'stb_editor');
    const connection = await event(editor, tr.id, p.id);
    p = await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
      ...editable(p),
      visibility: 'management',
      revision: p.revision,
    });
    expect(
      fixtureDatabase('SELECT status FROM *PREFIX*stb_calendar_links WHERE id = ?', [connection.id], true)[0]
        .status,
    ).toBe('unavailable');
    const hidden = await good(
      editor,
      `/trees/${tr.id}/connections/calendar/${connection.id}/refresh`,
      'POST',
    );
    expect(hidden.status).toBe('unavailable');
    expect(hidden.source).toBeUndefined();
    await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
      ...editable(p),
      visibility: 'members',
      confirmRelease: true,
      revision: p.revision,
    });
    const released = await good(
      editor,
      `/trees/${tr.id}/connections/calendar/${connection.id}/refresh`,
      'POST',
    );
    expect(released.status).toBe('unavailable');
    expect(released.source).toBeUndefined();
  } finally {
    await ctx.close();
  }
});

test('revocation between removal review and DAV success leaves an opaque cleanup receipt', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_editor',
    role: 'editor',
    exportAllowed: true,
    revision: 0,
  });
  const ctx = await browser.newContext({ baseURL: fixture.baseURL });
  const editor = await ctx.newPage();
  try {
    await login(editor, 'stb_editor');
    const connection = await event(editor, tr.id, p.id);
    const original = await dav(editor, connection);
    await good(editor, `/trees/${tr.id}/connections/calendar/${connection.id}/etag`, 'POST', {
      etag: original.etag,
    });
    const review = await good(
      editor,
      `/trees/${tr.id}/connections/calendar/${connection.id}/review-removal`,
      'POST',
    );
    const member = (await good(page, `/trees/${tr.id}/members`)).items.find((x) => x.userId === 'stb_editor');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_editor',
      role: 'editor',
      revision: member.revision,
      remove: true,
    });
    expect((await dav(editor, connection, 'DELETE', undefined, original.etag)).status).toBe(204);
    const completion = await request(
      editor,
      `/trees/${tr.id}/connections/calendar/${connection.id}/complete-removal`,
      'POST',
      { review, acknowledged: true },
    );
    expect(completion.status).toBe(409);
    const opaque = await good(
      editor,
      `/trees/${tr.id}/connections/calendar/${connection.id}/refresh`,
      'POST',
    );
    expect(opaque.status).toBe('authority-invalidated');
    expect(opaque.source).toBeUndefined();
    const revision = (await good(page, `/trees/${tr.id}`)).revision;
    await good(editor, `/trees/${tr.id}/connections/calendar/${connection.id}`, 'DELETE');
    expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
  } finally {
    await ctx.close();
  }
});

test('lost creation response retries the same receipt and never duplicates its event', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const capability = await good(page, '/integrations/calendar/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional writable Calendar absent.');
  const review = await good(page, `/trees/${tr.id}/calendar/review`, 'POST', {
    personId: p.id,
    collection: capability.items[0].uri,
    mode: 'appointment',
    date: '2027-01-17',
    title: 'Fictional archive visit',
    location: '',
  });
  const body = { review, requestId: key(), acknowledged: true };
  let serverStatus = 0;
  await page.route('**/api/v1/trees/*/calendar', async (route) => {
    const response = await route.fetch();
    serverStatus = response.status();
    await route.abort('failed');
  });
  const lost = await page.evaluate(
    async ({ path, body }) => {
      const app = document.getElementById('stammbaum-app');
      try {
        await fetch(app.dataset.apiBase + path, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', requesttoken: window.OC.requestToken },
          body: JSON.stringify(body),
        });
        return false;
      } catch {
        return true;
      }
    },
    { path: `/trees/${tr.id}/calendar`, body },
  );
  await page.unroute('**/api/v1/trees/*/calendar');
  expect(lost).toBe(true);
  expect(serverStatus).toBe(201);
  const retry = await good(page, `/trees/${tr.id}/calendar`, 'POST', body, 201);
  expect(retry.status).toBe('linked');
  const links = (await good(page, `/trees/${tr.id}/connections`)).items.filter((x) => x.id === retry.id);
  expect(links).toHaveLength(1);
  expect((await dav(page, { id: retry.id, collection: review.collection })).status).toBe(200);
});
