import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import de from '../../src/translations/de.js';
function files(dir) {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
    entry.isDirectory() ? files(`${dir}/${entry.name}`) : [`${dir}/${entry.name}`],
  );
}
test('literal UI labels and server validation messages have a German catalog entry', () => {
  const missing = [];
  for (const path of files('src').filter((path) => !path.includes('/translations/'))) {
    for (const match of readFileSync(path, 'utf8').matchAll(/\bt\(\s*'([^']+)'\s*[,)]/g)) {
      if (!Object.hasOwn(de, match[1])) missing.push(`${path}: ${match[1]}`);
    }
  }
  for (const path of files('lib')) {
    for (const match of readFileSync(path, 'utf8').matchAll(/new Problem\(\s*'[^']+',\s*'([^']+)'/g)) {
      if (!Object.hasOwn(de, match[1])) missing.push(`${path}: ${match[1]}`);
    }
  }
  assert.deepEqual(missing, []);
  for (const category of ['note', 'event', 'attribute', 'contact']) {
    assert.ok(Object.hasOwn(de, `New ${category}`));
  }
});
