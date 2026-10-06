"""Run the profile selector negative control only in an isolated temporary copy."""
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[4]
artifacts = Path(__file__).resolve().parent
source = Path('packages/core/src/Access/PermissionDriverManager.php')
original = (root / source).read_bytes()
hash_original = hashlib.sha256(original).hexdigest()
copy = Path(tempfile.mkdtemp(prefix='vaulter-p35-negative-'))
(copy / 'packages').mkdir()
for name in ('core',):
    shutil.copytree(root / 'packages' / name, copy / 'packages' / name, ignore=shutil.ignore_patterns('vendor', 'build'))
for package in (root / 'packages').iterdir():
    if package.name != 'core' and package.is_dir():
        (copy / 'packages' / package.name).symlink_to(package, target_is_directory=True)
(copy / 'load').symlink_to(root / 'load', target_is_directory=True)
for name in ('tests', 'scripts'):
    shutil.copytree(root / name, copy / name)
for name in ('phpunit.xml', 'composer.json', 'composer.lock'):
    shutil.copy2(root / name, copy / name)
(copy / 'vendor').mkdir()
for path in (root / 'vendor').iterdir():
    if path.name not in ('composer', 'bin', 'axioma-studio', 'autoload.php', 'pestphp'):
        (copy / 'vendor' / path.name).symlink_to(path, target_is_directory=path.is_dir())
shutil.copytree(root / 'vendor/pestphp', copy / 'vendor/pestphp', ignore=shutil.ignore_patterns('.temp'))
for name in ('composer', 'bin'):
    shutil.copytree(root / 'vendor' / name, copy / 'vendor' / name)
shutil.copy2(root / 'vendor/autoload.php', copy / 'vendor/autoload.php')
(copy / 'vendor/axioma-studio').mkdir()
for path in (root / 'vendor/axioma-studio').iterdir():
    target = copy / 'packages/core' if path.name == 'vaulter-core' else path.resolve()
    (copy / 'vendor/axioma-studio' / path.name).symlink_to(target, target_is_directory=True)
env = os.environ.copy()
env.update(APP_ENV='testing', DB_CONNECTION='sqlite', DB_DATABASE=':memory:')
probe = subprocess.check_output(['scripts/php84', 'php', '-r', 'require "vendor/autoload.php"; echo (new ReflectionClass(Vaulter\\Access\\PermissionDriverManager::class))->getFileName();'], cwd=copy, env=env, text=True)
assert Path(probe).resolve() == copy / source, probe
broken = original.replace(b'$this->container->make(ProfileRegistry::class)->get($profile)->getPermissionDriver()', b'null')
assert broken != original
(copy / source).write_bytes(broken)
command = ['scripts/php84', 'vendor/bin/pest', '--ci', 'packages/core/tests/Operations/ProfilePermissionDriverTest.php', '--filter=isolates profile drivers across alternating']
receipts = []
for name, expected in [('red', False), ('restored-green', True)]:
    if expected:
        (copy / source).write_bytes(original)
        assert hashlib.sha256((copy / source).read_bytes()).hexdigest() == hash_original
    log = artifacts / ('negative-' + name + '.log')
    with log.open('w') as output:
        result = subprocess.run(command, cwd=copy, env=env, stdout=output, stderr=subprocess.STDOUT)
    receipts.append({'state': name, 'at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'command': command, 'cwd': str(copy), 'environment': {key: env[key] for key in ['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE']}, 'exit': result.returncode, 'source_sha256': hashlib.sha256((copy / source).read_bytes()).hexdigest(), 'log': str(log.relative_to(root))})
    assert (result.returncode == 0) == expected, log.read_text()
    records = [json.loads(line) for line in log.read_text().splitlines() if line.startswith('{') and '"tool":"pest"' in line]
    assert records and records[-1].get('tests') == 1 and records[-1].get('skipped', 0) == 0, log.read_text()
    receipts[-1]['counts'] = records[-1]
assert (root / source).read_bytes() == original
receipt = {'item': 'PLAN2.P3.5', 'fault': 'Ignore selected profile driver and always select global driver', 'source': str(source), 'autoload_source': probe, 'original_sha256': hash_original, 'working_source_unchanged': True, 'isolated_copy': str(copy), 'checks': receipts}
(artifacts / 'negative-control.json').write_text(json.dumps(receipt, indent=2) + '\n')
print(json.dumps(receipt))
