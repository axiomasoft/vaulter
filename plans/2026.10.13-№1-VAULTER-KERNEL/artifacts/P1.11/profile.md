# P1.11 — профиль pgsql (группа database) и ускорение локальной линии

Замеры на загруженной машине (load average 9–17), одиночные прогоны: порядок величин, не бенчмарк.

## Профиль до (pgsql, `--group=database`)
| Пакет | Время | Топ тестов |
|---|---|---|
| core | 3 м 39 с | EvaluateManyEquivalence (500 деревьев) 107 с; WideNodeListing bigint 35 с; ULID 15 с |
| media | 91 с | BlobGcBatchTest 4 теста ≈ 43 с (посев 1000–2100 строк по одной) |
| drive | 45 с | DrivePurge 6 с, остальное < 5 с |
| documents | 66 с | в основном накладные расходы партиций |
| azgard | 9 с | RealAzguard: InvalidConfigurationException (нет реального AzGuard в окружении) |
Сравнение: те же core-партиции на mysql — 702 с (DatabaseMigrations) и 411 с (OpenApi).

## Сделано
1. `Scale::pick(full, fast)` (packages/core/tests/Support/Scale.php): быстрая линия гоняет дешёвый эквивалент того же кода,
   полный прогон (`VAULTER_FULL_SEEDS=1`, ставит `--full`) — настоящий размер.
   - EvaluateManyEquivalence: 40 деревьев вместо 500 (sqlite fast 6 с, full 50 с); pgsql три теста: 157 с → 15 с.
   - WideNodeListing: 2 500 строк вместо 65 540 (выше бюджета 1 000 биндингов; лимит 65 535 проверяется в full).
2. `scripts/pg-local.sh up|down|status` + `make db-up/db-down`: постоянный pgsql в /dev/shm; `scripts/test-db.sh` берёт его
   как DB_EXTERNAL, пока он отвечает. Накладные на запуск: 8,35 с → 3,5 с на вызов `scripts/t pgsql`.

## Не дало эффекта (откат)
BlobGcBatchTest: слить второй save() в один insert — время тестов не изменилось (≈11 с). Стоимость в посеве по строке
(диск + save + VaulterDatabase::write), нужен массовый посев с контролем побочных эффектов — отдельная задача.

## Падения на pgsql (не чинились; не сверены с base c64bba25)
core: PruneChangesCommandTest (ResetChangesCommand:32, нечисловой id в bigint), CoreDoctorChecksTest ×3,
HostKeyColumnsSchemaTest; media: ColdMutationSurvivorTest; drive: TreeInvariantsTest; azgard: нет реального AzGuard.

## Осталось
Посев в BlobGcBatchTest; накладные партиций documents/core (десятки пустых партиций по ~1 с); сверка падений с base.
