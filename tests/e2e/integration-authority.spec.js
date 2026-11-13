import { test, expect } from '@playwright/test';
import { fixture, good, grant, key, login, person, request, tree } from './helpers.js';

test('all tree roles keep outbound reviews behind export and all-target visibility', async ({
  page,
  browser,
}) => {
  test.setTimeout(120000);
  await login(page);
  const tr = await tree(page);
  const deceased = await person(page, tr.id, 'Fictional deceased researcher', { livingStatus: 'deceased' });
  const living = await person(page, tr.id, 'Fictional living researcher', { livingStatus: 'living' });
  const hidden = await person(page, tr.id, 'Fictional hidden researcher', {
    livingStatus: 'deceased',
    visibility: 'management',
  });
  const event = await good(
    page,
    `/trees/${tr.id}/records`,
    'POST',
    {
      originId: deceased.id,
      category: 'event',
      type: 'custom',
      title: 'Fictional private gathering',
      participants: [deceased, hidden].map((p) => ({ personId: p.id, role: 'subject' })),
      confirmedParticipants: [deceased.id, hidden.id],
      requestId: key(),
    },
    201,
  );
  for (const [user, role] of [
    ['stb_viewer', 'viewer'],
    ['stb_editor', 'editor'],
    ['stb_manager', 'manager'],
  ]) {
    await grant(page, tr.id, user, role);
  }
  const contexts = [];
  try {
    const actors = new Map([['stb_owner', page]]);
    for (const user of ['stb_viewer', 'stb_editor', 'stb_manager', 'stb_outsider']) {
      const context = await browser.newContext({ baseURL: fixture.baseURL });
      contexts.push(context);
      const actor = await context.newPage();
      await login(actor, user);
      actors.set(user, actor);
    }
    const collections = new Map();
    for (const [user, actor] of actors) {
      const tasks = await good(actor, '/integrations/tasks/collections');
      const calendars = await good(actor, '/integrations/calendar/collections');
      expect(tasks.available && tasks.items.length).toBeTruthy();
      expect(calendars.available && calendars.items.length).toBeTruthy();
      collections.set(user, { task: tasks.items[0].uri, calendar: calendars.items[0].uri });
    }
    const taskReview = (actor, user, sourceId, extra = {}) =>
      request(actor, `/trees/${tr.id}/tasks/review`, 'POST', {
        personId: sourceId,
        collection: collections.get(user).task,
        title: 'Fictional private research',
        ...extra,
      });
    const calendarReview = (actor, user, sourceId, extra = {}) =>
      request(actor, `/trees/${tr.id}/calendar/review`, 'POST', {
        personId: sourceId,
        collection: collections.get(user).calendar,
        mode: 'appointment',
        date: '2027-01-12',
        title: 'Fictional private appointment',
        ...extra,
      });
    for (const user of ['stb_viewer', 'stb_editor']) {
      expect((await taskReview(actors.get(user), user, deceased.id)).status).toBe(403);
      expect((await calendarReview(actors.get(user), user, deceased.id)).status).toBe(403);
    }
    expect((await taskReview(actors.get('stb_manager'), 'stb_manager', deceased.id)).status).toBe(200);
    expect((await calendarReview(actors.get('stb_owner'), 'stb_owner', deceased.id)).status).toBe(200);
    for (const kind of ['task', 'calendar']) {
      const denied =
        kind === 'task'
          ? await taskReview(actors.get('stb_outsider'), 'stb_outsider', deceased.id)
          : await calendarReview(actors.get('stb_outsider'), 'stb_outsider', deceased.id);
      expect(denied.status).toBe(404);
      expect(JSON.stringify(denied.body)).not.toContain(deceased.givenName);
    }
    for (const [user, role] of [
      ['stb_viewer', 'viewer'],
      ['stb_editor', 'editor'],
    ]) {
      const member = (await good(page, `/trees/${tr.id}/members`)).items.find((item) => item.userId === user);
      await good(page, `/trees/${tr.id}/members`, 'POST', {
        userId: user,
        role,
        exportAllowed: true,
        revision: member.revision,
      });
    }
    const revision = (await good(page, `/trees/${tr.id}`)).revision;
    for (const user of ['stb_viewer', 'stb_editor']) {
      expect((await taskReview(actors.get(user), user, deceased.id)).status).toBe(200);
      expect((await calendarReview(actors.get(user), user, deceased.id)).status).toBe(200);
      expect((await taskReview(actors.get(user), user, living.id)).status).toBe(422);
      expect((await taskReview(actors.get(user), user, living.id, { includeLiving: true })).status).toBe(200);
      expect((await taskReview(actors.get(user), user, hidden.id, { includeManagement: true })).status).toBe(
        404,
      );
      const hiddenEvent = await request(actors.get(user), `/trees/${tr.id}/calendar/review`, 'POST', {
        sourceType: 'event',
        sourceId: event.id,
        collection: collections.get(user).calendar,
        mode: 'appointment',
        date: '2027-01-12',
        title: 'Fictional private gathering',
        includeManagement: true,
      });
      expect(hiddenEvent.status).toBe(404);
      expect(JSON.stringify(hiddenEvent.body)).not.toContain(event.title);
    }
    for (const user of ['stb_owner', 'stb_manager']) {
      expect((await taskReview(actors.get(user), user, hidden.id)).status).toBe(422);
      expect((await taskReview(actors.get(user), user, hidden.id, { includeManagement: true })).status).toBe(
        200,
      );
      expect((await calendarReview(actors.get(user), user, living.id)).status).toBe(422);
      expect((await calendarReview(actors.get(user), user, living.id, { includeLiving: true })).status).toBe(
        200,
      );
    }
    expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
  } finally {
    for (const context of contexts) await context.close();
  }
});
