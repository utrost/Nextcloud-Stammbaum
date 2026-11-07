from pathlib import Path
import json,tarfile,hashlib,gzip,io
root=Path(__file__).resolve().parents[1]
version=json.loads((root/'package.json').read_text())['version']
asset=json.loads((root/'appinfo/assets.json').read_text())['name']
files=[]
for directory in ['appinfo','lib','templates','img']:
    files.extend(p for p in (root/directory).rglob('*') if p.is_file())
files += [root/'js'/f'{asset}.js',root/'css'/f'{asset}.css',root/'LICENSE',root/'THIRD-PARTY-NOTICES']
build=root/'build';build.mkdir(exist_ok=True)
archive=build/f'stammbaum-{version}.tar.gz'
with archive.open('wb') as raw:
    with gzip.GzipFile(fileobj=raw,mode='wb',mtime=0,filename='') as compressed:
        with tarfile.open(fileobj=compressed,mode='w') as tar:
            for p in sorted(files):
                data=p.read_bytes();info=tarfile.TarInfo('stammbaum/'+p.relative_to(root).as_posix())
                info.size=len(data);info.mode=0o644;info.mtime=0;info.uid=info.gid=0
                tar.addfile(info,io.BytesIO(data))
digest=hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix(archive.suffix+'.sha256').write_text(f'{digest}  {archive.name}\n')
print(f'{archive.relative_to(root)}\nSHA256 {digest}')
