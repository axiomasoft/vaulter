"""Provision this bootstrap OUTSIDE T under external host approval before invoking it.
Host Python, this bootstrap, runtime approval and checksums are protected prerequisites.
No candidate Python imports: invoke the external copy with absolute Python -I.
"""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tempfile

REQUIRED_BC_LINES = ('php-public', 'php-collaboration', 'npm-vue', 'npm-vue-collaboration')


def selected_lines(policy, selected='all', required=None):
    """Host-owned mandatory coverage; neither candidate selectors nor an old PHP-only policy can narrow it."""
    expected = set(REQUIRED_BC_LINES)
    if not isinstance(policy.get('lines'), dict) or set(policy['lines']) != expected:
        raise ValueError('trusted policy must require all four PHP/npm release lines')
    for value in (required, selected if selected != 'all' else None):
        if value is not None:
            lines = value.split(',')
            if len(lines) != len(expected) or set(lines) != expected:
                raise ValueError('required/selected BC lines must include all four PHP/npm release lines')
    return REQUIRED_BC_LINES


def validate_line_report(report, required, returncode):
    """A successful subprocess with missing, skipped or deferred npm coverage is still a tool error."""
    if type(report.get('exit')) is not int or report['exit'] not in (0, 1, 2) or report['exit'] != returncode:
        raise ValueError('incoherent contract checker exit status')
    if report['exit'] == 2:
        return
    lines = report.get('lines')
    if not isinstance(lines, dict) or set(lines) != set(required):
        raise ValueError('contract checker omitted required PHP/npm release lines')
    for name, line in lines.items():
        if not isinstance(line, dict) or line.get('admission') not in ('compared', 'initial_bootstrap', 'approved_initial_release'):
            raise ValueError('required line was not checked: ' + name)
        if line.get('selected', True) is not True or line.get('required', True) is not True or line.get('status', 'current') != 'current':
            raise ValueError('required line cannot be omitted/deferred: ' + name)
        if report['exit'] == 0 and line['admission'] == 'initial_bootstrap':
            raise ValueError('initial bootstrap is not a compatibility pass: ' + name)
        if line['admission'] == 'compared' and not isinstance(line.get('base'), str):
            raise ValueError('compared line has no release base: ' + name)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def verify(tool, revision, sums, runtime, runtime_digest):
    tool = Path(tool)
    if not tool.is_absolute() or tool.resolve() != tool or not re.fullmatch('[a-f0-9]{40}', revision):
        raise ValueError('invalid tool root/revision')
    for external in (sums, runtime):
        p = Path(external)
        if not p.is_absolute() or p.resolve() != p or p.is_relative_to(tool):
            raise ValueError('approval must be outside T')
    if digest(Path(runtime)) != runtime_digest:
        raise ValueError('runtime approval changed')
    approved = {}
    for row in Path(sums).read_text().splitlines():
        match = re.fullmatch(r'([0-9a-f]{64})  (.+)', row)
        if not match:
            raise ValueError('invalid checksum row')
        sha, name = match.groups()
        parts = PurePosixPath(name).parts
        if not parts or name.startswith('/') or str(PurePosixPath(name)) != name or any(p in ('.', '..') for p in parts) or parts[0] == '.git' or name in approved:
            raise ValueError('unsafe/duplicate checksum path')
        approved[name] = sha
    actual = {}
    for directory, dirs, files in os.walk(tool, followlinks=False):
        if Path(directory) == tool and '.git' in dirs:
            dirs.remove('.git')
        for name in dirs + files:
            p = Path(directory) / name
            if p.is_symlink():
                raise ValueError('symlink in tool closure')
        for name in files:
            p = Path(directory) / name
            relative = p.relative_to(tool).as_posix()
            if relative == '.git':
                continue
            if not p.is_file():
                raise ValueError('nonregular tool input')
            actual[relative] = digest(p)
    if not approved or actual != approved:
        raise ValueError('exact complete tool inventory mismatch')
    head = subprocess.check_output(['git', '-C', str(tool), 'rev-parse', 'HEAD'], text=True).strip()
    if head != revision:
        raise ValueError('tool HEAD mismatch')
    a = json.loads(Path(runtime).read_text())
    php = Path(a['php'])
    if not php.is_absolute() or php.resolve() != php or digest(php) != a['sha256']:
        raise ValueError('approved absolute PHP mismatch')
    env = a['environment']
    if not isinstance(env, dict) or not all(isinstance(k, str) and isinstance(v, str) for k, v in env.items()):
        raise ValueError('invalid runtime environment')
    probe = 'echo json_encode(["version"=>PHP_VERSION,"ini"=>php_ini_loaded_file(),"scanned"=>php_ini_scanned_files(),"extensions"=>get_loaded_extensions()]);'
    observed = json.loads(subprocess.check_output([str(php), '-r', probe], env=env, cwd='/'))
    if not observed['version'].startswith('8.4.') or observed != a['configuration']:
        raise ValueError('runtime configuration/extensions/version mismatch')
    configs = ([observed['ini']] if observed['ini'] else []) + [p.strip() for p in (observed['scanned'] or '').split(',') if p.strip()]
    if set(configs) != set(a['config_files']):
        raise ValueError('runtime config inventory mismatch')
    if not Path(env.get('BC_PYTHON', '')).is_absolute():
        raise ValueError('approved absolute wrapper Python missing')
    for filename, sha in a['config_files'].items():
        if digest(Path(filename)) != sha:
            raise ValueError('runtime config bytes changed')
    env = dict(env)
    env['BC_TOOL_CLOSURE_SHA256'] = digest(Path(sums))
    env['BC_REQUIRE_TOOL_PARSER'] = '1'
    return str(php), env


def main():
    e = os.environ
    tool = e['BC_TOOL_ROOT']
    php, env = verify(tool, e['BC_TOOL_REV'], e['BC_SHA256SUMS'], e['BC_RUNTIME_APPROVAL'], e['BC_RUNTIME_APPROVAL_SHA256'])
    policy_path = tool + '/scripts/release/Contract/bc-trust-policy.json'
    required = selected_lines(json.loads(Path(policy_path).read_text()), e.get('BC_SELECTED_LINES', 'all'), e.get('BC_REQUIRED_LINES'))
    # Protected mount and Git metadata integrity are external prerequisites; never claimed by a candidate fixture.
    with tempfile.TemporaryDirectory(prefix='vaulter-bc-host-') as tmp:
        args = [php, tool + '/scripts/release/bc-check.php', '--root=' + e['BC_SOURCE_ROOT'], '--candidate=' + e['BC_SOURCE_SHA'], '--policy=' + policy_path, '--policy-rev=' + e['BC_TOOL_REV'], '--exceptions=' + tool + '/release/bc-exceptions.json']
        result = subprocess.run(args, env=env, cwd=tmp, capture_output=True)
        report = json.loads(result.stdout)
        validate_line_report(report, required, result.returncode)
        report.update({'source_sha': e['BC_SOURCE_SHA'], 'policy_sha': e['BC_TOOL_REV'], 'signature_guard_only': True, 'actual_runtime_qualification': False})
        report['required_release_lines'] = list(required)
        report['selected_release_lines'] = list(required)
        for line in report['lines'].values():
            line['policy_sha'] = e['BC_TOOL_REV']
            line['source_sha'] = e['BC_SOURCE_SHA']
            line['base_sha'] = subprocess.check_output(['git', '-C', e['BC_SOURCE_ROOT'], 'rev-parse', line['base'] + '^{commit}'], text=True).strip() if line['base'] else None
        path = Path(tmp) / 'report.json'
        path.write_text(json.dumps(report))
        print(json.dumps(report), flush=True)
        if result.returncode == 2:
            return 2
        openapi_exit = subprocess.run(['/bin/bash', tool + '/scripts/release/oasdiff-check.sh', e['BC_SOURCE_ROOT'], e['BC_SOURCE_SHA'], str(path)], env=env, cwd=tmp).returncode
        return max(report['exit'], openapi_exit if openapi_exit in (0, 1, 2) else 2)


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as error:
        print(json.dumps({'exit': 2, 'status': 'untrusted_checker', 'error': str(error), 'actual_runtime_qualification': False}))
        sys.exit(2)
