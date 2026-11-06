from pathlib import Path
import json,re,xml.etree.ElementTree as ET
root=Path(__file__).resolve().parents[1]
package=json.loads((root/'package.json').read_text()); info=ET.parse(root/'appinfo/info.xml').getroot()
assert info.findtext('id')=='stammbaum'
assert info.findtext('version')==package['version']
assets=json.loads((root/'appinfo/assets.json').read_text())
for ext in ['js','css']: assert (root/ext/(assets['name']+'.'+ext)).is_file()
routes=(root/'appinfo/routes.php').read_text()
for controller,method in re.findall(r"[\"']name[\"']\s*=>\s*[\"']([a-z]+)#([a-zA-Z]+)[\"']",routes):
    source=(root/'lib'/'Controller'/(controller.title()+'Controller.php')).read_text()
    assert 'function '+method+'(' in source,(controller,method)
    assert 'PublicPage' not in source
print('Package metadata, built assets and route/controller contracts passed.')
