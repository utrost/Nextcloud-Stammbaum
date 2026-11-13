import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { endpoint, fixture, good, grant, key, login, person, request, tree } from './helpers.js';
import { fixtureDatabase } from './server-fixture.js';

function resetDisposableRateWindow(table, actor = fixture.user) {
  const now = Math.floor(Date.now() / 1000);
  fixtureDatabase(`UPDATE *PREFIX*${table} SET created_at = ? WHERE actor_id = ? AND created_at >= ?`, [
    now - 7200,
    actor,
    now - 3600,
  ]);
}

async function english(page) {
  const preferences = await good(page, '/preferences');
  if (preferences.locale !== 'en') {
    await good(page, '/preferences', 'PUT', { locale: 'en', revision: preferences.revision });
    await page.reload();
  }
}

async function event(page, treeId, people) {
  return good(
    page,
    `/trees/${treeId}/records`,
    'POST',
    {
      originId: people[0].id,
      category: 'event',
      type: 'custom',
      title: 'Fictional family archive visit',
      participants: people.map((p) => ({ personId: p.id, role: 'subject' })),
      confirmedParticipants: people.map((p) => p.id),
      requestId: key(),
    },
    201,
  );
}

test('source and media research tasks require a fresh review and expose only their own completion', async ({
  page,
}) => {
  await login(page);
  await english(page);
  resetDisposableRateWindow('stb_task_links');
  const capability = await good(page, '/integrations/tasks/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional Tasks list absent.');
  expect(Array.isArray(capability.readOnlyItems)).toBe(true);
  expect(capability.items.length + capability.readOnlyItems.length).toBeLessThanOrEqual(50);
  expect(capability.readOnlyItems.every((item) => item.state === 'read-only')).toBe(true);
  expect(capability.items[0].componentCheck).toBe('authenticated-dav');
  expect(['unknown', 'shared-with-me']).toContain(capability.items[0].audience);
  const tr = await tree(page);
  const source = await good(
    page,
    `/trees/${tr.id}/sources`,
    'POST',
    {
      title: 'Fictional parish register',
      requestId: key(),
    },
    201,
  );
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=sources`);
  await page.getByRole('button', { name: source.title }).click();
  await page.getByRole('button', { name: 'Create research task' }).click();
  await expect(page.getByRole('combobox', { name: /Task list/ })).toBeVisible();
  if (capability.items.some((item) => item.uri === 'personal')) {
    await expect(
      page.getByRole('combobox', { name: /Task list/ }).locator('option[value="personal"]'),
    ).toHaveCount(0);
  }
  await page.getByRole('combobox', { name: 'Priority' }).selectOption('1');
  await page.getByRole('button', { name: 'Review task' }).click();
  await expect(page.locator('.task-preview')).toContainText(source.title);
  await page.getByRole('checkbox', { name: 'I understand who may see this task' }).check();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(page.getByRole('button', { name: 'Create task', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/source-task-review-${width}.png`, fullPage: true });
  }
  await page.getByRole('button', { name: 'Create task', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('.task-links')).toContainText('Linked task');
  const [link] = (await good(page, `/trees/${tr.id}/tasks?sourceType=source&sourceId=${source.id}`)).items;
  expect(link.completion).toBe('open');
  const dav = await page.evaluate(
    async ({ collection, id }) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      const url = `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${id}.ics`;
      const initial = await fetch(url, { credentials: 'same-origin' });
      const bytes = await initial.text();
      const changed = bytes.replace('STATUS:NEEDS-ACTION', 'STATUS:COMPLETED');
      const update = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'text/calendar; charset=utf-8',
          'If-Match': initial.headers.get('ETag'),
          requesttoken: window.OC.requestToken,
        },
        body: changed,
      });
      return { status: update.status, bytes };
    },
    { collection: link.collection, id: link.id },
  );
  expect(dav.status).toBe(204);
  expect(dav.bytes).toContain('PRIORITY:1');
  expect(dav.bytes.replace(/\r\n[ \t]/g, '')).toContain(`&view=sources&source=${source.id}`);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=sources&source=${source.id}`);
  await expect(page.getByRole('heading', { name: source.title })).toBeVisible();
  expect(
    (await good(page, `/trees/${tr.id}/tasks?sourceType=source&sourceId=${source.id}`)).items[0].completion,
  ).toBe('complete');
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
  const changed = await good(page, `/trees/${tr.id}/sources/${source.id}`, 'PUT', {
    title: 'Corrected fictional register',
    revision: source.revision,
  });
  expect(changed.revision).toBeGreaterThan(source.revision);
  const stale = await good(page, `/trees/${tr.id}/connections/task/${link.id}/refresh`, 'POST');
  expect(stale.status).toBe('source-changed');
  expect(stale.completion).toBeUndefined();

  const response = await page.request.post(`${endpoint}/trees/${tr.id}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      requestId: key(),
      title: 'Fictional photograph',
      file: {
        name: 'fictional.pdf',
        mimeType: 'application/pdf',
        buffer: readFileSync('tests/fixtures/media-two-pages.pdf'),
      },
    },
  });
  expect(response.status()).toBe(201);
  const medium = await response.json();
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media`);
  const mediaCard = page.locator('.media-card').filter({ hasText: medium.title });
  await mediaCard.getByRole('button', { name: 'Create research task' }).click();
  await mediaCard.getByRole('button', { name: 'Review task' }).click();
  await expect(mediaCard.locator('.task-preview')).toContainText(medium.title);
  await mediaCard.getByRole('checkbox', { name: 'I understand who may see this task' }).check();
  await mediaCard.getByRole('button', { name: 'Create task', exact: true }).click();
  await expect(mediaCard.locator('.task-links')).toContainText('Linked task');
  const [mediumLink] = (await good(page, `/trees/${tr.id}/tasks?sourceType=medium&sourceId=${medium.id}`))
    .items;
  expect(mediumLink.status).toBe('linked');
  const mediumIcs = await page.evaluate(async ({ collection, id }) => {
    const user = document.getElementById('stammbaum-app').dataset.userId;
    const url = `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${id}.ics`;
    return (await fetch(url, { credentials: 'same-origin' })).text();
  }, mediumLink);
  expect(mediumIcs.replace(/\r\n[ \t]/g, '')).toContain(`&view=media&medium=${medium.id}`);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=media&medium=${medium.id}`);
  await expect(page.locator(`[data-medium-id="${medium.id}"]`)).toBeFocused();
  const removed = await page.evaluate(async ({ collection, id }) => {
    const user = document.getElementById('stammbaum-app').dataset.userId;
    const url = `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${id}.ics`;
    const current = await fetch(url, { credentials: 'same-origin' });
    return (
      await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
          'If-Match': current.headers.get('ETag'),
          requesttoken: window.OC.requestToken,
        },
      })
    ).status;
  }, mediumLink);
  expect(removed).toBe(204);
  const missing = (await good(page, `/trees/${tr.id}/tasks?sourceType=medium&sourceId=${medium.id}`))
    .items[0];
  expect(missing.status).toBe('destination-changed');
  expect(missing.completion).toBe('unknown');
  const en = await good(page, '/preferences');
  await good(page, '/preferences', 'PUT', { locale: 'de', revision: en.revision });
  await page.reload();
  await mediaCard.locator('.research-tasks > button').click();
  await expect(mediaCard.getByRole('combobox', { name: 'Priorität' })).toBeVisible();
  await page.setViewportSize({ width: 320, height: 900 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: 'test-results/media-task-de-320.png', fullPage: true });
  const de = await good(page, '/preferences');
  await good(page, '/preferences', 'PUT', { locale: 'en', revision: de.revision });
  const mediaReview = await good(page, `/trees/${tr.id}/tasks/review`, 'POST', {
    sourceType: 'medium',
    sourceId: medium.id,
    collection: capability.items[0].uri,
    title: 'Identify fictional document',
    priority: '5',
    includeLiving: false,
  });
  expect(mediaReview.sourceType).toBe('medium');
  const currentMedium = await good(page, `/trees/${tr.id}/media/${medium.id}`);
  await good(page, `/trees/${tr.id}/media/${medium.id}`, 'PUT', {
    title: currentMedium.title,
    revision: currentMedium.revision,
    metadata: {
      ...currentMedium.metadata,
      people: [{ name: 'Fictional unnamed researcher', personId: '', association: 'mentioned', page: '' }],
    },
  });
  expect(
    (
      await request(page, `/trees/${tr.id}/tasks/review`, 'POST', {
        sourceType: 'medium',
        sourceId: medium.id,
        collection: capability.items[0].uri,
        title: 'Research the named person',
      })
    ).status,
  ).toBe(422);
  const impact = await good(page, `/trees/${tr.id}/media/${medium.id}/impact`);
  await good(page, `/trees/${tr.id}/media/${medium.id}/delete`, 'POST', impact);
  const unavailable = await good(page, `/trees/${tr.id}/connections/task/${mediumLink.id}/refresh`, 'POST');
  expect(unavailable.status).toBe('unavailable');
  expect(unavailable.source).toBeUndefined();
  expect(
    (
      await request(page, `/trees/${tr.id}/tasks`, 'POST', {
        review: mediaReview,
        acknowledged: true,
        requestId: key(),
      })
    ).status,
  ).toBe(404);
});

test('shared-event calendar publication checks every participant and never changes genealogy', async ({
  page,
  browser,
}) => {
  await login(page);
  await english(page);
  resetDisposableRateWindow('stb_calendar_links');
  const capability = await good(page, '/integrations/calendar/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional Calendar absent.');
  expect(Array.isArray(capability.readOnlyItems)).toBe(true);
  expect(capability.items.length + capability.readOnlyItems.length).toBeLessThanOrEqual(50);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Ava', { livingStatus: 'deceased' });
  const q = await person(page, tr.id, 'Ben', { livingStatus: 'deceased' });
  const record = await event(page, tr.id, [p, q]);
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.locator('.details').getByRole('tab', { name: 'Timeline', exact: true }).click();
  const card = page.locator('.record-card').filter({ hasText: record.title });
  await card.getByRole('button', { name: 'Add to Nextcloud Calendar' }).click();
  await expect(card.getByRole('combobox', { name: 'Event type' }).locator('option')).toHaveCount(1);
  await card.getByRole('textbox', { name: 'Event title' }).fill('Review fictional archive');
  await card.getByRole('textbox', { name: 'Appointment date' }).fill('2026-12-01');
  await card.getByRole('button', { name: 'Review event' }).click();
  await expect(card.locator('.task-preview')).toContainText(record.title);
  await card.getByRole('checkbox', { name: 'I understand who may see this event' }).check();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(card.getByRole('button', { name: 'Create calendar event' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/shared-event-review-${width}.png`, fullPage: true });
  }
  await card.getByRole('button', { name: 'Create calendar event' }).focus();
  await page.keyboard.press('Enter');
  await expect(card).toContainText('Linked event');
  const [link] = (await good(page, `/trees/${tr.id}/calendar?sourceType=event&sourceId=${record.id}`)).items;
  expect(link.status).toBe('linked');
  await card.getByRole('button', { name: 'Create research task' }).click();
  await card.getByRole('button', { name: 'Review task' }).click();
  await expect(card.locator('.research-tasks .task-preview')).toContainText(record.title);
  await card
    .locator('.research-tasks')
    .getByRole('checkbox', { name: 'I understand who may see this task' })
    .check();
  await card.getByRole('button', { name: 'Create task', exact: true }).click();
  await expect(card.locator('.research-tasks .task-links')).toContainText('Linked task');
  const [eventTask] = (await good(page, `/trees/${tr.id}/tasks?sourceType=event&sourceId=${record.id}`))
    .items;
  expect(eventTask.status).toBe('linked');
  const eventTaskIcs = await page.evaluate(async ({ collection, id }) => {
    const user = document.getElementById('stammbaum-app').dataset.userId;
    const url = `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${id}.ics`;
    return (await fetch(url, { credentials: 'same-origin' })).text();
  }, eventTask);
  expect(eventTaskIcs.replace(/\r\n[ \t]/g, '')).toContain(`&person=${p.id}&event=${record.id}`);
  const eventIcs = await page.evaluate(async ({ collection, id }) => {
    const user = document.getElementById('stammbaum-app').dataset.userId;
    const url = `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${id}.ics`;
    return (await fetch(url, { credentials: 'same-origin' })).text();
  }, link);
  expect(eventIcs.replace(/\r\n[ \t]/g, '')).toContain(`&person=${p.id}&event=${record.id}`);
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}&event=${record.id}`);
  await expect(page.locator(`[data-record-id="${record.id}"]`)).toBeFocused();
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
  const en = await good(page, '/preferences');
  await good(page, '/preferences', 'PUT', { locale: 'de', revision: en.revision });
  await page.reload();
  await page.locator('.details').getByRole('tab', { name: 'Zeitverlauf', exact: true }).click();
  const germanCard = page.locator('.record-card').filter({ hasText: record.title });
  await germanCard.locator('.calendar-appointments > button').click();
  await expect(germanCard.getByRole('combobox', { name: 'Kalender', exact: true })).toBeVisible();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/shared-event-de-${width}.png`, fullPage: true });
  }
  const de = await good(page, '/preferences');
  await good(page, '/preferences', 'PUT', { locale: 'en', revision: de.revision });
  const current = await good(page, `/trees/${tr.id}/people/${q.id}`);
  await good(page, `/trees/${tr.id}/people/${q.id}`, 'PUT', {
    givenName: current.givenName,
    familyName: current.familyName,
    livingStatus: current.livingStatus,
    sex: current.sex,
    visibility: current.visibility,
    displayNote: 'Changed shared dependency',
    revision: current.revision,
  });
  const stale = await good(page, `/trees/${tr.id}/connections/calendar/${link.id}/refresh`, 'POST');
  expect(stale.status).toBe('source-changed');
  expect(stale.source?.label).toBe(record.title);
  const context = await browser.newContext();
  const viewer = await context.newPage();
  try {
    await grant(page, tr.id, 'stb_viewer', 'viewer');
    const members = await good(page, `/trees/${tr.id}/members`);
    const membership = members.items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: true,
      revision: membership.revision,
    });
    await login(viewer, 'stb_viewer');
    const hidden = await person(page, tr.id, 'Hidden', {
      livingStatus: 'deceased',
      visibility: 'management',
    });
    const bridge = await event(page, tr.id, [p, hidden]);
    const payload = {
      sourceType: 'event',
      sourceId: bridge.id,
      collection: capability.items[0].uri,
      mode: 'appointment',
      title: 'Private event',
      date: '2026-12-01',
      includeLiving: true,
      includeManagement: true,
    };
    expect((await request(viewer, `/trees/${tr.id}/calendar/review`, 'POST', payload)).status).toBe(404);
    expect((await request(viewer, `/trees/${tr.id}/tasks/review`, 'POST', payload)).status).toBe(404);
    await viewer.goto(
      `${fixture.baseURL}/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}&event=${bridge.id}`,
    );
    await expect(viewer.locator(`[data-record-id="${bridge.id}"]`)).toHaveCount(0);
    await expect(viewer.getByText(bridge.title)).toHaveCount(0);
  } finally {
    await context.close();
  }
});

test('revocation between task authorization and DAV write leaves only an opaque invalid receipt', async ({
  page,
  browser,
}) => {
  await login(page);
  resetDisposableRateWindow('stb_task_links', 'stb_viewer');
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Fictional', { livingStatus: 'deceased' });
  await grant(page, tr.id, 'stb_viewer');
  let members = await good(page, `/trees/${tr.id}/members`);
  let membership = members.items.find((m) => m.userId === 'stb_viewer');
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_viewer',
    role: 'viewer',
    exportAllowed: true,
    revision: membership.revision,
  });
  const context = await browser.newContext();
  const viewer = await context.newPage();
  const collection = 'stb-race-' + key().slice(0, 10);
  try {
    await login(viewer, 'stb_viewer');
    const capability = await good(viewer, '/integrations/tasks/collections');
    test.skip(!capability.available, 'Optional Tasks app absent.');
    const createdList = await viewer.evaluate(async (collection) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      const url = `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/`;
      return (
        await fetch(url, {
          method: 'MKCALENDAR',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/xml; charset=utf-8',
            requesttoken: window.OC.requestToken,
          },
          body: '<?xml version="1.0"?><c:mkcalendar xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:"><d:set><d:prop><d:displayname>Fictional race list</d:displayname><c:supported-calendar-component-set><c:comp name="VTODO"/></c:supported-calendar-component-set></d:prop></d:set></c:mkcalendar>',
        })
      ).status;
    }, collection);
    expect(createdList).toBe(201);
    const review = await good(viewer, `/trees/${tr.id}/tasks/review`, 'POST', {
      personId: p.id,
      collection,
      title: 'Fictional research after revoke',
    });
    const reserved = await good(
      viewer,
      `/trees/${tr.id}/tasks`,
      'POST',
      {
        review,
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
    const remote = await viewer.evaluate(async ({ collection, objectName, ics }) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      const url = `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${encodeURIComponent(objectName)}`;
      return (
        await fetch(url, {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'text/calendar; charset=utf-8',
            'If-None-Match': '*',
            requesttoken: window.OC.requestToken,
          },
          body: ics,
        })
      ).status;
    }, reserved);
    expect(remote).toBe(201);
    const copied = await viewer.evaluate(async ({ collection, objectName }) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      return (
        await fetch(
          `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${encodeURIComponent(objectName)}`,
          { credentials: 'same-origin' },
        )
      ).status;
    }, reserved);
    expect(copied).toBe(200);
    expect((await request(viewer, `/trees/${tr.id}/tasks/${reserved.id}/complete`, 'POST')).status).toBe(404);
    const hidden = await good(viewer, `/trees/${tr.id}/connections/task/${reserved.id}/refresh`, 'POST');
    expect(hidden.status).toBe('authority-invalidated');
    expect(hidden.source).toBeUndefined();
    members = await good(page, `/trees/${tr.id}/members`);
    membership = members.items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: true,
      revision: membership.revision,
    });
    expect(
      (await good(viewer, `/trees/${tr.id}/connections/task/${reserved.id}/refresh`, 'POST')).status,
    ).toBe('authority-invalidated');
  } finally {
    await viewer
      .evaluate(async (collection) => {
        const user = document.getElementById('stammbaum-app')?.dataset.userId;
        if (user)
          await fetch(
            `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/`,
            {
              method: 'DELETE',
              credentials: 'same-origin',
              headers: { requesttoken: window.OC.requestToken },
            },
          );
      }, collection)
      .catch(() => {});
    await context.close();
  }
});
