"""Create an app-specific disposable NC34 fixture. Secrets stay in ignored build/."""
from pathlib import Path
import json,os,secrets,subprocess,time,sys
root=Path(__file__).resolve().parents[1]; state=root/'build/test-instance.json'
network='stammbaum-iteration'; container='stammbaum-iteration-nc'; db='stammbaum-iteration-db'
def run(args,env=None,check=True):
    return subprocess.run(args,env={**os.environ,**(env or {})},capture_output=True,text=True,check=check)
if state.exists():
    print('Fixture record already exists; reuse it, do not reset shared or existing data.');sys.exit(0)
for name in [container,db]:
    if run(['docker','inspect',name],check=False).returncode==0: raise SystemExit(f'{name} already exists without fixture record; inspect before continuing')
secret=secrets.token_urlsafe(30); password=secrets.token_urlsafe(30)
run(['docker','network','create',network])
run(['docker','run','-d','--name',db,'--network',network,'-e','MARIADB_ROOT_PASSWORD','-e','MARIADB_DATABASE=nextcloud','-e','MARIADB_USER=nextcloud','-e','MARIADB_PASSWORD','mariadb:11'],{'MARIADB_ROOT_PASSWORD':secret,'MARIADB_PASSWORD':secret})
for _ in range(60):
    if run(['docker','exec',db,'healthcheck.sh','--connect','--innodb_initialized'],check=False).returncode==0:break
    time.sleep(2)
else:raise SystemExit('Database did not become ready')
run(['docker','run','-d','--name',container,'--network',network,'-p','127.0.0.1:9096:80','-e','MYSQL_HOST='+db,'-e','MYSQL_DATABASE=nextcloud','-e','MYSQL_USER=nextcloud','-e','MYSQL_PASSWORD','-e','NEXTCLOUD_ADMIN_USER=stb_owner','-e','NEXTCLOUD_ADMIN_PASSWORD','-e','NEXTCLOUD_TRUSTED_DOMAINS=127.0.0.1 localhost','nextcloud:34.0.4-apache'],{'MYSQL_PASSWORD':secret,'NEXTCLOUD_ADMIN_PASSWORD':password})
state.parent.mkdir(exist_ok=True)
state.write_text(json.dumps({'container':container,'database':db,'network':network,'baseURL':'http://127.0.0.1:9096','user':'stb_owner','password':password}))
state.chmod(0o600)
print('Created dedicated MariaDB and Nextcloud containers; waiting for install.',flush=True)
for _ in range(100):
    r=run(['docker','exec','-u','www-data',container,'php','occ','status','--output=json'],check=False)
    try:
        if json.loads(r.stdout).get('installed'):break
    except ValueError:pass
    time.sleep(3)
else:raise SystemExit('Nextcloud did not become ready')
run(['docker','exec','-u','www-data',container,'php','occ','config:system:set','trusted_domains','2','--value=127.0.0.1:9096'])
run(['docker','exec','-u','www-data',container,'php','occ','app:disable','firstrunwizard'])
for user in ['stb_editor','stb_viewer','stb_manager','stb_outsider']:
    run(['docker','exec','-u','www-data','-e','OC_PASS',container,'php','occ','user:add','--password-from-env',user],{'OC_PASS':password})
run(['docker','exec','-u','www-data',container,'php','occ','group:adduser','admin','stb_outsider'])
for user in ['stb_owner','stb_editor','stb_viewer','stb_manager','stb_outsider']:
    run(['docker','exec','-u','www-data',container,'php','occ','user:setting',user,'core','lang','en'])
print('Disposable Nextcloud 34 + MariaDB ready on loopback :9096; five fictional accounts configured.',flush=True)
