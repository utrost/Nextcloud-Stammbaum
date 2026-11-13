import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { fixture, good, key, login, person, request, tree } from './helpers.js';

test('collection discovery pages only the current account and offers later compatible choices', async ({
  page,
  browser,
}) => {
  test.setTimeout(240000);
  expect(fixture.container).toBe('stammbaum-iteration-nc');
  await login(page);
  const prefix = `zz-stb-page-${key().slice(0, 8)}`;
  const names = Array.from({ length: 52 }, (_, i) => `${prefix}-${String(i).padStart(2, '0')}`);
  const occ = (...args) =>
    execFileSync('docker', ['exec', '-u', 'www-data', fixture.container, 'php', 'occ', ...args], {
      stdio: 'pipe',
    });
  const created = [];
  try {
    for (const name of names) {
      occ('dav:create-calendar', fixture.user, name);
      created.push(name);
    }
    for (const kind of ['tasks', 'calendar']) {
      const first = await good(page, `/integrations/${kind}/collections?page=0`);
      expect(first.available).toBe(true);
      expect(first.items.length + first.readOnlyItems.length).toBe(50);
      expect(first.nextPage).toBe(1);
      const second = await good(page, `/integrations/${kind}/collections?page=1`);
      expect(second.nextPage).toBeNull();
      expect(second.items.some((item) => item.uri === names.at(-1))).toBe(true);
      const uris = [...first.items, ...first.readOnlyItems, ...second.items, ...second.readOnlyItems].map(
        (item) => item.uri,
      );
      expect(new Set(uris).size).toBe(uris.length);
      expect(first.items.map((item) => item.uri)).toEqual([...first.items.map((item) => item.uri)].sort());
      expect((await request(page, `/integrations/${kind}/collections?page=-1`)).status).toBe(422);
    }
    const otherContext = await browser.newContext({ baseURL: fixture.baseURL });
    try {
      const other = await otherContext.newPage();
      await login(other, 'stb_viewer');
      for (const kind of ['tasks', 'calendar']) {
        const collections = await good(other, `/integrations/${kind}/collections?page=0`);
        expect(
          [...collections.items, ...collections.readOnlyItems].some((item) => item.uri.startsWith(prefix)),
        ).toBe(false);
      }
    } finally {
      await otherContext.close();
    }
    const tr = await tree(page);
    const p = await person(page, tr.id, 'Fictional paged researcher', { livingStatus: 'deceased' });
    await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
    await page.getByRole('button', { name: /Create research task|Rechercheaufgabe erstellen/ }).click();
    await page.getByRole('button', { name: /Load more task lists|Weitere Aufgabenlisten laden/ }).focus();
    await page.keyboard.press('Enter');
    await expect(
      page
        .getByRole('combobox', { name: /Task list|Aufgabenliste/ })
        .locator(`option[value="${names.at(-1)}"]`),
    ).toHaveCount(1);
    await page
      .getByRole('button', { name: /Add to Nextcloud Calendar|Zu Nextcloud Calendar hinzufügen/ })
      .click();
    await page.getByRole('button', { name: /Load more calendars|Weitere Kalender laden/ }).focus();
    await page.keyboard.press('Enter');
    await expect(
      page.getByRole('combobox', { name: /Calendar|Kalender/ }).locator(`option[value="${names.at(-1)}"]`),
    ).toHaveCount(1);
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'test-results/paged-collections-320.png', fullPage: true });
  } finally {
    const failures = [];
    for (const name of created) {
      try {
        occ('dav:delete-calendar', '--force', '--no-interaction', fixture.user, name);
      } catch {
        failures.push(name);
      }
    }
    expect(failures).toEqual([]);
  }
});
