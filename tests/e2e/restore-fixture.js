import { execFileSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { createReadStream, closeSync, mkdtempSync, openSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fixture } from './helpers.js';

const source = 'stammbaum-iteration-nc';
const sourceDb = 'stammbaum-iteration-db';
const sourceNetwork = 'stammbaum-iteration';
const webRoot = '/var/www/html';
const dbClient = 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb';
const timeout = 120_000;

function docker(args, { inputFile, outputFile, env } = {}) {
  let input = 'ignore';
  let output = 'pipe';
  try {
    if (inputFile) input = openSync(inputFile, 'r');
    if (outputFile) output = openSync(outputFile, 'wx', 0o600);
    return execFileSync('docker', args, { encoding: 'utf8', env, timeout, stdio: [input, output, 'pipe'] });
  } catch (error) {
    // SQL and config output can contain credentials. Report the failed operation without dumping it.
    const detail = error.code || error.signal || `exit ${error.status}`;
    throw new Error(`Docker ${args[0]} failed (${detail})`);
  } finally {
    if (typeof input === 'number') closeSync(input);
    if (typeof output === 'number') closeSync(output);
  }
}

function inspectContainer(name) {
  return JSON.parse(
    docker([
      'container',
      'inspect',
      '--format',
      '{"image":{{json .Image}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}}}',
      name,
    ]),
  );
}

export function sourcePhp(...args) {
  return docker(['exec', '-u', 'www-data', source, 'php', ...args]);
}

const sourceOcc = (...args) => sourcePhp('occ', ...args);

export function verifyRecoverySource() {
  if (
    fixture.container !== source ||
    fixture.database !== sourceDb ||
    fixture.network !== sourceNetwork ||
    fixture.baseURL !== 'http://127.0.0.1:9096' ||
    fixture.user !== 'stb_owner'
  ) {
    throw new Error('Recovery requires the dedicated fictional Nextcloud fixture.');
  }
  const app = inspectContainer(source);
  const database = inspectContainer(sourceDb);
  for (const [container, destination] of [
    [app, webRoot],
    [database, '/var/lib/mysql'],
  ]) {
    const [mount] = container.mounts;
    if (
      Object.keys(container.networks).join() !== sourceNetwork ||
      container.mounts.length !== 1 ||
      mount?.Type !== 'volume' ||
      mount.Destination !== destination
    ) {
      throw new Error('The source fixture layout differs from the disposable Docker setup.');
    }
  }
  const status = JSON.parse(sourceOcc('status', '--output=json'));
  if (
    !status.installed ||
    status.maintenance ||
    status.needsDbUpgrade ||
    !status.version.startsWith('34.') ||
    sourceOcc('config:system:get', 'datadirectory').trim() !== `${webRoot}/data` ||
    sourceOcc('config:system:get', 'dbhost').trim() !== sourceDb ||
    sourceOcc('config:system:get', 'dbname').trim() !== 'nextcloud'
  ) {
    throw new Error('Expected a healthy Nextcloud 34 fixture with local data and its dedicated database.');
  }
  return { app: app.image, database: database.image, version: status.version };
}

async function sha256(path) {
  const hash = createHash('sha256');
  for await (const data of createReadStream(path)) hash.update(data);
  return hash.digest('hex');
}

export async function captureRecoveryBackup(images) {
  const directory = mkdtempSync(join(tmpdir(), 'stammbaum-recovery-'));
  const files = { database: join(directory, 'database.sql'), volume: join(directory, 'nextcloud.tar') };
  const cleanup = () => rmSync(directory, { recursive: true, force: true });
  try {
    sourceOcc('maintenance:mode', '--on');
    try {
      docker(['stop', source]);
      docker(
        [
          'exec',
          sourceDb,
          'sh',
          '-c',
          'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb-dump --user=nextcloud --single-transaction --default-character-set=utf8mb4 nextcloud',
        ],
        { outputFile: files.database },
      );
      docker(
        [
          'run',
          '--rm',
          '--network',
          'none',
          '--volumes-from',
          `${source}:ro`,
          '--entrypoint',
          'tar',
          images.app,
          '-cpf',
          '-',
          '-C',
          webRoot,
          '.',
        ],
        { outputFile: files.volume },
      );
    } finally {
      docker(['start', source]);
      sourceOcc('maintenance:mode', '--off');
    }
    return {
      files,
      checksums: { database: await sha256(files.database), volume: await sha256(files.volume) },
      cleanup,
    };
  } catch (error) {
    cleanup();
    throw error;
  }
}

export function recoveryTarget(images) {
  const name = `stammbaum-recovery-${randomBytes(6).toString('hex')}`;
  const database = `${name}-db`;
  const web = `${name}-web`;
  const mount = `type=volume,source=${name},target=${webRoot},volume-nocopy`;
  const cleanups = [];
  const php = (...args) => docker(['exec', '-u', 'www-data', web, 'php', ...args]);
  const offlineOcc = (...args) =>
    docker([
      'run',
      '--rm',
      '--network',
      name,
      '--mount',
      mount,
      '--user',
      'www-data',
      '--workdir',
      webRoot,
      '--entrypoint',
      'php',
      images.app,
      'occ',
      ...args,
    ]);

  return {
    php,
    hasWebContainer() {
      return Boolean(docker(['ps', '-a', '--filter', `name=^/${web}$`, '--format', '{{.Names}}']).trim());
    },
    async restore(backup, { afterDatabase } = {}) {
      for (const [part, path] of Object.entries(backup.files)) {
        if ((await sha256(path)) !== backup.checksums[part]) throw new Error(`Bad ${part} backup checksum`);
      }
      docker(['network', 'create', name]);
      cleanups.push(['network', 'rm', name]);
      docker(['volume', 'create', name]);
      cleanups.push(['volume', 'rm', name]);
      const password = docker(['exec', sourceDb, 'printenv', 'MARIADB_PASSWORD']).trim();
      if (!password) throw new Error('Missing disposable database password');
      docker(
        [
          'run',
          '-d',
          '--name',
          database,
          '--network',
          name,
          '--network-alias',
          sourceDb,
          '-e',
          'MARIADB_ROOT_PASSWORD',
          '-e',
          'MARIADB_DATABASE=nextcloud',
          '-e',
          'MARIADB_USER=nextcloud',
          '-e',
          'MARIADB_PASSWORD',
          images.database,
        ],
        {
          env: {
            ...process.env,
            MARIADB_ROOT_PASSWORD: randomBytes(32).toString('hex'),
            MARIADB_PASSWORD: password,
          },
        },
      );
      cleanups.push(['rm', '-f', '-v', database]);
      const ready = Date.now() + 60_000;
      for (;;) {
        try {
          docker(['exec', database, 'healthcheck.sh', '--connect', '--innodb_initialized']);
          break;
        } catch (error) {
          if (Date.now() >= ready) throw error;
          await delay(500);
        }
      }
      docker(['exec', '-i', database, 'sh', '-c', `${dbClient} --user=nextcloud nextcloud`], {
        inputFile: backup.files.database,
      });
      afterDatabase?.();
      docker(
        [
          'run',
          '--rm',
          '-i',
          '--network',
          'none',
          '--mount',
          mount,
          '--entrypoint',
          'tar',
          images.app,
          '-xpf',
          '-',
          '--numeric-owner',
          '-C',
          webRoot,
        ],
        { inputFile: backup.files.volume },
      );
      const status = JSON.parse(offlineOcc('status', '--output=json'));
      if (
        !status.installed ||
        !status.maintenance ||
        status.needsDbUpgrade ||
        status.version !== images.version
      ) {
        throw new Error('Restored Nextcloud must be installed, current, and in maintenance mode');
      }
      // Nextcloud hides app commands during maintenance. Keep HTTP and cron offline until this succeeds.
      offlineOcc('maintenance:mode', '--off');
      offlineOcc('stammbaum:invalidate-authority');
      docker([
        'run',
        '-d',
        '--name',
        web,
        '--network',
        name,
        '-p',
        '127.0.0.1::80',
        '--mount',
        mount,
        images.app,
      ]);
      cleanups.push(['rm', '-f', '-v', web]);
      const address = docker(['port', web, '80/tcp']).trim();
      if (!/^127\.0\.0\.1:\d+$/.test(address)) throw new Error('Restore must publish only to loopback');
      return `http://${address}`;
    },
    cleanup() {
      const errors = [];
      for (const args of cleanups.splice(0).reverse()) {
        try {
          docker(args);
        } catch (error) {
          errors.push(error);
        }
      }
      if (errors.length)
        throw new AggregateError(errors, 'Could not remove all disposable recovery resources');
    },
  };
}
