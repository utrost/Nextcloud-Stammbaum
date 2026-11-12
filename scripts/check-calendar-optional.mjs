import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from '@playwright/test';
import { fixture, login, tree, person, good, request } from '../tests/e2e/helpers.js';

assert.equal(fixture.container, 'stammbaum-iteration-nc', 'Only the disposable fixture may be changed.');
const occ = (...args) =>
  execFileSync('docker', ['exec', '-u', 'www-data', fixture.container, 'php', 'occ', ...args], {
    stdio: 'pipe',
  });
const refreshWorkers = () =>
  execFileSync('docker', ['exec', '-u', 'root', fixture.container, 'apachectl', '-k', 'graceful'], {
    stdio: 'pipe',
  });
const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
try {
  const context = await browser.newContext({ baseURL: fixture.baseURL });
  const page = await context.newPage();
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Optional Calendar', { livingStatus: 'deceased' });
  occ('app:disable', 'calendar');
  refreshWorkers();
  try {
    let collections;
    for (let attempt = 0; attempt < 10; attempt++) {
      collections = await good(page, '/integrations/calendar/collections');
      if (!collections.available) break;
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    assert.equal(collections.available, false);
    assert.deepEqual(collections.items, []);
    const review = await request(page, `/trees/${tr.id}/calendar/review`, 'POST', {
      personId: p.id,
      collection: 'stammbaum-calendar',
      mode: 'appointment',
      date: '2026-12-01',
      title: 'Fictional visit',
      includeLiving: false,
    });
    assert.equal(review.status, 409);
    assert.equal(review.body.error, 'unavailable');
    assert.equal((await good(page, `/trees/${tr.id}`)).id, tr.id);
    console.log('Core genealogy remains available and Calendar reports unavailable while disabled.');
  } finally {
    occ('app:enable', 'calendar');
    refreshWorkers();
  }
  await context.close();
} finally {
  await browser.close();
}
