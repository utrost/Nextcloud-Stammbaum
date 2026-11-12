import { test, expect } from '@playwright/test';
import { login, good, request, tree, person, key } from './helpers.js';

test('reviewed family anniversary creates one VEVENT without changing genealogy', async ({ page }) => {
  await login(page);
  const capability = await good(page, '/integrations/calendar/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional writable Calendar absent.');
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', {
    livingStatus: 'deceased',
    birthDate: { kind: 'exact', value: '2000-02-29', end: '' },
  });
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.getByRole('button', { name: 'Add to Nextcloud Calendar' }).click();
  await expect(page.getByRole('combobox', { name: 'Calendar', exact: true })).toBeVisible();
  await page.getByRole('combobox', { name: 'Event type' }).selectOption('anniversary');
  await page.getByRole('button', { name: 'Review event' }).click();
  await expect(page.getByText('29 February repeats only in leap years')).toBeVisible();
  await expect(page.getByText('Duplicate detection is incomplete.', { exact: false })).toBeVisible();
  await page.getByRole('checkbox', { name: 'I understand who may see this event' }).check();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 950 });
    await expect(page.getByRole('button', { name: 'Create calendar event' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/calendar-review-${width}.png`, fullPage: true });
  }
  await page.getByRole('button', { name: 'Create calendar event' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByText('Linked event')).toBeVisible();
  const links = (await good(page, `/trees/${tr.id}/calendar?person=${p.id}`)).items;
  expect(links).toHaveLength(1);
  expect(links[0].status).toBe('linked');
  const bytes = await page.evaluate(
    async ({ collection, id }) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      const root = `${window.OC?.getRootPath?.() || window.OC?.webroot || ''}/remote.php/dav/calendars/${encodeURIComponent(user)}`;
      const response = await fetch(`${root}/${encodeURIComponent(collection)}/${id}.ics`, {
        credentials: 'same-origin',
      });
      return { status: response.status, text: await response.text() };
    },
    { collection: links[0].collection, id: links[0].id },
  );
  expect(bytes.status).toBe(200);
  const ics = bytes.text.replace(/\r\n[ \t]/g, '');
  expect(ics).toContain('BEGIN:VEVENT');
  expect(ics).toContain('RRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29');
  expect(ics).not.toContain('ATTENDEE:');
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Unlink event' }).click();
  await expect(page.getByRole('button', { name: 'Unlink event' })).toHaveCount(0);
  expect((await good(page, `/trees/${tr.id}/calendar?person=${p.id}`)).items).toEqual([]);
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
  const afterUnlink = await page.evaluate(
    async ({ collection, id }) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      return (
        await fetch(
          `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${id}.ics`,
          { credentials: 'same-origin' },
        )
      ).status;
    },
    { collection: links[0].collection, id: links[0].id },
  );
  expect(afterUnlink).toBe(200);
});

test('Calendar retry is idempotent and a deleted destination is reported, not recreated', async ({
  page,
}) => {
  await login(page);
  const capability = await good(page, '/integrations/calendar/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional writable Calendar absent.');
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  const review = await good(page, `/trees/${tr.id}/calendar/review`, 'POST', {
    personId: p.id,
    collection: capability.items[0].uri,
    mode: 'appointment',
    date: '2026-12-01',
    title: 'Fictional archive visit',
    location: 'Example town',
    includeLiving: false,
  });
  const requestId = key();
  const one = await good(
    page,
    `/trees/${tr.id}/calendar`,
    'POST',
    { review, requestId, acknowledged: true },
    201,
  );
  const two = await good(
    page,
    `/trees/${tr.id}/calendar`,
    'POST',
    { review, requestId, acknowledged: true },
    201,
  );
  expect(two).toEqual(one);
  expect((await good(page, `/trees/${tr.id}/calendar?person=${p.id}`)).items).toHaveLength(1);
  const deleted = await page.evaluate(
    async ({ collection, id }) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      const url = `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${id}.ics`;
      const before = await fetch(url, { credentials: 'same-origin' });
      const bytes = await before.text();
      const result = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'If-Match': before.headers.get('ETag') || '', requesttoken: window.OC.requestToken },
      });
      return { status: result.status, bytes };
    },
    { collection: review.collection, id: one.id },
  );
  expect(deleted.status).toBe(204);
  expect(deleted.bytes).toContain('DTSTART;VALUE=DATE:20261201');
  expect((await good(page, `/trees/${tr.id}/calendar?person=${p.id}`)).items[0].status).toBe(
    'destination-changed',
  );
  const retry = await good(
    page,
    `/trees/${tr.id}/calendar`,
    'POST',
    { review, requestId, acknowledged: true },
    201,
  );
  expect(retry.status).toBe('destination-changed');
  const timedReview = await good(page, `/trees/${tr.id}/calendar/review`, 'POST', {
    personId: p.id,
    collection: capability.items[0].uri,
    mode: 'appointment',
    date: '2026-11-12',
    time: '16:30',
    timezone: 'Europe/Berlin',
    title: 'Fictional timed visit',
    location: '',
    includeLiving: false,
  });
  const timed = await good(
    page,
    `/trees/${tr.id}/calendar`,
    'POST',
    { review: timedReview, requestId: key(), acknowledged: true },
    201,
  );
  const timedBytes = await page.evaluate(
    async ({ collection, id }) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      const response = await fetch(
        `/remote.php/dav/calendars/${encodeURIComponent(user)}/${encodeURIComponent(collection)}/${id}.ics`,
        { credentials: 'same-origin' },
      );
      return await response.text();
    },
    { collection: timedReview.collection, id: timed.id },
  );
  expect(timedBytes).toContain('DTSTART:20261112T153000Z');
  expect(timedBytes).toContain('DTEND:20261112T163000Z');
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
});

test('a linked Contacts birthday requires a second explicit Calendar acknowledgement', async ({ page }) => {
  await login(page);
  const capability = await good(page, '/integrations/calendar/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional writable Calendar absent.');
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', {
    livingStatus: 'deceased',
    birthDate: { kind: 'exact', value: '1900-01-02', end: '' },
  });
  const cardUid = key();
  const cardName = `Fictional birthday ${cardUid.slice(0, 8)}`;
  const card = await page.evaluate(
    async ({ cardUid, cardName }) => {
      const user = document.getElementById('stammbaum-app').dataset.userId;
      const url = `/remote.php/dav/addressbooks/users/${encodeURIComponent(user)}/stammbaum-contacts/${cardUid}.vcf`;
      const text = [
        'BEGIN:VCARD',
        'VERSION:4.0',
        `UID:${cardUid}`,
        `FN:${cardName}`,
        'N:Birthday;Fictional;;;',
        'BDAY:1900-01-02',
        'END:VCARD',
        '',
      ].join('\r\n');
      const response = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'text/vcard; charset=utf-8',
          'If-None-Match': '*',
          requesttoken: window.OC.requestToken,
        },
        body: text,
      });
      return { status: response.status, url };
    },
    { cardUid, cardName },
  );
  expect(card.status).toBe(201);
  try {
    const books = await good(page, `/trees/${tr.id}/contacts/books?person=${p.id}`);
    const book = books.items.find((b) => b.name === 'stammbaum-contacts');
    expect(book).toBeTruthy();
    const search = await good(page, `/trees/${tr.id}/contacts/search`, 'POST', {
      personId: p.id,
      bookKey: book.key,
      query: cardName,
      page: 0,
    });
    const candidate = search.items.find((c) => c.cardUid === cardUid);
    expect(candidate).toBeTruthy();
    const linkReview = await good(page, `/trees/${tr.id}/contacts/review-link`, 'POST', {
      personId: p.id,
      bookKey: book.key,
      cardUid,
      cardUri: candidate.cardUri,
    });
    await good(page, `/trees/${tr.id}/contacts/links`, 'POST', { review: linkReview, requestId: key() }, 201);
    const review = await good(page, `/trees/${tr.id}/calendar/review`, 'POST', {
      personId: p.id,
      collection: capability.items[0].uri,
      mode: 'anniversary',
      anniversaryKind: 'birth',
      title: 'Fictional second birthday',
      location: '',
      includeLiving: false,
    });
    expect(review.linkedContactBirthday).toBe(true);
    const requestId = key();
    expect(
      (await request(page, `/trees/${tr.id}/calendar`, 'POST', { review, requestId, acknowledged: true }))
        .status,
    ).toBe(422);
    const created = await good(
      page,
      `/trees/${tr.id}/calendar`,
      'POST',
      { review, requestId, acknowledged: true, acknowledgedDuplicate: true },
      201,
    );
    expect(created.status).toBe('linked');
  } finally {
    await page.evaluate(async (url) => {
      const response = await fetch(url, { credentials: 'same-origin' });
      await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'If-Match': response.headers.get('ETag') || '', requesttoken: window.OC.requestToken },
      });
    }, card.url);
  }
});

test('Calendar rejects stale reviews, missing export authority and invalid anniversary dates', async ({
  page,
  browser,
}) => {
  await login(page);
  const capability = await good(page, '/integrations/calendar/collections');
  test.skip(!capability.available || !capability.items.length, 'Optional writable Calendar absent.');
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', {
    livingStatus: 'deceased',
    birthDate: { kind: 'about', value: '1900', end: '' },
  });
  const payload = {
    personId: p.id,
    collection: capability.items[0].uri,
    mode: 'anniversary',
    anniversaryKind: 'birth',
    title: 'Fictional birthday',
    location: '',
    includeLiving: false,
  };
  expect((await request(page, `/trees/${tr.id}/calendar/review`, 'POST', payload)).status).toBe(422);
  const appointment = {
    ...payload,
    mode: 'appointment',
    date: '2026-03-29',
    time: '02:30',
    timezone: 'Europe/Berlin',
  };
  expect((await request(page, `/trees/${tr.id}/calendar/review`, 'POST', appointment)).status).toBe(422);
  expect(
    (await request(page, `/trees/${tr.id}/calendar/review`, 'POST', { ...appointment, date: '2026-10-25' }))
      .status,
  ).toBe(422);
  const valid = { ...appointment, date: '2026-11-12', time: '16:30' };
  const review = await good(page, `/trees/${tr.id}/calendar/review`, 'POST', valid);
  expect((await request(page, `/trees/${tr.id}/calendar`, 'POST', { review, requestId: key() })).status).toBe(
    422,
  );
  const current = await good(page, `/trees/${tr.id}/people/${p.id}`);
  await good(page, `/trees/${tr.id}/people/${p.id}`, 'PUT', {
    givenName: current.givenName,
    familyName: current.familyName,
    livingStatus: current.livingStatus,
    sex: current.sex,
    visibility: current.visibility,
    birthDate: current.birthDate,
    deathDate: current.deathDate,
    birthPlace: current.birthPlace,
    deathPlace: current.deathPlace,
    displayNote: 'Fictional edit',
    revision: current.revision,
  });
  expect(
    (
      await request(page, `/trees/${tr.id}/calendar`, 'POST', {
        review,
        requestId: key(),
        acknowledged: true,
      })
    ).status,
  ).toBe(412);

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
    const viewerCalendars = await good(viewer, '/integrations/calendar/collections');
    expect(viewerCalendars.items.length).toBeGreaterThan(0);
    const viewerPayload = { ...valid, collection: viewerCalendars.items[0].uri };
    expect((await request(viewer, `/trees/${tr.id}/calendar/review`, 'POST', viewerPayload)).status).toBe(
      403,
    );
    expect((await good(viewer, `/trees/${tr.id}/calendar?person=${p.id}`)).items).toEqual([]);
    let member = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: true,
      revision: member.revision,
    });
    const viewerReview = await good(viewer, `/trees/${tr.id}/calendar/review`, 'POST', viewerPayload);
    const hidden = await person(page, tr.id, 'Private', {
      livingStatus: 'deceased',
      visibility: 'management',
    });
    expect(
      (await request(page, `/trees/${tr.id}/calendar/review`, 'POST', { ...valid, personId: hidden.id }))
        .status,
    ).toBe(422);
    const explicitHidden = await good(page, `/trees/${tr.id}/calendar/review`, 'POST', {
      ...valid,
      personId: hidden.id,
      includeManagement: true,
    });
    expect(explicitHidden.includeManagement).toBe(true);
    expect(
      (
        await request(viewer, `/trees/${tr.id}/calendar/review`, 'POST', {
          ...viewerPayload,
          personId: hidden.id,
        })
      ).status,
    ).toBe(404);
    member = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: false,
      revision: member.revision,
    });
    expect(
      (
        await request(viewer, `/trees/${tr.id}/calendar`, 'POST', {
          review: viewerReview,
          requestId: key(),
          acknowledged: true,
        })
      ).status,
    ).toBe(403);
    member = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: true,
      revision: member.revision,
    });
    expect(
      (
        await request(viewer, `/trees/${tr.id}/calendar`, 'POST', {
          review: viewerReview,
          requestId: key(),
          acknowledged: true,
        })
      ).status,
    ).toBe(412);
  } finally {
    await ctx.close();
  }
});
