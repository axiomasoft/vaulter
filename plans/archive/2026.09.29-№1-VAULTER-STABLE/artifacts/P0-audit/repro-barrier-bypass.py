#!/usr/bin/env python3
"""Run existing tests against a temporary ForkRace with its ready barrier bypassed.

No repository PHP is modified. This runs migrations only on vaulter_test.
Exit 0 from Pest reproduces PA01; after repair the same mutant must fail.
"""
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[4]
source = ROOT / "packages/core/tests/Concurrency/ForkRace.php"
needle = 'while (count(glob("{$dir}/ready.*") ?: []) < $count) {'
original = source.read_text()
assert original.count(needle) == 1, "Candidate barrier changed; re-evaluate the delta."
environment = dict(os.environ, APP_ENV="testing", DB_CONNECTION="pgsql",
                   DB_HOST="127.0.0.1", DB_PORT="5432", DB_DATABASE="vaulter_test",
                   DB_USERNAME="vaulter", DB_PASSWORD="secret")
subprocess.run([str(ROOT / "scripts/db-preflight.sh"), "pgsql"],
               cwd=ROOT, env=environment, check=True)
with tempfile.TemporaryDirectory(prefix="vaulter-p0-audit-") as temporary:
    directory = Path(temporary)
    (directory / "ForkRace-no-barrier.php").write_text(original.replace(
        needle, "while (false) { // audit-only: bypass ready barrier"))
    loader = directory / "prepend.php"
    loader.write_text(
        "<?php\nrequire " + json.dumps(str(ROOT / "vendor/autoload.php")) + ";\n"
        'require __DIR__."/ForkRace-no-barrier.php";\n'
        'fwrite(STDERR, "AUDIT_LOADED=".'
        '(new ReflectionClass(Vaulter\\Tests\\Concurrency\\ForkRace::class))'
        '->getFileName().PHP_EOL);\n')
    result = subprocess.run([
        str(ROOT / "scripts/php84"), "php", "-d", f"auto_prepend_file={loader}",
        "vendor/bin/pest", "--ci", "packages/core/tests/Concurrency",
    ], cwd=ROOT, env=environment)
    raise SystemExit(result.returncode)
