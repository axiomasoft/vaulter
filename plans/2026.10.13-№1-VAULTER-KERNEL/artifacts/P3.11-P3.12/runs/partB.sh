#!/bin/bash
cd /home/vostrikov/projects/packages/vaulter
R="plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P3.11-P3.12/runs"
run(){ n=$1; shift; echo "### $*" > $R/$n.txt; bash -c "$*" >> $R/$n.txt 2>&1; echo "EXIT=$?" >> $R/$n.txt; echo "$n $(tail -1 $R/$n.txt)" >> $R/summary.txt; }
: > $R/summary.txt
run 01-core-static 'scripts/php84 php vendor/bin/pint --test packages/core && scripts/php84 php vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
run 02-drive-static 'cd packages/drive && ../../scripts/php84 php vendor/bin/pint --test && ../../scripts/php84 php vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
run 03-documents-static 'cd packages/documents && ../../scripts/php84 php vendor/bin/pint --test && ../../scripts/php84 php vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
run 04-inventory 'scripts/php84 php scripts/inventory/inventory.php --check && scripts/php84 php vendor/bin/pint --test scripts/inventory tests/Repo/Arch && scripts/php84 php vendor/bin/phpstan analyse --no-progress --level=6 --memory-limit=1G scripts/inventory'
run 05-repo 'scripts/t sqlite -p repo'
run 06-core-sqlite 'scripts/t sqlite -p core packages/core/tests/Http/Problems packages/core/tests/OpenApi packages/core/tests/Modules packages/core/tests/Idempotency packages/core/tests/Operations packages/core/tests/Support/DomainExceptionStatusTest.php packages/core/tests/Configuration packages/core/tests/Doctor'
run 07a-pgsql-idem 'scripts/t pgsql -p core packages/core/tests/Idempotency'
run 07b-pgsql-race 'scripts/t pgsql -p core --group=serial packages/core/tests/Concurrency/IdempotencyRaceTest.php'
run 07c-pgsql-schema 'scripts/t pgsql -p core --group=database packages/core/tests/Schema'
run 08-mysql-idem 'scripts/t mysql -p core packages/core/tests/Idempotency'
for d in sqlite pgsql mysql; do run 09-snap-$d "scripts/schema-snapshot.sh --driver=$d --all-presets --compare=packages/core/tests/Fixtures/Schema/expected"; done
run 09-snap-original 'git diff --quiet 83b7169e -- packages/core/tests/Fixtures/Schema/original'
run 10-drive 'scripts/t sqlite -p drive'
run 11-documents 'scripts/t sqlite -p documents'
run 12-filament 'scripts/t sqlite -p filament tests/Feature/MoveRequiresDestinationCreateTest.php'
run 13-vue 'npm --prefix packages/vue run typecheck && npm --prefix packages/vue run api:check && npm --prefix packages/vue test'
run 14-baseline 'scripts/t baseline --compare'
run 15-doclinks 'scripts/php84 php scripts/check-doc-links.php docs README.md && scripts/php84 php scripts/check-doc-links.php --mirror docs'
echo DONE >> $R/summary.txt
