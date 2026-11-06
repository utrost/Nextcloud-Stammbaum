"""Install a verified runtime archive into an explicitly supported Alice container."""
import argparse
import hashlib
import json
import subprocess
import tarfile
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--container', choices=['stammbaum-iteration-nc', 'nextcloud'], default='stammbaum-iteration-nc')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
version = json.loads((root / 'package.json').read_text())['version']
archive = root / 'build' / f'stammbaum-{version}.tar.gz'
expected = archive.with_suffix(archive.suffix + '.sha256').read_text().split()[0]
assert hashlib.sha256(archive.read_bytes()).hexdigest() == expected
with tarfile.open(archive) as tar:
    assert all(m.isfile() and m.name.startswith('stammbaum/') and '..' not in Path(m.name).parts for m in tar.getmembers())
    assert not any(part in Path(n).parts for n in tar.getnames() for part in ['node_modules', '.git', 'docs', 'tests'])

def run(command, capture=False):
    result = subprocess.run(command, check=True, text=True, capture_output=capture)
    return result.stdout if capture else None

def occ(*command, capture=False):
    return run(['docker', 'exec', '-u', 'www-data', args.container, 'php', 'occ', *command], capture)

before = json.loads(occ('status', '--output=json', capture=True))
assert before['installed'] and not before['maintenance'] and not before['needsDbUpgrade'], 'Resolve the existing host state first.'
assert before['version'].startswith('34.'), 'Only Nextcloud 34 is currently supported.'
apps_before = json.loads(occ('app:list', '--output=json', capture=True))
run(['docker', 'cp', str(archive), args.container + ':/tmp/stammbaum-package.tar.gz'])
run(['docker', 'exec', '-u', 'root', args.container, 'sh', '-c',
     'mkdir -p /var/www/html/custom_apps && tar -xzf /tmp/stammbaum-package.tar.gz -C /var/www/html/custom_apps && chown -R www-data:www-data /var/www/html/custom_apps/stammbaum'])
occ('app:enable', 'stammbaum')
# Public NC32+ API runs only this app's migrations; no global upgrade or maintenance toggle.
upgrade = "define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';$apps=\\OCP\\Server::get(\\OCP\\App\\IAppManager::class);if($apps->isUpgradeRequired('stammbaum')&&!$apps->upgradeApp('stammbaum')){exit(1);}echo 'Stammbaum schema current.'.PHP_EOL;"
run(['docker', 'exec', '-u', 'www-data', args.container, 'php', '-r', upgrade])
run(['docker', 'exec', '-u', 'root', args.container, 'apachectl', '-k', 'graceful'])
after = json.loads(occ('status', '--output=json', capture=True))
apps_after = json.loads(occ('app:list', '--output=json', capture=True))
assert not after['maintenance'] and not after['needsDbUpgrade']
assert after['version'] == before['version']
for group in ['enabled', 'disabled']:
    assert {k:v for k,v in apps_before.get(group, {}).items() if k != 'stammbaum'} == {k:v for k,v in apps_after.get(group, {}).items() if k != 'stammbaum'}, 'A sibling app changed during deployment; inspect the concurrent change.'
assert apps_after['enabled']['stammbaum'] == version
record = {'version': version, 'sha256': expected, 'container': args.container, 'before': before, 'after': after, 'sibling_apps_unchanged': True}
(root / 'build' / f'deployment-{args.container}.json').write_text(json.dumps(record, indent=2) + '\n')
print(f'Installed {version} into {args.container}; archive SHA256 {expected}')
print('Nextcloud healthy; sibling app enablement and versions unchanged.')
