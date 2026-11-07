import { execFileSync } from 'node:child_process';
// This helper is deliberately pinned to the disposable fixture, never Alice's shared container.
export function fixtureDatabase(sql, params = [], read = false) {
  const encoded = Buffer.from(JSON.stringify({ sql, params, read })).toString('base64');
  const code = `define('OC_CONSOLE', true);require '/var/www/html/lib/base.php';$p=json_decode(base64_decode('${encoded}'),true);$db=\\OCP\\Server::get(\\OCP\\IDBConnection::class);$r=$p['read']?$db->executeQuery($p['sql'],$p['params'])->fetchAllAssociative():$db->executeStatement($p['sql'],$p['params']);echo json_encode($r);`;
  return JSON.parse(
    execFileSync('docker', ['exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', '-r', code], {
      encoding: 'utf8',
    }),
  );
}
