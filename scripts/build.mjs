import { execFileSync } from 'node:child_process';
import {
  mkdirSync,
  copyFileSync,
  readFileSync,
  writeFileSync,
  chmodSync,
  readdirSync,
  unlinkSync,
} from 'node:fs';
import { createHash } from 'node:crypto';
execFileSync(process.execPath, ['node_modules/vite/bin/vite.js', 'build'], { stdio: 'inherit' });
const version = JSON.parse(readFileSync('package.json')).version;
const hash = createHash('sha256')
  .update(readFileSync('dist/main.js'))
  .update(readFileSync('dist/main.css'))
  .digest('hex')
  .slice(0, 12);
const name = `main-${version}-${hash}`;
for (const dir of ['js', 'css']) {
  mkdirSync(dir, { recursive: true });
  for (const file of readdirSync(dir)) {
    if (/^main-.*\.(js|css)$/.test(file)) unlinkSync(`${dir}/${file}`);
  }
}
for (const [dir, ext] of [
  ['js', 'js'],
  ['css', 'css'],
]) {
  mkdirSync(dir, { recursive: true });
  copyFileSync(`dist/main.${ext}`, `${dir}/${name}.${ext}`);
  chmodSync(`${dir}/${name}.${ext}`, 0o644);
}
writeFileSync('appinfo/assets.json', JSON.stringify({ name, version }) + '\n');
