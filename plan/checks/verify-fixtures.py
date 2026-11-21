"""Run from any directory with Python 3 and jsonschema 4.x (Draft 2020-12). Offline, read-only."""

import argparse
import gzip
import hashlib
import json
import math
import re
import statistics
import sys
import subprocess
import tempfile
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker


SPEC = Path(__file__).resolve().parents[1]
STORAGE = SPEC / "checks/storage"


class FixtureError(ValueError):
    pass


def check(condition, where):
    if not condition:
        raise FixtureError(where)


def equal(actual, expected, where):
    if actual != expected:
        raise FixtureError(f"{where}: expected {expected!r}, got {actual!r}")


def load_example(name):
    return json.loads((SPEC / "examples" / name).read_text(encoding="utf-8"))


def sha(data):
    return hashlib.sha256(data).hexdigest()


SAFE_INTEGER = 2**53 - 1


def jcs_number(value):
    # RFC 8785 §3.2.2.3: ECMAScript Number::toString of the IEEE-754 double.
    # repr() yields the shortest round-trip digits; only their layout differs from ECMAScript.
    if isinstance(value, int):
        value = float(value)  # a JSON number is a double; 2**53+1 becomes 2**53
    check(math.isfinite(value), "JCS: NaN and Infinity are not JSON numbers")
    if value == 0:
        return "0"
    sign = "-" if value < 0 else ""
    mantissa, _, exponent = repr(abs(value)).partition("e")
    whole, _, fraction = mantissa.partition(".")
    significant = (whole + fraction).lstrip("0")
    leading_zeros = len(whole + fraction) - len(significant)
    digits = significant.rstrip("0")
    # value = 0.digits × 10**n, as in ECMA-262 Number::toString.
    k, n = len(digits), len(whole) + int(exponent or 0) - leading_zeros
    if k <= n <= 21:
        text = digits + "0" * (n - k)
    elif 0 < n <= 21:
        text = digits[:n] + "." + digits[n:]
    elif -6 < n <= 0:
        text = "0." + "0" * -n + digits
    else:
        e = n - 1
        text = digits[0] + ("." + digits[1:] if k > 1 else "") + "e" + ("+" if e >= 0 else "-") + str(abs(e))
    return sign + text


def jcs_string(value):
    out = ['"']
    for char in value:
        code = ord(char)
        check(not 0xD800 <= code <= 0xDFFF, "JCS: lone surrogate")
        if char in '"\\':
            out.append("\\" + char)
        elif char in "\b\t\n\f\r":
            out.append({"\b": "\\b", "\t": "\\t", "\n": "\\n", "\f": "\\f", "\r": "\\r"}[char])
        elif code < 0x20:
            out.append(f"\\u{code:04x}")
        else:
            out.append(char)  # including '/', DEL, U+2028 and U+2029
    return "".join(out) + '"'


def jcs_text(value):
    """RFC 8785 serializer written independently of json.dumps: explicit escaping,
    ECMAScript number layout and member order by UTF-16 code units."""
    if value is None or isinstance(value, bool):
        return {None: "null", True: "true", False: "false"}[value]
    if isinstance(value, (int, float)):
        return jcs_number(value)
    if isinstance(value, str):
        return jcs_string(value)
    if isinstance(value, list):
        return "[" + ",".join(jcs_text(item) for item in value) + "]"
    if isinstance(value, dict):
        for key in value:
            jcs_string(key)  # rejects lone surrogates before utf-16 ordering
        keys = sorted(value, key=lambda k: k.encode("utf-16-be"))
        return "{" + ",".join(jcs_string(k) + ":" + jcs_text(value[k]) for k in keys) + "}"
    raise FixtureError(f"JCS: unsupported value {type(value).__name__}")


def jcs(value):
    return jcs_text(value).encode("utf-8")


def digest_profile(value, where="proposal record"):
    # Office v1 digest records only carry safe integers; 27.0 is the same value as 27.
    if isinstance(value, bool) or value is None or isinstance(value, str):
        return
    if isinstance(value, (int, float)):
        check(math.isfinite(value) and float(value).is_integer() and abs(value) <= SAFE_INTEGER
              and float(value) == value, f"{where}: number outside the safe integer profile: {value!r}")
        return
    for item in (value.values() if isinstance(value, dict) else value):
        digest_profile(item, where)


def proposal_record(inputs, result):
    plan = {key: value for key, value in result.items() if key != "digest"}
    return {"version": 1, "prepare_input": inputs, "plan": plan}


def proposal_digest(inputs, result):
    record = proposal_record(inputs, result)
    digest_profile(record)
    return sha(jcs(record))


def indexed(items, key):
    result = {item[key]: item for item in items}
    equal(len(result), len(items), f"duplicate {key}")
    return result


def validate_message(validator, message, where):
    error = next(validator.iter_errors(message), None)
    if error:
        raise FixtureError(f"{where} {error.json_path}: {error.message}")


def matched_segment(segments, expected, where):
    # text.replace_exact@1: overlapping occurrences over the block text, exactly one, inside one run.
    text = "".join(s["text"] for s in segments)
    starts = [i for i in range(len(text)) if text.startswith(expected, i)]
    equal(len(starts), 1, where + " unique text")
    start, offset = starts[0], 0
    for segment in segments:
        if offset <= start and start + len(expected) <= offset + len(segment["text"]):
            return segment
        offset += len(segment["text"])
    raise FixtureError(where + " target crosses a formatting boundary")


def check_verified(inputs, result, command, plan, read, scenario):
    equal(result["mode"], inputs["mode"], scenario + " verify mode")
    equal(result["outcome"], "passed", scenario + " verification")
    checks = indexed(result["checks"], "name")
    check(all(item["passed"] for item in checks.values()), scenario + " postconditions")
    actual = {name: item["actual"] for name, item in checks.items()}
    args = command["arguments"]
    if command["command"] == "text.replace_exact@1":
        equal(read["data"]["block_id"], args["block_id"], scenario + " read target")
        equal(plan["targets"], [args["block_id"]], scenario + " prepared target")
        segment = matched_segment(read["data"]["segments"], args["expected"], scenario)
        marks = segment["marks"] if args["replacement"] else []
        equal(actual, {"text_exact": args["replacement"], "marks": marks}, scenario + " text result")
        return
    rows = indexed(read["data"]["rows"], "row_id")
    equal(plan["targets"], list(rows), scenario + " prepared row membership")
    expression = args["expression"]
    equal(expression["op"], "subtract", scenario + " operation")
    cells = [r["values"] for r in rows.values()]
    deltas = [c[expression["left_column_id"]] - c[expression["right_column_id"]] for c in cells]
    # check_events validates the formulas against row addresses after the human insertion.
    actual.pop("formulas")
    equal(actual, {
        "row_ids": list(rows),
        "calculated_values": deltas,
        "calculated_at": result["marker"],
        "sum": sum(deltas),
    }, scenario + " persisted formulas and independently calculated values")


def check_events(scenario):
    name, steps = scenario["id"], scenario["steps"]
    events = indexed(scenario["events"], "type")
    scheduled = [(e["before_step"], e["type"]) for e in events.values()]
    schedule = {
        "text_success": [(1, "seed_text"), (6, "human_edits_block")],
        "sheet_fixed_members": [(1, "seed_table"), (4, "human_inserts_row"), (5, "assert_cells_at_marker")],
        "sheet_current_rows": [(1, "seed_table"), (4, "human_inserts_row")],
        "lost_response": [(1, "seed_text"), (3, "human_edits_block"), (3, "drop_next_response")],
        "acl_revoked": [(1, "seed_text"), (3, "revoke_access")],
        "text_conflict": [(1, "seed_text"), (3, "human_edits_block")],
    }
    equal(scheduled, schedule[name], name + " event scheduling")
    seed = scenario["events"][0]["details"]
    for event in events.values():
        details = event["details"]
        if "resource_id" in details:
            equal(details["resource_id"], seed["resource_id"], name + " external event target")
    for step in steps:
        inputs, res = step["request"]["input"], step["response"]
        if "resource_id" in inputs:
            equal(inputs["resource_id"], seed["resource_id"], name + " seeded resource")
        if step["request"]["operation"] == "office.read" and res["ok"]:
            data = res["result"]["data"]
            if data["kind"] == "text_block" and data["block_id"] == "p42":
                equal(data["segments"], seed["p42_segments"], name + " seeded text")
                equal(res["result"]["marker"], seed["marker"], name + " seeded position")

    if "seed_table" in events:
        rows = list(seed["rows"])
        insert = events["human_inserts_row"]
        change = insert["details"]
        index = [r["row_id"] for r in rows].index(change["before_row_id"])
        after = rows[:index] + [change["row"]] + rows[index:]
        column_names = seed["columns_by_address"]
        equal(column_names, {"B": "plan", "C": "actual", "D": "delta"}, name + " formula coordinates")
        verify = steps[-1]["response"]["result"]
        equal(change["marker"]["position"], seed["marker"]["position"] + 1, name + " concurrent change")
        equal(verify["marker"]["position"], change["marker"]["position"] + 1, name + " sheet commit")
        equal(verify["marker"]["generation"], seed["marker"]["generation"], name + " commit generation")
        for n, step in enumerate(steps, 1):
            selector = step["request"]["input"].get("selector", {})
            if selector.get("kind") != "sheet_table":
                continue
            latest = n >= insert["before_step"]
            source = after if latest else rows
            if "row_ids" in selector:
                source = [r for r in source if r["row_id"] in selector["row_ids"]]
            result = step["response"]["result"]
            equal(result["marker"], change["marker"] if latest else seed["marker"], name + " sheet read marker")
            equal(result["data"]["rows"], [
                {"row_id": r["row_id"], "values": {c: r[c] for c in selector["columns"]}} for r in source
            ], name + " projected read")
        checks = {c["name"]: c["actual"] for c in verify["checks"]}
        at = {r["row_id"]: index for index, r in enumerate(after, seed["first_data_row"])}
        equal(checks["formulas"], [f"=C{at[r]}-B{at[r]}" for r in checks["row_ids"]], name + " relocated formulas")
        if "assert_cells_at_marker" in events:
            proof = events["assert_cells_at_marker"]["details"]
            equal(proof["marker"], verify["marker"], name + " untouched cell evidence")
            untouched = {f"D{at[r['row_id']]}": {"row_id": r["row_id"], "value": r["delta"], "has_formula": False}
                         for r in after if r["row_id"] not in checks["row_ids"]}
            equal(proof["cells"], untouched, name + " untouched cells")
            equal({v["value"] for v in untouched.values()}, {None, 77}, name + " preserve blank and existing cell")
        return

    if "human_edits_block" in events:
        change = events["human_edits_block"]["details"]
        equal(change["marker"]["position"], seed["marker"]["position"] + 1, name + " concurrent edit")
        equal(change["marker"]["generation"], seed["marker"]["generation"], name + " edit generation")
        if name == "text_conflict":
            equal(change["block_id"], "p42", name + " changed dependency")
            old = "".join(s["text"] for s in seed["p42_segments"])
            pair = change["replace"]
            check(pair["expected"] in old and pair["replacement"] != pair["expected"], name + " conflict cause")
        else:
            equal(change["block_id"], "p71", name + " unrelated edit")
            check(change["new_text"] != seed["p71_text"], name + " human edit")
            commit = next(s["response"]["result"] for s in steps if s["request"]["operation"] == "office.commit")
            equal(commit["marker"]["position"], change["marker"]["position"] + 1, name + " merged edit")
            if name == "text_success":
                read = steps[-1]["response"]["result"]["data"]
                equal((read["block_id"], read["segments"][0]["text"]), (change["block_id"], change["new_text"]), name + " preserved edit")
    if name == "lost_response":
        event = events["drop_next_response"]
        request = steps[event["before_step"] - 1]["request"]
        equal(event["details"], {"operation": request["operation"], "command_id": request["input"]["command_id"],
              "phase": "after_owner_commit_before_client_receives_response"}, name + " drop point")
    if name == "acl_revoked":
        revoke = events["revoke_access"]["details"]
        equal((revoke["subject"], set(revoke["permissions"])), ("requester", {"read", "edit"}), name + " effective permissions")


def check_scenario(scenario, validator):
    name = scenario["id"]
    reads, plans, commands, receipts = {}, {}, {}, {}
    failures = []
    for number, step in enumerate(scenario["steps"], 1):
        where = f"{name} exchange {number}"
        req, res = step["request"], step["response"]
        validate_message(validator, req, where + " request")
        validate_message(validator, res, where + " response")
        equal(req["operation"], res["operation"], where + " operation")
        op, inputs = req["operation"], req["input"]
        resource = inputs.get("resource_id")
        result = res.get("result")
        if op == "office.commit":
            command, plan, read = plans[inputs["proposal_id"]]
            equal(resource, command["resource_id"], where + " proposal resource")
            equal(inputs["digest"], plan["digest"], where + " committed digest")
        if not res["ok"]:
            error = res["error"]
            failures.append((op, error["code"], error["retryable"], error["next_action"]))
            continue
        if op == "office.read":
            equal(inputs["selector"]["kind"], result["data"]["kind"], where + " addressed read")
            if "after_receipt" in inputs:
                commit = receipts[inputs["after_receipt"]][0]
                equal(result["marker"]["generation"], commit["marker"]["generation"], where)
                check(result["marker"]["position"] >= commit["marker"]["position"], where + " freshness")
            reads[result["read_token"]] = resource, result
        elif op == "office.prepare":
            read_resource, read = reads[inputs["read_token"]]
            equal(resource, read_resource, where + " token resource")
            equal(result["base"], read["marker"], where + " token marker")
            equal(proposal_digest(inputs, result), result["digest"], where + " proposal digest")
            plans[result["proposal_id"]] = inputs, result, read
        elif op == "office.commit":
            equal(result["state"], "committed", where)
            equal(result["command_id"], inputs["command_id"], where)
            equal(result["marker"]["generation"], plan["base"]["generation"], where + " generation")
            check(result["marker"]["position"] > plan["base"]["position"], where + " commit position")
            if inputs["command_id"] in commands:
                equal(commands[inputs["command_id"]], (inputs, result), where + " idempotent retry")
            commands[inputs["command_id"]] = inputs, result
            receipts[result["receipt_id"]] = result, command, plan, read
        elif op == "office.status":
            equal(result, commands[inputs["command_id"]][1], where + " recovered receipt")
        elif op == "office.verify":
            commit, command, plan, read = receipts[inputs["receipt_id"]]
            equal(resource, command["resource_id"], where)
            equal(result["marker"], commit["marker"], where + " at_commit marker")
            equal(result["receipt_id"], commit["receipt_id"], where + " receipt")
            check_verified(inputs, result, command, plan, read, where)
    expected_failures = {
        "sheet_current_rows": [("office.commit", "scope_changed", False, "reread")],
        "acl_revoked": [(op, "resource_unavailable", False, "stop") for op in ("office.commit", "office.status")],
        "text_conflict": [("office.commit", "precondition_failed", False, "reread")],
    }
    equal(failures, expected_failures.get(name, []), name + " errors")
    if name == "lost_response":
        equal(len(receipts), 1, name + " exactly one logical receipt")


def negative_messages(validator, scenarios):
    text = scenarios["text_success"]["steps"]
    read, commit, verified = text[2]["request"], text[5]["request"], text[6]["response"]
    error = scenarios["acl_revoked"]["steps"][-1]["response"]
    inputs = commit["input"]
    selection = scenarios["sheet_fixed_members"]["steps"][1]["request"]
    sheet_inputs = selection["input"]
    duplicate_rows = {**sheet_inputs["selector"], "row_ids": ["r-travel", "r-travel"]}
    verified_result = verified["result"]
    contradictory_check = {**verified_result["checks"][0], "passed": False}
    sheet_verify = scenarios["sheet_fixed_members"]["steps"][-1]["response"]
    sheet_result = sheet_verify["result"]
    sheet_checks = indexed(sheet_result["checks"], "name")

    def verify_with_checks(response, checks):
        return {**response, "result": {**response["result"], "checks": checks}}

    negatives = {
        "unknown version": {**read, "version": 2},
        "unknown operation": {**read, "operation": "office.raw_sql"},
        "caller-supplied authority": {**read, "tenant_id": "other"},
        "unscoped selector": {**read, "input": {**read["input"], "selector": {"kind": "database"}}},
        "duplicate row selection": {**selection, "input": {**sheet_inputs, "selector": duplicate_rows}},
        "missing digest": {**commit, "input": {k: v for k, v in inputs.items() if k != "digest"}},
        "malformed digest": {**commit, "input": {**inputs, "digest": "0"}},
        "wrong result for operation": {**verified, "operation": "office.search"},
        "data on error": {**error, "result": {"title": "hidden"}},
        "contradictory verification": {**verified, "result": {**verified_result, "checks": [contradictory_check]}},
        "text verification omits marks": verify_with_checks(verified, verified_result["checks"][:1]),
        "spreadsheet verification uses only sum": verify_with_checks(sheet_verify, [sheet_checks["sum"]]),
        "duplicate check replaces formulas": verify_with_checks(sheet_verify, [
            sheet_checks["row_ids"] if name == "formulas" else item for name, item in sheet_checks.items()
        ]),
        "empty calculated values in passing proof": verify_with_checks(sheet_verify, [
            {**item, "actual": []} if name == "calculated_values" else item for name, item in sheet_checks.items()
        ]),
        "literals reported as saved formulas": verify_with_checks(sheet_verify, [
            {**item, "actual": ["21", "-21"]} if name == "formulas" else item for name, item in sheet_checks.items()
        ]),
        "privilege error marked retryable": {**error, "error": {**error["error"], "retryable": True}},
        "unknown error": {**error, "error": {**error["error"], "code": "undocumented"}},
    }
    for description, message in negatives.items():
        check(not validator.is_valid(message), f"schema accepted {description}")
    return len(negatives)


def adapter_negatives(validator, scenarios):
    """Schema-valid verify answers the adapter must still refuse (stale calculation marker,
    literals instead of formulas, swapped values, wrong receipt, lost marks)."""
    def last_verify(name, change):
        scenario = json.loads(json.dumps(scenarios[name]))
        step = [s for s in scenario["steps"] if s["request"]["operation"] == "office.verify"][-1]
        result = step["response"]["result"]
        checks = {c["name"]: c for c in result["checks"]}
        change(result, checks)
        validate_message(validator, step["response"], f"{name} mutated verify")
        return scenario

    def setter(name, value):
        return lambda result, checks: checks[name].__setitem__("actual", value)

    stale = {"generation": 1, "position": 12}
    cases = {
        "stale calculation marker": last_verify("sheet_fixed_members", setter("calculated_at", stale)),
        "verify marker before the receipt": last_verify("sheet_fixed_members", lambda r, c: (
            r.__setitem__("marker", stale), c["calculated_at"].__setitem__("actual", stale))),
        "literal formulas": last_verify("sheet_fixed_members", setter("formulas", ["=20", "=-20"])),
        "formulas of the other rows": last_verify("sheet_fixed_members", setter("formulas", ["=C4-B4", "=C2-B2"])),
        "swapped values with the same sum": last_verify("sheet_fixed_members", setter("calculated_values", [-20, 20])),
        "other receipt": last_verify("sheet_fixed_members", lambda r, c: r.__setitem__("receipt_id", "receipt-sheet-12")),
        "text other than the agreed replacement": last_verify("text_success", lambda r, c: (
            None if c.get("text_exact") is None else c["text_exact"].__setitem__("actual", "31"))),
        "bold lost": last_verify("text_success", lambda r, c: (
            None if c.get("marks") is None else c["marks"].__setitem__("actual", []))),
    }
    for name in ("sheet_fixed_members", "text_success"):  # control: the unmodified copy passes
        control = last_verify(name, lambda result, checks: None)
        check_scenario(control, validator)
        check_events(control)
    for name, scenario in cases.items():
        try:
            check_scenario(scenario, validator)
            check_events(scenario)
        except FixtureError:
            continue
        raise FixtureError(f"adapter accepted {name}")
    return len(cases)


def check_local_references(node):
    if isinstance(node, list):
        for child in node:
            check_local_references(child)
    if isinstance(node, dict):
        for key, value in node.items():
            if key in {"$ref", "$dynamicRef", "$recursiveRef"}:
                check(value.startswith("#"), f"external schema reference: {value}")
            check_local_references(value)


def verify_office():
    schema = json.loads((SPEC / "spec/schemas/office-v1.schema.json").read_text(encoding="utf-8"))
    check_local_references(schema)
    Draft202012Validator.check_schema(schema)
    validator = Draft202012Validator(schema, format_checker=FormatChecker())
    office = load_example("office-sdk.json")
    equal(office["fixture_version"], 1, "SDK fixture version")
    scenarios = indexed(office["scenarios"], "id")
    expected_steps = {"text_success": 8, "sheet_fixed_members": 5, "sheet_current_rows": 8,
                      "lost_response": 6, "acl_revoked": 4, "text_conflict": 3}
    equal({k: len(s["steps"]) for k, s in scenarios.items()}, expected_steps, "wire scenario coverage")
    for scenario in scenarios.values():
        check_scenario(scenario, validator)
        check_events(scenario)
    n = negative_messages(validator, scenarios)
    a = adapter_negatives(validator, scenarios)
    print(f"OK SDK: {sum(expected_steps.values())} exchanges, 68 messages, {n} rejected messages, "
          f"{a} schema-valid proofs refused by the adapter")


def verify_live():
    doc = load_example("live-write.json")
    equal(doc["fixture_version"], 1, "live fixture version")
    events = indexed(doc["timeline"], "step")
    order = "register_intent accept_human_edit prepare_on_copy commit_update_receipt_and_outbox crash_room_before_apply_and_drop_response query_status_as_authorized_actor open_next_room_owner replay_committed_prefix repeat_a42_and_deliver_outbox_twice read_at_commit_and_current".split()
    equal(list(events), order, "live timeline")
    initial, intent = doc["initial"], doc["agent_intent"]
    actors = indexed(doc["actors"], "id")
    check("edit" in actors[intent["effective_actor"]]["permissions"], "live effective actor")
    equal(intent["generation"], initial["generation"], "live generation")
    marker = {"generation": initial["generation"], "position": initial["head_seq"]}
    equal(intent["read_marker"], marker, "live read marker")
    equal(initial["applied_seq"], marker["position"], "initial live position")
    one, human, prepare, write, crash, status, owner, replay, retried, final = events.values()
    key = intent["command_id"]
    equal(one["assert"], {"command_id": key, "stored_intents": 1, "head_seq": marker["position"]}, "live register")
    blocks = {b["block_id"]: b["text"] for b in initial["blocks"]}
    check("edit" in actors[human["actor_id"]]["permissions"], "live human edit permission")
    blocks[human["block_id"]] = human["new_text"]
    previous, committed = marker["position"] + 1, marker["position"] + 2
    equal(human["assert"], {"head_seq": previous, "applied_seq": previous}, "human edit")
    equal(prepare["command_id"], key, "prepare key")
    equal(prepare["assert"], {"expected_head_seq": previous, "unchanged_in_live_room": True}, "live copy")
    replace, insert = intent["atomic_effects"]
    equal((replace["effect"], insert["effect"]), ("replace_exact", "insert_paragraph_after"), "live effects")
    target = replace["block_id"]
    condition, = intent["preconditions"]
    equal((condition["block_id"], condition["expected_text"]), (target, blocks[target]), "precondition")
    equal(blocks[target].count(replace["expected"]), 1, "live unique target")
    equal(insert["block_id"], target, "live insertion anchor")
    blocks[target] = blocks[target].replace(replace["expected"], replace["replacement"])
    expected_text = list(blocks.values())
    expected_text.insert(list(blocks).index(target) + 1, insert["text"])
    equal(write["command_id"], key, "write key")
    equal(write["assert"], {"head_seq": committed, "applied_seq": previous, "stored_receipts": 1}, "atomic commit")
    equal(crash["assert"]["durable_position"], committed, "crash durability")
    marker["position"] = committed
    receipt = status["assert"]["receipt_id"]
    equal(status["actor_id"], intent["effective_actor"], "live status actor")
    equal(status["assert"], {"command_id": key, "state": "committed", "receipt_id": receipt, "marker": marker}, "status")
    check(owner["owner_fence"] > initial["owner_fence"], "live owner fence")
    equal(owner["assert"]["a43_may_evaluate_against_position_41"], False, "block stale evaluation")
    equal(replay["byte_reference"], write["byte_reference"], "replay exact bytes")
    equal(replay["assert"], {"head_seq": committed, "applied_seq": committed, "a43_may_evaluate_against_position_42": True}, "recovered room")
    equal(retried["assert"], {"head_seq": committed, "receipt_id": receipt, "stored_receipts": 1, "inserted_paragraph_count": 1}, "duplicate delivery")
    equal(final["assert"], {"modes": ["at_commit", "current"], "marker": marker, "ordered_text": expected_text}, "live final")
    variants = {case["id"]: case["expect"] for case in doc["independent_variants"]}
    expected_variants = {
        "concurrent_duplicate": {"receipt_count": 1, "inserted_paragraph_count": 1, "head_seq": committed},
        "different_intent_same_key": {"key_conflict": True, "head_seq": committed, "receipt_count": 1},
        "partial_patch_failure": {"accepted_agent_updates": 0, "live_payment_text": condition["expected_text"], "inserted_paragraph_count": 0},
        "precondition_changed": {"outcome": "conflict", "live_payment_text": "Аванс в течение 20 дней", "inserted_paragraph_count": 0},
        "generation_restored": {"old_generation_writes_to_new_room": 0, "accepted_agent_updates": 0},
        "late_delivery_after_restore": {"old_generation_writes_to_new_room": 0, "authorized_historical_receipt_marker": marker},
        "stale_owner": {"updates_accepted_from_old_fence": 0},
        "edit_revoked": {"accepted_agent_updates": 0, "inserted_paragraph_count": 0},
        "read_revoked": {"durable_receipt_count": 1, "visible_receipts_to_revoked_actor": 0, "visible_content_to_revoked_actor": False},
        "projection_behind": {"minimum_read_marker": marker, "may_return_position_41_as_verified": False},
        "offline_target_edit_arrives_later": {"at_commit_payment_text": blocks[target], "current_read_uses_post_merge_position": True, "exact_current_payment_text_is_predeclared": False},
    }
    equal(variants, expected_variants, "live independent variants")
    print(f"OK live-write: {len(events)} ordered events, {len(variants)} fault variants")


def extended_amount(cells, row):
    formula = cells[f"D{row}"]["formula"]
    equal(formula, f"=B{row}*C{row}", "detail formula")
    return cells[f"B{row}"]["number"] * cells[f"C{row}"]["number"]


def verify_workbook():
    book = load_example("workbook.json")
    sheets = indexed(book["sheets"], "id")
    cells = sheets["detail"]["cells"]
    summary = sheets["summary"]["cells"]["B2"]
    detail = sheets["detail"]["name"]
    total = sheets["summary"]["name"] + "!B2"
    revision = book["initialRevision"]
    steps = book["steps"]
    equal([s["action"] for s in steps], [
        "recalculate", "set_number", "insert_row_before", "retry_command", "set_number", "cold_reopen"
    ], "workbook order")
    calc, edit, insert, retry, stale, cold = steps
    equal(summary["formula"], f"=SUM({detail}!D2:D3)", "summary formula")
    a, b = extended_amount(cells, 2), extended_amount(cells, 3)
    equal(calc["expect"], {detail + "!D2": a, detail + "!D3": b, total: a + b}, "initial recalc")
    equal(a + b, 35, "initial total")
    target = edit["target"]
    equal(book["fixtureVersion"], 1, "workbook fixture version")
    equal((target["sheetId"], target["cell"], edit["baseRevision"]), ("detail", "C3", revision), "edit target")
    equal(cells["C3"]["number"], target["expectedNumber"], "workbook precondition")
    cells["C3"]["number"] = edit["value"]
    b = extended_amount(cells, 3)
    revision += 1
    receipt_revision = revision
    equal(edit["expect"], {"revision": revision, detail + "!C3": edit["value"], detail + "!D3": b, total: a + b}, "recalc after edit")
    equal(a + b, 41, "updated total")
    equal(insert["target"], {"sheetId": "detail", "row": 2}, "row insertion target")
    equal(insert["baseRevision"], revision, "row insertion base")
    revision += 1
    # Moving rows 2..3 down by one shifts each relative address in both formulas.
    def below(address):
        return re.sub(r"([A-D])([23])", lambda m: m[1] + str(int(m[2]) + 1), address)
    moved = {
        below(addr): ({**cell, "formula": below(cell["formula"])} if "formula" in cell else cell)
        for addr, cell in cells.items()
    }
    summary = {**summary, "formula": below(summary["formula"])}
    a, b = extended_amount(moved, 3), extended_amount(moved, 4)
    equal(insert["expect"], {
        "revision": revision, detail + "!C3": moved["C3"]["number"], detail + "!C4": moved["C4"]["number"],
        detail + "!D3": {"formula": moved["D3"]["formula"], "number": a},
        detail + "!D4": {"formula": moved["D4"]["formula"], "number": b},
        total: {**summary, "number": a + b},
    }, "row movement and rewritten references")
    equal((retry["originalCommandId"], retry["actor"]), (edit["commandId"], edit["actor"]), "workbook retry identity")
    equal(retry["expect"], {"receiptRevision": receipt_revision, "currentRevision": revision, "newWrites": 0}, "workbook retry")
    col = stale["target"]["cell"]
    check(stale["commandId"] not in (edit["commandId"], insert["commandId"]), "conflict requires a fresh key")
    check(stale["baseRevision"] < revision and moved[col]["number"] != stale["target"]["expectedNumber"], "stale precondition")
    equal(stale["expect"], {"conflict": True, "currentRevision": revision, detail + "!C3": moved["C3"]["number"], detail + "!C4": moved["C4"]["number"]}, "stale edit leaves values intact")
    equal(cold["snapshotRevision"], revision, "cold source version")
    equal(cold["localSnapshotFaultInjection"], {"cacheOnly": {total: 999}}, "corrupt display cache")
    equal(cold["expectBeforeRecalculation"], {"verified": False}, "stale verification")
    equal(cold["expectAfterFullRecalculation"], {
        "currentRevision": revision, "calculatedAtRevision": revision, total: a + b, **summary
    }, "cold authoritative recalculation")
    print("OK workbook: 35 → 41, row insertion, idempotent retry, conflict, cold cache 999 → 41")


def verify_workbook_wire():
    # Private PHP <-> Node wire of the workbook reference: examples follow runtime-v1.schema.json,
    # every digest is RFC 8785 over the whole wrapper, and untagged cell content is not a valid message.
    ref = SPEC / "examples/reference/workbook-operation"
    schema = json.loads((ref / "runtime-v1.schema.json").read_text(encoding="utf-8"))
    check_local_references(schema)
    Draft202012Validator.check_schema(schema)
    validator = Draft202012Validator(schema, format_checker=FormatChecker())
    examples = {path.stem: json.loads(path.read_text(encoding="utf-8")) for path in sorted((ref / "examples").glob("*.json"))}
    equal(sorted(examples), ["apply-request", "apply-response", "scope-changed-request", "scope-changed-response"], "wire examples")
    for name, message in examples.items():
        validate_message(validator, message, "wire example " + name)
    request, response = examples["apply-request"], examples["apply-response"]
    equal(request["base"]["sha256"], sha(jcs(request["workbook"])), "wire base digest")
    equal(response["base_sha256"], request["base"]["sha256"], "wire response base")
    equal(response["candidate_sha256"], sha(jcs(response["workbook"])), "wire candidate digest")
    equal(response["calculation"], {"state": "ready", "source_sha256": response["candidate_sha256"]}, "wire calculation barrier")
    equal(examples["scope-changed-request"]["base"]["sha256"], sha(jcs(examples["scope-changed-request"]["workbook"])), "wire scope base digest")
    equal(examples["scope-changed-response"]["error"]["code"], "scope_changed", "wire scope refusal")
    mutants = []
    bare = json.loads(json.dumps(request))
    bare["commands"][0]["expected"] = bare["commands"][0]["expected"]["number"]
    mutants.append(("untagged expected", bare))
    two_tags = json.loads(json.dumps(request))
    two_tags["commands"][0]["expected"] = {"number": 5, "text": "5"}
    mutants.append(("two tags", two_tags))
    zero = json.loads(json.dumps(request))
    zero["commands"][0]["row"] = 0
    mutants.append(("zero-based row", zero))
    code = json.loads(json.dumps(examples["scope-changed-response"]))
    code["error"]["code"] = "version_conflict"
    mutants.append(("public code on the private wire", code))
    for where, message in mutants:
        check(not validator.is_valid(message), "wire schema accepts " + where)
    print(f"OK workbook wire: {len(examples)} examples schema-valid, digests are JCS of the wrapper, {len(mutants)} mutants rejected")


def pg_jsonb_order(value):
    # PostgreSQL jsonb's text rendering for this corpus of strings and integral numbers.
    if isinstance(value, list):
        return [pg_jsonb_order(item) for item in value]
    if isinstance(value, dict):
        keys = sorted(value, key=lambda k: (len(k.encode()), k.encode()))
        return {k: pg_jsonb_order(value[k]) for k in keys}
    return value


def verify_warm(path):
    opener = gzip.open if path.suffix == ".gz" else open
    with opener(path, "rt", encoding="utf-8") as stream:
        report = json.load(stream)
    equal(report["status"], "MEASURED", "warm benchmark status")
    equal(report["cache_state_claimed_by_operator"], "warm", "warm cache label")
    equal(report["cache_reset_performed_by_runner"], False, "warm measurement conditions")
    check(report["postgres_version"].startswith("PostgreSQL 18."), "warm PostgreSQL version")
    with tempfile.TemporaryDirectory() as work:
        fixtures = Path(work)
        subprocess.run([sys.executable, str(STORAGE / "generate_fixtures.py"), "--output", work],
                       stdout=subprocess.DEVNULL, check=True)
        generated = json.loads((fixtures / "fixture_manifest.json").read_text())
        measured = dict(report["fixture_manifest"])
        # The generator checks the canonical manifest and its input hashes on every default run.
        # The original measurement may have generated the same inputs under a different Python version.
        for manifest in (generated, measured):
            check(isinstance(manifest.pop("generator_python"), str), "fixture generator version")
        equal(measured, generated, "benchmark input manifest")
        cases = indexed(report["cases"], "case")
        queries = {query.stem for query in (STORAGE / "queries").glob("*.sql")}
        equal(set(cases), queries, "benchmark case coverage")
        equal(len(queries), 8, "benchmark queries")
        for name, case in cases.items():
            equal(case["status"], "MEASURED", name)
            samples = case["samples_ms"]
            equal(len(samples), 50, name + " measured samples")
            equal(case["sample_count"], len(samples), name + " sample count")
            check(all(math.isfinite(n) and n >= 0 for n in samples), name + " durations")
            equal(case["p95_ms"], sorted(samples)[math.ceil(len(samples) * .95) - 1], name + " p95")
            check(math.isclose(case["mean_ms"], statistics.mean(samples)), name + " mean")
            equal(case["sql_calls_by_category"]["warmup_read"], 10, name + " warmup")
            equal(case["sql_calls_by_category"]["measured_read"], len(samples), name + " measured reads")
            check(case["explain_after_batch"][0]["Plan"], name + " query plan")
            equal(sha((STORAGE / "queries" / (name + ".sql")).read_bytes()), case["query_sha256"], name + " query digest")
            family = name.split("_", 1)[0]
            expected = json.loads((fixtures / f"expected_{family}.json").read_bytes())
            payload = json.dumps(pg_jsonb_order(expected), ensure_ascii=False).encode()
            equal(case["response_sha256"], sha(payload), name + " response digest")
            equal(case["response_utf8_bytes"], [len(payload)], name + " response size")
    print(f"OK warm evidence: {len(cases)} queries × 50 samples, p95, source and response digests")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--storage-result", type=Path, metavar="PATH",
                        help="also verify a measured warm PostgreSQL benchmark (.json or .json.gz)")
    args = parser.parse_args()
    try:
        verify_office()
        verify_live()
        verify_workbook()
        verify_workbook_wire()
        if args.storage_result is not None:
            verify_warm(args.storage_result)
    except (OSError, ValueError, KeyError, IndexError, TypeError, subprocess.CalledProcessError) as exc:
        sys.exit(f"FAIL {exc}")
