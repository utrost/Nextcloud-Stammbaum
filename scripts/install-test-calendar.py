"""Install pinned Calendar only in the disposable Stammbaum browser fixture."""
import hashlib
import json
import subprocess
import tarfile
import urllib.request
from pathlib import Path

root = Path(__file__).resolve().parents[1]
fixture = json.loads((root / 'build/test-instance.json').read_text())
assert fixture['container'] == 'stammbaum-iteration-nc', 'Never install fixture Calendar on Alice.'
version = '6.6.5'
expected = 'e3afbc2a10db87920f5347279423422411010550a809b2902c0e8cf2f2492107'
url = f'https://github.com/nextcloud-releases/calendar/releases/download/v{version}/calendar-v{version}.tar.gz'
archive = root / 'build' / f'calendar-v{version}.tar.gz'
if not archive.exists():
    with urllib.request.urlopen(url, timeout=60) as response:
        archive.write_bytes(response.read())
assert hashlib.sha256(archive.read_bytes()).hexdigest() == expected, 'Unexpected Calendar release bytes'
with tarfile.open(archive) as package:
    assert all(item.isfile() or item.isdir() for item in package.getmembers())
    assert all((item.name == 'calendar' or item.name.startswith('calendar/')) and '..' not in Path(item.name).parts for item in package.getmembers())

def run(*args):
    subprocess.run(args, check=True)

run('docker', 'cp', str(archive), 'stammbaum-iteration-nc:/tmp/calendar-test.tar.gz')
run('docker', 'exec', '-u', 'root', 'stammbaum-iteration-nc', 'sh', '-c',
    'tar -xzf /tmp/calendar-test.tar.gz -C /var/www/html/custom_apps && chown -R www-data:www-data /var/www/html/custom_apps/calendar')
run('docker', 'exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ', 'integrity:check-app', 'calendar')
run('docker', 'exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ', 'app:enable', 'calendar')
for user in [fixture['user'], 'stb_viewer', 'stb_editor', 'stb_manager']:
    created = subprocess.run(
        ['docker', 'exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ',
         'dav:create-calendar', user, 'stammbaum-calendar'],
        capture_output=True, text=True,
    )
    if created.returncode and not ('Duplicate entry' in created.stderr and 'calendars_index' in created.stderr):
        raise RuntimeError('Could not create fictional calendar: ' + created.stderr[:300])
print(f'Disposable fixture has Calendar {version}, pinned SHA256 {expected}, and a fictional event calendar.')
