import json, pathlib, subprocess, sys
root = pathlib.Path(__file__).resolve().parents[4]
art = pathlib.Path(__file__).resolve().parent
package = sys.argv[1]
cwd = root if package == "core" else root / "packages" / package
php = str(root / "scripts/php84")
commands = {
    "pint": [php, "vendor/bin/pint", "--test"] + (["packages/core"] if package == "core" else []),
    "phpstan": [php, "vendor/bin/phpstan", "analyse", "--no-progress", "--memory-limit=1G"],
    "pest": ["env", "DB_CONNECTION=sqlite", "DB_DATABASE=:memory:", php, "vendor/bin/pest", "--ci"] + (["packages/core/tests"] if package == "core" else []),
}
rows = []
for check, command in commands.items():
    log = art / (package + "-" + check + "-post-review.log")
    with log.open("w") as stream:
        process = subprocess.run(command, cwd=cwd, stdout=stream, stderr=subprocess.STDOUT)
    rows.append({"package": package, "check": check, "cwd": str(cwd), "command": command, "exit_code": process.returncode, "log": str(log.relative_to(root))})
    (art / (package + "-gates-post-review.json")).write_text(json.dumps(rows, indent=2) + "\n")
    print(package, check, process.returncode, flush=True)
sys.exit(int(any(row["exit_code"] for row in rows)))
