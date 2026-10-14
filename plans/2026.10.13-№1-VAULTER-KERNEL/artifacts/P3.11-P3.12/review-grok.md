# Ревью P3.11 + P3.12 — grok-4.7 (high, 500k), read-only, 3 дорожки

Маршрут: `plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high` (ro-run, `--disallowed-tools search_replace,write`);
сырой поток — `review-R{1,2,3}.jsonl`, итог дорожки — `review-R{1,2,3}.md`, брифы — `briefs/`.
Запрещённые команды ревьюеры не запускали (проверено grep по tool-вызовам). Вердикт всех трёх дорожек: **changes**.

## Находки и решения исполнителя

| # | Дорожка | Sev | Находка | Решение |
|---|---|---|---|---|
| R1.1 | реестр | high | `restore_destination_changed` — `cacheable`, хотя исход зависит от гонки | **исправлено**: `receipt` → never (+ комментарий, тест в `ProblemSpecRulesTest`, reference-доки en/ru) |
| R1.2 | реестр | med | `collaboration_unavailable` — `with_backoff`, хотя отказ задаёт конфигурация хоста | **исправлено**: `retryable`/`receipt` → never (+ тест, доки) |
| R1.3 | реестр | med | дубль кода внутри одного модуля схлопывается `array_unique` и не валит граф | **исправлено**: причина `declares the problem code … twice` в `ModuleCompiler::problemCodes` + тест `ModuleCompilerTest` |
| R1.4 | реестр | low | `upload_conflict`/`upload_offset_mismatch` без receipt и без комментария | **частично**: комментарий-обоснование (`never`: исход зависит от конкурента); разделение кода на гонку лока и закрытую сессию — не делаем (меняет публичный каталог) |
| R2.1 | claim/store | high | `prune()` и `claim()` удаляют квитанцию без `expires_at` в предикате: удаление отложенной гонкой стирает свежую квитанцию → второй эффект | **исправлено**: `where expires_at <= now` в обоих `DELETE` |
| R2.2 | claim/store | high | MySQL: после 1205 `innodb_lock_wait_timeout` остаётся 2 с на соединении | **исправлено**: restore только для PG (транзакционная настройка), MySQL/SQLite восстанавливаются |
| R2.3 | claim/store | med | SQLite без ограничения 2 с и без `operation_in_progress` (`SQLITE_BUSY` → `concurrent_update`) | **исправлено**: `pragma busy_timeout` 2 с с восстановлением; `SQLITE_BUSY` → `OperationInProgressException`. Гонку SQLite отдельным тестом не покрыли (один процесс) — остаточный риск |
| R2.4 | схема | med | `SchemaTablesCheck::ENTITIES` без `idempotency_keys`; doctor не видит удалённую таблицу | **исправлено** (красный `ModuleReadinessTest` подтвердил) |
| R3.1 | drive/HTTP | high | сентинел `PHP_INT_MAX` совпадает с реальной sequence узла на максимуме | **смягчено**: узел с `sequence === PHP_INT_MAX` получает 412 сразу, сентинел остаётся для остальных. Полное устранение требует передать «устарело» в операцию — отдельная правка контракта операции (отклонение) |
| R3.2 | drive/HTTP | high | повтор rename после purge/trash отвечал 404 до replay | **исправлено**: `NodeNotFoundException` при ключе уходит в операцию (replay/404 решает она); тест `IdempotencyKeyHttpTest` |
| R3.3 | drive/HTTP | med | trashed трактуется как gone (fallback на записанный scope) | **отклонено**: решение D25/ADR-0029 §5 («узел, который актёр больше не видит»), закреплено `IdempotencyContractTest` |
| R3.4 | drive/HTTP | med | ETag при replay удалённого узла отличается (abilities пересчитаны) | **доки исправлены** (ADR-0029, concepts/idempotency): historic ETag гарантирован, пока узел виден актёру. Код не менял |
| R3.5 | drive/HTTP | low | `create(null)` и `create(rootId)` — разные fingerprint | **исправлено**: fingerprint нормализует родителя в id корня |

## Находки прогонов (не ревьюеров)

- phpstan core 29 / drive 19 ошибок (касты `mixed` из строк БД и сырого тела) — введён типизированный `StoredReceipt`, типизированные аксессоры в `NodeSnapshotCodec`.
- `IdempotencyContractTest`: без `permission_driver=database` доступ открыт, отказы replay не воспроизводились → `beforeEach`.
- `OwnershipRegistryTest`: `toHaveKey($key, $id)` сравнивал значение с id → убран лишний аргумент.
- Устаревшие ожидания: `IdentitiesTest` (26 таблиц, 29 команд), `SchemaSnapshotTest` (набор таблиц original), `tests/zones.json` (database: `core/tests/Idempotency`), `OpenApiExamplesTest` (`retryable` в `problem-412/428`), allowlist `i2.json` (счётчик `Retryable`), fixtures `identities-protocol`/`target-map`.
- MySQL-набор `core/Idempotency` не завис: `DatabaseMigrations` на MySQL ≈25 с на тест (DDL). Первый прогон остановлен через 16 мин.
