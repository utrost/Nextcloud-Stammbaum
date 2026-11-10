import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { login, request, good, tree, person, key } from './helpers.js';
import { fixture } from './helpers.js';

const bookUri = 'stammbaum-contacts';
const cardName = (id) => `Fictional Contact ${id.slice(0, 10)}`;

async function cardDav(page, uid, method = 'GET', body, selectedBookUri = bookUri) {
  const user = await page.locator('#stammbaum-app').getAttribute('data-user-id');
  return page.evaluate(
    async ({ user, bookUri, uid, method, body }) => {
      const root = `${window.OC?.getRootPath?.() || window.OC?.webroot || ''}/remote.php/dav/addressbooks/users/${encodeURIComponent(user)}/${encodeURIComponent(bookUri)}`;
      const url = `${root}/${uid}.vcf`;
      const previous =
        method === 'PUT' && body?.update ? await fetch(url, { credentials: 'same-origin' }) : null;
      const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
          requesttoken: window.OC.requestToken,
          ...(method === 'PUT'
            ? {
                'Content-Type': 'text/vcard; charset=utf-8',
                ...(body?.update
                  ? { 'If-Match': previous?.headers.get('ETag') || '' }
                  : { 'If-None-Match': '*' }),
              }
            : {}),
        },
        ...(method === 'PUT' ? { body: body.text } : {}),
      });
      return { status: response.status, text: await response.text(), etag: response.headers.get('ETag') };
    },
    { user, bookUri: selectedBookUri, uid, method, body },
  );
}

async function putFictionalCard(page, uid, extra = {}) {
  const text = [
    'BEGIN:VCARD',
    'VERSION:4.0',
    `UID:${uid}`,
    `FN:${extra.name || cardName(uid)}`,
    'N:Contact;Fictional;;;',
    `EMAIL;TYPE=HOME:${extra.email || `mira-${uid.slice(0, 8)}@example.invalid`}`,
    'TEL;TYPE=WORK:+49 30 555 0101',
    'ADR;TYPE=HOME:;;Example Street 5;Example Town;;12345;Example Country',
    'BDAY:1900-02-03',
    'END:VCARD',
    '',
  ].join('\r\n');
  const result = await cardDav(page, uid, 'PUT', { text, update: !!extra.update }, extra.bookUri || bookUri);
  expect([201, 204]).toContain(result.status);
}

async function ownerBook(page, treeId, personId) {
  const response = await good(page, `/trees/${treeId}/contacts/books?person=${personId}`);
  expect(response.available).toBe(true);
  const book = response.items.find((b) => b.name === bookUri);
  expect(book).toBeTruthy();
  return book;
}

async function searchCard(page, treeId, personId, bookKey, uid) {
  const response = await good(page, `/trees/${treeId}/contacts/search`, 'POST', {
    personId,
    bookKey,
    query: cardName(uid),
    page: 0,
  });
  return response.items.find((c) => c.cardUid === uid);
}

test('private Contacts link, reviewed confidential copy and unlink keep independent ownership', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const uid = key();
  try {
    await putFictionalCard(page, uid);
    const book = await ownerBook(page, tr.id, p.id);
    const system = (await good(page, `/trees/${tr.id}/contacts/books?person=${p.id}`)).items.find(
      (item) => item.system,
    );
    expect(system).toBeTruthy();
    const systemSearch = await good(page, `/trees/${tr.id}/contacts/search`, 'POST', {
      personId: p.id,
      bookKey: system.key,
      query: 'stb',
      page: 0,
    });
    expect(Array.isArray(systemSearch.items)).toBe(true);
    const candidate = await searchCard(page, tr.id, p.id, book.key, uid);
    expect(candidate.name).toBe(cardName(uid));
    expect(candidate.fields.email[0].value).toContain('@example.invalid');
    expect(candidate.fields.address[0].display).toContain('Example Street 5');
    expect(candidate.fields.address[0].display).not.toContain(';');
    expect(candidate.fields.address[0].components[2]).toBe('Example Street 5');
    expect(candidate.birthdayPresent).toBe(true);
    const revision = (await good(page, `/trees/${tr.id}`)).revision;
    const review = await good(page, `/trees/${tr.id}/contacts/review-link`, 'POST', {
      personId: p.id,
      bookKey: book.key,
      cardUid: uid,
      cardUri: candidate.cardUri,
    });
    await putFictionalCard(page, uid, {
      email: `review-changed-${uid.slice(0, 8)}@example.invalid`,
      update: true,
    });
    expect(
      (
        await request(page, `/trees/${tr.id}/contacts/links`, 'POST', {
          review,
          requestId: key(),
        })
      ).status,
    ).toBe(412);
    const currentReview = await good(page, `/trees/${tr.id}/contacts/review-link`, 'POST', {
      personId: p.id,
      bookKey: book.key,
      cardUid: uid,
      cardUri: candidate.cardUri,
    });
    const requestId = key();
    const linked = await good(
      page,
      `/trees/${tr.id}/contacts/links`,
      'POST',
      { review: currentReview, requestId },
      201,
    );
    const retry = await good(
      page,
      `/trees/${tr.id}/contacts/links`,
      'POST',
      { review: currentReview, requestId },
      201,
    );
    expect(retry.id).toBe(linked.id);
    expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision);
    expect((await good(page, `/trees/${tr.id}/contacts/birthday?person=${p.id}`)).linkedContactBirthday).toBe(
      true,
    );

    const copyReview = await good(page, `/trees/${tr.id}/contacts/links/${linked.id}/review-copy`, 'POST', {
      kind: 'email',
      index: 0,
      mode: 'append',
    });
    expect(copyReview.current).toEqual([]);
    expect(copyReview.proposed.value).toContain('@example.invalid');
    const copied = await good(
      page,
      `/trees/${tr.id}/contacts/links/${linked.id}/copy`,
      'POST',
      {
        review: copyReview,
        requestId: key(),
        acknowledged: true,
      },
      201,
    );
    expect(copied.visibility).toBe('management');
    expect(copied.type).toBe('email');
    expect(copied.text).toContain('@example.invalid');
    expect(copied.original.fields.some((f) => f.value === 'Nextcloud Contacts')).toBe(true);
    expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision + 1);

    await putFictionalCard(page, uid, { email: `changed-${uid.slice(0, 8)}@example.invalid`, update: true });
    const changed = await good(page, `/trees/${tr.id}/contacts/links?person=${p.id}`);
    expect(changed.items[0].status).toBe('changed');
    expect(
      (
        await request(page, `/trees/${tr.id}/contacts/links/${linked.id}/copy`, 'POST', {
          review: copyReview,
          requestId: key(),
          acknowledged: true,
        })
      ).status,
    ).toBe(412);
    const replaceReview = await good(
      page,
      `/trees/${tr.id}/contacts/links/${linked.id}/review-copy`,
      'POST',
      { kind: 'email', index: 0, mode: 'replace', replaceId: copied.id },
    );
    expect(replaceReview.current.find((r) => r.id === copied.id)?.value).toBe(copied.text);
    await good(page, `/trees/${tr.id}/records/${copied.id}`, 'PUT', {
      originId: p.id,
      category: 'contact',
      type: 'email',
      title: copied.title,
      text: 'Deliberate manual correction',
      fields: copied.fields,
      visibility: 'management',
      revision: copied.revision,
      participants: [{ personId: p.id, role: 'subject' }],
    });
    expect(
      (
        await request(page, `/trees/${tr.id}/contacts/links/${linked.id}/copy`, 'POST', {
          review: replaceReview,
          requestId: key(),
          acknowledged: true,
        })
      ).status,
    ).toBe(412);
    expect((await good(page, `/trees/${tr.id}/records/${copied.id}`)).text).toBe(
      'Deliberate manual correction',
    );
    await good(page, `/trees/${tr.id}/contacts/links/${linked.id}`, 'DELETE');
    expect((await good(page, `/trees/${tr.id}/contacts/links?person=${p.id}`)).items).toEqual([]);
    expect((await cardDav(page, uid)).status).toBe(200);
    expect((await good(page, `/trees/${tr.id}/records/${copied.id}`)).text).toBe(
      'Deliberate manual correction',
    );
    expect((await good(page, `/trees/${tr.id}/contacts/birthday?person=${p.id}`)).linkedContactBirthday).toBe(
      false,
    );
  } finally {
    await cardDav(page, uid, 'DELETE');
  }
});

test('linking needs readable card and person, but no export; other accounts cannot see links or copy confidential records', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const hidden = await person(page, tr.id, 'Hidden', { visibility: 'management' });
  const ownerBookKey = (await ownerBook(page, tr.id, p.id)).key;
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_viewer',
    role: 'viewer',
    exportAllowed: false,
    revision: 0,
  });
  const context = await browser.newContext();
  const viewer = await context.newPage();
  const uid = key();
  try {
    await login(viewer, 'stb_viewer');
    const book = await ownerBook(viewer, tr.id, p.id);
    expect(book.key).not.toBe(ownerBookKey);
    expect(
      (
        await request(viewer, `/trees/${tr.id}/contacts/search`, 'POST', {
          personId: p.id,
          bookKey: ownerBookKey,
          query: 'Fictional',
          page: 0,
        })
      ).status,
    ).toBe(404);
    expect((await request(viewer, `/trees/${tr.id}/contacts/books?person=${hidden.id}`)).status).toBe(404);
    await putFictionalCard(viewer, uid);
    const card = await searchCard(viewer, tr.id, p.id, book.key, uid);
    const review = await good(viewer, `/trees/${tr.id}/contacts/review-link`, 'POST', {
      personId: p.id,
      bookKey: book.key,
      cardUid: uid,
      cardUri: card.cardUri,
    });
    const linked = await good(
      viewer,
      `/trees/${tr.id}/contacts/links`,
      'POST',
      { review, requestId: key() },
      201,
    );
    expect((await good(page, `/trees/${tr.id}/contacts/links?person=${p.id}`)).items).toEqual([]);
    expect(
      (
        await request(viewer, `/trees/${tr.id}/contacts/links/${linked.id}/review-copy`, 'POST', {
          kind: 'email',
          index: 0,
          mode: 'append',
        })
      ).status,
    ).toBe(403);
    const member = (await good(page, `/trees/${tr.id}/members`)).items.find((m) => m.userId === 'stb_viewer');
    await good(page, `/trees/${tr.id}/members`, 'POST', {
      userId: 'stb_viewer',
      role: 'viewer',
      exportAllowed: false,
      revision: member.revision,
      remove: true,
    });
    expect((await request(viewer, `/trees/${tr.id}/contacts/links?person=${p.id}`)).status).toBe(404);
    await good(viewer, `/trees/${tr.id}/contacts/links/${linked.id}`, 'DELETE');
    expect((await cardDav(viewer, uid)).status).toBe(200);
  } finally {
    await cardDav(viewer, uid, 'DELETE');
    await context.close();
  }
});

test('same-name cards need explicit selection and a deleted card becomes unavailable without deleting a link', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const first = key(),
    second = key(),
    name = `Fictional Twins ${first.slice(0, 8)}`;
  try {
    await putFictionalCard(page, first, { name });
    await putFictionalCard(page, second, { name });
    const book = await ownerBook(page, tr.id, p.id);
    const result = await good(page, `/trees/${tr.id}/contacts/search`, 'POST', {
      personId: p.id,
      bookKey: book.key,
      query: name,
      page: 0,
    });
    expect(result.items.filter((c) => [first, second].includes(c.cardUid))).toHaveLength(2);
    const selected = result.items.find((c) => c.cardUid === second);
    const review = await good(page, `/trees/${tr.id}/contacts/review-link`, 'POST', {
      personId: p.id,
      bookKey: book.key,
      cardUid: second,
      cardUri: selected.cardUri,
    });
    const linked = await good(
      page,
      `/trees/${tr.id}/contacts/links`,
      'POST',
      { review, requestId: key() },
      201,
    );
    const other = result.items.find((c) => c.cardUid === first);
    const firstReview = await good(page, `/trees/${tr.id}/contacts/review-link`, 'POST', {
      personId: p.id,
      bookKey: book.key,
      cardUid: first,
      cardUri: other.cardUri,
    });
    await good(
      page,
      `/trees/${tr.id}/contacts/links`,
      'POST',
      { review: firstReview, requestId: key() },
      201,
    );
    expect(
      (await good(page, `/trees/${tr.id}/contacts/links?person=${p.id}`)).items
        .map((item) => item.card.cardUid)
        .sort(),
    ).toEqual([first, second].sort());
    expect((await cardDav(page, second, 'DELETE')).status).toBe(204);
    const unavailable = await good(page, `/trees/${tr.id}/contacts/links?person=${p.id}`);
    expect(unavailable.items.find((item) => item.id === linked.id)).toMatchObject({
      id: linked.id,
      status: 'unavailable',
      card: null,
    });
    expect(unavailable.items.find((item) => item.card?.cardUid === first)?.status).toBe('current');
    expect(
      (
        await request(page, `/trees/${tr.id}/contacts/links/${linked.id}/review-copy`, 'POST', {
          kind: 'email',
          index: 0,
          mode: 'append',
        })
      ).status,
    ).toBe(404);
    await good(page, `/trees/${tr.id}/contacts/links/${linked.id}`, 'DELETE');
  } finally {
    await cardDav(page, first, 'DELETE');
    await cardDav(page, second, 'DELETE');
  }
});

test('editor can privately link but cannot create management-only genealogy contact records', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_editor',
    role: 'editor',
    exportAllowed: false,
    revision: 0,
  });
  const context = await browser.newContext();
  const editor = await context.newPage();
  const uid = key();
  try {
    await login(editor, 'stb_editor');
    await putFictionalCard(editor, uid);
    const book = await ownerBook(editor, tr.id, p.id);
    const card = await searchCard(editor, tr.id, p.id, book.key, uid);
    const review = await good(editor, `/trees/${tr.id}/contacts/review-link`, 'POST', {
      personId: p.id,
      bookKey: book.key,
      cardUid: uid,
      cardUri: card.cardUri,
    });
    const linked = await good(
      editor,
      `/trees/${tr.id}/contacts/links`,
      'POST',
      { review, requestId: key() },
      201,
    );
    expect(
      (
        await request(editor, `/trees/${tr.id}/contacts/links/${linked.id}/review-copy`, 'POST', {
          kind: 'email',
          index: 0,
          mode: 'append',
        })
      ).status,
    ).toBe(403);
  } finally {
    await cardDav(editor, uid, 'DELETE');
    await context.close();
  }
});

test('a shared read-only address book can be searched and linked without obtaining write access', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  await good(page, `/trees/${tr.id}/members`, 'POST', {
    userId: 'stb_viewer',
    role: 'viewer',
    exportAllowed: false,
    revision: 0,
  });
  const sharedUri = `stb-shared-${key().slice(0, 8)}`;
  const uid = key();
  const occ = (...args) =>
    execFileSync('docker', ['exec', '-u', 'www-data', fixture.container, 'php', 'occ', ...args], {
      stdio: 'pipe',
    });
  expect(fixture.container).toBe('stammbaum-iteration-nc');
  occ('dav:create-addressbook', fixture.user, sharedUri);
  const context = await browser.newContext();
  const viewer = await context.newPage();
  try {
    await putFictionalCard(page, uid, { bookUri: sharedUri });
    const php = [
      "define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';",
      '$db=\\OCP\\Server::get(\\OCP\\IDBConnection::class);',
      "$id=$db->executeQuery('SELECT id FROM *PREFIX*addressbooks WHERE principaluri = ? AND uri = ?',['principals/users/" +
        fixture.user +
        "','" +
        sharedUri +
        "'])->fetchOne();",
      "if(!$id)throw new RuntimeException('Fixture address book missing');",
      '$share=\\OCP\\Server::get(\\OCA\\DAV\\CardDAV\\Sharing\\Service::class);',
      "$share->shareWith((int)$id,'principals/users/stb_viewer',\\OCA\\DAV\\DAV\\Sharing\\Backend::ACCESS_READ);",
      'echo $id;',
    ].join('');
    const sharedId = execFileSync('docker', ['exec', '-u', 'www-data', fixture.container, 'php', '-r', php], {
      stdio: 'pipe',
    })
      .toString()
      .trim();
    expect(sharedId).toMatch(/^\d+$/);
    await login(viewer, 'stb_viewer');
    const books = await good(viewer, `/trees/${tr.id}/contacts/books?person=${p.id}`);
    const shared = books.items.find((book) => book.key === sharedId);
    expect(shared, JSON.stringify(books.items)).toBeTruthy();
    expect(shared).toMatchObject({ shared: true, readOnly: true });
    const candidate = await searchCard(viewer, tr.id, p.id, shared.key, uid);
    expect(candidate.cardUid).toBe(uid);
    const review = await good(viewer, `/trees/${tr.id}/contacts/review-link`, 'POST', {
      personId: p.id,
      bookKey: shared.key,
      cardUid: uid,
      cardUri: candidate.cardUri,
    });
    const linked = await good(
      viewer,
      `/trees/${tr.id}/contacts/links`,
      'POST',
      {
        review,
        requestId: key(),
      },
      201,
    );
    expect(linked.card.cardUid).toBe(uid);
    expect(
      (
        await request(viewer, `/trees/${tr.id}/contacts/links/${linked.id}/review-copy`, 'POST', {
          kind: 'email',
          index: 0,
          mode: 'append',
        })
      ).status,
    ).toBe(403);
    execFileSync(
      'docker',
      [
        'exec',
        '-u',
        'www-data',
        fixture.container,
        'php',
        '-r',
        "define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';" +
          '$share=\\OCP\\Server::get(\\OCA\\DAV\\CardDAV\\Sharing\\Service::class);' +
          `$share->deleteShare(${sharedId},'principals/users/stb_viewer');`,
      ],
      { stdio: 'pipe' },
    );
    const unavailable = await good(viewer, `/trees/${tr.id}/contacts/links?person=${p.id}`);
    expect(unavailable.items.find((item) => item.id === linked.id)).toMatchObject({
      status: 'unavailable',
      card: null,
    });
  } finally {
    await context.close();
    const status = await page.evaluate(
      async ({ sharedUri, owner }) => {
        const url = `${window.OC.getRootPath()}/remote.php/dav/addressbooks/users/${encodeURIComponent(owner)}/${encodeURIComponent(sharedUri)}/`;
        const response = await fetch(url, {
          method: 'DELETE',
          credentials: 'same-origin',
          headers: { requesttoken: window.OC.requestToken },
        });
        return response.status;
      },
      { sharedUri, owner: fixture.user },
    );
    expect(status).toBe(204);
  }
});

test('person UI searches, reviews, links, compares, copies and unlinks a card at desktop and phone widths', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const uid = key();
  try {
    await putFictionalCard(page, uid);
    await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
    await page.getByRole('button', { name: 'Link Nextcloud contact' }).click();
    await expect(page.getByRole('region', { name: 'Nextcloud contact links' })).toBeVisible();
    await page.getByRole('combobox', { name: 'Address book' }).selectOption({ label: bookUri });
    await page.getByRole('searchbox', { name: 'Find contact' }).fill(cardName(uid));
    await page.locator('.contact-search').getByRole('button', { name: 'Search contacts' }).click();
    const candidate = page.locator('.contact-candidates li').filter({ hasText: cardName(uid) });
    await expect(candidate).toBeVisible();
    await candidate.getByRole('button', { name: 'Review link' }).click();
    await expect(page.getByText('No contact details are copied into the tree by linking.')).toBeVisible();
    await page.getByRole('button', { name: 'Link contact', exact: true }).click();
    const linked = page.locator('.contact-linked-card').filter({ hasText: cardName(uid) });
    await expect(linked).toBeVisible();
    await linked.getByRole('button', { name: 'Compare and copy' }).first().click();
    await expect(page.getByText('No saved value of this type yet.')).toBeVisible();
    await page.getByRole('button', { name: 'Review copy' }).click();
    await expect(page.getByText('Current card value')).toBeVisible();
    await page
      .getByRole('checkbox', { name: 'I understand this confidential copy is retained independently' })
      .check();
    for (const width of [1440, 390, 320]) {
      await page.setViewportSize({ width, height: 950 });
      await expect(page.getByRole('button', { name: 'Copy into genealogy' })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.screenshot({ path: `test-results/contact-copy-review-${width}.png`, fullPage: true });
    }
    await page.getByRole('button', { name: 'Copy into genealogy' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('tab', { name: 'Details' })).toHaveAttribute('aria-selected', 'true');
    await expect(
      page.locator('.record-card').filter({ hasText: 'Email copied from Nextcloud Contacts' }),
    ).toBeVisible();
    page.once('dialog', (dialog) => dialog.accept());
    await linked.getByRole('button', { name: 'Unlink contact' }).click();
    await expect(linked).toHaveCount(0);
    expect((await cardDav(page, uid)).status).toBe(200);
  } finally {
    await cardDav(page, uid, 'DELETE');
  }
});

test('German contact review remains usable by keyboard on a narrow phone', async ({ page }) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Mira', { livingStatus: 'deceased' });
  const uid = key();
  await page.getByLabel('Language', { exact: true }).selectOption('de');
  await expect.poll(async () => (await good(page, '/preferences')).locale).toBe('de');
  try {
    await putFictionalCard(page, uid);
    await page.setViewportSize({ width: 320, height: 950 });
    await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
    await page.getByRole('button', { name: 'Nextcloud-Kontakt verknüpfen' }).click();
    await page.getByRole('combobox', { name: 'Adressbuch' }).selectOption({ label: bookUri });
    await page.getByRole('searchbox', { name: 'Kontakt finden' }).fill(cardName(uid));
    await page.locator('.contact-search').getByRole('button', { name: 'Kontakte suchen' }).click();
    const candidate = page.locator('.contact-candidates li').filter({ hasText: cardName(uid) });
    await candidate.getByRole('button', { name: 'Verknüpfung prüfen' }).click();
    await expect(
      page.getByText('Durch die Verknüpfung werden keine Kontaktangaben in den Stammbaum kopiert.'),
    ).toBeVisible();
    for (const width of [1440, 390, 320]) {
      await page.setViewportSize({ width, height: 950 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.screenshot({ path: `test-results/contact-link-review-de-${width}.png`, fullPage: true });
    }
    await page.getByRole('button', { name: 'Kontakt verknüpfen', exact: true }).focus();
    await page.keyboard.press('Enter');
    const linked = page.locator('.contact-linked-card').filter({ hasText: cardName(uid) });
    await expect(linked).toBeVisible();
    await linked.getByRole('button', { name: 'Vergleichen und kopieren' }).first().click();
    await page.getByRole('button', { name: 'Kopie prüfen' }).click();
    await expect(
      page.getByRole('checkbox', {
        name: 'Ich verstehe, dass diese vertrauliche Kopie unabhängig aufbewahrt wird',
      }),
    ).toBeVisible();
  } finally {
    const state = await good(page, '/preferences');
    await good(page, '/preferences', 'PUT', { locale: 'en', revision: state.revision });
    await cardDav(page, uid, 'DELETE');
  }
});
