#!/usr/bin/env python3
"""Real product operations used by the R11/P7 chaos matrix.

The old P7 runner issued a labelled GET for every cell.  This module prepares
an authenticated, run-scoped product fixture and dispatches the operation named
by the cell.  It intentionally contains no verdict logic; the matrix runner
joins the resulting package rows and objects before deciding evidence validity.
"""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Callable


APP = 'vaulter-load-app-1'
BASE_URL = 'http://127.0.0.1:8080'
NORMAL_BYTES = 4096
SPILL_BYTES = 3 * 1024 * 1024


class ProductOperationError(RuntimeError):
    """A fixture or product operation could not be executed."""


@dataclass(frozen=True)
class HttpResponse:
    status: int
    body: bytes
    headers: dict[str, str]


Request = Callable[[str, str, bytes, dict[str, str]], HttpResponse]
Artisan = Callable[[list[str]], subprocess.CompletedProcess[str]]
Sql = Callable[[str], str]


def http_request(method: str, path: str, body: bytes = b'',
                 headers: dict[str, str] | None = None) -> HttpResponse:
    request = urllib.request.Request(
        BASE_URL + path,
        data=body if method not in {'GET', 'HEAD'} else None,
        method=method,
        headers=headers or {},
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return HttpResponse(
                status=int(response.status),
                body=response.read(),
                headers={key.lower(): value for key, value in response.headers.items()},
            )
    except urllib.error.HTTPError as error:
        return HttpResponse(
            status=int(error.code),
            body=error.read(),
            headers={key.lower(): value for key, value in error.headers.items()},
        )
    except (OSError, TimeoutError):
        return HttpResponse(status=0, body=b'', headers={})


def docker_artisan(arguments: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ['docker', 'exec', APP, 'php', 'vendor/bin/testbench', *arguments],
        text=True,
        capture_output=True,
        timeout=120,
        check=False,
    )


def marker_for(run_id: str, cell_id: str, attempt: int, ordinal: int = 1) -> str:
    digest = hashlib.sha256(f'{run_id}|{cell_id}|{attempt}|{ordinal}'.encode()).hexdigest()[:12]
    safe_run_id = re.sub(r'[^a-zA-Z0-9._-]+', '-', run_id).strip('-')[:64] or 'run'
    return f'p7-{safe_run_id}-{digest}-a{attempt}-o{ordinal}'


def actor_for(run_id: str, cell_id: str, attempt: int) -> int:
    digest = hashlib.sha256(f'{run_id}|{cell_id}|{attempt}'.encode()).digest()
    return 100_000 + int.from_bytes(digest[:4], 'big') % 800_000


def payload_for(marker: str, size: int = NORMAL_BYTES) -> bytes:
    seed = (marker + '|').encode()
    return (seed * ((size // len(seed)) + 1))[:size]


def json_body(response: HttpResponse, context: str) -> dict:
    try:
        value = json.loads(response.body.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ProductOperationError(f'{context} did not return JSON (status {response.status})') from exc
    if not isinstance(value, dict):
        raise ProductOperationError(f'{context} did not return a JSON object')
    return value


def auth_headers(actor_id: int, *, content_type: str = 'application/json') -> dict[str, str]:
    return {
        'X-Load-Actor': str(actor_id),
        'X-Load-Tier': 'S',
        'Content-Type': content_type,
        'Accept': 'application/json',
    }


class ProductOperationRunner:
    """Prepare and execute one named P7 operation through its real public seam."""

    def __init__(self, *, request: Request = http_request, artisan: Artisan = docker_artisan,
                 sql: Sql | None = None) -> None:
        self.request = request
        self.artisan = artisan
        self.sql = sql

    def prepare(self, cell: dict, run_id: str, attempt: int) -> dict:
        operation = str(cell['operation'])
        marker = marker_for(run_id, str(cell['cell_id']), attempt)
        actor_id = actor_for(run_id, str(cell['cell_id']), attempt)
        payload_size = SPILL_BYTES if operation == 'spill' else NORMAL_BYTES
        payload = payload_for(marker, payload_size)
        fixture = {
            'run_id': run_id,
            'cell_id': cell['cell_id'],
            'attempt': attempt,
            'marker': marker,
            'actor_id': actor_id,
            'payload': payload,
            'payload_sha256': hashlib.sha256(payload).hexdigest(),
            'requested_bytes': len(payload),
        }

        if operation in {'cas-stage', 'write', 'lock-holder'}:
            return fixture

        if self.sql is not None:
            existing = self.sql(
                'SELECT id::text FROM public.v_storages '
                f'WHERE owner_id = {actor_id} AND deleted_at IS NULL LIMIT 1'
            )
            if existing.strip():
                raise ProductOperationError('actor fixture already owns a live storage')

        storage_response = self.request('GET', '/load/storage', b'', auth_headers(actor_id))
        if storage_response.status != 200:
            raise ProductOperationError(f'storage fixture returned {storage_response.status}')
        storage = json_body(storage_response, 'storage fixture')
        fixture['storage_id'] = storage.get('storage_id')
        fixture['root_node_id'] = storage.get('root_node_id')
        fixture['cas_scope'] = storage.get('cas_scope')
        if not isinstance(fixture['cas_scope'], str) or not re.fullmatch(
                r'global|t(?:null|[0-9]+)|s(?:null|[A-Za-z0-9]+)', fixture['cas_scope']):
            raise ProductOperationError('storage fixture omitted a valid CAS scope')

        if operation in {'outbox-mutation', 'pgbouncer'}:
            return fixture

        protocol = 's3-multipart' if operation in {'s3-put-part', 's3-complete', 'sweeper'} else 'tus'
        total = len(payload) if operation in {'s3-complete', 'cas-promote', 'upload-commit'} else len(payload) * 2
        create_body = json.dumps({
            'protocol': protocol,
            'parent_id': fixture['root_node_id'],
            'name': marker + '.bin',
            'total_size_bytes': total,
            'mime_type': 'application/octet-stream',
            'metadata': {'filename': marker + '.bin'},
        }).encode()
        create = self.request(
            'POST',
            f'/api/vaulter/storages/{fixture["storage_id"]}/resumable-uploads',
            create_body,
            auth_headers(actor_id),
        )
        if create.status != 201:
            raise ProductOperationError(f'upload fixture returned {create.status}')
        created = json_body(create, 'upload fixture')
        data = created.get('data')
        if not isinstance(data, dict) or not data.get('upload_id'):
            raise ProductOperationError('upload fixture omitted data.upload_id')
        fixture['upload_id'] = str(data['upload_id'])

        if operation in {'s3-complete', 'sweeper'}:
            staged = self._multipart_part(fixture, payload, ordinal=0)
            if staged['status'] != 201:
                raise ProductOperationError(f'multipart fixture returned {staged["status"]}')

        if operation == 'sweeper':
            if self.sql is None:
                raise ProductOperationError('sweeper fixture requires SQL access')
            upload_id = str(fixture['upload_id']).replace("'", "''")
            changed = self.sql(
                "UPDATE public.v_upload_sessions SET expires_at = now() - interval '1 minute' "
                f"WHERE upload_id = '{upload_id}' RETURNING upload_id"
            )
            if str(fixture['upload_id']) not in changed:
                raise ProductOperationError('sweeper fixture was not made due')

        return fixture

    def execute(self, cell: dict, fixture: dict, *, ordinal: int = 1) -> dict:
        operation = str(cell['operation'])
        if operation in {'tus-patch', 'upload-patch', 'cas-promote', 'upload-commit', 'spill'}:
            return self._tus_patch(fixture, ordinal)
        if operation == 's3-put-part':
            return self._multipart_part(fixture, fixture['payload'], ordinal)
        if operation == 's3-complete':
            return self._http(
                'POST', f'/api/vaulter/resumable-uploads/{fixture["upload_id"]}/complete',
                b'', auth_headers(int(fixture['actor_id'])), operation, ordinal,
            )
        if operation == 'abort':
            headers = auth_headers(int(fixture['actor_id']))
            headers['Tus-Resumable'] = '1.0.0'
            return self._http(
                'DELETE', f'/api/vaulter/resumable-uploads/{fixture["upload_id"]}',
                b'', headers, operation, ordinal,
            )
        if operation in {'outbox-mutation', 'pgbouncer'}:
            body = json.dumps({
                'name': marker_for(fixture['run_id'], fixture['cell_id'], fixture['attempt'], ordinal),
                'parent_id': fixture['root_node_id'],
            }).encode()
            return self._http(
                'POST', f'/api/vaulter/storages/{fixture["storage_id"]}/folders',
                body, auth_headers(int(fixture['actor_id'])), operation, ordinal,
            )
        if operation == 'sweeper':
            return self._artisan(['vaulter:sweep-uploads'], operation, fixture, ordinal)
        if operation in {'cas-stage', 'write', 'lock-holder'}:
            return self._artisan([
                'load:chaos-operation', operation,
                f'--run-prefix={fixture["marker"]}',
                f'--bytes={fixture["requested_bytes"]}',
                f'--ordinal={ordinal}',
            ], operation, fixture, ordinal)
        raise ProductOperationError(f'unsupported P7 operation {operation}')

    def _tus_patch(self, fixture: dict, ordinal: int) -> dict:
        headers = auth_headers(int(fixture['actor_id']), content_type='application/offset+octet-stream')
        headers['Tus-Resumable'] = '1.0.0'
        headers['Upload-Offset'] = '0'
        return self._http(
            'PATCH', f'/api/vaulter/resumable-uploads/{fixture["upload_id"]}',
            fixture['payload'], headers, 'tus-patch', ordinal,
        )

    def _multipart_part(self, fixture: dict, payload: bytes, ordinal: int) -> dict:
        part = max(1, ordinal)
        path = (
            f'/api/vaulter/resumable-uploads/{fixture["upload_id"]}/parts'
            f'?part_no={part}&byte_offset=0'
        )
        return self._http(
            'POST', path, payload,
            auth_headers(int(fixture['actor_id']), content_type='application/octet-stream'),
            's3-put-part', ordinal,
        )

    def _http(self, method: str, path: str, body: bytes, headers: dict[str, str],
              operation: str, ordinal: int) -> dict:
        response = self.request(method, path, body, headers)
        return {
            'transport': 'http',
            'operation': operation,
            'ordinal': ordinal,
            'request_id': f'{operation}-{ordinal}',
            'status': response.status,
            'command_exit': None,
            'requested_bytes': len(body),
            'written_bytes': self._written_bytes(response),
        }

    @staticmethod
    def _written_bytes(response: HttpResponse) -> int | None:
        offset = response.headers.get('upload-offset')
        return int(offset) if offset is not None and offset.isdigit() else None

    def _artisan(self, arguments: list[str], operation: str, fixture: dict, ordinal: int) -> dict:
        result = self.artisan(arguments)
        measured = self._last_json_object(result.stdout)
        return {
            'transport': 'artisan',
            'operation': operation,
            'ordinal': ordinal,
            'request_id': f'{operation}-{ordinal}',
            'status': 200 if result.returncode == 0 else 500,
            'command_exit': result.returncode,
            'requested_bytes': int(measured.get('requested_bytes', fixture['requested_bytes'])),
            'written_bytes': measured.get('written_bytes'),
            'error_class': measured.get('error_class'),
            'entity_id': measured.get('entity_id'),
        }

    @staticmethod
    def _last_json_object(stdout: str) -> dict:
        for line in reversed(stdout.splitlines()):
            try:
                value = json.loads(line)
            except json.JSONDecodeError:
                continue
            if isinstance(value, dict):
                return value
        return {}
