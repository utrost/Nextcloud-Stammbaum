import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { login, tree, person, good, key, endpoint, grant } from './helpers.js';
import { fixtureDatabase } from './server-fixture.js';

const image = (orientation) => readFileSync(`tests/fixtures/exif-orientation-${orientation}.jpg`);
async function upload(page, treeId, bytes, personId = '') {
  const response = await page.request.post(`${endpoint}/trees/${treeId}/media`, {
    headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
    multipart: {
      requestId: key(),
      personId,
      file: { name: 'fictional-orientation.jpg', mimeType: 'image/jpeg', buffer: bytes },
    },
  });
  expect(response.status(), await response.text()).toBe(201);
  return response.json();
}
async function corners(page, bytes) {
  return page.evaluate(async (base64) => {
    const img = new Image();
    img.src = `data:image/png;base64,${base64}`;
    await img.decode();
    const canvas = document.createElement('canvas');
    canvas.width = img.width;
    canvas.height = img.height;
    const context = canvas.getContext('2d');
    context.drawImage(img, 0, 0);
    const sample = (x, y) => {
      const [r, g, b] = context.getImageData(x, y, 1, 1).data;
      if (r > 170 && g < 100 && b < 100) return 'R';
      if (g > 130 && r < 100 && b < 120) return 'G';
      if (b > 150 && r < 100 && g < 100) return 'B';
      if (r > 170 && g > 130 && b < 100) return 'Y';
      return `${r},${g},${b}`;
    };
    return {
      width: img.width,
      height: img.height,
      colors: [
        sample(8, 8),
        sample(img.width - 9, 8),
        sample(8, img.height - 9),
        sample(img.width - 9, img.height - 9),
      ].join(''),
    };
  }, bytes.toString('base64'));
}

test('all eight EXIF orientations render once; originals and derived metadata remain private', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page);
  const p = await person(page, tr.id, 'Fictional orientation');
  const expected = ['RG BY', 'GR YB', 'YB GR', 'BY RG', 'RB GY', 'BR YG', 'YG BR', 'GY RB'].map((v) =>
    v.replace(' ', ''),
  );
  const revision = (await good(page, `/trees/${tr.id}`)).revision;
  for (let orientation = 1; orientation <= 8; orientation++) {
    const bytes = image(orientation),
      medium = await upload(page, tr.id, bytes, p.id);
    expect(medium.sha256).toBe(createHash('sha256').update(bytes).digest('hex'));
    const response = await page.request.get(`${endpoint}/trees/${tr.id}/media/${medium.id}/preview`);
    expect(response.status()).toBe(200);
    const preview = await response.body();
    expect(preview.includes(Buffer.from('Fictional EXIF description'))).toBe(false);
    expect(preview.includes(Buffer.from('Exif'))).toBe(false);
    expect(await corners(page, preview)).toEqual({
      width: orientation >= 5 ? 60 : 80,
      height: orientation >= 5 ? 80 : 60,
      colors: expected[orientation - 1],
    });
    expect(
      await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${medium.id}/original`)).body(),
    ).toEqual(bytes);
    fixtureDatabase('UPDATE *PREFIX*stb_media_people SET crop_json = ? WHERE media_id = ?', [
      JSON.stringify({ x: 0, y: 0, width: 0.5, height: 0.5 }),
      medium.id,
    ]);
    const portrait = await page.request.get(
      `${endpoint}/trees/${tr.id}/media/${medium.id}/preview?person=${p.id}`,
    );
    expect(portrait.status()).toBe(200);
    expect((await corners(page, await portrait.body())).colors).toBe('RRRR');
  }
  // Uploads change the tree; viewing derivatives never does.
  expect((await good(page, `/trees/${tr.id}`)).revision).toBe(revision + 8);
});

test('missing and invalid EXIF use raw orientation; legacy crop keeps its intended pixels', async ({
  page,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Fictional crop');
  const noExif = await upload(page, tr.id, image('none'));
  const invalid = Buffer.from(image(1));
  const entry = invalid.indexOf(Buffer.from([0x01, 0x12, 0x00, 0x03]));
  expect(entry).toBeGreaterThan(0);
  invalid[entry + 9] = 9;
  const badExif = await upload(page, tr.id, invalid);
  for (const medium of [noExif, badExif]) {
    const preview = await page.request.get(`${endpoint}/trees/${tr.id}/media/${medium.id}/preview`);
    expect(await corners(page, await preview.body())).toEqual({ width: 80, height: 60, colors: 'RGBY' });
  }
  const bytes = image(6),
    medium = await upload(page, tr.id, bytes, p.id);
  fixtureDatabase('UPDATE *PREFIX*stb_media_people SET crop_json = ? WHERE media_id = ?', [
    JSON.stringify({ x: 0, y: 0, width: 0.5, height: 0.5 }),
    medium.id,
  ]);
  const upgraded = await good(page, `/trees/${tr.id}/media/${medium.id}`);
  expect(upgraded.assignments[0].crop).toEqual({ x: 0.5, y: 0, width: 0.5, height: 0.5 });
  const url = `${endpoint}/trees/${tr.id}/media/${medium.id}/preview?person=${p.id}`;
  const before = await (await page.request.get(url)).body();
  expect((await corners(page, before)).colors).toBe('RRRR');
  const saved = await good(page, `/trees/${tr.id}/media/${medium.id}`, 'PUT', {
    revision: upgraded.revision,
    title: upgraded.title,
    visibility: upgraded.visibility,
    assignments: upgraded.assignments.map(({ personId, purpose, preferred, crop }) => ({
      personId,
      purpose,
      preferred,
      crop,
    })),
  });
  expect(
    JSON.parse(
      fixtureDatabase(
        'SELECT crop_json FROM *PREFIX*stb_media_people WHERE media_id = ?',
        [medium.id],
        true,
      )[0].crop_json,
    ).space,
  ).toBe('oriented');
  expect(await (await page.request.get(url)).body()).toEqual(before);
  expect(saved.assignments[0].crop).toEqual(upgraded.assignments[0].crop);
  expect(
    await (await page.request.get(`${endpoint}/trees/${tr.id}/media/${medium.id}/original`)).body(),
  ).toEqual(bytes);
});

test('oriented portrait crop and reset work in the browser; a warm preview cannot cross a hidden assignment', async ({
  page,
  browser,
}) => {
  await login(page);
  const tr = await tree(page),
    p = await person(page, tr.id, 'Fictional portrait'),
    hidden = await person(page, tr.id, 'Hidden subject', { visibility: 'management' }),
    bytes = image(6);
  await grant(page, tr.id, 'stb_viewer', 'viewer');
  await page.goto(`/index.php/apps/stammbaum/#tree=${tr.id}&view=people&person=${p.id}`);
  await page.locator('.details').getByRole('tab', { name: 'Media', exact: true }).click();
  await page.getByLabel('Choose original', { exact: true }).setInputFiles({
    name: 'fictional-oriented-portrait.jpg',
    mimeType: 'image/jpeg',
    buffer: bytes,
  });
  await page.getByRole('combobox', { name: 'Visibility' }).selectOption('members');
  await page.getByRole('button', { name: 'Upload original', exact: true }).click();
  await page.getByRole('button', { name: 'Edit media', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Purpose', { exact: true }).selectOption('portrait');
  await dialog.getByRole('checkbox', { name: 'Preferred portrait', exact: true }).check();
  await dialog.getByLabel('Left (%)', { exact: true }).fill('25');
  await dialog.getByLabel('Width (%)', { exact: true }).fill('50');
  await dialog.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.person-portrait')).toHaveJSProperty('naturalWidth', 30);
  const medium = (await good(page, `/trees/${tr.id}/media?person=${p.id}`)).items[0];
  const original = `${endpoint}/trees/${tr.id}/media/${medium.id}/original`;
  expect(await (await page.request.get(original)).body()).toEqual(bytes);
  await page.getByRole('button', { name: 'Edit media', exact: true }).click();
  await dialog.getByRole('button', { name: 'Reset crop', exact: true }).click();
  await dialog.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.person-portrait')).toHaveJSProperty('naturalWidth', 60);
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/oriented-portrait-${width}.png`, fullPage: true });
  }
  const context = await browser.newContext(),
    viewer = await context.newPage();
  await login(viewer, 'stb_viewer');
  const preview = `${endpoint}/trees/${tr.id}/media/${medium.id}/preview?person=${p.id}`;
  expect((await viewer.request.get(preview)).status()).toBe(200);
  const current = await good(page, `/trees/${tr.id}/media/${medium.id}`);
  await good(page, `/trees/${tr.id}/media/${medium.id}`, 'PUT', {
    revision: current.revision,
    title: current.title,
    visibility: current.visibility,
    assignments: [
      ...current.assignments.map(({ personId, purpose, preferred, crop }) => ({
        personId,
        purpose,
        preferred,
        crop,
      })),
      { personId: hidden.id, purpose: 'attachment' },
    ],
  });
  expect((await viewer.request.get(preview)).status()).toBe(404);
  expect((await viewer.request.get(original)).status()).toBe(404);
  expect(await (await page.request.get(original)).body()).toEqual(bytes);
  await context.close();
});
