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
ALLOWED_OCI_REGISTRIES = frozenset({'ghcr.io'})
OCI_REPO_RE = re.compile(r'^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)+$')


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


def parse_oci_image_ref(raw):
    if not isinstance(raw, str) or not raw:
        refuse('OCI image_ref is required')
    if re.search(r'[\x00-\x20\x7f]', raw) or '://' in raw or '?' in raw or '#' in raw:
        refuse(f'OCI image_ref contains disallowed characters or URL syntax: {raw!r}')
    if ':' in raw or '@' in raw or '/' not in raw:
        refuse(f'OCI image_ref must be registry/repository without tag or digest: {raw!r}')
    registry, _, repository = raw.partition('/')
    if registry not in ALLOWED_OCI_REGISTRIES:
        refuse(f'OCI registry {registry!r} is outside allowlist {sorted(ALLOWED_OCI_REGISTRIES)}')
    if any(seg in ('', '.', '..') for seg in repository.split('/')) or OCI_REPO_RE.fullmatch(repository) is None:
        refuse(f'OCI repository path {repository!r} is malformed')
    return registry, repository


def verify_npm_qualification(path, source_sha, channel, release_line, pkg_name, pkg_version, tgz_sha256, tgz_integrity):
    try:
        with open(path, 'r', encoding='utf-8') as handle:
            receipt = json.load(handle)
    except (OSError, UnicodeDecodeError, ValueError) as exc:
        refuse(f'the qualification receipt is missing or invalid JSON: {exc}')
    if not isinstance(receipt, dict) or receipt.get('schema') != 'vaulter.release-qualification/v1':
        refuse('the qualification receipt schema is invalid')
    if receipt.get('source_sha') != source_sha:
        refuse('the qualification receipt is for another source')
    gate = receipt.get('ci_gate')
    if not isinstance(gate, dict) or gate.get('sha') != source_sha or gate.get('conclusion') != 'success':
        refuse('the qualification receipt ci_gate is not a success for this source')
    if receipt.get('channel') != channel:
        refuse(f'the qualification receipt channel {receipt.get("channel")!r} differs from {channel!r}')
    if release_line:
        if receipt.get('release_line') != release_line:
            refuse(f'the qualification receipt release_line {receipt.get("release_line")!r} differs from {release_line!r}')
    elif not isinstance(receipt.get('release_line'), str) or not receipt.get('release_line'):
        refuse('the qualification receipt lacks release_line')
    artifacts = receipt.get('artifacts')
    entry = artifacts.get(pkg_name) if isinstance(artifacts, dict) else None
    need(entry, ('version', 'tgz_sha256', 'integrity'))
    if entry['version'] != pkg_version:
        refuse(f'the weekly qualification accepted {pkg_name}@{entry["version"]}, the tgz is {pkg_name}@{pkg_version}')
    if entry['tgz_sha256'] != tgz_sha256:
        refuse('tgz sha256 does not match the weekly qualification receipt')
    if entry['integrity'] != tgz_integrity:
        refuse('tgz integrity does not match the weekly qualification receipt')


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest='kind', required=True)
    npm = sub.add_parser('npm')
    npm.add_argument('--tgz', required=True)
    npm.add_argument('--emit-verified')
    npm.add_argument('--channel', required=True)
    npm.add_argument('--visibility', required=True)
    npm.add_argument('--qualification')
    npm.add_argument('--release-line', default='')
    oci = sub.add_parser('oci')
    oci.add_argument('--image-ref', required=True)
    oci.add_argument('--emit-verified')
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
        tgz_sha256 = hashlib.sha256(data).hexdigest()
        tgz_integrity = 'sha512-' + base64.b64encode(hashlib.sha512(data).digest()).decode()
        if args.qualification:
            verify_npm_qualification(
                args.qualification,
                args.source_sha,
                args.channel,
                args.release_line,
                name,
                version,
                tgz_sha256,
                tgz_integrity,
            )
        entry = (artifacts.get('npm') or {}).get(name) if isinstance(artifacts.get('npm'), dict) else None
        need(entry, ('kind', 'version', 'tgz_sha256', 'integrity', 'consumer_evidence_sha256', 'channel', 'visibility'))
        if entry['kind'] != 'npm' or entry['version'] != version:
            refuse(f'the approval accepted {name}@{entry["version"]}, the tgz is {name}@{version}')
        if tgz_sha256 != entry['tgz_sha256']:
            refuse('tgz sha256 is not the accepted tgz')
        if tgz_integrity != entry['integrity']:
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
        registry, repository = parse_oci_image_ref(args.image_ref)
        entry = artifacts.get('oci')
        need(entry, ('kind', 'registry', 'repository', 'digest', 'platforms', 'sbom_sha256', 'policy_sha256', 'tool_sha256', 'channel', 'visibility'))
        if entry['kind'] != 'oci':
            refuse('the approval entry is not an OCI entry')
        approved_registry = entry['registry']
        approved_repo = entry['repository']
        if approved_registry not in ALLOWED_OCI_REGISTRIES:
            refuse(f'the approved OCI registry {approved_registry!r} is outside allowlist {sorted(ALLOWED_OCI_REGISTRIES)}')
        if registry != approved_registry:
            refuse(f'OCI registry {registry!r} is not the approved registry {approved_registry!r}')
        if OCI_REPO_RE.fullmatch(str(approved_repo)) is None or repository != approved_repo:
            refuse(f'OCI repository {repository!r} is not the approved repository {approved_repo!r}')
        if re.fullmatch(r'sha256:[0-9a-f]{64}', args.digest) is None:
            refuse(f'a tag or other reference is not an identity: {args.digest!r}')
        if args.digest != entry['digest']:
            refuse('digest is not the accepted tested digest')
        if sorted(args.platforms.split(',')) != sorted(entry['platforms']):
            refuse('platform set is not the accepted one')
        for flag, field in (('sbom_sha256', 'sbom_sha256'), ('policy_sha256', 'policy_sha256'), ('tool_sha256', 'tool_sha256')):
            if getattr(args, flag) != entry[field]:
                refuse(f'{field} is not the accepted one')
        if args.channel not in ('public', 'private') or entry['channel'] != args.channel:
            refuse(f'channel {args.channel!r} is not the accepted channel {entry["channel"]!r}')
        if args.visibility != entry['visibility']:
            refuse('destination visibility is not the accepted one')
        if args.channel == 'private' and (args.visibility != 'private' or entry['visibility'] != 'private'):
            refuse('a private channel needs a destination probed private and accepted private')
        if args.channel == 'public' and args.visibility != 'public':
            refuse('a public channel needs a public destination')
        if args.emit_verified:
            with open(args.emit_verified, 'w', encoding='utf-8') as out:
                json.dump({
                    'kind': 'oci',
                    'source_sha': args.source_sha,
                    'registry': registry,
                    'repository': repository,
                    'image': f'{registry}/{repository}',
                    'digest': args.digest,
                    'channel': args.channel,
                    'visibility': args.visibility,
                }, out, sort_keys=True)
                out.write('\n')
    print(f'artifact-gate: accepted {args.kind}')


if __name__ == '__main__':
    main()
