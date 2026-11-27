#!/usr/bin/env python3
"""Validate repository IP, resolved runtime dependencies and newly added PHP suppressions."""

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile


PERMISSIVE = re.compile(r'MIT|Apache-2\.0|ISC|0BSD|BSD-[234]-Clause(?:-Clear)?')
FORBIDDEN = {
    'handsontable', 'hyperformula', 'dunglas/mercure', 'minio/minio',
    'exceljs', 'unzipper', 'mammoth', 'libclamav', 'filament/blueprint',
}
FORBIDDEN_PREFIXES = ('@tiptap-pro/', '@univerjs-pro/', '@univer-cli/')
PRIVATE_COLLABORATION = {
    'axiomasoft/vaulter-collaboration', '@vaulter/vue-collaboration',
    '@vaulter/collaboration-hub', 'yjs', 'y-protocols', 'y-prosemirror', '@tiptap/y-tiptap',
}
MINIMUMS = {'@tiptap/core': (3, 34, 0), 'vue': (3, 5, 42)}
TIPTAP_REGISTRY = 'registry.' 'tiptap.dev'
IP_MARKERS = (
    (b'plan/' b'tiptap-pro', 'retired Tiptap Pro path'),
    (b'tiptap_' b'team_id', 'Tiptap team identifier'),
    (b'.collab.' b'tiptap.cloud', 'Tiptap collaboration host'),
    (TIPTAP_REGISTRY.encode(), 'commercial Tiptap registry'),
)
COMMERCIAL_TARBALL = re.compile(rb'(?<![a-z0-9])(?:tiptap-pro|univerjs-pro|univer-cli)[^\s"\'<>]*\.tgz\b')
HISTORY_SCRUB_RUNBOOK = 'docs/security/git-history-scrub-runbook.md'
HISTORY_SCRUB_COMMAND = re.compile(
    rb'^git filter-repo --invert-paths --path plan/' rb'tiptap-pro --path plan --path audits\r?$', re.MULTILINE,
)


def ip_markers(content):
    lowered = content.lower()
    for marker, problem in IP_MARKERS:
        offset = lowered.find(marker)
        if offset >= 0:
            yield offset, problem
    match = COMMERCIAL_TARBALL.search(lowered)
    if match:
        yield match.start(), 'commercial package tarball'


def is_commercial_tarball(file):
    try:
        with tarfile.open(file, 'r:gz') as archive:
            for member in archive:
                if member.name.removeprefix('./') not in ('package/package.json', 'package.json') or not member.isfile():
                    continue
                with archive.extractfile(member) as manifest:
                    package = json.load(manifest)
                if isinstance(package, dict) and str(package.get('name', '')).lower().startswith(FORBIDDEN_PREFIXES):
                    return True
    except (tarfile.TarError, OSError, EOFError, ValueError):
        return False
    return False


def repository_ip_problems(root):
    paths = subprocess.check_output(
        ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=root,
    ).split(b'\0')
    for raw_path in dict.fromkeys(filter(None, paths)):
        path_problems = list(ip_markers(raw_path))
        if path_problems:
            # The path itself may contain a private identifier.
            for _, problem in path_problems:
                yield f'repository path: {problem}'
            continue
        path = os.fsdecode(raw_path)
        file = root / path
        if file.is_symlink():
            content = os.fsencode(os.readlink(file))
        elif file.is_file():
            content = file.read_bytes()
            if file.suffix.lower() == '.tgz' and is_commercial_tarball(file):
                yield f'{path}: commercial package tarball'
        else:
            continue
        if path == HISTORY_SCRUB_RUNBOOK:
            content = HISTORY_SCRUB_COMMAND.sub(b'', content)
        for offset, problem in ip_markers(content):
            line_number = content.count(b'\n', 0, offset) + 1
            yield f'{path}:{line_number}: {problem}'


def dependency_problems(package, name, licenses):
    version = package.get('version', '')
    source = str(package.get('resolved', ''))
    if (name in FORBIDDEN or name.startswith(FORBIDDEN_PREFIXES)
            or TIPTAP_REGISTRY in source or (name, version) == ('xlsx', '0.18.5')):
        yield f'{name}@{version}: dependency forbidden'
    # Composer license lists offer a choice. Only an approved license may be chosen.
    if not any(
        PERMISSIVE.fullmatch(license_id) for license_id in licenses
    ):
        yield f'{name}@{version}: no approved runtime license ({", ".join(licenses)})'
    if name in MINIMUMS:
        parsed = re.fullmatch(r'(\d+)\.(\d+)\.(\d+)(?:\+[^ ]+)?', version)
        if parsed is None or tuple(map(int, parsed.groups())) < MINIMUMS[name]:
            yield f'{name}@{version}: below the required version {MINIMUMS[name]}'


def composer_runtime_packages(root, lock):
    packages = {package['name']: package for package in lock['packages'] + lock.get('packages-dev', [])}
    runtime = {package['name'] for package in lock['packages']}

    # Shipped packages are roots even when installed as development dependencies in the monorepo.
    shipped = json.loads((root / 'release/packages.json').read_text())['php']
    seen = {package['name'] for package in shipped}
    pending = []
    for package in shipped:
        manifest = json.loads((root / package['path'] / 'composer.json').read_text())
        pending.extend(manifest.get('require', {}))

    providers = {}
    for name, package in packages.items():
        for alias in package.get('provide', {}).keys() | package.get('replace', {}).keys():
            providers.setdefault(alias, []).append(name)

    while pending:
        name = pending.pop()
        if name in seen:
            continue
        seen.add(name)
        if name in packages:
            runtime.add(name)
            pending.extend(packages[name].get('require', {}))
        else:
            # The lock does not bind virtual requirements to a single provider.
            pending.extend(providers.get(name, ()))

    yield from (package for name, package in packages.items() if name in runtime)


def scan_lock(path):
    lock = json.loads(path.read_text())
    if path.name == 'composer.lock':
        for package in lock['packages'] + lock.get('packages-dev', []):
            if package['name'] in PRIVATE_COLLABORATION:
                yield f"{package['name']}: private collaboration dependency"
        for package in composer_runtime_packages(path.parent, lock):
            yield from dependency_problems(package, package['name'], package.get('license', []))
    else:
        for location, package in lock['packages'].items():
            name = package.get('name') or location.rsplit('node_modules/', 1)[-1]
            if name in PRIVATE_COLLABORATION or name.startswith('@hocuspocus/'):
                yield f'{name}: private collaboration dependency'
            if 'node_modules/' in location and not package.get('link') and not package.get('dev'):
                yield from dependency_problems(package, name, [package.get('license') or ''])


def added_suppressions(diff, adr_exists):
    path = ''
    line_number = 0
    for line in diff.splitlines():
        if line.startswith('+++ b/'):
            path = line[6:]
        elif line.startswith('@@ '):
            line_number = int(re.search(r'\+(\d+)', line)[1])
        elif path.endswith('.php') and line.startswith('+'):
            match = re.search(r'@phpstan-ignore\b|@psalm-suppress\b|->\s*skip\s*\(', line)
            adr = re.search(r'\bADR-(\d{4})\b', line)
            if match and not (adr and adr_exists(adr[1])):
                yield f'{path}:{line_number}: {match[0]} requires a recorded ADR'
            line_number += 1
        elif line.startswith(' '):
            line_number += 1


def image_problems(source):
    for line_number, line in enumerate(source.splitlines(), 1):
        if line.lstrip().startswith('#'):
            continue
        if re.search(r'(?i)\b(?:minio/minio|libclamav[\w-]*)\b', line):
            yield f'{line_number}: restricted runtime component; use an external service'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', default='HEAD')
    args = parser.parse_args()
    base = args.base if re.fullmatch(r'[0-9a-f]{40}|HEAD', args.base) and args.base != '0' * 40 else 'HEAD^'
    if base not in ('HEAD', 'HEAD^') and subprocess.run(
        ['git', 'cat-file', '-e', f'{base}^{{commit}}'], capture_output=True
    ).returncode:
        base = 'HEAD^'
    diff = subprocess.check_output(['git', 'diff', '--unified=0', base, '--', 'packages', 'tests', 'load'], text=True)
    adr_exists = lambda number: any(Path('docs/adr').glob(f'{number}-*.md'))
    findings = list(added_suppressions(diff, adr_exists))
    findings.extend(repository_ip_problems(Path('.')))
    files = [Path('composer.lock'), Path('package-lock.json')]
    for file in files:
        findings.extend(f'{file}: {problem}' for problem in scan_lock(file))
    tracked = subprocess.check_output(['git', 'ls-files', '-z', '--', 'packages'], text=True).split('\0')
    for file in map(Path, tracked):
        if file.name.startswith('Dockerfile') and file.is_file():
            findings.extend(f'{file}:{problem}' for problem in image_problems(file.read_text()))
    for file in Path('packages').glob('*/src/**'):
        if file.name in ('Helpers', 'Utils', 'Common', 'Misc', 'Traits'):
            findings.append(f'{file}: use the domain namespace instead')
    for finding in findings:
        print(finding)
    return int(bool(findings))


if __name__ == '__main__':
    raise SystemExit(main())
