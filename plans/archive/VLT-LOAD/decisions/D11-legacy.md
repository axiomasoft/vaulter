---
id: D11
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Фикс горячего пути (dev-срез по L-01/L-02/L-06 и т.п.) открывается только после того, как VLT-MUT закрыл `vaulter-drive` до Score ≥ 95.** Факт на сегодня (VLT-MUT P0.2 baseline, холодный кэш): `S3MultipartHandler.php` — **38** выживших мутантов, `SweepUploadsCommand.php` — **17**, `MoveNodeAction.php` — **4** (`plans/VLT-MUT/findings/P0.2-drive-escaped.txt`). Рефакторинг под тестами, которые не ловят инверсию условия или пропажу элемента массива, не доказывает, что переписанное поведение корректно — только то, что оно не упало. Порядок: VLT-MUT P2 (`vaulter-drive` → ≥95) закрывается **до** любого dev-среза из §7 по drive-файлам.

## Почему

Imported noncanonical legacy decision row
