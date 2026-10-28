#!/usr/bin/env python3
"""Publish gate for the accepted npm tgz and the tested OCI digest (PLAN4 P7.3/U3).

  artifact-gate.py npm --tgz F --approval A.json --source-sha S --channel C --visibility V [--emit-verified FILE]
  artifact-gate.py oci --approval A.json --source-sha S --digest sha256:H --platforms a,b --sbom-sha256 H --policy-sha256 H
                       --tool-sha256 H --channel C --visibility V

Authenticity comes ONLY from outside the candidate: the exact bytes of the approval A must hash to the value in the
environment variable VAULTER_ARTIFACT_APPROVAL_SHA256 (a protected repository/owner variable), checked BEFORE A is parsed.
There is no flag, default or candidate-derived way to supply that hash, and no compare-only mode: values of this run (the
tgz bytes, the digest/platforms/SBOM/policy/tool the candidate reports) are compared with what the AUTHENTICATED A accepted,
never with the candidate's own record. A binds source, kind, package, version, the npm tgz sha256/integrity and consumer
evidence, or the OCI digest/platforms/SBOM/policy/tool and visibility. A source-SHA-only A, an A for another artifact of the
same source, or a repacked tgz refuses. The tgz is only read as data (package.json); nothing is extracted or executed.
This tool never contacts a registry and never creates an approval. Exit 1 = refused (reason on stderr).
"""

import argparse
import base64
import hashlib
import hmac
import json
import os
import re
import sys
import tarfile

HEX64 = re.compile(r'[0-9a-f]{64}')


def refuse(message):
    print(f'artifact-gate: refused: {message}', file=sys.stderr)
    sys.exit(1)


def authenticate(path):
    pinned = os.environ.get('VAULTER_ARTIFACT_APPROVAL_SHA256', '')
    if HEX64.fullmatch(pinned) is None:
        refuse('VAULTER_ARTIFACT_APPROVAL_SHA256 (protected external hash of the approval bytes) is missing or malformed')
    try:
        data = open(path, 'rb').read()
    except OSError:
        refuse('the approval is missing')
    if not hmac.compare_digest(pinned, hashlib.sha256(data).hexdigest()):
        refuse('the approval bytes do not match the protected external hash')
    try:
        approval = json.loads(data)
    except ValueError:
        refuse('the approval is not JSON')
    if not isinstance(approval, dict) or approval.get('status') != 'approved':
        refuse('the approval is not approved')
    return approval


def need(entry, fields):
    if not isinstance(entry, dict):
        refuse('the approval has no entry for this artifact (a source-SHA-only approval authorizes nothing)')
    for field in fields:
        value = entry.get(field)
        if value in (None, '', []):
            refuse(f'the approval entry is incomplete: {field} is missing')


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest='kind', required=True)
    npm = sub.add_parser('npm')
    npm.add_argument('--tgz', required=True)
    npm.add_argument('--emit-verified')
    npm.add_argument('--channel', required=True)
    npm.add_argument('--visibility', required=True)
    oci = sub.add_parser('oci')
    for flag in ('digest', 'platforms', 'sbom-sha256', 'policy-sha256', 'tool-sha256', 'channel', 'visibility'):
        oci.add_argument('--' + flag, required=True)
    for p in (npm, oci):
        p.add_argument('--approval', required=True)
        p.add_argument('--source-sha', required=True)
    args = parser.parse_args()

    if re.fullmatch(r'[0-9a-f]{40}', args.source_sha) is None:
        refuse('--source-sha must be a 40 hex commit')
    approval = authenticate(args.approval)
    if approval.get('source_sha') != args.source_sha:
        refuse('the approval is for another source')
    artifacts = approval.get('artifacts')
    artifacts = artifacts if isinstance(artifacts, dict) else {}

    if args.kind == 'npm':
        try:
            data = open(args.tgz, 'rb').read()
            with tarfile.open(args.tgz, 'r:gz') as tar:
                manifest = json.load(tar.extractfile('package/package.json'))
        except (OSError, tarfile.TarError, KeyError, ValueError, AttributeError) as exc:
            refuse(f'tgz has no readable package/package.json: {exc}')
        name, version = manifest.get('name'), manifest.get('version')
        entry = (artifacts.get('npm') or {}).get(name) if isinstance(artifacts.get('npm'), dict) else None
        need(entry, ('kind', 'version', 'tgz_sha256', 'integrity', 'consumer_evidence_sha256', 'channel', 'visibility'))
        if entry['kind'] != 'npm' or entry['version'] != version:
            refuse(f'the approval accepted {name}@{entry["version"]}, the tgz is {name}@{version}')
        if hashlib.sha256(data).hexdigest() != entry['tgz_sha256']:
            refuse('tgz sha256 is not the accepted tgz')
        if 'sha512-' + base64.b64encode(hashlib.sha512(data).digest()).decode() != entry['integrity']:
            refuse('tgz integrity is not the accepted tgz')
        if HEX64.fullmatch(str(entry['consumer_evidence_sha256'])) is None:
            refuse('the accepted consumer evidence is not a sha256')
        if args.channel not in ('public', 'private') or entry['channel'] != args.channel:
            refuse(f'channel {args.channel!r} is not the accepted channel {entry["channel"]!r}')
        if args.visibility != entry['visibility']:
            refuse('destination visibility is not the accepted one')
        if args.channel == 'private' and (args.visibility != 'private' or entry['visibility'] != 'private'):
            refuse('a private channel needs a destination probed private and accepted private')
        if args.channel == 'public' and args.visibility != 'public':
            refuse('a public channel needs a public destination')
        if args.emit_verified:
            if re.search(r'[\x00-\x1f\x7f]', args.tgz) or args.tgz.startswith('-'):
                refuse('the verified path must not contain control characters or start with -')
            with open(args.emit_verified, 'a') as out:
                out.write(args.tgz + '\t' + ('restricted' if args.channel == 'private' else 'public') + '\n')
    else:
        entry = artifacts.get('oci')
        need(entry, ('kind', 'digest', 'platforms', 'sbom_sha256', 'policy_sha256', 'tool_sha256', 'visibility'))
        if entry['kind'] != 'oci':
            refuse('the approval entry is not an OCI entry')
        if re.fullmatch(r'sha256:[0-9a-f]{64}', args.digest) is None:
            refuse(f'a tag or other reference is not an identity: {args.digest!r}')
        if args.digest != entry['digest']:
            refuse('digest is not the accepted tested digest')
        if sorted(args.platforms.split(',')) != sorted(entry['platforms']):
            refuse('platform set is not the accepted one')
        for flag, field in (('sbom_sha256', 'sbom_sha256'), ('policy_sha256', 'policy_sha256'), ('tool_sha256', 'tool_sha256')):
            if getattr(args, flag) != entry[field]:
                refuse(f'{field} is not the accepted one')
        if args.channel not in ('public', 'private'):
            refuse(f'unknown channel {args.channel!r}')
        if args.channel == 'private' and (args.visibility != 'private' or entry['visibility'] != 'private'):
            refuse('a private channel needs a destination probed private and accepted private')
        if args.visibility != entry['visibility']:
            refuse('destination visibility is not the accepted one')
    print(f'artifact-gate: accepted {args.kind}')


if __name__ == '__main__':
    main()
