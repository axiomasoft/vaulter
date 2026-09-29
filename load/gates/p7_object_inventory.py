"""P7 attempt-owned object expectations and bidirectional reconciliation."""

import re

import p7_chaos as gate


TOKEN = re.compile(r'^[A-Za-z0-9._-]+$')
SCOPE = re.compile(r'^(global|t(?:null|[0-9]+)|s(?:null|[A-Za-z0-9]+))$')


def final_key(scope, checksum):
    gate._require(isinstance(scope, str) and SCOPE.fullmatch(scope), 'invalid fixture CAS scope')
    gate._require(isinstance(checksum, str) and gate.CHECKSUM.fullmatch(checksum), 'invalid fixture checksum')
    return f'{scope}/{checksum[:2]}/{checksum[2:4]}/{checksum}'


def scope_for(fixture, operation):
    uid = fixture.get('upload_id')
    gate._require(uid is None or (isinstance(uid, str) and TOKEN.fullmatch(uid) and uid not in {'.', '..'}),
                  'invalid attempt upload id')
    multipart = operation in {'s3-put-part', 's3-complete', 'sweeper'}
    gate._require(isinstance(fixture.get('marker'), str) and TOKEN.fullmatch(fixture['marker'])
                  and fixture['marker'] not in {'.', '..'}, 'invalid attempt marker')
    return {'schema': 'p7-object-inventory/v1', 'upload_id': uid,
            'protocol': ('s3-multipart' if multipart else 'tus') if uid else None,
            'cas_scope': fixture.get('cas_scope') if uid else None,
            'marker': fixture['marker'], 'part_listing': {'status': 'observed', 'keys': []}}


def expectations(entities, inventory, attempt):
    require = gate._require
    require(inventory.get('schema') == 'p7-object-inventory/v1', 'missing object inventory')
    uid = inventory.get('upload_id')
    require(uid is None or (isinstance(uid, str) and TOKEN.fullmatch(uid) and uid not in {'.', '..'}),
            'invalid attempt upload id')
    marker = inventory.get('marker')
    require(isinstance(marker, str) and TOKEN.fullmatch(marker) and marker not in {'.', '..'}, 'invalid attempt marker')
    upload = next((row for row in entities if row['entity'] == 'upload_session'), None)
    blob = next((row for row in entities if row['entity'] == 'blob'), None)
    parts = [row for row in entities if row['entity'] == 'upload_part']
    listing = inventory.get('part_listing')
    require(isinstance(listing, dict), 'missing object inventory listing')
    require(listing.get('status') == 'observed', 'object inventory observation error')
    keys = listing.get('keys')
    require(isinstance(keys, list) and all(isinstance(key, str) for key in keys)
            and keys == sorted(set(keys)), 'invalid or duplicate inventory keys')
    if not uid:
        require(upload is None and blob is None and not parts and not keys, 'unscoped durable object')
        key = f'tmp/uploads/{attempt["run_id"]}/{inventory["marker"]}/not-applicable'
        return [{'key': key, 'role': 'not-applicable', 'required': False, 'checksum': None,
                 'size': 0, 'durable_identity': entities[0]['entity_id']}]
    require(upload is not None and upload.get('upload_id') == uid, 'missing or foreign upload session')
    protocol = inventory.get('protocol')
    require(protocol in {'tus', 's3-multipart'}, 'invalid inventory protocol')
    expected_protocol = 's3-multipart' if attempt['operation'] in {'s3-put-part', 's3-complete', 'sweeper'} else 'tus'
    require(protocol == expected_protocol, 'inventory protocol contradicts operation')
    require(upload['status'] in {'initiated', 'uploading', 'assembled', 'committed', 'aborted', 'expired'},
            'unknown durable upload status')
    require(upload['status'] != 'committed' or blob is not None, 'committed upload is missing its blob')
    received = upload.get('received_bytes')
    require(gate._int(received) and received >= 0, 'upload received bytes')
    terminal = upload['status'] in {'committed', 'aborted', 'expired'} or blob is not None
    prefix = f'tmp/uploads/{uid}.parts/'
    require(all(key.startswith(prefix) and len(key) > len(prefix)
                and all(piece not in {'', '.', '..'} for piece in key[len(prefix):].split('/')) for key in keys),
            'foreign inventory key')
    before = inventory.get('final_before')
    final = final_key(inventory.get('cas_scope'), attempt['payload_checksum'])
    require(isinstance(before, dict) and before.get('key') == final and before.get('observation') == 'absent'
            and before.get('exists') is False and before.get('checksum') is None and before.get('size') == 0,
            'final candidate has no observed absence before fault')
    if blob is not None:
        require(upload.get('assembled_blob_id') == blob['entity_id'], 'blob does not join upload session')
        require(blob.get('storage_key') == final, 'object does not join blob key')
        require(blob.get('checksum') == attempt['payload_checksum'],
                'blob checksum contradicts payload and CAS key')
    staged = not terminal and received > 0 and (protocol == 'tus' or upload['status'] == 'assembled')
    expected = [{'key': f'tmp/uploads/{uid}', 'role': 'staged', 'required': staged,
                 'checksum': attempt['payload_checksum'] if staged else None,
                 'size': received if staged else 0, 'durable_identity': upload['entity_id']},
                {'key': final, 'role': 'final', 'required': blob is not None,
                 'checksum': attempt['payload_checksum'] if blob else None,
                 'size': blob.get('size') if blob else 0,
                 'durable_identity': (blob or upload)['entity_id']}]
    part_rows = {}
    for part in parts:
        require(protocol == 's3-multipart' and part['entity_id'].startswith(upload['entity_id'] + ':'),
                'part does not join upload session')
        number = part['entity_id'].rsplit(':', 1)[-1]
        require(number.isdigit() and int(number) > 0 and str(int(number)) == number, 'invalid part identity')
        require(prefix + number not in part_rows, 'duplicate part identity')
        require(gate._int(part.get('size')) and part['size'] >= 0, 'invalid part size')
        part_rows[prefix + number] = part
    if protocol == 's3-multipart' and not terminal and upload['status'] != 'assembled':
        # received_bytes is the sum of recorded parts while the upload is open.
        # Empty joins cannot prove absence after both a part row and its bytes are lost.
        require(sum(part['size'] for part in part_rows.values()) == received,
                'multipart received bytes contradict durable parts')
    part_keys = set(keys) | set(part_rows)
    if protocol == 's3-multipart':
        part_keys.add(prefix + '1')  # Prepared/attempted part remains observable if its row is gone.
    for key in sorted(part_keys):
        part = part_rows.get(key)
        present = part is not None and not terminal
        expected.append({'key': key, 'role': 'part', 'required': present,
                         'checksum': part.get('checksum') if present else None,
                         'size': part.get('size') if present else 0,
                         'durable_identity': (part or upload)['entity_id']})
    return sorted(expected, key=lambda row: row['key'])


def reconcile(expected, observations):
    gate._require(len(observations) == len({row['key'] for row in observations}), 'duplicate object observation')
    actual = {row['key']: row for row in observations}
    gate._require(set(actual) == {row['key'] for row in expected}, 'incomplete or extra object observations')
    joins = []
    for item in expected:
        obj = actual[item['key']]
        if (obj.get('observation') != ('observed' if obj.get('exists') is True else 'absent')
                or not isinstance(obj.get('exists'), bool) or not gate._int(obj.get('size')) or obj['size'] < 0):
            status = 'observation-error'
        elif obj.get('exists') is not item['required']:
            status = 'missing-object' if item['required'] else 'orphan-object'
        elif item['required'] and obj.get('checksum') != item['checksum']:
            status = 'checksum-mismatch'
        elif obj.get('size') != item['size'] or (not item['required'] and obj.get('checksum') is not None):
            status = 'size-mismatch'
        else:
            status = ('not-applicable' if item['role'] == 'not-applicable' else
                      'matched' if item['required'] else 'matched-absent')
        for direction in ('db-to-object', 'object-to-db'):
            joins.append({'join': direction, 'object_key': item['key'],
                          'durable_identity': item['durable_identity'] if direction == 'db-to-object' else item['key'],
                          'requirement': 'presence' if item['required'] else 'absence', 'status': status})
    return joins


def verify(rows, attempt):
    entities = rows['database.jsonl']
    inventory = attempt.get('object_inventory')
    gate._require(isinstance(inventory, dict), 'missing object inventory')
    expected = expectations(entities, inventory, attempt)
    gate._require(inventory.get('expected') == expected, 'stored object expectations contradict DB state')
    observed = rows['objects.jsonl']
    joins = reconcile(expected, observed)
    present_parts = sorted(row['key'] for row in observed if row.get('role') == 'part' and row.get('exists') is True)
    gate._require(present_parts == inventory['part_listing']['keys'], 'part listing contradicts object observations')
    for obj, item in zip(sorted(observed, key=lambda row: row['key']), expected):
        gate._require(obj.get('role') == item['role'] and obj.get('expected') == item, 'object expectation mismatch')
        gate._require(obj['schema'] == 'p7-object/v3' and obj['run_marker'] == attempt['run_id'], 'object schema/run mismatch')
        gate._require(obj.get('observation') == ('observed' if obj.get('exists') is True else 'absent'),
                      'object observation error')
        if item['required']:
            gate._require(isinstance(item['checksum'], str) and gate.CHECKSUM.fullmatch(item['checksum'])
                          and gate._int(item['size']) and item['size'] >= 0, 'missing DB/payload checksum or size')
    actual = [{key: row.get(key) for key in ('join', 'object_key', 'durable_identity', 'requirement', 'status')}
              for row in rows['reconcile.jsonl']]
    gate._require(all(isinstance(row['object_key'], str) and row['object_key']
                      and row['join'] in {'db-to-object', 'object-to-db'} for row in actual), 'invalid inventory join identity')
    gate._require(all(row['status'] in gate.RESOLVED for row in joins), 'missing required object or orphan object or observation error')
    gate._require(len(actual) == len(joins) and sorted(actual, key=lambda row: (row['object_key'], row['join'])) ==
                  sorted(joins, key=lambda row: (row['object_key'], row['join'])), 'reconcile contradicts raw inventory')
