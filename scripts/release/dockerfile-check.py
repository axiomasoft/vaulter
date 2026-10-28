"""Static Dockerfile content guard; no image build or runtime qualification."""
import json
import re
import shlex
import sys
from pathlib import Path


def errors(text):
    problems = []
    logical = re.sub(r"\\\s*\n", " ", text)
    stages = []
    aliases = set()
    user = None
    health = False
    for raw in logical.splitlines():
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        tokens = line.split(None, 1)
        instruction = tokens[0].upper()
        value = tokens[1].strip() if len(tokens) > 1 else ''
        if instruction == 'FROM':
            tokens = shlex.split(value)
            tokens = [t for t in tokens if not t.startswith('--platform=')]
            image = tokens[0] if tokens else ''
            if not re.fullmatch(r'[^\s]+@sha256:[0-9a-fA-F]{64}', image):
                problems.append('every FROM must pin a sha256 digest')
            stages.append(image)
            user, health = None, False
            if len(tokens) == 3 and tokens[1].lower() == 'as':
                aliases.add(tokens[2])
        elif instruction == 'USER':
            user = value.split(':')[0].strip().lower()
        elif instruction == 'HEALTHCHECK':
            health = value.upper() != 'NONE' and bool(re.search(r'\bCMD\s+\S', value, re.I))
        elif instruction == 'ADD':
            # Reject remote sources in shell and JSON forms, including flags and git URLs.
            if re.search(r'(?i)(https?://|git://|ssh://|git@)', value):
                problems.append('remote ADD is forbidden')
    if not stages:
        problems.append('missing FROM')
    if user in (None, '', 'root', '0') or not re.fullmatch(r'[a-z_][a-z0-9_-]*|[1-9][0-9]*', user or ''):
        problems.append('runtime USER must be explicitly non-root')
    if not health:
        problems.append('runtime HEALTHCHECK CMD is required')
    return problems


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('usage: dockerfile-check.py Dockerfile')
    result = errors(Path(sys.argv[1]).read_text())
    print(json.dumps({'schema': 'vaulter.dockerfile-content/v1', 'errors': result,
                      'runtime_qualification': 'pending'}))
    sys.exit(bool(result))
