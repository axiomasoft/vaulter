#!/usr/bin/env python3
"""Resolve and verify a trusted GitHub Actions qualification run for an exact source SHA.

Fails closed unless an explicit (or SHA-matched completed/successful trusted workflow)
run from the same non-fork repository carries an unexpired `release-qualification` artifact.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

DEFAULT_ALLOWED_WORKFLOWS = ('.github/workflows/weekly-full.yml',)
TRUSTED_EVENTS = frozenset({'schedule', 'workflow_dispatch', 'push', 'workflow_call'})
WORKFLOW_PATH_RE = re.compile(r'^\.github/workflows/[A-Za-z0-9_.-]+\.ya?ml$')
SHA40_RE = re.compile(r'^[0-9a-f]{40}$')
REPO_RE = re.compile(r'^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$')
RUN_ID_RE = re.compile(r'^[1-9][0-9]*$')


def refuse(message: str) -> None:
    print(f'qualification-run: refused: {message}', file=sys.stderr)
    raise SystemExit(1)


def parse_allowed_workflows(base: list[str], extra: str) -> tuple[str, ...]:
    workflows: list[str] = []
    raw_items = list(base)
    if extra.strip():
        raw_items.extend(part.strip() for part in extra.split(',') if part.strip())
    for item in raw_items or list(DEFAULT_ALLOWED_WORKFLOWS):
        if WORKFLOW_PATH_RE.fullmatch(item) is None:
            refuse(f'untrusted workflow path specification {item!r}')
        if item not in workflows:
            workflows.append(item)
    return tuple(workflows)


def github_get_json(api_url: str, path: str, token: str) -> dict:
    parsed = urllib.parse.urlsplit(api_url)
    if parsed.scheme != 'https' or not parsed.netloc:
        refuse(f'GitHub API URL must use https://, got {api_url!r}')
    url = api_url.rstrip('/') + path
    request = urllib.request.Request(
        url,
        headers={
            'Accept': 'application/vnd.github+json',
            'Authorization': f'Bearer {token}',
            'X-GitHub-Api-Version': '2022-11-28',
            'User-Agent': 'vaulter-qualification-gate/1',
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            body = response.read().decode('utf-8')
    except urllib.error.HTTPError as exc:
        refuse(f'GitHub API {path} returned HTTP {exc.code}')
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        refuse(f'GitHub API {path} request failed: {exc}')
    try:
        payload = json.loads(body)
    except ValueError:
        refuse(f'GitHub API {path} returned invalid JSON')
    if not isinstance(payload, dict):
        refuse(f'GitHub API {path} returned non-object JSON')
    return payload


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


def has_qualification_artifact(api_url: str, repo: str, run_id: str, token: str) -> bool:
    payload = github_get_json(api_url, f'/repos/{repo}/actions/runs/{run_id}/artifacts?per_page=100', token)
    for artifact in payload.get('artifacts') or []:
        if artifact.get('name') == 'release-qualification' and artifact.get('expired') is False:
            size = artifact.get('size_in_bytes')
            if size is None or (isinstance(size, int) and size > 0):
                return True
    return False


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='Resolve & verify trusted release-qualification run.')
    parser.add_argument('--repo', required=True)
    parser.add_argument('--sha', required=True)
    parser.add_argument('--run-id', default='')
    parser.add_argument('--allowed-workflow', action='append', default=[])
    parser.add_argument('--extra-workflow', default='')
    parser.add_argument('--api-url', default=os.environ.get('GITHUB_API_URL', 'https://api.github.com'))
    parser.add_argument('--github-output', default='')
    args = parser.parse_args(argv)

    if REPO_RE.fullmatch(args.repo) is None:
        refuse(f'invalid repository {args.repo!r}')
    if SHA40_RE.fullmatch(args.sha) is None:
        refuse(f'invalid source SHA {args.sha!r}')
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
        if not has_qualification_artifact(args.api_url, args.repo, explicit_id, token):
            refuse(f'run {explicit_id} does not carry an unexpired release-qualification artifact')
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
        for run in candidates:
            if validate_run(run, args.repo, args.sha, allowed_workflows) is not None:
                continue
            run_id = str(run.get('id') or '')
            if RUN_ID_RE.fullmatch(run_id) and has_qualification_artifact(args.api_url, args.repo, run_id, token):
                selected = run
                break
        if selected is None:
            refuse(
                f'no completed successful trusted qualification run in {allowed_workflows} '
                f'for {args.sha} with release-qualification artifact (no SHA-only or latest-run fallback)'
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
