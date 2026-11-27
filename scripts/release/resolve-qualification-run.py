#!/usr/bin/env python3
"""Resolve and verify a trusted GitHub Actions qualification run for an exact source SHA.

Fails closed unless an explicit (or SHA-matched completed/successful trusted workflow)
run from the same non-fork repository carries an unexpired `release-qualification` artifact
whose archive contents satisfy all required receipts/approvals for the caller.
"""
from __future__ import annotations

import argparse
import io
import json
import os
import posixpath
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import zipfile

DEFAULT_ALLOWED_WORKFLOWS = ('.github/workflows/weekly-full.yml',)
TRUSTED_EVENTS = frozenset({'schedule', 'workflow_dispatch', 'push', 'workflow_call'})
WORKFLOW_PATH_RE = re.compile(r'^\.github/workflows/[A-Za-z0-9_.-]+\.ya?ml$')
SHA40_RE = re.compile(r'^[0-9a-f]{40}$')
REPO_RE = re.compile(r'^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$')
RUN_ID_RE = re.compile(r'^[1-9][0-9]*$')
LINE_RE = re.compile(r'^[A-Za-z0-9_.-]+$')
MAX_ARTIFACT_ZIP_BYTES = 16 * 1024 * 1024
MAX_ARTIFACT_MEMBERS = 64
MAX_MEMBER_BYTES = 4 * 1024 * 1024


class _CrossHostAuthStrippingRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Ensure GitHub artifact SAS redirects remain HTTPS-only and strip API bearer auth cross-host."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        target = urllib.parse.urlsplit(newurl)
        if target.scheme != 'https' or not target.netloc:
            raise urllib.error.URLError(f'refused non-HTTPS redirect target {newurl!r}')
        redirected = super().redirect_request(req, fp, code, msg, headers, newurl)
        if redirected is None:
            return None
        origin = urllib.parse.urlsplit(req.full_url)
        if target.netloc.lower() != origin.netloc.lower():
            sensitive = frozenset({'authorization', 'x-github-api-version', 'cookie'})
            for mapping in (redirected.headers, redirected.unredirected_hdrs):
                for key in [k for k in mapping if k.lower() in sensitive]:
                    mapping.pop(key, None)
        return redirected


def refuse(message: str) -> None:
    print(f'qualification-run: refused: {message}', file=sys.stderr)
    raise SystemExit(1)


def parse_allowed_workflows(base: list[str], extra: str) -> tuple[str, ...]:
    workflows: list[str] = []
    clean_base = [item.strip() for item in base if item.strip()]
    raw_items = clean_base if clean_base else list(DEFAULT_ALLOWED_WORKFLOWS)
    if extra.strip():
        raw_items.extend(part.strip() for part in extra.split(',') if part.strip())
    for item in raw_items:
        if WORKFLOW_PATH_RE.fullmatch(item) is None:
            refuse(f'untrusted workflow path specification {item!r}')
        if item not in workflows:
            workflows.append(item)
    return tuple(workflows)


def _open_https(url: str, headers: dict[str, str], max_bytes: int | None = None) -> bytes:
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or not parsed.netloc:
        refuse(f'GitHub API URL must use https://, got {url!r}')
    request = urllib.request.Request(url, headers=headers)
    opener = urllib.request.build_opener(_CrossHostAuthStrippingRedirectHandler)
    try:
        with opener.open(request, timeout=20) as response:
            if max_bytes is None:
                return response.read()
            chunk = response.read(max_bytes + 1)
            if len(chunk) > max_bytes:
                refuse(f'artifact archive exceeds maximum allowed size ({max_bytes} bytes)')
            return chunk
    except urllib.error.HTTPError as exc:
        refuse(f'GitHub API {parsed.path} returned HTTP {exc.code}')
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        refuse(f'GitHub API {parsed.path} request failed: {exc}')


def github_get_json(api_url: str, path: str, token: str) -> dict:
    parsed = urllib.parse.urlsplit(api_url)
    if parsed.scheme != 'https' or not parsed.netloc:
        refuse(f'GitHub API URL must use https://, got {api_url!r}')
    url = api_url.rstrip('/') + path
    raw = _open_https(
        url,
        headers={
            'Accept': 'application/vnd.github+json',
            'Authorization': f'Bearer {token}',
            'X-GitHub-Api-Version': '2022-11-28',
            'User-Agent': 'vaulter-qualification-gate/1',
        },
    )
    try:
        payload = json.loads(raw.decode('utf-8'))
    except ValueError:
        refuse(f'GitHub API {path} returned invalid JSON')
    if not isinstance(payload, dict):
        refuse(f'GitHub API {path} returned non-object JSON')
    return payload


def github_get_bytes(url: str, token: str) -> bytes:
    return _open_https(
        url,
        headers={
            'Accept': 'application/vnd.github+json',
            'Authorization': f'Bearer {token}',
            'X-GitHub-Api-Version': '2022-11-28',
            'User-Agent': 'vaulter-qualification-gate/1',
        },
        max_bytes=MAX_ARTIFACT_ZIP_BYTES,
    )


def validate_run(run: dict, repo: str, sha: str, allowed_workflows: tuple[str, ...]) -> str | None:
    if run.get('head_sha') != sha:
        return f"run {run.get('id')} is for head_sha={run.get('head_sha')!r}, not {sha!r}"
    if run.get('status') != 'completed':
        return f"run {run.get('id')} status is {run.get('status')!r}, not 'completed'"
    if run.get('conclusion') != 'success':
        return f"run {run.get('id')} conclusion is {run.get('conclusion')!r}, not 'success'"
    event = run.get('event')
    if event not in TRUSTED_EVENTS:
        return f"run {run.get('id')} triggered by untrusted event {event!r}"
    if run.get('pull_requests'):
        return f"run {run.get('id')} is attached to a pull request"
    repo_full = (run.get('repository') or {}).get('full_name')
    if repo_full is not None and repo_full != repo:
        return f"run {run.get('id')} belongs to repository {repo_full!r}, not {repo!r}"
    head_repo_full = (run.get('head_repository') or {}).get('full_name')
    if head_repo_full is not None and head_repo_full != repo:
        return f"run {run.get('id')} originates from fork/other repository {head_repo_full!r}"
    wf_path = run.get('path')
    if wf_path not in allowed_workflows:
        return f"run {run.get('id')} workflow path {wf_path!r} is not in trusted workflows {allowed_workflows}"
    return None


def _extract_zip_members(raw_zip: bytes) -> tuple[dict[str, bytes] | None, str | None]:
    try:
        zf = zipfile.ZipFile(io.BytesIO(raw_zip))
    except zipfile.BadZipFile:
        return None, 'release-qualification artifact is not a valid zip archive'

    with zf:
        infos = zf.infolist()
        if len(infos) > MAX_ARTIFACT_MEMBERS:
            return None, f'release-qualification artifact exceeds member limit ({len(infos)} > {MAX_ARTIFACT_MEMBERS})'
        members: dict[str, bytes] = {}
        total_bytes = 0
        for info in infos:
            if info.is_dir():
                continue
            if ((info.external_attr >> 16) & 0o170000) == 0o120000:
                return None, f'release-qualification artifact contains symlink {info.filename!r}'
            if info.file_size > MAX_MEMBER_BYTES:
                return None, f'release-qualification member {info.filename!r} exceeds size limit'
            total_bytes += info.file_size
            if total_bytes > MAX_ARTIFACT_ZIP_BYTES:
                return None, 'release-qualification artifact uncompressed size exceeds limit'
            raw_name = info.filename.replace('\\', '/')
            if raw_name.startswith('/') or '..' in raw_name.split('/'):
                return None, f'release-qualification artifact contains unsafe path {info.filename!r}'
            norm = posixpath.normpath(raw_name).lstrip('./')
            if norm in members:
                return None, f'release-qualification artifact contains duplicate entry {norm!r}'
            members[norm] = zf.read(info)
    return members, None


def _validate_receipt_json(name: str, data: bytes, sha: str, expected_line: str | None = None) -> str | None:
    try:
        doc = json.loads(data.decode('utf-8'))
    except (UnicodeDecodeError, ValueError):
        return f'{name} is not valid UTF-8 JSON'
    if not isinstance(doc, dict):
        return f'{name} must be a JSON object'
    if doc.get('schema') != 'vaulter.release-qualification/v1':
        return f'{name}: invalid qualification schema'
    if doc.get('source_sha') != sha:
        return f'{name}: source_sha does not match {sha}'
    gate = doc.get('ci_gate')
    if not isinstance(gate, dict) or gate.get('sha') != sha or gate.get('conclusion') != 'success':
        return f'{name}: ci_gate must be a success of {sha}'
    if expected_line is not None:
        if doc.get('release_line') != expected_line:
            return f'{name}: release_line is {doc.get("release_line")!r}, expected {expected_line!r}'
        if doc.get('channel') not in ('public', 'private'):
            return f'{name}: invalid channel {doc.get("channel")!r}'
        artifacts = doc.get('artifacts')
        if not isinstance(artifacts, dict) or not artifacts:
            return f'{name}: per-line artifact identities are missing'
        if expected_line.startswith('npm-'):
            for pkg_name, entry in artifacts.items():
                if not isinstance(entry, dict):
                    return f'{name}: artifact identity for {pkg_name!r} must be an object'
                ver = entry.get('version')
                sha256 = entry.get('tgz_sha256')
                integrity = entry.get('integrity')
                if not isinstance(ver, str) or not ver.strip():
                    return f'{name}: artifact {pkg_name!r} is missing version'
                if not isinstance(sha256, str) or re.fullmatch(r'[0-9a-f]{64}', sha256) is None:
                    return f'{name}: artifact {pkg_name!r} has invalid tgz_sha256'
                if not isinstance(integrity, str) or re.fullmatch(r'sha512-[A-Za-z0-9+/]+=*', integrity) is None:
                    return f'{name}: artifact {pkg_name!r} has invalid integrity'
    return None


def _validate_approval_json(data: bytes, sha: str, kind: str) -> str | None:
    try:
        doc = json.loads(data.decode('utf-8'))
    except (UnicodeDecodeError, ValueError):
        return 'artifact-approval.json is not valid UTF-8 JSON'
    if not isinstance(doc, dict):
        return 'artifact-approval.json must be a JSON object'
    if doc.get('status') != 'approved':
        return 'artifact-approval.json status is not approved'
    if doc.get('source_sha') != sha:
        return f'artifact-approval.json source_sha does not match {sha}'
    artifacts = doc.get('artifacts')
    slot = artifacts.get(kind) if isinstance(artifacts, dict) else None
    if not isinstance(slot, dict) or not slot:
        return f'artifact-approval.json lacks approved {kind} artifact entry'
    return None


def verify_qualification_artifact(
    api_url: str,
    repo: str,
    run_id: str,
    sha: str,
    token: str,
    *,
    require_files: tuple[str, ...] = (),
    require_any_files: tuple[tuple[str, ...], ...] = (),
    require_lines: tuple[str, ...] = (),
    require_approvals: tuple[str, ...] = (),
) -> str | None:
    payload = github_get_json(api_url, f'/repos/{repo}/actions/runs/{run_id}/artifacts?per_page=100', token)
    matched_artifact = None
    for artifact in payload.get('artifacts') or []:
        if artifact.get('name') == 'release-qualification' and artifact.get('expired') is False:
            size = artifact.get('size_in_bytes')
            if size is None or (isinstance(size, int) and 0 < size <= MAX_ARTIFACT_ZIP_BYTES):
                matched_artifact = artifact
                break
    if matched_artifact is None:
        return 'does not carry an unexpired non-empty release-qualification artifact'

    download_url = matched_artifact.get('archive_download_url')
    if not isinstance(download_url, str) or not download_url.startswith('https://'):
        artifact_id = matched_artifact.get('id')
        if artifact_id is None:
            return 'release-qualification artifact metadata lacks id and archive_download_url'
        download_url = f"{api_url.rstrip('/')}/repos/{repo}/actions/artifacts/{artifact_id}/zip"

    raw_zip = github_get_bytes(download_url, token)
    members, zip_err = _extract_zip_members(raw_zip)
    if zip_err is not None or members is None:
        return zip_err or 'unable to inspect release-qualification archive'

    for req_file in require_files:
        if req_file not in members:
            return f'release-qualification archive lacks required file {req_file}'

    for any_group in require_any_files:
        present = [name for name in any_group if name in members]
        if not present:
            return f'release-qualification archive lacks any of {list(any_group)}'
        for name in present:
            if name.endswith('.json'):
                err = _validate_receipt_json(name, members[name], sha)
                if err is not None:
                    return err

    for line in require_lines:
        filename = f'{line}.json'
        if filename not in members:
            return f'release-qualification archive lacks per-line receipt {filename}'
        err = _validate_receipt_json(filename, members[filename], sha, expected_line=line)
        if err is not None:
            return err

    for kind in require_approvals:
        if 'artifact-approval.json' not in members:
            return f'release-qualification archive lacks external artifact-approval.json required for {kind}'
        err = _validate_approval_json(members['artifact-approval.json'], sha, kind)
        if err is not None:
            return err

    if not (require_files or require_any_files or require_lines or require_approvals):
        receipt_names = [name for name in members if name.endswith('.json') and name in ('qualification.json', 'oci.json', 'npm-vue.json', 'php-public.json')]
        if not receipt_names:
            return 'release-qualification archive lacks a qualification receipt'
        for name in receipt_names:
            err = _validate_receipt_json(name, members[name], sha)
            if err is not None:
                return err

    return None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='Resolve & verify trusted release-qualification run.')
    parser.add_argument('--repo', required=True)
    parser.add_argument('--sha', required=True)
    parser.add_argument('--run-id', default='')
    parser.add_argument('--allowed-workflow', action='append', default=[])
    parser.add_argument('--extra-workflow', default='')
    parser.add_argument('--require-file', action='append', default=[])
    parser.add_argument('--require-any-file', action='append', default=[])
    parser.add_argument('--require-line', action='append', default=[])
    parser.add_argument('--require-approval', action='append', choices=['npm', 'oci'], default=[])
    parser.add_argument('--api-url', default=os.environ.get('GITHUB_API_URL', 'https://api.github.com'))
    parser.add_argument('--github-output', default='')
    args = parser.parse_args(argv)

    if REPO_RE.fullmatch(args.repo) is None:
        refuse(f'invalid repository {args.repo!r}')
    if SHA40_RE.fullmatch(args.sha) is None:
        refuse(f'invalid source SHA {args.sha!r}')
    require_files = tuple(dict.fromkeys(f.strip().lstrip('./') for f in args.require_file if f.strip()))
    require_any_files = tuple(
        tuple(dict.fromkeys(part.strip().lstrip('./') for part in group.split(',') if part.strip()))
        for group in args.require_any_file
        if group.strip()
    )
    require_lines = tuple(dict.fromkeys(line.strip() for line in args.require_line if line.strip()))
    for line in require_lines:
        if LINE_RE.fullmatch(line) is None:
            refuse(f'invalid release line {line!r}')
    require_approvals = tuple(dict.fromkeys(args.require_approval))

    token = (os.environ.get('GH_TOKEN') or os.environ.get('GITHUB_TOKEN') or '').strip()
    if not token:
        refuse('GH_TOKEN or GITHUB_TOKEN is required to inspect qualification runs')
    allowed_workflows = parse_allowed_workflows(args.allowed_workflow, args.extra_workflow)

    selected: dict | None = None
    explicit_id = args.run_id.strip()
    if explicit_id:
        if RUN_ID_RE.fullmatch(explicit_id) is None:
            refuse(f'invalid qualification_run_id {explicit_id!r}')
        run = github_get_json(args.api_url, f'/repos/{args.repo}/actions/runs/{explicit_id}', token)
        err = validate_run(run, args.repo, args.sha, allowed_workflows)
        if err is not None:
            refuse(err)
        art_err = verify_qualification_artifact(
            args.api_url,
            args.repo,
            explicit_id,
            args.sha,
            token,
            require_files=require_files,
            require_any_files=require_any_files,
            require_lines=require_lines,
            require_approvals=require_approvals,
        )
        if art_err is not None:
            refuse(f'run {explicit_id}: {art_err}')
        selected = run
    else:
        encoded_sha = urllib.parse.quote(args.sha, safe='')
        runs_payload = github_get_json(
            args.api_url,
            f'/repos/{args.repo}/actions/runs?head_sha={encoded_sha}&status=completed&per_page=100',
            token,
        )
        candidates = sorted(
            (r for r in (runs_payload.get('workflow_runs') or []) if isinstance(r, dict)),
            key=lambda r: int(r.get('id') or 0),
            reverse=True,
        )
        rejected_reasons: list[str] = []
        for run in candidates:
            if validate_run(run, args.repo, args.sha, allowed_workflows) is not None:
                continue
            run_id = str(run.get('id') or '')
            if RUN_ID_RE.fullmatch(run_id) is None:
                continue
            art_err = verify_qualification_artifact(
                args.api_url,
                args.repo,
                run_id,
                args.sha,
                token,
                require_files=require_files,
                require_any_files=require_any_files,
                require_lines=require_lines,
                require_approvals=require_approvals,
            )
            if art_err is None:
                selected = run
                break
            rejected_reasons.append(f'run {run_id}: {art_err}')
        if selected is None:
            detail = f' ({"; ".join(rejected_reasons)})' if rejected_reasons else ''
            refuse(
                f'no completed successful trusted qualification run in {allowed_workflows} '
                f'for {args.sha} with compatible release-qualification artifact{detail} '
                f'(no SHA-only or latest-run fallback)'
            )

    run_id = str(selected['id'])
    if args.github_output:
        with open(args.github_output, 'a', encoding='utf-8') as handle:
            handle.write(f'run_id={run_id}\n')
    print(json.dumps({
        'run_id': run_id,
        'workflow_path': selected.get('path'),
        'head_sha': selected.get('head_sha'),
        'event': selected.get('event'),
        'conclusion': selected.get('conclusion'),
    }, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
