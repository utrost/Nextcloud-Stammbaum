import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from '@playwright/test';
import { fixture, login, tree, person, good, key } from '../tests/e2e/helpers.js';

assert.equal(fixture.container, 'stammbaum-iteration-nc');
const occ = (...args) =>
  execFileSync('docker', ['exec', '-u', 'www-data', fixture.container, 'php', 'occ', ...args], {
    stdio: 'pipe',
  });
const reload = () =>
  execFileSync('docker', ['exec', '-u', 'root', fixture.container, 'apachectl', '-k', 'graceful'], {
    stdio: 'pipe',
  });
const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const disabled = [];
try {
  const context = await browser.newContext({ baseURL: fixture.baseURL });
  const page = await context.newPage();
  await login(page);
  const linkedTree = await tree(page);
  const linkedPerson = await person(page, linkedTree.id, 'Fictional disabled app', {
    livingStatus: 'deceased',
  });
  const calendars = await good(page, '/integrations/calendar/collections');
  assert.equal(calendars.available && calendars.items.length > 0, true);
  const review = await good(page, `/trees/${linkedTree.id}/calendar/review`, 'POST', {
    personId: linkedPerson.id,
    collection: calendars.items[0].uri,
    mode: 'appointment',
    date: '2027-02-01',
    title: 'Fictional disabled app',
    location: '',
  });
  const linked = await good(
    page,
    `/trees/${linkedTree.id}/calendar`,
    'POST',
    {
      review,
      requestId: key(),
      acknowledged: true,
    },
    201,
  );
  for (const app of ['tasks', 'contacts', 'calendar']) {
    occ('app:disable', app);
    disabled.push(app);
  }
  reload();
  const unavailable = (await good(page, `/trees/${linkedTree.id}/connections`)).items.find(
    (x) => x.id === linked.id,
  );
  assert.equal(unavailable.status, 'unavailable');
  assert.equal(unavailable.kind, 'calendar');
  const tr = await tree(page);
  const p = await person(page, tr.id, 'All optional apps absent', { livingStatus: 'deceased' });
  assert.equal((await good(page, `/trees/${tr.id}/people/${p.id}`)).givenName, 'All optional apps absent');
  for (const route of [
    '/integrations/tasks/collections',
    '/integrations/calendar/collections',
    `/trees/${tr.id}/contacts/books?person=${p.id}`,
  ]) {
    let state;
    for (let attempt = 0; attempt < 10; attempt++) {
      state = await good(page, route);
      if (!state.available) break;
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    assert.equal(state.available, false, `${route} must be unavailable`);
    assert.deepEqual(state.items, []);
  }
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.getByText('All optional apps absent', { exact: false }).first().waitFor();
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  console.log(
    'Core tree and person UI work with Tasks, Contacts and Calendar all disabled; all adapters report unavailable.',
  );
  await context.close();
} finally {
  for (const app of disabled.reverse()) occ('app:enable', app);
  if (disabled.length) reload();
  await browser.close();
}
