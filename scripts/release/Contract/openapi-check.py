"""Trusted OpenAPI orchestration. Synthetic stubs prove argv/exit handling only."""
import json
import os
from pathlib import PurePosixPath
import re
import subprocess
import sys
import tempfile
from pathlib import Path

# The host lane requires the parser inside the approved complete tool inventory.
# Direct synthetic orchestration fixtures may use the host parser, without qualification authority.
TOOL = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(TOOL / 'vendor/python'))

# Transitional pin until P7.1 supplies its manifest. Never read candidate tool configuration.
IMAGE = 'tufin/oasdiff:v1.33.0'
SPECS = {'php-public': 'openapi/vaulter-v1.yaml',
         'php-collaboration': 'packages/collaboration/openapi/hub.yaml'}


def blob(root, sha, path):
    mode = subprocess.check_output(['git', '-C', root, 'ls-tree', sha, '--', path], text=True)
    if not mode.startswith('100'):
        raise ValueError('missing/nonregular OpenAPI input: ' + path)
    return subprocess.check_output(['git', '-C', root, 'show', sha + ':' + path])


def closure(root, sha, path, dest, seen=None):
    seen = set() if seen is None else seen
    if path in seen:
        return
    seen.add(path)
    if str(PurePosixPath(path)) != path or '..' in PurePosixPath(path).parts or path.startswith('/'):
        raise ValueError('unsafe OpenAPI path')
    data = blob(root, sha, path)
    parsed = yaml.safe_load(data)
    if not isinstance(parsed, dict):
        raise ValueError('invalid OpenAPI object')
    target = dest / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(data)
    def visit(node):
        if isinstance(node, dict):
            for key, value in node.items():
                if key == '$ref':
                    if not isinstance(value, str) or '://' in value:
                        raise ValueError('remote/invalid OpenAPI ref')
                    file, _, fragment = value.partition('#')
                    referenced = path
                    if file:
                        import posixpath
                        referenced = posixpath.normpath(str(PurePosixPath(path).parent / file))
                        closure(root, sha, referenced, dest, seen)
                    doc = yaml.safe_load(blob(root, sha, referenced))
                    if fragment:
                        if not fragment.startswith('/'):
                            raise ValueError('invalid ref fragment')
                        for part in fragment[1:].split('/'):
                            doc = doc[part.replace('~1', '/').replace('~0', '~')]
                else:
                    visit(value)
        elif isinstance(node, list):
            for value in node:
                visit(value)
    visit(parsed)


def run(root, candidate, report):
    import importlib.util
    parser = importlib.util.find_spec('yaml')
    if parser is None or (os.environ.get('BC_REQUIRE_TOOL_PARSER') == '1' and not Path(parser.origin).resolve().is_relative_to(TOOL)):
        raise ValueError('OpenAPI parser is outside approved T')
    global yaml
    import yaml
    rows = []
    code = report['exit']
    for line, spec in SPECS.items():
        status = report['lines'].get(line)
        if not status or status['admission'] != 'compared':
            rows.append({'line': line, 'spec': spec, 'status': 'not_checked', 'qualification': 'pending_P8.2'})
            code = max(code, 2)
            continue
        base = subprocess.check_output(['git', '-C', root, 'rev-parse', status['base'] + '^{commit}'], text=True).strip()
        with tempfile.TemporaryDirectory(prefix='vaulter-oas-') as tmp:
            d = Path(tmp)
            closure(root, base, spec, d / 'base')
            closure(root, candidate, spec, d / 'candidate')
            binary = os.environ.get('BC_OASDIFF_BIN')
            args = ([binary] if binary else ['docker', 'run', '--rm', '-v', tmp + ':/work:ro', IMAGE])
            prefix = tmp if binary else '/work'
            result = subprocess.run(args + ['breaking', prefix + '/base/' + spec, prefix + '/candidate/' + spec, '--fail-on', 'ERR'], capture_output=True)
            exit_code = result.returncode if result.returncode in (0, 1) else 2
            rows.append({'line': line, 'owner': 'collaboration' if line == 'php-collaboration' else 'core', 'spec': spec, 'base_sha': base, 'candidate_sha': candidate, 'status': 'compared' if exit_code < 2 else 'not_checked', 'exit': exit_code, 'qualification': 'pending_P8.2'})
            code = max(code, exit_code)
    return {'exit': code, 'openapi': rows, 'signature_guard_only': True, 'actual_runtime_qualification': False}


if __name__ == '__main__':
    try:
        result = run(sys.argv[1], sys.argv[2], json.load(open(sys.argv[3])))
    except Exception as error:
        result = {'exit': 2, 'status': 'not_checked', 'error': str(error), 'actual_runtime_qualification': False}
    print(json.dumps(result))
    sys.exit(result['exit'])
