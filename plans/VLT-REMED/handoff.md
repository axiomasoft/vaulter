# HANDOFF — 2026-07-11 — after P3.3

**Next:** /task:plan-exec VLT-REMED P3.4

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — локальный код по готовому ТЗ, автономный batch (drive/tus-протокол), Routing: P3 весь дефолтный sonnet/medium, escalation-триггеров §10 в фазе нет |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P3.4 (B7 — Протокольная корректность tus)**: коды 409/415/412 + OPTIONS-discovery + whitelist метаданных (F-P1.4-02/05/06/07/08). Drive. **Лок/семантику из P0.3 НЕ переопределять** — P3.4 только маппит статусы/заголовки поверх уже введённого лока. Автономен, готов. |

```
/task:plan-exec VLT-REMED P3.4
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) В РАБОТЕ (3/6 🟢):**
  - **P3.1 (B11) закрыт 🟢** — коммит `756ba2a`. Собственный флаг `features.collaboration`
    (дефолт `false`) гейтит все collab-пути; `ActivityResource` гейтится на `activity`;
    дефолт-транспорт выровнен документацией+ADR-0001 Amendment (D21). Закрыты F-P1.6-01,
    F-P3.6-01, F-P2.5-06, F-P1.10-03. Гейт волны B11→B4 снят.
  - **P3.2 (B4) закрыт 🟢** — коммит `58d8225`. Двусторонняя HMAC-подпись входящего
    collab-webhook (тот же `WebhookSigner`, что подписывает исходящие) поверх identity
    (secret/JWT не ослаблены), timestamp-окно ±300с, anti-replay через `Cache::add`;
    серверный `exp`-таймер в `hocuspocus-server`. Закрыты F-P1.6-02, F-P1.6-03. **Known
    Deviation:** `webhook_secret` теперь ОБЯЗАТЕЛЕН для ЛЮБОГО collab-вызова (санкционировано
    ТЗ, задокументировано в `UPGRADE.md`).
  - **P3.3 (B6) закрыт 🟢** — коммит `bebd81e`. `UploadSession::scopeSweepable` подбирает
    застрявшие `Assembled`-сессии (тот же `expires_at`, стамплен один раз при `initiate`, без
    новой TTL-колонки) наряду с `Initiated`/`Uploading`; `authorizeSession` (оба контроллера,
    tus и S3-multipart) сверяет `created_by` сессии с актором ПОВЕРХ существующей
    ability-проверки — закрывает IDOR-by-ability; `UploadSessionManager::initiate/abort/
    expire` эмитят три новых события (`UploadSessionInitiated/Aborted/Expired`,
    `ShouldDispatchAfterCommit`, тот же паттерн что `FileUploaded`), `RecordsDriveActivity`
    пишет по одной activity-строке через существующий `ActivityRecorder`. Закрыты
    F-P1.4-10, F-P1.4-12, F-P1.4-13. 10 новых/обновлённых регрессионных тестов с F-id
    (`SweepUploadsTest`, новый `UploadSessionOwnershipTest`, новый
    `UploadSessionActivityTest`). `UPGRADE.md` — новая секция breaking (D3). Никаких
    отклонений от Routing (sonnet/medium — дефолт P3).
  - **P3.4 (B7):** tus-коды 409/415/412 + OPTIONS-discovery + whitelist метаданных
    (F-P1.4-02/05/06/07/08). Drive. **Лок/семантику из P0.3 НЕ переопределять.** Автономен,
    готов.
  - **P3.5 (B22):** cache-safe `mergeConfigFrom`-гвард в documents-провайдере + doc
    канонического режима миграций (F-P2.2-01/02). Documents+core-doc. Автономен, готов.
  - **P3.6 (B23):** параметризация `config('vaulter.connection')` (nullable) в схему+модели
    + doc политики изменения схемы (F-P2.3-02/04). Core. Автономен, готов.

**Remaining:** P3.4–P3.6 (3 items, ГОТОВЫ к exec, все автономны), P4 (10, СКЕЛЕТ),
P5 (3, СКЕЛЕТ). Развёртывание P4/P5 — `/task:plan-design VLT-REMED Pn` перед первым
item'ом каждой (скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status
Board/Decision Log D1–D21, Update Log с записью P3.3) ·
`plans/VLT-REMED/phases/P3.md` (P3.1/P3.2/P3.3 закрыты 🟢 с Completion Notes; P3.4–P3.6
готовы) ·
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
P3.2 для входящего направления — эталон для будущих HMAC-швов пакета) ·
`packages/drive/src/Uploads/Models/UploadSession.php` (`scopeSweepable` — P3.3 эталон
«TTL-порог = expires_at, стамплен один раз, не бампается» для любого будущего
lifecycle-sweep) ·
`packages/drive/src/Activity/RecordsDriveActivity.php` (эталон: как добавить новое
lifecycle-событие в activity-feed через существующий `ActivityRecorder::record()` —
P3.3 добавила три обработчика по этому шаблону).

**Open risks:**
- **P3.4 взаимодействует с P0.3.** P0.3 уже тронул `TusUploadHandler` (лок сессии).
  Если F-P1.4-02 (409) уже закрыт P0.3 — эскалация/опровержение (D9), не дублирование.
  Лок/транзакцию из P0.3 не переопределять.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P3.4 — drive
  (own-гейт обязателен, как в P3.3), P3.5 — documents+core-doc, P3.6 — core (root
  покрывает) — свериться, какие own-гейты (`cd packages/<pkg> && composer analyse &&
  test && test:types`) нужны сверх root для каждого конкретного item'а.
- Root `composer lint:check` красный на пред-существующем
  `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ
  блокирует; свой новый код обязан быть pint-чистым (проверено точечно
  `vendor/bin/pint --test <файлы>` в P3.1/P3.2/P3.3 — держать эту практику).
- `CHANGELOG.md` заблокирован для Read/Write (`deny: Read(**/CHANGELOG.md)`) — долг
  записей за P1.2, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2, P3.3 и далее. `UPGRADE.md`
  покрывает breaking-change-документирование по D3. Сессия с доступом должна догнать
  CHANGELOG.md.
- **Пред-существующий PG-only флейк `NodeCommentSchemaTest`** (jsonb `anchor` key-order
  round-trip на PostgreSQL) — вне scope, ещё не заведён отдельной находкой. Следующая
  сессия с доступом к `findings/` должна это сделать.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-
  feature-log.last` удалён, `.gitignore`, `.claude/workflows/vlt-remed-*.js`,
  `plans/ACTIVE.md`, `plans/AUDIT-VLT/{plan.md,phases/*.md}`, `plans/VLT-HSEAM/`) из
  конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а (P3.3
  подтвердил: staged только 16 файлов item'а, остальное осталось нетронутым).

**Workarounds/Deferred/Open questions:**
- workarounds: drive/documents/filament — их own `composer analyse`/`test`/`test:types`
  (root покрывает только core). Node-часть collab (P3.2) — `cd hocuspocus-server && npm
  test` (node:test); P3.4 маловероятно тронет Node (drive-only батч). Testbench-дефолт
  `cache.default=database` без миграции → `cache.default => 'array'` в тестовом
  `defineEnvironment()` (нужно для throttle P1.3 и nonce-store P3.2). Feature-флаги,
  гейтящие МАРШРУТЫ (`document_versions`, `collaboration`, `comments`), обязаны
  ставиться в `defineEnvironment()` (до boot), НЕ в `beforeEach()` теста. Console-контекст
  в in-process тестах (`$this->artisan(...)`) НАСЛЕДУЕТ `actingAs()`-пользователя из
  того же теста — `Auth::logout()` перед прогоном консольной команды, если тест
  проверяет null-актор системного действия (P3.3 `UploadSessionActivityTest`). PG-прогон
  для F-id с реальным FK/SERIALIZABLE — `php8.4` + БД `vaulter_test` в контейнере
  `agelto_postgres` (127.0.0.1:5455, роль `vaulter`); P3.3 не потребовал PG (sweep/IDOR/
  activity не задевают FK/SERIALIZABLE-инварианты P0.3).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный
  `/task:plan-close archive AUDIT-VLT`. Ротация `signing_secret` (Q3) и абсолютный
  барьер wall-deny (Q4) — отдельные items по решению владельца. `CHANGELOG.md` записи
  за P1.2/P1.4/P2.1/P2.2/P2.3/P3.1/P3.2/P3.3 и далее — сессия с доступом.
  Пред-существующий PG-only jsonb-флейк `NodeCommentSchemaTest` — завести
  находку/чинить отдельно.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) —
  оба `Decision pending`, ни один P3-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
