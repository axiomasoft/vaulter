import json, subprocess, pathlib, re, hashlib, yaml
root=pathlib.Path.cwd()
state=json.loads((root/'audits/2026-09-29-post-archive-remediation/evidence/baseline.json').read_text())
for name in ['monorepo-split.yml','frontend.yml','tests.yml','static-analysis.yml','code-style.yml']:
    d=yaml.safe_load((root/'.github/workflows'/name).read_text())
    assert isinstance(d['jobs'], dict); print('YAML parsed:',name)
    if name == 'monorepo-split.yml':
        count=0
        for job in d['jobs'].values():
            for step in job.get('steps',[]):
                if 'run' not in step: continue
                script=re.sub(r'\$\{\{.*?\}\}', 'EXPRESSION', step['run'])
                r=subprocess.run(['bash','-n'],input=script,text=True,capture_output=True)
                assert r.returncode == 0, r.stderr; count+=1
        print('bash -n:',count,'release shell blocks; expressions replaced for syntax only')
lock=json.loads((root/'hocuspocus-server/package-lock.json').read_text())
manifest=json.loads((root/'hocuspocus-server/package.json').read_text())
for key in ['name','version','dependencies','devDependencies','engines']:
    assert manifest[key] == lock['packages'][''][key], key
print('Standalone manifest/lock fields match')
old=json.loads(subprocess.check_output(['git','show',state['initial_head']+':hocuspocus-server/package-lock.json'],text=True))
for key in old['packages']:
    if key:
        assert old['packages'][key]['version'] == lock['packages'][key]['version'], key
print('All pre-existing standalone versions preserved')
root_lock=json.loads((root/'package-lock.json').read_text())
for path in ['', 'packages/vue','hocuspocus-server']:
    manifest=json.loads((root/path/'package.json').read_text())
    record=root_lock['packages'][path]
    for key in ['name','version','dependencies','devDependencies','peerDependencies','engines']:
        if key == 'name' and path=='': continue
        assert manifest.get(key) == record.get(key), (path,key)
print('Root lock agrees with all workspace manifests')
assert hashlib.sha256((root/'packages/azgard/composer.lock').read_bytes()).hexdigest() == state['azgard_lock_sha256']
print('Original ignored Azgard lock preserved:',state['azgard_lock_sha256'])
for path,sha in state['preserved'].items():
    assert hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest() == sha,path
print('Original review report/evidence byte hashes preserved:',len(state['preserved']))
