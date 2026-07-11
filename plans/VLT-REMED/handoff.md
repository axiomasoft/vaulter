# HANDOFF — 2026-07-11 — after P3.2

**Next:** /task:plan-exec VLT-REMED P3.3

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — локальный код по готовому ТЗ, автономный batch (drive uploads), Routing: P3 весь дефолтный sonnet/medium, escalation-триггеров §10 в фазе нет |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P3.3 (B6 — Жизненный цикл upload-сессии)**: sweep застрявших `Assembled`-сессий + осиротевших staged/S3-частей, IDOR-guard `created_by` в `authorizeSession` (оба контроллера), activity-события initiate/abort/expire (F-P1.4-10, F-P1.4-12, F-P1.4-13). **Автономен** — не зависит от P3.2. |

```
/task:plan-exec VLT-REMED P3.3
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) В РАБОТЕ (2/6 🟢):**
  - **P3.1 (B11) закрыт 🟢** — коммит `756ba2a`. Собственный флаг `features.collaboration`
    (дефолт `false`) гейтит все collab-пути; `ActivityResource` гейтится на `activity`;
    дефолт-транспорт выровнен документацией+ADR-0001 Amendment (D21). Закрыты F-P1.6-01,
    F-P3.6-01, F-P2.5-06, F-P1.10-03. Гейт волны B11→B4 снят.
  - **P3.2 (B4) закрыт 🟢** — коммит `58d8225` на ветке `remediation/audit-vlt`.
    Двусторонняя HMAC-подпись входящего collab-webhook: `CollaborationCallResolver`
    верифицирует HMAC-SHA256 тела (canonical `"{timestamp}.{rawBody}"`, переиспользован
    `Vaulter\Webhooks\WebhookSigner` — тот же класс, что подписывает исходящие webhook)
    ПОВЕРХ существующей identity (secret/JWT не ослаблены), с timestamp-окном ±300с
    (новый ключ `collaboration.webhook_timestamp_tolerance`, дефолт 300) и anti-replay
    через `Cache::add` (ключ nonce = предъявленная подпись). `hocuspocus-server/src/
    webhook.js`: обе функции (`authenticate`, `storeDocument`) считают `rawBody` ДО
    отправки, подписывают `crypto.createHmac('sha256', …).digest('hex')` и шлют
    `X-Vaulter-Collab-Timestamp`/`X-Vaulter-Collab-Signature`. `hocuspocus-server/src/
    index.js`: новый `decodeTokenExpiry()` (читает `exp` БЕЗ проверки подписи — сервер
    уже верифицировал токен через `collab/authenticate`) + `armExpiryTimer()`
    (`connection.close({code:4001,reason})` на момент `exp`, API подтверждён RAG
    context7 `/ueberdosis/hocuspocus`) — `onAuthenticate` вызывает обе. Закрыты
    F-P1.6-02, F-P1.6-03. **Known Deviation:** `webhook_secret` теперь ОБЯЗАТЕЛЕН для
    ЛЮБОГО collab-вызова (ключ HMAC всегда `webhook_secret`, даже на JWT-identity-пути)
    — санкционировано ТЗ (C1.2: не смешивать с JWT-секретом), задокументировано как
    breaking в `UPGRADE.md`. Red-before подтверждён вручную (`git stash` кода
    резолвера/миддлвари/Config → 19/20 failed → `git stash pop` → 20/20 green).
  - **P3.3 (B6):** sweep застрявших `Assembled`/осиротевших частей + сверка `created_by`
    (IDOR) + activity lifecycle (F-P1.4-10/12/13). Drive. Автономен, готов.
  - **P3.4 (B7):** tus-коды 409/415/412 + OPTIONS-discovery + whitelist метаданных
    (F-P1.4-02/05/06/07/08). Drive. **Лок/семантику из P0.3 НЕ переопределять.** Автономен, готов.
  - **P3.5 (B22):** cache-safe `mergeConfigFrom`-гвард в documents-провайдере + doc
    канонического режима миграций (F-P2.2-01/02). Documents+core-doc. Автономен, готов.
  - **P3.6 (B23):** параметризация `config('vaulter.connection')` (nullable) в схему+модели
    + doc политики изменения схемы (F-P2.3-02/04). Core. Автономен, готов.

**Remaining:** P3.3–P3.6 (4 items, ГОТОВЫ к exec, все автономны), P4 (10, СКЕЛЕТ),
P5 (3, СКЕЛЕТ). Развёртывание P4/P5 — `/task:plan-design VLT-REMED Pn` перед первым
item'ом каждой (скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status
Board/Decision Log D1–D21, Update Log с записью P3.2) ·
`plans/VLT-REMED/phases/P3.md` (P3.1/P3.2 закрыты 🟢 с Completion Notes; P3.3–P3.6 готовы) ·
`plans/VLT-REMED/phases/{P0,P1,P2}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P3) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (N3 — коды tus для P3.4; неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него
НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.4-uploads,P1.10-filament-surface,P2.2-service-providers,
P2.3-schema-embeddability,P2.5-dx,P3.6-optional-subsystem}.md` (детальные находки
батчей P3) ·
`packages/documents/tests/Collaboration/CollaborationEnabledTestCase.php` (эталон:
как правильно включать оба collab-флага в тестах — В `defineEnvironment()`, ДО boot,
не в `beforeEach()`) ·
`packages/core/src/Webhooks/WebhookSigner.php` (canonical string, переиспользован
P3.2 для входящего направления — эталон для будущих HMAC-швов пакета).

**Open risks:**
- **P3.3 не имеет крипто-шва** — авторизационный/lifecycle батч, ниже риск, чем P3.2.
- **P3.4 взаимодействует с P0.3.** P0.3 уже тронул `TusUploadHandler` (лок сессии).
  Если F-P1.4-02 (409) уже закрыт P0.3 — эскалация/опровержение (D9), не дублирование.
  Лок/транзакцию из P0.3 не переопределять.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P3.3/P3.4 —
  drive, P3.5 — documents+core-doc, P3.6 — core (root покрывает) — свериться, какие
  own-гейты (`cd packages/<pkg> && composer analyse && test && test:types`) нужны сверх
  root для каждого конкретного item'а.
- Root `composer lint:check` красный на пред-существующем
  `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ
  блокирует; свой новый код обязан быть pint-чистым (проверено точечно
  `vendor/bin/pint --test <файлы>` в P3.1/P3.2 — держать эту практику).
- `CHANGELOG.md` заблокирован для Read/Write (`deny: Read(**/CHANGELOG.md)`) — долг
  записей за P1.2, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2 и далее. `UPGRADE.md` покрывает
  breaking-change-документирование по D3. Сессия с доступом должна догнать CHANGELOG.md.
- **Пред-существующий PG-only флейк `NodeCommentSchemaTest`** (jsonb `anchor` key-order
  round-trip на PostgreSQL) — вне scope, ещё не заведён отдельной находкой. Следующая
  сессия с доступом к `findings/` должна это сделать.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-
  feature-log.last` удалён, `.gitignore`, `.claude/workflows/vlt-remed-*.js`,
  `plans/ACTIVE.md`, `plans/AUDIT-VLT/{plan.md,phases/*.md}`, `plans/VLT-HSEAM/`) из
  конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а (P3.2
  подтвердил: staged только 12 файлов item'а, остальное осталось нетронутым).

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament — их own `composer analyse`/`test`/`test:types`
  (root покрывает только core). Node-часть collab (P3.2) — `cd hocuspocus-server && npm
  test` (node:test); тот же workaround нужен, если P3.3/P3.4 когда-либо тронут Node
  (маловероятно — drive-only батчи). Testbench-дефолт `cache.default=database` без
  миграции → `cache.default => 'array'` в тестовом `defineEnvironment()` (нужно для
  throttle P1.3 и nonce-store P3.2). Feature-флаги, гейтящие МАРШРУТЫ
  (`document_versions`, `collaboration`, `comments`), обязаны ставиться в
  `defineEnvironment()` (до boot), НЕ в `beforeEach()` теста. PG-прогон для F-id с
  реальным FK/SERIALIZABLE — `php8.4` + БД `vaulter_test` в контейнере
  `agelto_postgres` (127.0.0.1:5455, роль `vaulter`); P3.3 (sweep/discard, IDOR) может
  требовать PG для честной проверки FK-каскада staged-частей — сверить при
  исполнении, не откладывать на SQLite молча.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный
  `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) и абсолютный
  барьер wall-deny (Q4) — отдельные items по решению владельца. `CHANGELOG.md` записи
  за P1.2/P1.4/P2.1/P2.2/P2.3/P3.1/P3.2 и далее — сессия с доступом. Пред-существующий
  PG-only jsonb-флейк `NodeCommentSchemaTest` — завести находку/чинить отдельно.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) —
  оба `Decision pending`, ни один P3-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
