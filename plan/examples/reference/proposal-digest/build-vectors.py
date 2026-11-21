# One-off builder of plan/examples/reference/proposal-digest/vectors.json.
# Expected canonical texts of the "jcs" vectors are written here BY HAND from RFC 8785
# (two of them are the published RFC examples); only utf8_hex/sha256 are derived mechanically.
# Proposal records come from office-sdk.json prepares (their digests pre-exist in the fixture)
# and from the text-replace exchange; their canonical text is cross-checked by PHP, Node and
# the Python reference in plan/checks/sdk/digest_vectors.py.
import hashlib, json, importlib.util, sys
from pathlib import Path

ROOT = Path(sys.argv[1])
spec = importlib.util.spec_from_file_location("vf", ROOT / "plan/checks/verify-fixtures.py")
vf = importlib.util.module_from_spec(spec); spec.loader.exec_module(vf)

def vec(id, input_json, jcs, profile, note):
    out = {"id": id, "note": note, "input_json": input_json, "jcs": jcs, "profile": profile}
    if jcs is not None:
        b = jcs.encode("utf-8")
        out["utf8_hex"], out["sha256"] = b.hex(), hashlib.sha256(b).hexdigest()
    return out

jcs = [
    vec("rfc8785_3_2_2_values",
        '{"numbers":[333333333.33333329,1E30,4.50,2e-3,0.000000000000000000000000001],"string":"\\u20ac$\\u000F\\u000aA\'\\u0042\\u0022\\u005c\\\\\\"\\/","literals":[null,true,false]}',
        '{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\\u000f\\nA\'B\\"\\\\\\\\\\"/"}',
        "reject", "Published RFC 8785 §3.2.2 example; fractions are outside the v1 digest profile"),
    vec("rfc8785_3_2_3_sorting",
        '{"\\u20ac":"Euro Sign","\\r":"Carriage Return","\\ufb33":"Hebrew Letter Dalet With Dagesh","1":"One","\\ud83d\\ude00":"Emoji: Grinning Face","\\u0080":"Control","\\u00f6":"Latin Small Letter O With Diaeresis"}',
        '{"\\r":"Carriage Return","1":"One","\u0080":"Control","\u00f6":"Latin Small Letter O With Diaeresis","\u20ac":"Euro Sign","\U0001F600":"Emoji: Grinning Face","\ufb33":"Hebrew Letter Dalet With Dagesh"}',
        "accept", "Published RFC 8785 §3.2.3 order: U+1F600 (D83D DE00) before U+FB33"),
    vec("utf16_vs_code_point_order",
        '{"\\ufb33":"FB33","\\ud83d\\ude00":"1F600","\\ue000":"E000","\\u00e9":"E9","a":"61"}',
        '{"a":"61","\u00e9":"E9","\U0001F600":"1F600","\ue000":"E000","\ufb33":"FB33"}',
        "accept", "UTF-16 puts the astral key before U+E000; code point / UTF-8 byte order (Python sort_keys, PHP ksort/strcmp) puts it last"),
    vec("escapes_and_raw_line_separators",
        '{"s":"\\u0000\\b\\t\\n\\f\\r\\u001F\\"\\\\\\/\\u007f\\u2028\\u2029\\u00e9\\ud83e\\uddfe"}',
        '{"s":"\\u0000\\b\\t\\n\\f\\r\\u001f\\"\\\\/\u007f\u2028\u2029\u00e9\U0001F9FE"}',
        "accept", "Short escapes, lowercase \\u001f, '/' DEL U+2028 U+2029 and non-ASCII stay raw"),
    vec("safe_integer_max", '{"position":9007199254740991}', '{"position":9007199254740991}', "accept", "2^53-1, schema counter maximum"),
    vec("integer_2_pow_53", '{"position":9007199254740992}', '{"position":9007199254740992}', "reject", "Representable double but outside the safe profile"),
    vec("integer_double_rounding", '{"position":9007199254740993}', '{"position":9007199254740992}', "reject",
        "JCS works on IEEE doubles: Node yields ...992, exact-int parsers (PHP, Python) would print ...993; the profile rejects"),
    vec("integral_numbers_by_value", '{"a":27.0,"b":2.7e1,"c":-0,"d":-0.0,"e":1.0000000000000001,"f":10.0}',
        '{"a":27,"b":27,"c":0,"d":0,"e":1,"f":10}', "accept", "Value semantics: 27.0 is 27, -0 is 0; mmccook 1.0.0 prints 10.0 as 1"),
    vec("fraction", '{"n":1.5}', '{"n":1.5}', "reject", "Valid JCS, outside the v1 digest profile"),
    vec("large_and_small_exponents", '{"a":1.5e20,"b":1e21,"c":1e-7}', '{"a":150000000000000000000,"b":1e+21,"c":1e-7}', "reject",
        "ECMAScript layout; mmccook 1.0.0 prints 1.5e20 as 15"),
    vec("infinity_literal_overflow", '{"n":1e400}', None, "reject", "Overflows to Infinity in every parser"),
    vec("lone_surrogate_value", '{"s":"\\ud800"}', None, "reject", "I-JSON: lone surrogate"),
    vec("lone_surrogate_key", '{"\\udc00":1}', None, "reject", "I-JSON: lone surrogate in a member name"),
    vec("empty_containers", '{"c":[{}],"b":[],"a":{}}', '{"a":{},"b":[],"c":[{}]}', "accept",
        "{} must stay an object: PHP assoc decoding (ContentHasher) turns it into []"),
    vec("numeric_member_names", '{"o":{"1":"b","0":"a"},"p":{"2":"d","10":"c"}}', '{"o":{"0":"a","1":"b"},"p":{"10":"c","2":"d"}}', "accept",
        "Names are strings: '10' < '2'; PHP assoc + array_is_list turns {\"0\":..,\"1\":..} into a list"),
    vec("request_fingerprint_text_success",
        '{"proposal_id":"proposal-text-1","digest":"73ae0653dc7b339151e295629775c224395862ab1cbb0267e7c09849834e7847","command":"text.replace_exact@1"}',
        '{"command":"text.replace_exact@1","digest":"73ae0653dc7b339151e295629775c224395862ab1cbb0267e7c09849834e7847","proposal_id":"proposal-text-1"}',
        "accept", "request_fingerprint input of area 1 (sha256 of this JCS)"),
]

def reverse(value):
    if isinstance(value, dict):
        return {k: reverse(value[k]) for k in reversed(list(value))}
    if isinstance(value, list):
        return [reverse(v) for v in value]
    return value

def proposal(id, note, request, response):
    record = vf.proposal_record(request["input"], response["result"])
    text = vf.jcs_text(record)
    b = text.encode("utf-8")
    return {"id": id, "note": note, "prepare_request": request, "prepare_response": response,
            "canonical_record": text, "utf8_hex": b.hex(), "digest": hashlib.sha256(b).hexdigest(),
            "permuted_record_json": json.dumps(reverse(record), ensure_ascii=False)}

office = {s["id"]: s for s in json.loads((ROOT / "plan/examples/office-sdk.json").read_text())["scenarios"]}
exchange = json.loads((ROOT / "plan/examples/reference/text-replace/exchange.json").read_text())
def prep(steps, n):
    s = steps[n - 1]
    assert s["request"]["operation"] == "office.prepare"
    return s["request"], s["response"]
proposals = [
    proposal("text_success", "office-sdk.json text_success step 5 (Cyrillic, «»)", *prep(office["text_success"]["steps"], 5)),
    proposal("sheet_fixed_members", "office-sdk.json sheet_fixed_members step 3", *prep(office["sheet_fixed_members"]["steps"], 3)),
    proposal("sheet_current_rows_retry", "office-sdk.json sheet_current_rows step 6 (three rows)", *prep(office["sheet_current_rows"]["steps"], 6)),
    proposal("text_link_astral", "text-replace/exchange.json step 3", *prep(exchange["steps"], 3)),
]
astral_req = {"version": 1, "operation": "office.prepare", "input": {
    "arguments": {"replacement": "10 дней 🧾\u2028«см. условия»", "expected": "15 дней 🧾", "block_id": "p-terms"},
    "read_token": "rt-terms-40", "command": "text.replace_exact@1", "resource_id": "contract-17"}}
astral_plan = {"targets": ["p-terms"], "summary": "В абзаце p-terms заменить «15 дней 🧾»", "proposal_id": "proposal-terms-2",
               "expires_at": "2026-11-21T12:00:00Z", "base": {"position": 40, "generation": 3}, "approval": "human"}
astral_plan["digest"] = vf.proposal_digest(astral_req["input"], astral_plan)
proposals.append(proposal("text_astral_line_separator",
    "Schema-valid prepare whose replacement holds U+1F9FE and U+2028; members deliberately unsorted",
    astral_req, {"version": 1, "operation": "office.prepare", "ok": True, "result": astral_plan}))

out = {"format": 1,
       "profile": "Office v1 proposal digest = lowercase hex SHA-256 of the UTF-8 RFC 8785 text of {version:1,prepare_input,plan}; numbers only safe integers by value",
       "jcs": jcs, "proposals": proposals}
(ROOT / "plan/examples/reference/proposal-digest/vectors.json").write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n")
print(len(jcs), "jcs vectors,", len(proposals), "proposal vectors")
