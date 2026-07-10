# HANDOFF — 2026-07-11 — after P3.0

**Next:** /task:plan-exec VLT-REMED P3.1

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — локальный код по готовому ТЗ; корень известен, нормы зафиксированы (Routing: P3 весь дефолтный sonnet/medium, escalation-триггеров §10 в фазе нет) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P3.1 (B11 — Feature-flag-гейтинг надстроек)**: собственный флаг `features.collaboration` (дефолт false, D20) гейтит все collab-пути; дефолт-транспорт выровнен (гайд+ADR-0001 Amendment+мёртвый fallback, D21); README помечает пять opt-in; Filament-ресурсы гейтятся на core-флаги. F-P1.6-01, F-P3.6-01, F-P2.5-06, F-P1.10-03 |

```
/task:plan-exec VLT-REMED P3.1 — читать phases/P3.md §P3.1 (16 полей), findings/P1.6-collab-seam.md §F-P1.6-01, findings/P3.6-optional-subsystem.md §F-P3.6-01; трогает documents+filament+core+docs → гнать root-гейт + own-гейты documents и filament (у filament свой vendor/TestCase). P3.1 — item-гейт волны: пока он не 🟢, P3.2 не исполнять (ребро B11→B4)
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) РАЗВЁРНУТА из скелета (design, не exec):** 6 items детализированы (16 полей каждый), DoR закрыт, скелет-маркер снят. Несущие решения — **D20** (флаг `features.collaboration`, дефолт false), **D21** (ADR-0001 Amendment о смене дефолт-транспорта reverb→hocuspocus). Ребро **B11→B4** закодировано как gate P3.1→P3.2 (P3.2 не 🟢 без P3.1). Routing — дефолт sonnet/medium (escalation-триггеров нет). Свежей внешней RAG не требовалось (нормы W3 унаследованы датированными выжимками AUDIT-VLT).
  - **P3.1 (B11):** флаг collab + Filament-гейт на core-флаги + честный README + дефолт-транспорт (F-P1.6-01, F-P3.6-01, F-P2.5-06, F-P1.10-03). Трогает documents+filament+core+docs.
  - **P3.2 (B4):** двусторонний HMAC-подпись входящего collab-webhook (Node подписывает, PHP верифицирует) + timestamp-окно + nonce-replay + серверный `exp`-таймер (F-P1.6-02, F-P1.6-03). Крипто-шов: анти-подгонка, `hash_hmac`/`hash_equals`, PHP↔Node canonical string байт-в-байт. **Предусловие — P3.1 закрыт.**
  - **P3.3 (B6):** sweep застрявших `Assembled`/осиротевших частей + сверка `created_by` (IDOR) + activity lifecycle (F-P1.4-10/12/13). Drive.
  - **P3.4 (B7):** tus-коды 409/415/412 + OPTIONS-discovery + whitelist метаданных (F-P1.4-02/05/06/07/08). Drive. **Лок/семантику из P0.3 НЕ переопределять** — только HTTP-статусы/заголовки/валидация входа.
  - **P3.5 (B22):** cache-safe `mergeConfigFrom`-гвард в documents-провайдере + doc канонического режима миграций (F-P2.2-01/02). Documents+core-doc.
  - **P3.6 (B23):** параметризация `config('vaulter.connection')` (nullable) в схему+модели + doc политики изменения схемы (F-P2.3-02/04). Core.

**Remaining:** P3 (6 items, ГОТОВЫ к exec — начинать с P3.1), P4 (10, СКЕЛЕТ), P5 (3, СКЕЛЕТ). Развёртывание P4/P5 — `/task:plan-design VLT-REMED Pn` перед первым item'ом каждой (скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D21) ·
`plans/VLT-REMED/phases/P3.md` (РАЗВЁРНУТА, 6 items, DoR закрыт) · `plans/VLT-REMED/phases/{P0,P1,P2}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P3) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (N2 — эталон webhook-контролей для P3.2; N3 — коды tus для P3.4; неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.6-collab-seam,P1.4-uploads,P1.10-filament-surface,P2.2-service-providers,P2.3-schema-embeddability,P2.5-dx,P3.6-optional-subsystem}.md` (детальные находки батчей P3) ·
`docs/adr/0001-collaboration-transport.md` (несёт Amendment P3.1 по D21).

**Open risks:**
- **Ребро B11→B4 — жёсткое.** P3.2 (харденинг collab-шва) НЕ закрывать 🟢, пока P3.1 (собственный флаг collab) не 🟢 — иначе харденится код, который по D13 не должен грузиться в базовой поставке. Закодировано как gate в Code Guidance P3.2.
- **P3.2 крипто-чувствителен и кросс-рантаймовый (PHP + Node).** HMAC-подпись двусторонняя: `hocuspocus-server/src/webhook.js` (Node) подписывает, `CollaborationCallResolver` (PHP) верифицирует — canonical string обязан совпадать байт-в-байт. Node-изменения root-гейт НЕ покрывает → `cd hocuspocus-server && npm test`. Дефолт-`false` флага collab ломает существующие collab-тесты — они обязаны сами включать `features.collaboration=true` (анти-регресс, не баг флага).
- **P3.4 взаимодействует с P0.3.** P0.3 уже тронул `TusUploadHandler` (лок сессии). Если F-P1.4-02 (409) уже закрыт P0.3 — эскалация/опровержение (D9), не дублирование. Лок/транзакцию из P0.3 не переопределять.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P3.1 трогает documents+filament, P3.2/P3.3/P3.4 — documents/drive, P3.5 — documents — их own-гейты (`cd packages/<pkg> && composer analyse && test && test:types`) обязательны.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым.
- `CHANGELOG.md` заблокирован для Read/Write (`deny: Read(**/CHANGELOG.md)`) — долг записей за P1.2, P1.4, P2.1, P2.2, P2.3 и весь P3. `UPGRADE.md` покрывает breaking-change-документирование по D3. Сессия с доступом должна догнать CHANGELOG.md.
- **Пред-существующий PG-only флейк `NodeCommentSchemaTest`** (jsonb `anchor` key-order round-trip на PostgreSQL) — вне scope, ещё не заведён отдельной находкой. Следующая сессия с доступом к `findings/` должна это сделать.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/workflows/vlt-remed-*.js` и др. из конкурентной сессии/хука) — НЕ трогать, коммитить только scoped-пути item'а.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament — их own `composer analyse`/`test`/`test:types` (root покрывает только core). Node-часть P3.2 — `cd hocuspocus-server && npm test` (node:test). Testbench-дефолт `cache.default=database` без миграции → `cache.default => 'array'` в тестовом `defineEnvironment()` (нужно для throttle P1.3 и nonce-store P3.2). Collab-тесты обязаны включать `features.collaboration=true` в `defineEnvironment()` после P3.1 (маршруты иначе не смонтированы). PG-прогон для F-id с реальным FK/SERIALIZABLE — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (127.0.0.1:5455, роль `vaulter`); в P3 жёсткого PG-требования нет (второе соединение P3.6 — второй sqlite `:memory:`).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца. `CHANGELOG.md` записи за P1.2/P1.4/P2.1/P2.2/P2.3/P3.* — сессия с доступом. Пред-существующий PG-only jsonb-флейк `NodeCommentSchemaTest` — завести находку/чинить отдельно.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) — оба `Decision pending`, ни один P3-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
