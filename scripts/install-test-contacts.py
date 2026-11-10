"""Install pinned Contacts only in the disposable Stammbaum browser fixture."""
import hashlib
import json
import subprocess
import tarfile
import urllib.request
from pathlib import Path

root = Path(__file__).resolve().parents[1]
fixture = json.loads((root / 'build/test-instance.json').read_text())
assert fixture['container'] == 'stammbaum-iteration-nc', 'Never install fixture Contacts on Alice.'
version = '8.9.3'
expected = '2844792ca52f25057ea0ee30b326cc3a63771ab7d82b1e52c39b2e344906ac8c'
url = f'https://github.com/nextcloud-releases/contacts/releases/download/v{version}/contacts-v{version}.tar.gz'
archive = root / 'build' / f'contacts-{version}-test.tar.gz'
if not archive.exists():
    with urllib.request.urlopen(url, timeout=60) as response:
        archive.write_bytes(response.read())
assert hashlib.sha256(archive.read_bytes()).hexdigest() == expected, 'Unexpected Contacts release bytes'
with tarfile.open(archive) as package:
    assert all(item.isfile() or item.isdir() for item in package.getmembers())
    assert all((item.name == 'contacts' or item.name.startswith('contacts/')) and '..' not in Path(item.name).parts for item in package.getmembers())

def run(*args):
    subprocess.run(args, check=True)

run('docker', 'cp', str(archive), 'stammbaum-iteration-nc:/tmp/contacts-test.tar.gz')
run('docker', 'exec', '-u', 'root', 'stammbaum-iteration-nc', 'sh', '-c',
    'tar -xzf /tmp/contacts-test.tar.gz -C /var/www/html/custom_apps && chown -R www-data:www-data /var/www/html/custom_apps/contacts')
run('docker', 'exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ', 'integrity:check-app', 'contacts')
run('docker', 'exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ', 'app:enable', 'contacts')
for user in [fixture['user'], 'stb_viewer', 'stb_editor', 'stb_manager']:
    created = subprocess.run(
        ['docker', 'exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ',
         'dav:create-addressbook', user, 'stammbaum-contacts'],
        capture_output=True, text=True,
    )
    if created.returncode and 'Duplicate entry' not in created.stderr:
        raise RuntimeError('Could not create fictional address book: ' + created.stderr[:300])
print(f'Disposable fixture has Contacts {version}, pinned SHA256 {expected}, and a fictional address book.')
