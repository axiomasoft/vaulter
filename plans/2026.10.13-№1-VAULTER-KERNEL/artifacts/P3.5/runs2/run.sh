cd /home/vostrikov/projects/packages/vaulter
O=plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P3.5/runs2
scripts/php84 php vendor/bin/pint --test packages/core packages/drive packages/documents packages/azgard packages/media packages/filament > $O/pint.log 2>&1; echo $? > $O/pint.exit
scripts/php84 php scripts/inventory/inventory.php --check > $O/inventory.log 2>&1; echo $? > $O/inventory.exit
scripts/t baseline --compare > $O/baseline.log 2>&1; echo $? > $O/baseline.exit
for p in core drive documents azgard media filament; do scripts/t sqlite -p $p > $O/$p.log 2>&1; echo $? > $O/$p.exit; done
scripts/t sqlite -p repo tests/Repo/Arch/BoundaryScanTest.php > $O/repo.log 2>&1; echo $? > $O/repo.exit
scripts/php84 php vendor/bin/phpstan analyse --no-progress --memory-limit=1G > $O/phpstan-core.log 2>&1; echo $? > $O/phpstan-core.exit
for p in drive documents azgard media; do (cd packages/$p && ../../scripts/php84 php vendor/bin/phpstan analyse --no-progress --memory-limit=1G > ../../$O/phpstan-$p.log 2>&1; echo $? > ../../$O/phpstan-$p.exit); done
echo done > $O/ALL
