"""Wire exchange of the text-replace reference, checked the way the adapter checks it.

python3 plan/checks/sdk/text_exchange.py — schema validity of every message, digest recomputation,
receipt binding, verify(at_commit)/verify(current) semantics after a later human edit, and a set of
schema-valid but wrong answers that must be refused. Document semantics (offsets, marks, href) are
replayed on ProseMirror by text-replace.test.mjs on the same file.
"""

import copy
import importlib.util
import json
import sys
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker

PLAN = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("verify_fixtures", PLAN / "checks/verify-fixtures.py")
vf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(vf)
schema = json.loads((PLAN / "spec/schemas/office-v1.schema.json").read_text(encoding="utf-8"))
validator = Draft202012Validator(schema, format_checker=FormatChecker())
EXCHANGE = json.loads((PLAN / "examples/reference/text-replace/exchange.json").read_text(encoding="utf-8"))


def apply_edit(segments, edit):
    text = "".join(s["text"] for s in segments)
    starts = [i for i in range(len(text)) if text.startswith(edit["expected"], i)]
    vf.equal(len(starts), 1, "human edit target")
    out, offset = [], 0
    for s in segments:
        lo, hi = offset, offset + len(s["text"])
        if lo <= starts[0] < hi:
            k = starts[0] - lo
            s = {**s, "text": s["text"][:k] + edit["replacement"] + s["text"][k + len(edit["expected"]):]}
        out.append(s)
        offset = hi
    return out


def check_exchange(exchange):
    """Adapter-side rules; raises FixtureError on the first violation."""
    steps, events = exchange["steps"], {e["type"]: e for e in exchange["events"]}
    for n, step in enumerate(steps, 1):
        vf.validate_message(validator, step["request"], f"step {n} request")
        vf.validate_message(validator, step["response"], f"step {n} response")
    read = steps[0]["response"]["result"]
    segments = read["data"]["segments"]
    proposals, receipt = {}, None
    state = segments
    for n, step in enumerate(steps, 1):
        req, res = step["request"], step["response"]
        op, inputs = req["operation"], req["input"]
        if "human_edits_block" in events and events["human_edits_block"]["before_step"] == n:
            state = apply_edit(committed_segments, events["human_edits_block"]["details"]["replace"])
            edit_marker = events["human_edits_block"]["details"]["marker"]
        if op == "office.prepare":
            args = inputs["arguments"]
            text = "".join(s["text"] for s in segments)
            count = sum(1 for i in range(len(text)) if text.startswith(args["expected"], i))
            if count != 1:
                vf.equal(res["ok"], False, f"step {n} ambiguous or absent target refused")
                vf.equal(res["error"]["code"], "invalid_input" if count > 1 else "precondition_failed", f"step {n} code")
                continue
            result = res["result"]
            vf.equal(result["base"], read["marker"], f"step {n} base")
            vf.equal(vf.proposal_digest(inputs, result), result["digest"], f"step {n} digest")
            proposals[result["proposal_id"]] = inputs, result
        elif op == "office.commit":
            prepared, plan = proposals[inputs["proposal_id"]]
            vf.equal(inputs["digest"], vf.proposal_digest(prepared, plan), f"step {n} committed digest")
            receipt = res["result"]
            vf.check(receipt["marker"]["position"] > plan["base"]["position"], f"step {n} commit position")
            args = prepared["arguments"]
            segment = vf.matched_segment(segments, args["expected"], f"step {n}")
            committed_segments = apply_edit(segments, args)
            expected_marks = segment["marks"] if args["replacement"] else []
        elif op == "office.verify":
            result = res["result"]
            vf.equal(result["receipt_id"], receipt["receipt_id"], f"step {n} receipt")
            vf.equal(result["mode"], inputs["mode"], f"step {n} mode")
            checks = vf.indexed(result["checks"], "name")
            vf.equal(set(checks), {"text_exact", "marks"}, f"step {n} text_and_marks profile")
            if inputs["mode"] == "at_commit":
                vf.equal(result["marker"], receipt["marker"], f"step {n} at_commit marker")
                observed = committed_segments
            else:
                vf.equal(result["marker"], edit_marker, f"step {n} current marker")
                observed = state
            start = "".join(s["text"] for s in committed_segments).index(args["replacement"])
            text = "".join(s["text"] for s in observed)
            actual = text[start:start + len(args["replacement"])]
            text_passed = observed == committed_segments
            vf.equal((checks["text_exact"]["actual"], checks["text_exact"]["passed"]), (actual, text_passed), f"step {n} text_exact")
            vf.equal(checks["marks"]["actual"], expected_marks, f"step {n} marks")
            vf.equal(result["outcome"], "passed" if text_passed and checks["marks"]["passed"] else "failed", f"step {n} outcome")
    return [s["response"]["result"]["outcome"] for s in steps if s["request"]["operation"] == "office.verify"]


outcomes = check_exchange(EXCHANGE)
vf.equal(outcomes, ["passed", "failed", "passed"], "same receipt: at_commit passed, current failed, at_commit still passed")
print("ok   exchange: ambiguous prepare refused, digest recomputed, verify at_commit/current/at_commit =", outcomes)


def mutated(step_no, change):
    exchange = copy.deepcopy(EXCHANGE)
    change(exchange["steps"][step_no - 1])
    return exchange


def check_of(step, name):
    return next(c for c in step["response"]["result"]["checks"] if c["name"] == name)


negatives = {
    "forged digest at commit": mutated(4, lambda s: s["request"]["input"].__setitem__("digest", "0" * 64)),
    "verify for another receipt": mutated(5, lambda s: s["response"]["result"].__setitem__("receipt_id", "receipt-terms-40")),
    "at_commit with a stale marker": mutated(5, lambda s: s["response"]["result"].__setitem__("marker", {"generation": 3, "position": 40})),
    "at_commit reporting the old fragment": mutated(5, lambda s: check_of(s, "text_exact").__setitem__("actual", "приёмка: 15 дней")),
    "marks invented": mutated(5, lambda s: check_of(s, "marks").__setitem__("actual", ["link"])),
    "current claims passed after the human edit": mutated(6, lambda s: (
        s["response"]["result"].__setitem__("outcome", "passed"),
        check_of(s, "text_exact").__setitem__("passed", True))),
    "current answered from the receipt marker": mutated(6, lambda s: s["response"]["result"].__setitem__("marker", {"generation": 3, "position": 41})),
    "ambiguous prepare accepted": mutated(2, lambda s: s.__setitem__("response", copy.deepcopy(EXCHANGE["steps"][2]["response"]))),
}
failures = 0
for name, exchange in negatives.items():
    valid = all(validator.is_valid(s["request"]) and validator.is_valid(s["response"]) for s in exchange["steps"])
    try:
        check_exchange(exchange)
        refused = False
    except (vf.FixtureError, KeyError):
        refused = True
    ok = valid and refused
    failures += 0 if ok else 1
    print(("ok   " if ok else "FAIL ") + f"schema-valid, refused by adapter: {name}")
sys.exit(1 if failures else 0)
