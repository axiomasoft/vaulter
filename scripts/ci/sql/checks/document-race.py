"""Exercise two physical PostgreSQL connections in the cluster created by schema.sh."""

import hashlib
import json
import os
import subprocess
import sys
import time
from contextlib import contextmanager


@contextmanager
def connect(psql):
    options = "-c statement_timeout=15s -c idle_in_transaction_session_timeout=25s"
    with subprocess.Popen(
        [psql, "-XqAt", "-v", "ON_ERROR_STOP=1", "-d", "office"],
        env={**os.environ, "PGOPTIONS": options},
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True,
    ) as process:
        try:
            yield process
        finally:
            process.terminate()


def send(session, sql):
    session.stdin.write(sql + "\n\\echo finished\n")
    session.stdin.flush()


def result(session):
    rows = []
    for line in session.stdout:
        value = line.rstrip("\n")
        if value == "finished":
            return rows
        rows.append(value)
    raise RuntimeError(f"psql closed before returning a result (exit {session.wait()})")


def query(session, sql):
    send(session, sql)
    return result(session)


def expect(observed, expected):
    if observed != expected:
        raise AssertionError(f"expected {expected!r}, got {observed!r}")


def payload(label):
    tree = {"type": "doc", "content": [{"type": "paragraph", "content": [{"type": "text", "text": label}]}]}
    body = json.dumps(tree, separators=(",", ":"), sort_keys=True)
    return f"'{body}'::jsonb, '{hashlib.sha256(body.encode()).hexdigest()}', {len(body.encode())}"


def snapshot_write(doc, label, generation=0, position=0):
    return f"""
        WITH accepted AS (
            UPDATE v_documents SET state_version=state_version+1
            WHERE id='{doc}' AND write_mode='snapshot' AND collab_epoch={generation} AND state_version={position}
            RETURNING id
        ), saved AS (
            UPDATE v_document_payloads SET (content,content_hash,content_size)=({payload(label)})
            FROM accepted WHERE document_id=accepted.id RETURNING document_id
        ) SELECT count(*) FROM saved;
    """


def wait_for_lock(holder, holder_pid, waiter_pid):
    deadline = time.monotonic() + 8
    while time.monotonic() < deadline:
        if query(holder, f"SELECT {holder_pid} = ANY(pg_blocking_pids({waiter_pid}));") == ["t"]:
            return
        time.sleep(0.02)
    raise TimeoutError("second connection did not wait on the first writer")


def run_case(first, second, holder_pid, waiter_pid, outcome):
    drive, folder, node, doc = query(first, "SELECT uuidv7(),uuidv7(),uuidv7(),uuidv7();")[0].split("|")
    query(first, f"""
        BEGIN;
        INSERT INTO v_drives(id,tenant_id,owner_id,owner_type,name) VALUES ('{drive}',1,1,'spec_{outcome}','Office');
        INSERT INTO v_nodes(id,tenant_id,drive_id,kind,name,name_key) VALUES ('{folder}',1,'{drive}','folder','Root','root');
        UPDATE v_drives SET root_node_id='{folder}' WHERE id='{drive}';
        INSERT INTO v_nodes(id,tenant_id,drive_id,parent_id,kind,name,name_key)
            VALUES ('{node}',1,'{drive}','{folder}','document','Race','race');
        INSERT INTO v_documents(id,tenant_id,node_id,type,schema_version) VALUES ('{doc}',1,'{node}','vaulter/rich-text',1);
        INSERT INTO v_document_payloads(document_id,content,content_hash,content_size) VALUES ('{doc}',{payload("initial")});
        COMMIT;
    """)
    token = f"SELECT write_mode,collab_epoch,state_version FROM v_documents WHERE id='{doc}';"
    for session in (first, second):
        expect(query(session, token), ["snapshot|0|0"])

    query(first, "BEGIN ISOLATION LEVEL READ COMMITTED;")
    if outcome == "switch":
        expect(query(first, f"""
            UPDATE v_documents SET write_mode='journal', collab_epoch=collab_epoch+1, state_version=state_version+1
            WHERE id='{doc}' AND write_mode='snapshot' AND collab_epoch=0 AND state_version=0 RETURNING id;
        """), [doc])
    else:
        expect(query(first, snapshot_write(doc, "first")), ["1"])

    query(second, "BEGIN ISOLATION LEVEL READ COMMITTED;")
    send(second, snapshot_write(doc, "second"))
    wait_for_lock(first, holder_pid, waiter_pid)
    query(first, "ROLLBACK;" if outcome == "rollback" else "COMMIT;")
    accepted = result(second)
    expect(accepted, ["1"] if outcome == "rollback" else ["0"])
    query(second, "COMMIT;")
    if outcome == "switch":
        # Even a refreshed position cannot authorize snapshot writes while the journal owns the document.
        expect(query(second, snapshot_write(doc, "second", generation=1, position=1)), ["0"])

    final = query(first, f"""
        SELECT d.write_mode,d.collab_epoch,d.state_version,p.content #>> '{{content,0,content,0,text}}'
        FROM v_documents d JOIN v_document_payloads p ON p.document_id=d.id WHERE d.id='{doc}';
    """)
    expected = {"commit": "snapshot|0|1|first", "rollback": "snapshot|0|1|second", "switch": "journal|1|1|initial"}
    expect(final, [expected[outcome]])
    print(f"OK document race {outcome}: blocked=yes second_written={accepted[0]} final={final[0]}")


if __name__ == "__main__":
    with connect(sys.argv[1]) as first, connect(sys.argv[1]) as second:
        holder_pid = int(query(first, "SELECT pg_backend_pid();")[0])
        waiter_pid = int(query(second, "SELECT pg_backend_pid();")[0])
        for outcome in ("commit", "rollback", "switch"):
            run_case(first, second, holder_pid, waiter_pid, outcome)
