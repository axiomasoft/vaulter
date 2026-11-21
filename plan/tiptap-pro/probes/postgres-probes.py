"""Isolated PostgreSQL 18 experiments for P/Q, SSE order, and tool recovery."""

import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile
import uuid

# Load the same psql connection and lock barrier used by the plan's document race.
from runpy import run_path

PROBE_DIR = Path(__file__).resolve().parent
PLAN_DIR = PROBE_DIR.parent.parent
race_helpers = run_path(str(PLAN_DIR / "checks/database/document-race.py"))
connect = race_helpers["connect"]
expect = race_helpers["expect"]
query = race_helpers["query"]
send = race_helpers["send"]
result = race_helpers["result"]
wait_for_lock = race_helpers["wait_for_lock"]


def proposal_pair(connection):
    ids = [str(uuid.uuid4()) for _ in range(2)]
    query(connection, f"""
        INSERT INTO v_ai_proposals (
            proposal_id, tenant_id, project_scope, effective_subject, resource_id,
            command_name, read_token_hash, base_generation, base_position, policy_revision,
            schema_revision, digest, approval_mode, decision, expires_at
        )
        SELECT id, 'tenant-test', 'legal', 'initiator', 'contract', 'text.replace_exact@1',
            digest, 3, 27, '1', '1', digest, 'human', 'pending', clock_timestamp() + interval '1 hour'
        FROM (VALUES ('{ids[0]}'::uuid, decode(repeat('01',32),'hex')),
                     ('{ids[1]}'::uuid, decode(repeat('02',32),'hex'))) AS seed(id,digest);
    """)
    return ids


def decide(proposal, reviewer, decision, digest="01", lock_before_check=True):
    # Inputs are constants and generated UUIDs. The UPDATE plus dependent inserts
    # are one statement, inside the review request's transaction.
    lock = f"""locked AS MATERIALIZED (
        SELECT proposal_id, decision, expires_at FROM v_ai_proposals
        WHERE proposal_id='{proposal}' AND tenant_id='tenant-test' AND project_scope='legal'
        FOR UPDATE
    ),""" if lock_before_check else ""
    expiry = "AND EXISTS (SELECT FROM locked WHERE decision='pending' AND expires_at > clock_timestamp())" \
        if lock else "AND p.expires_at > clock_timestamp()"
    return f"""
        WITH {lock} decided AS (
            UPDATE v_ai_proposals AS p
            SET decision='{decision}', approver_subject='{reviewer}', decided_at=clock_timestamp()
            WHERE p.proposal_id='{proposal}' AND p.tenant_id='tenant-test'
                AND p.project_scope='legal' AND p.decision='pending'
                AND p.digest=decode(repeat('{digest}',32),'hex')
                {expiry}
            RETURNING p.*
        ), routed AS (
            INSERT INTO v_ai_owner_routes (
                tenant_id,project_scope,effective_subject,resource_id,command_id,proposal_id,
                request_fingerprint,owner_key,dedupe_until
            )
            SELECT tenant_id,project_scope,effective_subject,resource_id,uuidv7()::text,proposal_id,
                digest,'documents.single_text@1',clock_timestamp()+interval '1 day'
            FROM decided WHERE decision='approved'
            RETURNING proposal_id
        ), staged AS (
            INSERT INTO v_ai_dispatches(proposal_id) SELECT proposal_id FROM routed
            RETURNING event_id
        )
        SELECT (SELECT count(*) FROM decided), (SELECT count(*) FROM staged);
    """


def decision_state(connection, proposal):
    return query(connection, f"""
        SELECT decision, coalesce(approver_subject,'-'),
            (SELECT count(*) FROM v_ai_owner_routes WHERE proposal_id=p.proposal_id),
            (SELECT count(*) FROM v_ai_dispatches WHERE proposal_id=p.proposal_id)
        FROM v_ai_proposals p WHERE proposal_id='{proposal}';
    """)[0]


def check_decision_race(writer, waiter, writer_pid, waiter_pid, first, rollback=False):
    proposal, independent = proposal_pair(writer)
    opposite = "rejected" if first == "approved" else "approved"
    query(writer, "BEGIN ISOLATION LEVEL READ COMMITTED;")
    expect(query(writer, decide(proposal, "reviewer-1", first)), [f"1|{int(first == 'approved')}"])
    expect(decision_state(waiter, proposal), "pending|-|0|0")
    send(waiter, decide(proposal, "reviewer-2", opposite))
    wait_for_lock(writer, writer_pid, waiter_pid)
    query(writer, "ROLLBACK;" if rollback else "COMMIT;")
    expected = opposite if rollback else first
    expect(result(waiter), [f"1|{int(expected == 'approved')}"] if rollback else ["0|0"])
    winner = "reviewer-2" if rollback else "reviewer-1"
    expected_counts = "1|1" if expected == "approved" else "0|0"
    state = f"{expected}|{winner}|{expected_counts}"
    expect(decision_state(waiter, proposal), state)
    expect(decision_state(writer, independent), "pending|-|0|0")
    expect(query(waiter, decide(proposal, "reviewer-2", opposite)), ["0|0"])
    expect(query(waiter, decide(independent, "reviewer-2", "approved")), ["0|0"])
    expect(decision_state(writer, independent), "pending|-|0|0")
    return {"first": first, "first_rolled_back": rollback, "second_waited": True,
            "outcome": expected, "reviewer": winner, "route_count": int(expected == "approved"),
            "dispatch_count": int(expected == "approved"), "Q": "pending"}


def check_expiry(writer, waiter, writer_pid, waiter_pid, lock_before_check):
    proposal, _ = proposal_pair(writer)
    query(writer, f"""
        UPDATE v_ai_proposals SET expires_at=clock_timestamp()+interval '1.5 seconds'
        WHERE proposal_id='{proposal}';
        BEGIN;
        SELECT proposal_id FROM v_ai_proposals WHERE proposal_id='{proposal}' FOR UPDATE;
    """)
    send(waiter, decide(proposal, "reviewer-2", "approved", lock_before_check=lock_before_check))
    wait_for_lock(writer, writer_pid, waiter_pid)
    expect(query(writer, f"SELECT expires_at > clock_timestamp() FROM v_ai_proposals WHERE proposal_id='{proposal}';"), ["t"])
    query(writer, f"""
        SELECT pg_sleep_until(expires_at + interval '25 milliseconds')
            FROM v_ai_proposals WHERE proposal_id='{proposal}';
        COMMIT;
    """)
    expect(result(waiter), ["0|0"] if lock_before_check else ["1|1"])
    return decision_state(writer, proposal).split("|")[0]


def check_stream_order(writer, waiter, writer_pid, waiter_pid):
    query(writer, """
        CREATE TABLE probe_events (
            id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            session_id text NOT NULL,
            value text NOT NULL
        );
        CREATE TABLE probe_streams (session_id text PRIMARY KEY, last_seq bigint NOT NULL);
        INSERT INTO probe_streams VALUES ('session-test', 0), ('other-session', 0);
        BEGIN;
        INSERT INTO probe_events (session_id,value) VALUES ('session-test','earlier allocation');
    """)
    later = query(waiter, """
        INSERT INTO probe_events (session_id,value) VALUES ('session-test','earlier commit')
        RETURNING id;
    """)
    expect(later, ["2"])
    expect(query(waiter, "SELECT id FROM probe_events WHERE session_id='session-test';"), ["2"])
    query(writer, "COMMIT;")
    expect(query(waiter, "SELECT id FROM probe_events WHERE session_id='session-test' AND id>2;"), [])
    expect(query(waiter, "SELECT id FROM probe_events ORDER BY id;"), ["1", "2"])

    query(writer, """
        TRUNCATE probe_events;
        ALTER TABLE probe_events DROP CONSTRAINT probe_events_pkey;
        ALTER TABLE probe_events ADD PRIMARY KEY (session_id,id);
    """)
    ordered_insert = """
        WITH assigned AS (
            UPDATE probe_streams SET last_seq=last_seq+1
            WHERE session_id='{session_id}' RETURNING last_seq, session_id
        )
        INSERT INTO probe_events (session_id,id,value)
        SELECT session_id,last_seq,'delta' FROM assigned RETURNING id;
    """
    query(writer, "BEGIN;")
    expect(query(writer, ordered_insert.format(session_id="session-test")), ["1"])
    expect(query(waiter, ordered_insert.format(session_id="other-session")), ["1"])
    send(waiter, ordered_insert.format(session_id="session-test"))
    wait_for_lock(writer, writer_pid, waiter_pid)
    query(writer, "COMMIT;")
    expect(result(waiter), ["2"])
    expect(query(waiter, "SELECT id FROM probe_events WHERE session_id='session-test' ORDER BY id;"), ["1", "2"])

    query(writer, "BEGIN;")
    expect(query(writer, ordered_insert.format(session_id="session-test")), ["3"])
    query(writer, "ROLLBACK;")
    expect(query(waiter, ordered_insert.format(session_id="session-test")), ["3"])
    return {"identity_cursor_skipped_committed_late_event": 1, "serialized_session_sequences": [1, 2, 3],
            "different_session_written_without_wait": True, "rolled_back_sequence_reused": True}


def check_tool_recovery(first, second):
    query(first, """
        CREATE TABLE probe_tools (
            run_id text NOT NULL, call_id text NOT NULL, digest text NOT NULL,
            command_id uuid NOT NULL DEFAULT uuidv7(), receipt_id uuid,
            PRIMARY KEY (run_id, call_id), UNIQUE (command_id)
        );
        CREATE TABLE probe_owner_effects (
            command_id uuid PRIMARY KEY, receipt_id uuid NOT NULL DEFAULT uuidv7()
        );
        INSERT INTO probe_tools(run_id, call_id, digest) VALUES ('run-1','slot-1','normalized-input');
    """)
    key_query = "SELECT command_id FROM probe_tools WHERE run_id='run-1' AND call_id='slot-1';"
    command = query(first, key_query)[0]
    # The owner commits independently. Dropping its reply leaves the ledger untouched.
    query(first, f"INSERT INTO probe_owner_effects(command_id) VALUES ('{command}');")
    expect(query(second, "SELECT receipt_id IS NULL FROM probe_tools;"), ["t"])
    expect(query(second, key_query), [command])
    receipt = query(second, f"SELECT receipt_id FROM probe_owner_effects WHERE command_id='{command}';")[0]
    query(second, f"""
        UPDATE probe_tools SET receipt_id='{receipt}'
        WHERE run_id='run-1' AND call_id='slot-1' AND digest='normalized-input';
    """)
    expect(query(first, f"""
        SELECT receipt_id, digest='different-input' FROM probe_tools
        WHERE run_id='run-1' AND call_id='slot-1';
    """), [f"{receipt}|f"])
    expect(query(second, "SELECT count(*) FROM probe_owner_effects;"), ["1"])

    other = query(second, """
        INSERT INTO probe_tools(run_id, call_id, digest) VALUES ('run-1','slot-2','normalized-input')
        RETURNING command_id;
    """)[0]
    assert command != other
    return {"receipt_recovered_from_owner": True, "owner_effects_after_retry": 1,
            "same_slot_other_digest_matches": False,
            "separate_slot_same_digest_has_distinct_command": True}


def run(pg_bin):
    with tempfile.TemporaryDirectory(prefix="vaulter-review-pg-") as temporary:
        work = Path(temporary)
        os.environ.update(PGHOST=str(work), PGPORT="5432", PGUSER="spec_owner", PGDATABASE="postgres")
        for key in ("PGSERVICE", "PGOPTIONS", "PGHOSTADDR"):
            os.environ.pop(key, None)

        def pg(command, *args, capture=False):
            return subprocess.run([str(pg_bin / command), *args], text=True,
                                  stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                                  check=True, timeout=30).stdout

        pg("initdb", "-D", str(work / "db"), "-U", "spec_owner",
           "--encoding=UTF8", "--no-locale", "--auth-local=trust", "--auth-host=reject")
        pg("pg_ctl", "-D", str(work / "db"), "-l", str(work / "postgres.log"),
           "-o", f"-k {work} -c listen_addresses='' -c unix_socket_permissions=0700", "-w", "start")
        try:
            pg("createdb", "office")
            pg("psql", "-Xq", "-v", "ON_ERROR_STOP=1", "-d", "office",
               "-f", str(PLAN_DIR / "spec/database/sql/ai-core.sql"))
            psql = str(pg_bin / "psql")
            with connect(psql) as first, connect(psql) as second:
                version = query(first, "SHOW server_version;")[0]
                first_pid, second_pid = (int(query(session, "SELECT pg_backend_pid();")[0]) for session in (first, second))
                decision = [check_decision_race(first, second, first_pid, second_pid, initial, rollback)
                            for initial, rollback in [("approved", False), ("rejected", False), ("approved", True)]]
                expiry = {
                    "checked_before_lock": check_expiry(first, second, first_pid, second_pid, False),
                    "checked_after_lock": check_expiry(first, second, first_pid, second_pid, True),
                }
                stream = check_stream_order(first, second, first_pid, second_pid)
                tools = check_tool_recovery(first, second)
            return {"postgres": version, "decision_cases": decision, "expiry_race": expiry,
                    "sse_order": stream, "tool_recovery": tools}
        finally:
            pg("pg_ctl", "-D", str(work / "db"), "-m", "fast", "-w", "stop")


if __name__ == "__main__":
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("--pg-bin", type=Path, default=Path("/usr/lib/postgresql/18/bin"))
    cli.add_argument("--report", type=Path)
    args = cli.parse_args()
    report = json.dumps(run(args.pg_bin), indent=2) + "\n"
    if args.report:
        args.report.write_text(report)
    print(report, end="")
