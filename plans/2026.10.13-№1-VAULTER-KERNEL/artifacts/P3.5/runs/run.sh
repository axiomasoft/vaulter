cd /home/vostrikov/projects/packages/vaulter
O=plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P3.5/runs
for p in core drive documents azgard media filament; do scripts/t sqlite -p $p > $O/$p.log 2>&1; echo $? > $O/$p.exit; done
scripts/t sqlite -p repo tests/Repo/Arch/BoundaryScanTest.php > $O/repo.log 2>&1; echo $? > $O/repo.exit
echo done > $O/ALL
