"""Install the pinned optional Tasks app only into the disposable browser fixture."""
import hashlib
import json
import subprocess
import tarfile
import urllib.request
from pathlib import Path

root = Path(__file__).resolve().parents[1]
fixture = json.loads((root / 'build/test-instance.json').read_text())
assert fixture['container'] == 'stammbaum-iteration-nc', 'Never install this fixture app on Alice.'
version = '0.18.1'
expected = '0c988d50531c9bfa246e6c31f3f95369adde1629ba70d15194915ab56e24687b'
url = f'https://github.com/nextcloud/tasks/releases/download/v{version}/tasks.tar.gz'
archive = root / 'build' / f'tasks-{version}-test.tar.gz'
if not archive.exists():
    with urllib.request.urlopen(url, timeout=60) as response:
        archive.write_bytes(response.read())
assert hashlib.sha256(archive.read_bytes()).hexdigest() == expected, 'Unexpected Tasks release bytes'
with tarfile.open(archive) as package:
    assert all(item.isfile() or item.isdir() for item in package.getmembers())
    assert all((item.name == 'tasks' or item.name.startswith('tasks/')) and '..' not in Path(item.name).parts for item in package.getmembers())

def run(*args):
    subprocess.run(args, check=True)

run('docker', 'cp', str(archive), 'stammbaum-iteration-nc:/tmp/tasks-test.tar.gz')
run('docker', 'exec', '-u', 'root', 'stammbaum-iteration-nc', 'sh', '-c',
    'tar -xzf /tmp/tasks-test.tar.gz -C /var/www/html/custom_apps && chown -R www-data:www-data /var/www/html/custom_apps/tasks')
run('docker', 'exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ', 'app:enable', 'tasks')
calendar = subprocess.run(
    ['docker', 'exec', '-u', 'www-data', 'stammbaum-iteration-nc', 'php', 'occ',
     'dav:create-calendar', fixture['user'], 'stammbaum-research'],
    capture_output=True, text=True,
)
if calendar.returncode and not ('Duplicate entry' in calendar.stderr and 'calendars_index' in calendar.stderr):
    raise RuntimeError('Could not create the fictional task calendar: ' + calendar.stderr[:300])
print(f'Disposable fixture has Tasks {version}, pinned SHA256 {expected}, and a fictional VTODO calendar.')
