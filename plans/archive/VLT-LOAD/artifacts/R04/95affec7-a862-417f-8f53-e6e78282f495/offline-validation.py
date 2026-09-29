import datetime, hashlib, json, os, subprocess
from pathlib import Path
root = Path.cwd()
out = root / 'plans/VLT-LOAD/artifacts/R04/95affec7-a862-417f-8f53-e6e78282f495'
commands = []
def capture(label, argv, overrides=None, expected=0):
    result = subprocess.run(argv, cwd=root, env={**os.environ, **(overrides or {})}, capture_output=True, text=True)
    (out / f'{label}.stdout').write_text(result.stdout)
    (out / f'{label}.stderr').write_text(result.stderr)
    commands.append({'label': label, 'argv': argv, 'environment': overrides or {}, 'exit': result.returncode, 'expected_exit': expected})
    if result.returncode != expected:
        raise SystemExit(f'{label}: unexpected exit {result.returncode}')
capture('final-r04', ['python3', '-B', '-m', 'unittest', 'discover', '-s', 'load/gates', '-p', 'test_r04_*.py'])
capture('final-p3', ['python3', '-B', '-m', 'unittest', 'discover', '-s', 'load/gates', '-p', 'test_p3_*.py'])
capture('final-php', ['composer', 'test', '--', 'load/tests/Observability/QueryTraceRecorderTest.php', 'load/tests/Observability/QueryTraceLifecycleTest.php', '--no-coverage'], {'DB_CONNECTION': 'sqlite', 'DB_DATABASE': ':memory:'})
capture('final-pint', ['vendor/bin/pint', '--test', 'load/tests/Observability/QueryTraceLifecycleTest.php'])
capture('final-shell', ['bash', '-n', 'load/scripts/p3-profile.sh', 'load/scripts/p3-capture-workload.sh', 'load/scripts/p3-explain-capture.sh'])
capture('final-diff', ['git', 'diff', '--check'])
capture('final-historical-verification', ['python3', '-B', 'load/gates/p3_workload.py', 'verify-artifacts', '--artifact-dir', 'plans/VLT-LOAD/artifacts/P3'], expected=1)
capture('final-historical-report', ['python3', '-B', 'load/gates/p3_trace_report.py', '--artifact-dir', 'plans/VLT-LOAD/artifacts/P3', '--output', str(out / 'historical-trace-analysis.json')], expected=1)
(out / 'offline-regression.py').write_bytes(Path('/tmp/vaulter-r04-regression.py').read_bytes())
(out / 'regression-before.stdout').write_bytes(Path('/tmp/vaulter-r04-regression.stdout').read_bytes())
files = subprocess.check_output(['git', 'diff', '--name-only'], text=True).splitlines()
files += subprocess.check_output(['git', 'ls-files', '--others', '--exclude-standard', 'load'], text=True).splitlines()
source = {name: hashlib.sha256((root / name).read_bytes()).hexdigest() for name in sorted(set(files)) if name.startswith('load/')}
(out / 'scope-source-sha256.json').write_text(json.dumps(source, indent=2, sort_keys=True) + '\n')
(out / 'offline-checks.json').write_text(json.dumps({'completed_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'baseline_revision': '15170d5c02cb4bfe49fe587cb00db49faada5a50', 'commands': commands, 'scope_source_digest': hashlib.sha256(json.dumps(source, sort_keys=True).encode()).hexdigest(), 'live_started': False, 'product_proof': 'unevaluated'}, indent=2, sort_keys=True) + '\n')
manifest_path = out / 'run-manifest.json'
manifest = json.loads(manifest_path.read_text())
manifest.update(status='CODE_TESTS_VERIFIED_LIVE_DEFERRED', kind='readiness-and-offline-code-validation', owner_clarification='resolved: no live stand, maximize code and tests', final_checks='offline-checks.json', scope_source_hashes='scope-source-sha256.json')
for name, expected in manifest['historical_p3_files'].items():
    actual = hashlib.sha256((root / 'plans/VLT-LOAD/artifacts/P3' / name).read_bytes()).hexdigest()
    if actual != expected:
        raise SystemExit(f'historical file changed: {name}')
manifest['historical_hashes_unchanged'] = True
manifest_path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + '\n')
(out / 'offline-validation.py').write_bytes(Path('/tmp/vaulter-r04-validation.py').read_bytes())
hashes = {str(path.relative_to(out)): hashlib.sha256(path.read_bytes()).hexdigest() for path in out.rglob('*') if path.is_file() and path.name != 'sha256.json'}
(out / 'sha256.json').write_text(json.dumps(hashes, indent=2, sort_keys=True) + '\n')
print(json.dumps({'commands': len(commands), 'scope_files': len(source), 'evidence_files': len(hashes), 'scope_digest': hashlib.sha256(json.dumps(source, sort_keys=True).encode()).hexdigest()}))
