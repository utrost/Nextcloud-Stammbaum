"""Verify immutable archive resources and the committed backlog's structural coverage."""
from pathlib import Path
from html.parser import HTMLParser
import hashlib
import json
import re

root = Path(__file__).resolve().parents[1]
archive = root / 'docs' / 'intial-spec'
manifest = json.loads((archive / 'archive-manifest.json').read_text())
assert len(manifest['files']) == 100
for item in manifest['files']:
    assert hashlib.sha256((archive / item['archived_path']).read_bytes()).hexdigest() == item['archived_sha256'], item['archived_path']

class Assets(HTMLParser):
    def handle_starttag(self, tag, attrs):
        for key, value in attrs:
            if key in ['src', 'href'] and value and not value.startswith(('https:', 'http:', '#', 'data:', 'mailto:')):
                assert (archive / value.split('#')[0]).exists(), value

Assets().feed((archive / 'current-product-specification.html').read_text())
backlog = json.loads((root / 'docs' / 'backlog.json').read_text())
assert len(backlog['issues']) == 59
keys = {issue['key'] for issue in backlog['issues']}
assert len(keys) == 59
examples = {e for issue in backlog['issues'] if not issue['deferred'] and issue['key'] != 'I09' for e in issue['examples']}
assert all(f'A{i:02}' in examples for i in range(1, 31))
for issue in backlog['issues']:
    assert all(d in keys for d in issue['dependencies']), issue['key']
    assert issue['url'].endswith('/' + str(issue['number']))
for doc in [root / 'README.md', *list((root / 'docs').glob('*.md'))]:
    for link in re.findall(r'\]\(([^)]+)\)', doc.read_text()):
        if not link.startswith(('http:', 'https:', '#', 'mailto:', '/')):
            assert (doc.parent / link.split('#')[0]).exists(), (doc.name, link)
print('100 archive hashes, HTML resources, local documentation links and A01–A30 backlog coverage passed.')
