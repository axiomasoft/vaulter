# P5.16 — квалификация фазы P5 (Fast-набор, D41)

Дата: 2026-10-18. Режим: только Fast-набор (sqlite, статика, структурные проверки). Серверные движки, full, serial/extended/stand, coverage, мутации, load, `npm run build`, `consumer-check` без `--dry-run` не запускались. Суммарное время запусков PHP/структурных проверок ≈ 6 мин (каждая команда < 70 с). Сырые выводы лежат рядом: `artifacts/P5.16/V*.log`.

## Итог

Красный остаток только окружением песочницы: V5 (1 тест из 242), V6 в части vue (нет реестра npm), V11 `npm test` (29 файлов хаба не находят `yjs`/`dotenv`/`@hocuspocus/*`), V12 целиком (нет `vue-tsc`/`vitest`, реестр npm недоступен). Кодовых красных, не объяснённых песочницей, нет. В ходе квалификации исправлены два красных (см. «Найдено и исправлено»).

## Поправка (executor.2): V11 на хосте

Прогон V11 у координатора (хост с доступным node_modules) дал 306 tests, 305 pass, 1 fail (exit 1). Имя упавшего теста в переданном хвосте вывода отсутствует; в песочнице исполнителя `yjs`, `dotenv` и `@hocuspocus/*` недоступны (node_modules замаскирован), поэтому воспроизвести падение и исправить его исполнитель не смог. Строка V11 в таблице ниже описывает только песочничный прогон (155 pass / 29 fail по ERR_MODULE_NOT_FOUND). Пункт V11 остаётся красным до разбора упавшего теста на хосте.

## Результаты по проверкам

| Id | Команда | Время | passed / failed / skipped | exit |
|:--|:--|:--|:--|:--|
| V1 | `scripts/t sqlite -p collaboration tests/Module tests/Collaboration tests/Persistence tests/Restore tests/Disable tests/Revocation tests/Http tests/Arch tests/Console tests/Document tests/Configuration tests/Providers` | 24 с | 348 / 0 / 0 (7 партиций) | 0 (после исправления RawDisableTest; первый прогон exit 1, 1 failed) |
| V2 | `scripts/t sqlite -p documents tests/State tests/Document tests/Http tests/Operations tests/Arch` | 46 с | 444 / 0 / 1 | 0 |
| V3 | `scripts/t sqlite -p core packages/core/tests/Modules` | 18 с | 238 / 0 / 0 | 0 |
| V4 | `scripts/t sqlite -p drive tests/Access` | 10 с | 136 / 0 / 0 | 0 |
| V5 | `scripts/t sqlite -p repo` | 67 с | 241 / 1 / 0 (из 242) | 1 — `ConsumerCheckTest › stages the root Pest harness next to core…`: `tar: ./hub/package-lock.json: Cannot mknod` (файл замаскирован песочницей), тот же артефакт, что в P5.14 V6; остальные 18 тестов ConsumerCheckTest и вся остальная repo-партиция зелёные |
| V6 | `scripts/t baseline --compare` | 4 с | — | 1 — vue-discovery `npx vitest list` → `E403 registry.npmjs.org`. Те же сравнения без vue/vue-collaboration: `scripts/t baseline --compare -p core,media,drive,webhooks,documents,collaboration,azgard,filament,repo,hub` — 8 с, exit 0 (collaboration current 380, missing 0; hub current 30) |
| V7 | `scripts/php84 php scripts/inventory/inventory.php --check` | 23 с | — | 0 (I2/I4 без остатка D32, I3 зелёный) |
| V8 | `scripts/schema-snapshot.sh --driver=sqlite --all-presets --rollback --compare=packages/core/tests/Fixtures/Schema/expected` | 27 с | — | 0 |
| V9 | `git diff --quiet 2a657840 -- packages/core/tests/Fixtures/Schema/original` | <1 с | — | 0 (original неизменен) |
| V10 | pint `--test` + phpstan: core / drive / documents / collaboration (по пакетам, раздельно) | 13 + 8 + 8 + 9 с | pint passed, phpstan 0 errors ×4 | 0 (collaboration — после pint-правки, см. ниже) |
| V11 | `(cd packages/collaboration/hub && npm run check && npm test)` | 4 с | `check` — exit 0 (19 `node --check`); `npm test`: 184 tests, 155 pass, 29 fail, 0 skipped | 1 — все 29 падений `ERR_MODULE_NOT_FOUND` (`yjs`, `dotenv`, `@hocuspocus/server`; node_modules замаскирован, реестр закрыт); других причин нет |
| V12 | `npm --prefix packages/vue run typecheck && … api:check && … vue-collaboration typecheck && … test && … vue test -- tests/arch tests/exports.test.ts tests/document-node.test.ts` | 0 с | не запускались | 127 — `vue-tsc: not found`, `vitest: not found`; `api:check` → `E403 registry.npmjs.org` (`V12.log`, `V12-parts.log`) |
| V13 | `scripts/release/consumer-check.sh collaboration --dry-run` | 4 с | — | 0 (dry run OK) |
| V14 | `scripts/php84 php scripts/check-doc-links.php docs README.md && … --mirror docs` | <1 с | 1154 ссылки в 150 файлах; mirror 59 страниц | 0 |
| V15 | `test -s …/artifacts/P5.16/qualification.md` | — | — | 0 (этот файл) |

azgard: RealAzgard* не запускались (D41, известные красные внешнего `../azguard`).

## Найдено и исправлено в ходе квалификации (D43)

1. `packages/collaboration/tests/Disable/RawDisableTest.php:171` — ожидал 3 миграции `create_v_collaboration_%`; P5.10 добавил четвёртую (`000504` permission_versions), тест был красным с P5.10. Ожидание 3 → 4.
2. `packages/collaboration/tests/Arch/I17ScenarioMapTest.php:56` — pint `unary_operator_spaces` (`! $inMonorepo` → `!$inMonorepo`), правка P5.14 нарушала pint пакета; V10 collaboration был красным.

## Отложено в PLAN4

`knowledge/deferred-runs#items-p5`: полные пакетные прогоны `scripts/t sqlite -p core,media,drive,documents,webhooks,collaboration,filament,azgard`, pgsql/mysql (`--group=database`), serial-гонки `tests/Concurrency/*RaceTest.php` (collaboration, documents), stand 2/3/10 (`scripts/collab-stand.sh`), `scripts/collab-e2e.sh`, сборка образа хаба, `npm run build`, снимки схемы pgsql/mysql (файлы expected для pgsql/mysql написаны вручную по паттернам, сравнение на движках — PLAN4), `consumer-check` без `--dry-run`, coverage, мутации, load. Дополнительно для PLAN4 (из-за ограничений песочницы): V11 `npm test` целиком (Yjs/Hocuspocus-тесты хаба ни разу не исполнялись в PLAN3), V12 (vue/vue-collaboration typecheck, `api:check`, vitest), vue-baseline compare, ConsumerCheckTest «root Pest harness» на хосте с читаемыми lock-файлами.

## nits.md

В `artifacts/nits.md` по P5 записи есть только от P5.1 и P5.2 (строки 84–92): старые FQCN в docs (закрыто P5.15), CollaborationLivenessRaceTest pgsql serial не запускался, CollaborationModule без required config, `broadcast.channel_prefix` остаётся в `vaulter-documents.*`, дрейф lock-файлов tiptap, переименование джоб CI (required checks), остатки hocuspocus-server в docs (закрыто P5.15), stderr-лог библиотеки в `hocuspocus-v4.test.js`. Записей P5.3–P5.15 в файле нет; их сопутствующие замечания живут в lessons/friction этих пунктов.

## Решения D#, принятые агентами в P5

- D46 — `permissions_version` коллаборации: собственная таблица `v_collaboration_permission_versions` (scope_key `drive:<id>`|`global`), EventSink в транзакции события drive (P5 детализация, D44).
- Решения детализации уровня note без нового D# (ссылки на пункты): протокол compaction lease (P5.5), `seed_state_version` в append (P5.7), cap `hub.token_ttl` ≤ 300 с и `clock_skew` 0..60 (P5.10), draining-флаг не снимается реактивацией, только `--release` (P5.6).

## Открытые риски для следующего плана

- Клиент `@vaulter/vue-collaboration` не добавляет `?protocol=2` к URL хаба, хаб считает такое соединение read-only: реальный редактор не сохранит (P5.13/P5.15). Исправить до PLAN4 e2e.
- Drive-ветки retention purge / purge шага удаления диска отдают `document_state_pending` без drain (P5.3, нужен drive pre-purge hook и D#).
- Предположения о Hocuspocus 4.3 (origins, readOnly, `beforeHandleMessage`) проверены чтением dist, но Node-тесты с yjs/hocuspocus в PLAN3 не исполнены.
- drive `tests/Arch` (вне V4): `Testing/Events` слой и устаревший VLT-REL golden (P5.10) — pre-existing.
