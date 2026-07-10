# HANDOFF — 2026-07-10 — after P0.3

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — развёртывание скелет-фазы P1 (батчи W1) в детальные items, проектные решения по scope |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Развернуть скелет фазы **P1 (W1 — быстрые высоко-эксплуатируемые)** в детальные items по «Обязательным осям» её Phase Context, закрыть DoR. Это `/task:plan-design`, НЕ `plan-exec`: P1 — скелет (Items 🟢/всего = 0/4, поля items не заполнены), прямой exec упадёт на предусловии. |

```
/task:plan-design VLT-REMED P1
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`, не дожидаясь W1–W4.
- P0.1 (B1 — stored XSS в TipTap) 🟢 — коммит `86a60a6` (F-P1.11-01/02/04/05).
- P0.2 (B3 — SSRF/целостность webhook) 🟢 — коммиты `296fb6d`/`a31462d`/`e49c890` (F-P1.7-01/02/03/05/06).
- P0.3 (B5 — целостность resumable-загрузок/CAS) 🟢 — коммит `69f589a`. Лок сессии на весь read-modify-write tus-PATCH
  (`pg_try_advisory_xact_lock` на PG — конфликт, не ожидание; FOR UPDATE на MySQL; single-writer на SQLite), offset со
  свежей залоченной строки. Потоковый `StagedUpload::append` (php://temp-spill, память не масштабируется с файлом) +
  пер-чанк-лимит. Сверка total-bytes в `S3MultipartHandler::complete`. magic-bytes-валидация собранного объекта
  (`MagicByteSniffer` → `UploadContentMismatchException` 415). temp+rename промоушен в `FlysystemStorageDriver::put`
  (skip-if-exists только для conditional-create — overwrite-путь S3-части сохранён). `sweepPending` под
  `serializable()`+retry-40001. 6 регресс-тестов с F-id, red-before подтверждён; концкррент-тест F-P1.4-01 — реальные
  два форкнутых процесса на PostgreSQL с барьером (до фикса okCount=2). Детали — `phases/P0.md` P0.3 Completion Notes.

**Remaining:** P1 (4 items), P2 (3), P3 (6), P4 (10), P5 (3) — все скелеты. Развёртывание КАЖДОЙ фазы P1–P5 —
`/task:plan-design VLT-REMED Pn` перед первым её item'ом (Execution Rules: скелет-фаза не исполняется напрямую).
После развёртывания P1 — `/task:plan-exec VLT-REMED P1.1` и далее по её items.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log) ·
`plans/VLT-REMED/phases/P0.md` (закрыта) · `plans/VLT-REMED/phases/P1.md` (скелет, «Обязательные оси» в Phase Context —
вход развёртывания) · `plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/P5.2-remediation-backlog.md` (партиция батчей/волн — раскладка W1).

**Open risks:**
- Все три Blocker'а закрыты — релиз-блокеров W0 больше нет. Но P1–P5 ещё не тронуты: пакет не «чист», это лишь конец W0.
- **PG-окружение для конкурентных/БД-зависимых тестов (D12):** root CLI PHP 8.5 БЕЗ `pdo_pgsql`. Прогон таких тестов
  делался через `php8.4` (8.4.23, входит в constraint `^8.4`) против БД `vaulter_test` в контейнере `agelto_postgres`
  (127.0.0.1:5455, роль agelto/пароль agelto_dev_local — изолированная БД, данные agelto не тронуты). Любой будущий item
  с PG-требованием (P2.3 dedup-UNIQUE NULL-tenant и т.п.) должен использовать тот же путь. Постоянную vaulter-роль/БД
  можно дооснастить, но не обязательно.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. Items, трогающие drive/media/documents/filament,
  ОБЯЗАНЫ гнать их собственные `cd packages/<pkg> && composer analyse && composer test && composer test:types`
  дополнительно (workaround с P0.1).
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне
  scope VLT-REMED) — известно с P0.1, closure НЕ блокирует; свой новый код обязан быть pint-чистым.

**Workarounds/Deferred/Open questions:**
- workarounds: (D12) PG-прогоны — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres`; drive/media/documents/filament —
  их own `composer analyse`/`test`/`test:types` (root покрывает только core). Форк-тесты конкуренции требуют закоммиченных
  строк — их директория (`tests/Concurrency`) под `DatabaseMigrations`, не `RefreshDatabase`.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация уже выданных
  `signing_secret` (Q3, P0.2 Pending Work) — отдельный item по решению владельца. Пред-существующие незакоммиченные диффы
  (`plans/ACTIVE.md`, `plans/AUDIT-VLT/**`, `.claude/.maind-feature-log.last`, `.claude/workflows/vlt-remed-drive.js`)
  оставлены как есть (артефакты драйвера/чужого плана, вне scope P0.3).
- open_questions: Q3 (`open-questions.md`) — нужна ли ротация уже выданных `signing_secret`; решение владельца. Q1/Q2 —
  `Resolved → D1/D10`.
