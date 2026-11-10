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
  const p = await person(page, tr.id, 'Optional Contacts', { livingStatus: 'deceased' });
  occ('app:disable', 'contacts');
  refreshWorkers();
  try {
    let books;
    for (let attempt = 0; attempt < 10; attempt++) {
      books = await good(page, `/trees/${tr.id}/contacts/books?person=${p.id}`);
      if (!books.available) break;
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    assert.equal(books.available, false);
    assert.deepEqual(books.items, []);
    const search = await request(page, `/trees/${tr.id}/contacts/search`, 'POST', {
      personId: p.id,
      bookKey: 'unavailable',
      query: 'Example',
      page: 0,
    });
    assert.equal(search.status, 409);
    assert.equal(search.body.error, 'unavailable');
    assert.equal((await good(page, `/trees/${tr.id}`)).id, tr.id);
    console.log('Core genealogy remains available and Contacts reports unavailable while disabled.');
  } finally {
    occ('app:enable', 'contacts');
    refreshWorkers();
  }
  await context.close();
} finally {
  await browser.close();
}
