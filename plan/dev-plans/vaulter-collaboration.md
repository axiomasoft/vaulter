# План разработки репозитория `vaulter-collaboration` (Совместный текст)

**Паспорт:** [repos/vaulter-collaboration.md](../repos/vaulter-collaboration.md) · **Контракт:** [spec/collaboration.md](../spec/collaboration.md) · **Спецификация:** [spec/implementation/vaulter.md](../spec/implementation/vaulter.md#2122-комплект-совместного-текста) · **Схема БД:** [collaboration.sql](../spec/database/sql/collaboration.sql) + [collaboration-agent.sql](../spec/database/sql/collaboration-agent.sql)

Новый закрытый репозиторий, создаваемый выносом приватных пакетов коллаборации из `vaulter` с последующим исправлением транспорта, закрытием дыры compaction lease и реализацией строгого профиля `3.5` (протокол A / A42).

## Очередь задач репозитория

| Шаг | ID | Волна | Зависимости | Исполнитель | Ревьюер | Сдаваемый артефакт |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `2.0` | W1 | `0.1` (`vaulter`) | **Gemini Neon** | GPT-6.2 Sol | Новый репозиторий `vaulter-collaboration`, перенесённые PHP/Vue/Hub/E2E, `release/packages.json`, сборка 3 артефактов |
| 2 | `2.1` | W2 | `2.0` | **Gemini Carbon** | Gemini Neon | Исправленный `transport.ts` (`protocol=2`), прохождение `protocol.test.ts` без `missing` |
| 3 | Parity `3.1` | W2 | `2.0`, `3.1` (`vaulter`) | **Gemini Neon** | GPT-6.2 Sol | `hub/src/schema.js` с `attrs.blockId`, `schema-parity.test.js`, `TipTapSchemaFixtureTest.php` |
| 4 | `2.2` | W3 | `1.0` (`vaulter`), `2.1` | **GPT-6.2 Sol** | **Astra** | `lease_through_seq` в compaction, исправленный `restore-epoch.e2e.js`, прогон долговечности/поколений на PostgreSQL + Hub |
| 5 | `3.5` (часть collab) | W5 | `2.2`, `3.3` (`vaulter-ai`) | **GPT-6.2 Sol** | **Astra** | `v_collaboration_commands`, `v_collaboration_client_ids`, `profile` на `v_collaboration_heads`, `RoomOwnership`, `CommandJournal`, `room-sequencer.js`, все 11 вариантов A42 + замеры |

## Детальные инструкции по шагам

### Шаг 1 (`2.0`) — Gemini Neon → ревью GPT-6.2 Sol
- Инициализировать репозиторий `vaulter-collaboration` (можно предварительно выполнить scaffold через Gemini Argon): корневые `composer.json`, `package.json` (workspaces: `packages/vue-collaboration`, `packages/hub`), `release/packages.json`, CI-скрипты сборки ZIP/tgz/Docker.
- Перенести из `vaulter`:
  - `packages/collaboration/` (минус `hub/` — его перенести в `packages/hub/` для плоской структуры)
  - `packages/vue-collaboration/`
  - `e2e/` (`CollabEditor.vue`, `collab.spec.ts`, `docker-compose.yml`, `docker/Dockerfile.laravel` — вынести `migrate` в отдельный одноразовый сервис вместо `migrate:fresh`!)
- Подключить зависимости `axiomasoft/vaulter-core`, `drive`, `documents`, `@vaulter/vue` через локальные артефакты `vaulter` (в приёмке — через `artifacts.json`).
- **Проверка:** 84 теста `@vaulter/vue-collaboration`, 308 тестов Hub на Node 26, `scripts/t sqlite -p collaboration`, сборка 3 приватных артефактов.

### Шаг 2 (`2.1`) — Gemini Carbon → ревью Gemini Neon
- В `packages/vue-collaboration/src/transport.ts` в `defaultHocuspocusProvider` формировать URL через `new URL(...)` и `url.searchParams.set('protocol', '2')`, сохраняя path и существующие query-параметры (включая экранирование).
- Обновить `e2e/src/CollabEditor.vue` на `createCollaborationAdapter({client})` из `@vaulter/vue-collaboration`.
- **Проверка:**
  - `node node_modules/vitest/vitest.mjs run plan/checks/collaboration/protocol.test.ts` (без `VAULTER_HANDSHAKE_EXPECT=missing`) → PASS;
  - `npm test --workspace @vaulter/vue-collaboration` → PASS;
  - два редактора + один читатель в реальном Hub.

### Шаг 3 (Schema parity `3.1`) — Gemini Neon → ревью GPT-6.2 Sol
- Добавить `attrs.blockId` в `packages/hub/src/schema.js` и обновить `packages/collaboration/tests/Fixtures/tiptap-schema.json`.
- Гарантировать, что клиент со старой схемой v1 не получает право записи в адресуемый документ v2.
- **Проверка:** `node --test test/schema-parity.test.js` в Hub + `TipTapSchemaFixtureTest.php`.

### Шаг 4 (`2.2`) — GPT-6.2 Sol → ревью Astra
- **Compaction lease:** добавить `lease_through_seq` в `CompactionLeases`, `CompactionLease`, `Head`, `Checkpoint`, `CollaborationSchema` по `plan/checks/collaboration/lease-schema.sql`. На публикации checkpoint под `T→N→D→head` проверять `C <= covered_through <= lease_through_seq` и время по `clock_timestamp()`.
- **Устранение бага checkout в E2E:** обновить `packages/hub/test/e2e/restore-epoch.e2e.js` под текущий контракт сессии (`mode: write/read`, `generation`, `options.room`), передавать `If-Match` на restore, выровнять morph type субъекта в fixture с Hub resolver (устранить 404 `node_not_found`).
- **Офлайн-restore и поколения:** при `4409`/смене поколения браузер уничтожает старый `Y.Doc`, создаёт новый пустой `Y.Doc`, замораживает старую очередь как отдельный черновик.
- **Проверка:** `CheckpointLeaseCoverageTest.php`, `RestoreFenceTest.php`, `RestorePendingTest.php`, `restore-epoch.e2e.js`, Playwright `e2e/tests/collab.spec.ts` с рестартом Hub, потерей ACK и отзывом прав.

### Шаг 5 (`3.5`) — GPT-6.2 Sol → ревью Astra
- **БД и PHP:** создать миграции строго по обновлённому `plan/spec/database/sql/collaboration-agent.sql`:
  - `v_collaboration_heads`: добавить `profile` (`standard`/`strict`), `owner_fence`, `owner_id`, `owner_expires_at`;
  - `v_collaboration_commands`: бизнес-квитанции и evidence;
  - `v_collaboration_client_ids`: реестр уникальных `clientID` по `(document_id, generation, client_id)`.
- Реализовать `RoomOwnership.php`, `CommandJournal.php`, `HubCommandController.php`, обновить `UpdateLog.php`, `routes/collaboration.php`, `openapi/hub.yaml` (`ownership/acquire`, `ownership/renew`, `commands/next`, `commands/settle`).
- **Hub (`packages/hub/`):** создать `src/room-sequencer.js` по эталону `plan/examples/reference/live-write/room-sequencer.mjs`. В строгом профиле: `readOnly=true` на обычном sync, ввод только через `onStateless` envelope → sequencer → fenced append; запретить `DirectConnection.transact` и штатный Redis sync; replay только из журнала с origin `OWN`.
- **Vue:** запрашивать и привязывать `clientID` от владельца до первой мутации.
- **Проверка:** все 11 вариантов A42 (`plan/examples/live-write.json`) на реальных Laravel + PostgreSQL 18 + Hub + 2 браузерах, SIGKILL между SQL commit и apply, коллизия `clientID`, замеры p50/p95/p99 (обычный vs строгий профиль, 50 КБ и 500 КБ, 1/2/5 авторов).
