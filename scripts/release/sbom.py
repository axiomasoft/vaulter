"""Offline reference SBOM. Candidate inputs are JSON data; no Composer/plugin/autoload execution."""
import hashlib
import importlib.metadata
import json
from pathlib import Path
import sys
import warnings
warnings.filterwarnings("ignore", category=DeprecationWarning)

HERE = Path(__file__).resolve().parent

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def canonical(data):
    return json.dumps(data, sort_keys=True, indent=2, ensure_ascii=False) + "\n"

def schema_check(bom):
    import jsonschema
    component = bom.get("metadata", {}).get("component", {})
    for field in ("type", "name", "version"):
        if not isinstance(component.get(field), str) or not component[field]:
            raise ValueError("reference SBOM metadata.component." + field + " is required")
    pins = json.loads((HERE / "schema/provenance.json").read_text())
    store = {}
    for name, expected in pins["files"].items():
        path = HERE / "schema" / name
        if digest(path) != expected:
            raise ValueError("offline schema pin differs: " + name)
        document = json.loads(path.read_text())
        store[path.as_uri()] = document
        if "$id" in document:
            store[document["$id"]] = document
        for prefix in ("http://cyclonedx.org/schema/", "https://cyclonedx.org/schema/"):
            store[prefix + name] = document
    schema = json.loads((HERE / "schema/bom-1.5.schema.json").read_text())
    def refuse(uri):
        raise ValueError("offline schema reference unavailable: " + uri)
    resolver = jsonschema.RefResolver(base_uri=(HERE / "schema/bom-1.5.schema.json").as_uri(), referrer=schema, store=store, handlers={"http": refuse, "https": refuse})
    jsonschema.Draft7Validator(schema, resolver=resolver, format_checker=jsonschema.FormatChecker()).validate(bom)

def generate(composer, resolution, lock, source, version, tool, policy):
    for field in ("source_sha", "lock_sha256", "php_version", "composer_version", "profile"):
        if not isinstance(resolution.get(field), str) or not resolution[field]:
            raise ValueError("resolution prerequisite missing: " + field)
    if resolution["source_sha"] != source or digest(lock) != resolution["lock_sha256"]:
        raise ValueError("resolution source/lock hash mismatch")
    data = json.loads(lock.read_text())
    packages = data.get("packages", []) + data.get("packages-dev", [])
    index = {}
    for package in packages:
        if not package.get("name") or not package.get("version") or package["name"] in index:
            raise ValueError("invalid/ambiguous resolution package")
        index[package["name"]] = package
    root = composer["name"]
    index.pop(root, None)
    selected, dependencies = {}, {}
    def targets(name, optional=False):
        if name == "php" or name.startswith(("ext-", "lib-", "composer-")):
            return []
        if name == root:
            return [root]
        hits = sorted(p["name"] for p in index.values() if p["name"] == name or name in p.get("provide", {}) or name in p.get("replace", {}))
        if len(hits) > 1:
            raise ValueError("ambiguous resolution for " + name + ": " + ", ".join(hits))
        if not hits and not optional:
            raise ValueError("unresolved runtime dependency: " + name)
        return hits
    queue = [(root, composer)]
    visited = set()
    optional_edges = []
    while queue:
        name, package = queue.pop(0)
        if name in visited:
            continue
        visited.add(name)
        edges = set()
        for kind in ("require", "suggest"):
            for requirement in sorted(package.get(kind, {})):
                for target in targets(requirement, kind == "suggest"):
                    edges.add(target)
                    if kind == "suggest":
                        optional_edges.append(name + " -> " + target)
                    if target != root:
                        selected[target] = index[target]
                        queue.append((target, index[target]))
        dependencies[name] = sorted(edges)
    component = lambda name, ver: {"type": "library", "name": name, "version": ver, "bom-ref": name, "purl": "pkg:composer/" + name + "@" + ver}
    properties = {
        "vaulter:scope": "reference-resolution; not installed consumer environment",
        "vaulter:generator_python": sys.version.split()[0],
        "vaulter:generator_jsonschema": importlib.metadata.version("jsonschema"),
        "vaulter:schema_commit": json.loads((HERE / "schema/provenance.json").read_text())["commit"],
        "vaulter:source_sha": source, "vaulter:resolution_sha256": digest(lock),
        "vaulter:php_version": resolution["php_version"], "vaulter:composer_version": resolution["composer_version"],
        "vaulter:resolution_metadata_sha256": hashlib.sha256(canonical({k: v for k, v in resolution.items() if k != "lock"}).encode()).hexdigest(),
        "vaulter:optional_constraints": json.dumps(composer.get("suggest", {}), sort_keys=True),
        "vaulter:profile": resolution["profile"], "vaulter:tool_sha256": tool, "vaulter:policy_sha256": policy,
        "vaulter:constraints": json.dumps(composer.get("require", {}), sort_keys=True),
        "vaulter:optional_edges": json.dumps(sorted(optional_edges)),
    }
    bom = {"bomFormat": "CycloneDX", "specVersion": "1.5", "version": 1,
           "metadata": {"component": component(root, version), "properties": [{"name": k, "value": v} for k, v in sorted(properties.items())]},
           "components": [component(name, p["version"]) for name, p in sorted(selected.items())],
           "dependencies": [{"ref": name, "dependsOn": edges} for name, edges in sorted(dependencies.items())]}
    schema_check(bom)
    return canonical(bom)

def main():
    verify = sys.argv[1] == "--verify"
    composer_path, resolution_path, source, version, tool, policy, output = sys.argv[2:] if verify else sys.argv[1:]
    resolution_path = Path(resolution_path)
    resolution = json.loads(resolution_path.read_text())
    lock = Path(resolution["lock"])
    if not lock.is_absolute():
        lock = resolution_path.parent / lock
    body = generate(json.loads(Path(composer_path).read_text()), resolution, lock, source, version, tool, policy)
    output = Path(output)
    if verify:
        if output.read_text() != body:
            raise ValueError("SBOM differs from saved reference resolution/metadata")
        print("SBOM reference verified")
        return
    output.write_text(body)
    saved_lock = output.with_suffix(".lock")
    saved_lock.write_bytes(lock.read_bytes())
    saved_resolution = output.with_suffix(".resolution.json")
    saved_resolution.write_text(canonical({**resolution, "lock": saved_lock.name}))
    print(json.dumps({"sbom": output.name, "sbom_sha256": digest(output), "resolution": saved_lock.name, "resolution_sha256": digest(saved_lock), "resolution_provenance": saved_resolution.name, "resolution_provenance_sha256": digest(saved_resolution)}))

if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("sbom: incomplete/refused: " + str(error), file=sys.stderr)
        sys.exit(1)
