# HANDOFF — 2026-07-11 — after P3.1

**Next:** /task:plan-exec VLT-REMED P3.2

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — локальный код по готовому ТЗ, крипто-шов компенсируется тугим Code Guidance, не сменой модели (Routing: P3 весь дефолтный sonnet/medium, escalation-триггеров §10 в фазе нет) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P3.2 (B4 — Харденинг collab-шва)**: двусторонняя HMAC-подпись входящего collab-webhook (Node подписывает сырое тело, PHP верифицирует `hash_equals`) + timestamp-окно ±300с + анти-replay (nonce/Cache) + серверный таймер `exp` в `hocuspocus-server`. **Предусловие уже выполнено — P3.1 (B11) закрыт 🟢, ребро B11→B4 снято.** F-P1.6-02, F-P1.6-03 |

```
/task:plan-exec VLT-REMED P3.2 — читать phases/P3.md §P3.2 (16 полей),
findings/P1.6-collab-seam.md §F-P1.6-02/03, findings/P0-collab-seam.md §C3/§C5,
findings/P0-remediation-norms.md N2 (пять контролей webhook — эталон направления);
крипто-шов PHP↔Node — canonical string обязан совпадать байт-в-байт; если тронут
hocuspocus-server/src — `cd hocuspocus-server && npm test` (node:test) ОБЯЗАТЕЛЕН
сверх root+documents-гейта; collab-тесты используют `packages/documents/tests/Collaboration/
CollaborationEnabledTestCase.php` (P3.1) — оба флага (document_versions+collaboration) уже
включены там, НЕ добавлять третий TestCase.
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) В РАБОТЕ (1/6 🟢):**
  - **P3.1 (B11) закрыт 🟢** — коммит `756ba2a` на ветке `remediation/audit-vlt`.
    Собственный флаг `features.collaboration` (дефолт `false`) гейтит `collab/token`,
    `collab/authenticate`, `collab/store`, `registerBroadcastChannel` и (транзитивно
    через маршруты) collab-драйверы — И-условие с `document_versions`, который
    остался чистым гейтом не-realtime editing/версий. `VaulterPlugin::resourceEnabled`
    AND-ится на core-флаг `activity` для `ActivityResource` (НЕ расширено на
    `grant`/`share` — см. Known Deviations phases/P3.md P3.1, оба консультируются
    `PanelTablesBuilder::entityEnabled()` только для динамических per-panel схем, а
    статические миграции пакета создают `v_node_grants`/`v_node_shares` безусловно).
    Дефолт-транспорт выровнен документацией+ADR-0001 Amendment (D21) — код НЕ менялся
    (уже был `hocuspocus`). README помечает пять opt-in-пунктов. `UPGRADE.md` incl.
    breaking-note. Закрыты F-P1.6-01, F-P3.6-01, F-P2.5-06, F-P1.10-03. **Гейт
    волны B11→B4 снят — P3.2 разрешён к исполнению.**
  - **P3.2 (B4):** двусторонний HMAC-подпись входящего collab-webhook + timestamp-окно
    + nonce-replay + серверный `exp`-таймер (F-P1.6-02, F-P1.6-03). Крипто-шов:
    анти-подгонка, `hash_hmac`/`hash_equals`, PHP↔Node canonical string байт-в-байт.
    **Предусловие выполнено** (P3.1 🟢) — готов к исполнению.
  - **P3.3 (B6):** sweep застрявших `Assembled`/осиротевших частей + сверка `created_by`
    (IDOR) + activity lifecycle (F-P1.4-10/12/13). Drive. Автономен, готов.
  - **P3.4 (B7):** tus-коды 409/415/412 + OPTIONS-discovery + whitelist метаданных
    (F-P1.4-02/05/06/07/08). Drive. **Лок/семантику из P0.3 НЕ переопределять.** Автономен, готов.
  - **P3.5 (B22):** cache-safe `mergeConfigFrom`-гвард в documents-провайдере + doc
    канонического режима миграций (F-P2.2-01/02). Documents+core-doc. Автономен, готов.
  - **P3.6 (B23):** параметризация `config('vaulter.connection')` (nullable) в схему+модели
    + doc политики изменения схемы (F-P2.3-02/04). Core. Автономен, готов.

**Remaining:** P3.2–P3.6 (5 items, ГОТОВЫ к exec, ребро B11→B4 снято), P4 (10, СКЕЛЕТ),
P5 (3, СКЕЛЕТ). Развёртывание P4/P5 — `/task:plan-design VLT-REMED Pn` перед первым
item'ом каждой (скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status
Board/Decision Log D1–D21, Update Log с записью P3.1) ·
`plans/VLT-REMED/phases/P3.md` (P3.1 закрыт 🟢 с Completion Notes; P3.2–P3.6 готовы) ·
`plans/VLT-REMED/phases/{P0,P1,P2}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P3) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (N2 — эталон webhook-контролей для
P3.2; N3 — коды tus для P3.4; неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него
НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.6-collab-seam,P1.4-uploads,P1.10-filament-surface,
P2.2-service-providers,P2.3-schema-embeddability,P2.5-dx,P3.6-optional-subsystem}.md`
(детальные находки батчей P3) ·
`docs/adr/0001-collaboration-transport.md` (несёт Amendment P3.1 по D21) ·
`packages/documents/tests/Collaboration/CollaborationEnabledTestCase.php` (эталон:
как правильно включать оба collab-флага в тестах — В `defineEnvironment()`, ДО boot,
не в `beforeEach()`).

**Open risks:**
- **P3.2 крипто-чувствителен и кросс-рантаймовый (PHP + Node).** HMAC-подпись
  двусторонняя: `hocuspocus-server/src/webhook.js` (Node) подписывает,
  `CollaborationCallResolver` (PHP) верифицирует — canonical string обязан совпадать
  байт-в-байт. Node-изменения root-гейт НЕ покрывает → `cd hocuspocus-server && npm test`.
  Collab-тесты используют `CollaborationEnabledTestCase` (P3.1) — оба флага уже там,
  P3.2 НЕ создаёт третий TestCase, расширяет существующий набор тестов в
  `packages/documents/tests/Collaboration/`.
- **P3.4 взаимодействует с P0.3.** P0.3 уже тронул `TusUploadHandler` (лок сессии).
  Если F-P1.4-02 (409) уже закрыт P0.3 — эскалация/опровержение (D9), не дублирование.
  Лок/транзакцию из P0.3 не переопределять.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P3.2/P3.3/P3.4 —
  documents/drive, P3.5 — documents — их own-гейты (`cd packages/<pkg> && composer analyse
  && test && test:types`) обязательны.
- Root `composer lint:check` красный на пред-существующем
  `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ
  блокирует; свой новый код обязан быть pint-чистым (проверено точечно
  `vendor/bin/pint --test <файлы>` в P3.1 — держать эту практику).
- `CHANGELOG.md` заблокирован для Read/Write (`deny: Read(**/CHANGELOG.md)`) — долг
  записей за P1.2, P1.4, P2.1, P2.2, P2.3, P3.1 и далее. `UPGRADE.md` покрывает
  breaking-change-документирование по D3. Сессия с доступом должна догнать CHANGELOG.md.
- **Пред-существующий PG-only флейк `NodeCommentSchemaTest`** (jsonb `anchor` key-order
  round-trip на PostgreSQL) — вне scope, ещё не заведён отдельной находкой. Следующая
  сессия с доступом к `findings/` должна это сделать.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-
  feature-log.last` удалён, `.gitignore`, `.claude/workflows/vlt-remed-*.js`,
  `plans/ACTIVE.md`, `plans/AUDIT-VLT/{plan.md,phases/*.md}`, `plans/VLT-HSEAM/`) из
  конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а (P3.1
  подтвердил: staged только 19 файлов item'а, остальное осталось нетронутым).

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament — их own `composer analyse`/`test`/`test:types`
  (root покрывает только core). Node-часть P3.2 — `cd hocuspocus-server && npm test`
  (node:test). Testbench-дефолт `cache.default=database` без миграции →
  `cache.default => 'array'` в тестовом `defineEnvironment()` (нужно для throttle P1.3 и
  nonce-store P3.2). Feature-флаги, гейтящие МАРШРУТЫ (`document_versions`,
  `collaboration`, `comments`), обязаны ставиться в `defineEnvironment()` (до boot), НЕ
  в `beforeEach()` теста — маршруты регистрируются один раз при старте приложения
  (усвоено на P3.1: `config()->set()` в `beforeEach()` для этих флагов не действует
  ретроактивно на уже смонтированные маршруты). PG-прогон для F-id с реальным
  FK/SERIALIZABLE — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres`
  (127.0.0.1:5455, роль `vaulter`); в P3 жёсткого PG-требования нет (второе соединение
  P3.6 — второй sqlite `:memory:`).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный
  `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) и абсолютный
  барьер wall-deny (Q4) — отдельные items по решению владельца. `CHANGELOG.md` записи
  за P1.2/P1.4/P2.1/P2.2/P2.3/P3.1 и далее — сессия с доступом. Пред-существующий
  PG-only jsonb-флейк `NodeCommentSchemaTest` — завести находку/чинить отдельно.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) —
  оба `Decision pending`, ни один P3-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
