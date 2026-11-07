import { readFileSync } from 'node:fs';
import { expect } from '@playwright/test';
export const fixture = JSON.parse(readFileSync('build/test-instance.json', 'utf8'));
export const endpoint = '/index.php/apps/stammbaum/api/v1';
export const key = () => crypto.randomUUID().replaceAll('-', '');
export async function login(page, user = fixture.user) {
  await page.goto('/login');
  await page.locator('#user').fill(user);
  await page.locator('#password').fill(fixture.password);
  await page
    .locator('form[name="login"]')
    .getByRole('button', { name: /log in/i })
    .click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'));
  await page.goto('/index.php/apps/stammbaum/');
  await expect(page.locator('#stammbaum-app .brand')).toBeVisible();
}
export async function request(page, path, method = 'GET', body) {
  const token = await page.evaluate(() => window.OC.requestToken);
  const response = await page.request.fetch(endpoint + path, {
    method,
    headers: { requesttoken: token },
    ...(body === undefined ? {} : { data: body }),
  });
  let data;
  try {
    data = await response.json();
  } catch {
    data = { raw: (await response.text()).slice(0, 300) };
  }
  return { status: response.status(), body: data };
}
export async function good(page, path, method = 'GET', body, status = 200) {
  const r = await request(page, path, method, body);
  expect(r.status, JSON.stringify(r.body)).toBe(status);
  return r.body;
}
export async function tree(page) {
  return good(
    page,
    '/trees',
    'POST',
    {
      name: 'Fictional family ' + key().slice(0, 8),
      description: 'Automated fictional fixture',
      requestId: key(),
    },
    201,
  );
}
export async function person(page, tid, givenName, extra = {}) {
  return good(
    page,
    `/trees/${tid}/people`,
    'POST',
    { givenName, familyName: 'Example', ...extra, requestId: key() },
    201,
  );
}
export async function grant(page, tid, userId, role = 'viewer') {
  return good(page, `/trees/${tid}/members`, 'POST', { userId, role, revision: 0, exportAllowed: false });
}
export async function relative(page, tid, source, target, direction = 'child', parentType = 'biological') {
  const p = await good(page, `/trees/${tid}/people/${source.id}`),
    q = await good(page, `/trees/${tid}/people/${target.id}`);
  return request(page, `/trees/${tid}/people/${p.id}/relations`, 'POST', {
    personId: q.id,
    targetRevision: q.revision,
    revision: p.revision,
    direction,
    parentType,
    requestId: key(),
  });
}
