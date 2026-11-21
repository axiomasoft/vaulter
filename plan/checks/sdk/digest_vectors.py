"""Proposal digest vectors: independent Python reference, naive variants, field coverage.

python3 plan/checks/sdk/digest_vectors.py   (stdlib + jsonschema 4.x; PHP 8.4 on PATH for the
ContentHasher probe). Exit 0 only if every expectation, including every expected refusal, holds.
"""

import copy
import hashlib
import importlib.util
import json
import subprocess
import sys
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker

ROOT = Path(__file__).resolve().parents[3]
PLAN = ROOT / "plan"
spec = importlib.util.spec_from_file_location("verify_fixtures", PLAN / "checks/verify-fixtures.py")
vf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(vf)

VECTORS = json.loads((PLAN / "examples/reference/proposal-digest/vectors.json").read_text(encoding="utf-8"))
failures = []


def expect(condition, message):
    print(("ok   " if condition else "FAIL ") + message)
    if not condition:
        failures.append(message)


def strict_loads(text):
    def no_duplicates(pairs):
        keys = [k for k, _ in pairs]
        if len(keys) != len(set(keys)):
            raise vf.FixtureError("duplicate member name")
        return dict(pairs)

    def no_constants(name):
        raise vf.FixtureError(f"non-JSON literal {name}")

    return json.loads(text, object_pairs_hook=no_duplicates, parse_constant=no_constants)


def python_reference(text):
    """Returns (canonical text or None, 'accept'|'reject')."""
    try:
        value = strict_loads(text)
        canonical = vf.jcs_text(value)
    except (vf.FixtureError, ValueError):
        return None, "reject"
    try:
        vf.digest_profile(value)
        return canonical, "accept"
    except vf.FixtureError:
        return canonical, "reject"


# 1. Independent reference against every JCS vector; the two RFC 8785 vectors carry the
#    published expected output, so they anchor the reference outside this repository.
for v in VECTORS["jcs"]:
    canonical, profile = python_reference(v["input_json"])
    same_bytes = canonical is None or (canonical.encode().hex() == v["utf8_hex"]
                                       and hashlib.sha256(canonical.encode()).hexdigest() == v["sha256"])
    expect(canonical == v["jcs"] and profile == v["profile"] and same_bytes, f"python {v['id']}")

# 2. Naive serializers are distinguished by the vectors.
by_id = {v["id"]: v for v in VECTORS["jcs"]}


def naive_python(text):
    return json.dumps(json.loads(text), ensure_ascii=False, sort_keys=True, separators=(",", ":"))


for vid in ("utf16_vs_code_point_order", "integral_numbers_by_value", "integer_double_rounding", "rfc8785_3_2_3_sorting"):
    expect(naive_python(by_id[vid]["input_json"]) != by_id[vid]["jcs"],
           f"naive json.dumps(sort_keys) differs on {vid}")
order = list(json.loads(naive_python(by_id["utf16_vs_code_point_order"]["input_json"])))
expect(order[-1] == "\U0001F600", "code point order puts U+1F600 after U+FB33; JCS puts it before U+E000")

# 3. The live product symbol ContentHasher (packages/documents) is not a JCS digest.
probe = r"""
require $argv[1];
$out = [];
foreach (json_decode(stream_get_contents(STDIN), true) as $id => $text) {
    $m = new ReflectionMethod(Vaulter\Documents\Content\ContentHasher::class, 'encode');
    $out[$id] = $m->invoke(null, json_decode($text, true));
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
"""
inputs = {vid: by_id[vid]["input_json"] for vid in
          ("utf16_vs_code_point_order", "empty_containers", "numeric_member_names", "escapes_and_raw_line_separators")}
record_text = json.dumps(vf.proposal_record(VECTORS["proposals"][0]["prepare_request"]["input"],
                                            VECTORS["proposals"][0]["prepare_response"]["result"]), ensure_ascii=False)
inputs["text_success_record"] = record_text
php = subprocess.run(["php", "-r", probe, str(ROOT / "packages/documents/src/Content/ContentHasher.php")],
                     input=json.dumps(inputs), capture_output=True, text=True, check=True)
hashed = json.loads(php.stdout)
for vid in inputs:
    expected = VECTORS["proposals"][0]["canonical_record"] if vid == "text_success_record" else by_id[vid]["jcs"]
    expect(hashed[vid] != expected, f"ContentHasher::encode differs from JCS on {vid}: {hashed[vid][:60]}")

# 4. Proposal vectors: schema-valid exchanges, canonical bytes, fixture digests.
schema = json.loads((PLAN / "spec/schemas/office-v1.schema.json").read_text(encoding="utf-8"))
validator = Draft202012Validator(schema, format_checker=FormatChecker())
office = {s["id"]: s for s in json.loads((PLAN / "examples/office-sdk.json").read_text(encoding="utf-8"))["scenarios"]}
fixture_digests = {st["response"]["result"]["digest"] for s in office.values() for st in s["steps"]
                   if st["request"]["operation"] == "office.prepare" and st["response"]["ok"]}
for p in VECTORS["proposals"]:
    req, res = p["prepare_request"], p["prepare_response"]
    valid = validator.is_valid(req) and validator.is_valid(res)
    record = vf.proposal_record(req["input"], res["result"])
    text = vf.jcs_text(record)
    permuted = vf.jcs_text(strict_loads(p["permuted_record_json"]))
    expect(valid and text == p["canonical_record"] and permuted == text and text.encode().hex() == p["utf8_hex"]
           and vf.proposal_digest(req["input"], res["result"]) == p["digest"] == res["result"]["digest"],
           f"proposal {p['id']} digest {p['digest'][:12]}…")
    if not p["id"].startswith("text_astral") and not p["id"].startswith("text_link"):
        expect(p["digest"] in fixture_digests, f"proposal {p['id']} equals the pre-existing office-sdk.json digest")

# 5. Which field changes the digest: every leaf of prepare_input and plan does; the commit key,
#    the decision and the private preconditions are outside the record by construction.
p = next(x for x in VECTORS["proposals"] if x["id"] == "text_link_astral")
base_in, base_res = p["prepare_request"]["input"], p["prepare_response"]["result"]


def leaves(node, path=()):
    if isinstance(node, dict):
        for k, v in node.items():
            yield from leaves(v, path + (k,))
    elif isinstance(node, list):
        for i, v in enumerate(node):
            yield from leaves(v, path + (i,))
    else:
        yield path


def mutate(node, path):
    node = copy.deepcopy(node)
    target = node
    for key in path[:-1]:
        target = target[key]
    old = target[path[-1]]
    target[path[-1]] = old + 1 if isinstance(old, int) else old + "x"
    return node


covered = []
for side, value in (("prepare_input", base_in), ("plan", base_res)):
    for path in leaves(value):
        if path == ("digest",):
            continue
        changed = vf.proposal_digest(mutate(base_in, path), base_res) if side == "prepare_input" \
            else vf.proposal_digest(base_in, mutate(base_res, path))
        covered.append(".".join(map(str, (side,) + path)))
        expect(changed != p["digest"], f"digest changes with {covered[-1]}")
expect(vf.proposal_digest(base_in, {**base_res, "digest": "0" * 64}) == p["digest"], "digest field itself is excluded")
exchange = json.loads((PLAN / "examples/reference/text-replace/exchange.json").read_text(encoding="utf-8"))
private = exchange["proposal_record"]
expect(private["proposal_id"] == base_res["proposal_id"] and "private_preconditions" in private,
       "private preconditions are pinned by proposal_id (v_ai_proposal_payloads), bound to the digest only through proposal_id")

# 6. Sensitivity of the digest check itself: a one-character change in a schema-valid commit is refused.
commit = next(s for s in exchange["steps"] if s["request"]["operation"] == "office.commit")["request"]
forged = {**commit, "input": {**commit["input"], "digest": ("0" if commit["input"]["digest"][0] != "0" else "1") + commit["input"]["digest"][1:]}}
expect(validator.is_valid(forged) and forged["input"]["digest"] != p["digest"], "schema accepts a forged digest; only recomputation refuses it")

print(f"{len(VECTORS['jcs'])} JCS vectors, {len(VECTORS['proposals'])} proposals, {len(covered)} digest fields covered")
sys.exit(1 if failures else 0)
