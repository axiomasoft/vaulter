# P3.11 + P3.12 — ревью и прогоны (следующая сессия)

Код обоих пунктов закоммичен (executor-коммиты `P3.11`, `P3.12`), пункты `in_progress`, `finish` не вызывался.
Тесты/phpstan в сессии исполнителя НЕ запускались; выполнены только `php -l`, `pint` (fix), `inventory --check`,
`schema-snapshot --write` (sqlite/pgsql/mysql), генерация reference-доков и `schema.d.ts`.
Решения и отклонения — `plan.py events 2026.10.13-№1-VAULTER-KERNEL --item P3.11|P3.12`.

## Часть А — ревью на grok 4.7 (read-only, без длинных прогонов)

Селектор: `grok/grok-4.7/500k/high`. Запуск — headless через бридж
(`plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high`), бриф на stdin, поток — в артефакт
`artifacts/P3.11-P3.12/review-<lane>.jsonl`; маршрут подтверждать по сессии ревьюера
(`~/.grok/sessions/.../updates.jsonl`, `contextWindowTokens` ≥ 500k).

Три параллельные дорожки (независимые срезы диффа; общий бриф + свой фокус):

| Lane | Диапазон | Фокус |
|---|---|---|
| R1 registry | `git show 46d06076` | классификация retryable/receipt каждого кода против D25 и knowledge/p3-surface#problems (401/403/404/422 validation/428/429/5xx → never; 412 → after_refetch+cacheable; предметные 409/413/422 → cacheable); `ModuleCompiler` (дубль кода среди установленных модулей, вызов `codes()` в компиляторе), fallback каталога при невалидном графе, `ErrorsReference` по offered-модулям, D39-раскладка (`DriveSliceProblemCodes`→drive, `WebhooksProblemCodes`→webhooks), генератор `identities-protocol` (`new ProblemSpec` позиционно/именованно), точность ожиданий `OwnershipRegistryTest` (P4/P5/P3.13), OpenAPI `retryable`, vue `errors.ts` |
| R2 claim/runner (hard risk) | `git show <sha P3.12 executor — см. `plan.py commits`> -- packages/core/src/{Idempotency,Operations} packages/core/src/Schema packages/core/database` | атомарность claim+мутация+receipt; невидимость uncommitted claim; PG: aborted transaction не продолжается, `lock_timeout` через `set_config(…, true)`; MySQL: `innodb_lock_wait_timeout` restore, RR + locking read, `ON DUPLICATE KEY` блокировки; `OperationInProgressException` без previous (иначе `isConcurrencyFailure` → retry); savepoint = вложенная транзакция (откат sinks/afterCommit); `CachedRefusal` бросается после commit; удаление истёкшей квитанции под гонкой; канонизация digest/request hash; сравнение tuple при коллизии; nested write без claim; порядок authorize→decode при replay; схема (`char(64)` hex, base64 ключа) и `SchemaTablesCheck`/readiness |
| R3 drive/HTTP | `git show <sha P3.12 executor — см. `plan.py commits`> -- packages/drive packages/core/src/Http packages/core/src/Contracts openapi docs` | полнота `NodeSnapshotCodec` (все поля, время/таймзона); `NodeReplayAuthorizer` (create vs edit, moved/trashed/gone/root); fingerprint `FolderOperations::create`/`NodeOperations::rename`; `UpdateNodeController` (stale If-Match + ключ → `PHP_INT_MAX`); парсер `ReadIdempotencyKey` (SF String/token, списки, 422 вместо 400 — отклонение), HTTP-fingerprint; `HttpOperationContextFactory`; `NodeResponses::respond` при replay удалённого узла; OpenAPI/доки/ADR-0029 против реализации |

Ограничения брифа (обязательно): **никаких прогонов наборов** — запрещены `scripts/t …`, `pest`, `phpstan`,
`inventory --check`, `schema-snapshot`, `npm test`. Разрешены: чтение, `git diff/show/log`, `grep`, `php -l <файл>`.
Каждая находка — файл:строка, сценарий отказа (входы → неверный результат) и предложенный минимальный репро
(тест/команда), но не запуск. Ревьюер ничего не правит.

После ответа: исполнитель подтверждает/отклоняет каждую находку своим целевым прогоном (часть Б), вердикт —
`artifacts/P3.11-P3.12/review-grok.md`, `plan.py log <item> --type note`, `plan.py commit <item> --role reviewer`.
Не больше одного раунда исправлений + одного ре-ревью (только по затронутому).

## Часть Б — прогоны (только изменённое) и фиксы

Каждая команда — один раз, вывод в файл (`artifacts/P3.11-P3.12/runs/*.txt`). Общие проверки обоих пунктов
считаются один раз и записываются в `--check` обоих.

| # | Команда | Зачем | Чеки |
|---|---|---|---|
| 1 | `scripts/php84 php vendor/bin/pint --test packages/core && scripts/php84 php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | core статика | P3.11 V5, P3.12 V10 |
| 2 | `cd packages/drive && ../../scripts/php84 php vendor/bin/pint --test && ../../scripts/php84 php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | drive | P3.11 V6, P3.12 V11 |
| 3 | то же для `packages/documents` | documents (манифест, коды) | P3.11 V7 |
| 4 | `scripts/php84 php scripts/inventory/inventory.php --check` + `scripts/php84 php vendor/bin/pint --test scripts/inventory tests/Repo/Arch && scripts/php84 php vendor/bin/phpstan analyse --no-progress --level=6 --memory-limit=1G scripts/inventory` | инвентарь/генератор | P3.11 V8,V10; P3.12 V12 |
| 5 | `scripts/t sqlite -p repo` | ledger, identities, zones/suites (покрывает ZonesMap/TestGroups) | P3.11 V9; P3.12 V13,V14 |
| 6 | `scripts/t sqlite -p core packages/core/tests/Http/Problems packages/core/tests/OpenApi packages/core/tests/Modules packages/core/tests/Idempotency packages/core/tests/Operations packages/core/tests/Support/DomainExceptionStatusTest.php packages/core/tests/Configuration packages/core/tests/Doctor` | ядро на sqlite одним прогоном | P3.11 V1; P3.12 V1 |
| 7 | `scripts/t pgsql -p core packages/core/tests/Idempotency` + `scripts/t pgsql -p core --group=serial packages/core/tests/Concurrency/IdempotencyRaceTest.php` + `scripts/t pgsql -p core --group=database packages/core/tests/Schema` | схема/транзакции/гонка на PG (схема менялась → нужен сервер) | P3.12 V2,V3,V9 |
| 8 | `scripts/t mysql -p core packages/core/tests/Idempotency` | отдельная ветка кода MySQL (lock-wait, RR) — один прогон | доп. (deviation-free) |
| 9 | `scripts/schema-snapshot.sh --driver=<sqlite|pgsql|mysql> --all-presets --compare=packages/core/tests/Fixtures/Schema/expected` + `git diff --quiet 83b7169e -- packages/core/tests/Fixtures/Schema/original` | DDL | P3.12 V5–V8 |
| 10 | `scripts/t sqlite -p drive` | drive целиком: create/rename используются повсюду, HTTP-тесты ключа | P3.11 V2; P3.12 V4 |
| 11 | `scripts/t sqlite -p documents` | манифест/коды documents | P3.11 V3 |
| 12 | `scripts/t sqlite -p filament tests/Feature/MoveRequiresDestinationCreateTest.php` | изменён контракт DriveHandle (SpyDriveHandle) | доп. |
| 13 | `npm --prefix packages/vue run typecheck && npm --prefix packages/vue run api:check && npm --prefix packages/vue test` | `errors.ts`, `schema.d.ts` | P3.11 V4 |
| 14 | `scripts/t baseline --compare` | переименован тест ProblemCatalogLangTest (CHANGES.json) | P3.11 V11; P3.12 V15 |
| 15 | `scripts/php84 php scripts/check-doc-links.php docs README.md && scripts/php84 php scripts/check-doc-links.php --mirror docs` | доки/ADR/зеркало | P3.11 V12; P3.12 V16 |

Не запускать: полный core-набор, azgard, media, mutation, load, coverage (квалификация фазы — P3.19).
Известное красное вне диффа (не чинить в этих пунктах): azgard RealAzgard* (дрейф ../azguard), drive
LifecycleBoundary на pgsql, core NodeLocatorTest на pgsql — см. логи P3.4–P3.10.

Фиксы: правка только в зоне пункта-владельца файла, перепрогон затронутых команд из таблицы,
`plan.py commit <item> --role fix`. Затем `plan.py finish … P3.11 --status done|done_with_deviations --check V1=0 …`,
потом P3.12 (последний закрывает фазу? — нет, P3.13+ ещё открыты). Отчёт: sha, результаты, `plan.py next`.
