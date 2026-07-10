# HANDOFF — 2026-07-11 — after P1.4

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — развёртывание скелет-фазы: несущие архитектурные решения (ядро `AccessResolver`, изоляция) требуют design-time RAG и Alternatives, не механическое ТЗ |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Развернуть скелет-фазу **P2 (W2 — Авторизация и изоляция)**: 3 items из `plan.md` §Routing (P2.1 → opus/high явно поимённо — ядро `AccessResolver`), Phase Context/DoR/16 полей на item. Скелет-фаза НЕ исполняется напрямую (Execution Rules). |

```
/task:plan-design VLT-REMED P2
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`.
- P0.1 (B1 — stored XSS в TipTap) 🟢 — коммит `86a60a6`. P0.2 (B3 — SSRF/webhook) 🟢 — `296fb6d`/`a31462d`/`e49c890`. P0.3 (B5 — целостность загрузок/CAS) 🟢 — `69f589a`.
- **Фаза P1 (W1 — Быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).** Закрыты 17 находок волны W1.
  - **P1.1 (B2 — заголовки скачивания: nosniff + whitelist Content-Type) 🟢.** Закрыты F-P1.3-04, F-P1.11-03. Новый `final class DownloadHeaders` (`packages/core/src/Http/Support/DownloadHeaders.php`) — единственный шов сборки заголовков раздачи.
  - **P1.2 (B8 — публичная share-поверхность) 🟢.** Коммит `d855df7`. Закрыты F-P1.3-01/02/03/05: presign-редирект убран (D15), issuer-re-check в `ShareResolver` через `Ability::Share` (D14), дефолтный/max TTL ссылок, опаковка публичного node-id `hash_hmac`-проекцией (D15).
  - **P1.3 (B14 — rate-limit и DoS-поверхность листингов) 🟢.** Коммиты `0a1644d`/`fbeac3b`. Закрыты F-P1.8-01, F-P1.4-09, F-P4.2-03, F-P4.2-04: именованные throttle-лимитеры пакета (`vaulter-public`/`vaulter-uploads`), кап активных upload-сессий, O(1) памоизация storage в листингах (identity-map + `scoped()`-биндинг), документированный offset-контракт ленты `shared` (D16-fallback).
  - **P1.4 (B13 — mass-assignment-харденинг всех 16 моделей) 🟢.** Коммит `00bf26e`. Закрыты F-P1.8-02, F-P1.5-04, F-P1.7-04:
    - Снят `$guarded=[]` со всех 16 моделей: закрытый `$fillable` (только легитимно пользовательские колонки) на 9 моделях (`Node`, `Storage`, `FileVersion`, `NodeShare`, `WebhookEndpoint`, `UploadSession`, `NodeComment`, `NodeDocumentVersion` + частичный `$guarded`-список на `File`); полный `$guarded=['*']` (Eloquent «totally guarded» — `fill()` с любым атрибутом бросает `MassAssignmentException`) на 6 моделях (`Blob`, `FileVersionBlob`, `NodeGrant`, `Activity`, `Outbox`, `WebhookDelivery`).
    - Все внутренние write-пути (`*StoreRepository::create()`, `OutboxRecorder::stage()`, `OutboxRelay::createDelivery()`, `GrantStoreRepository::grant()` — последний переписан с `updateOrCreate` на ручной find-then-forceFill) переведены с `fill()`/`updateOrCreate()`/статического `::create([...])` на `forceFill()` — доверенный шов, принимающий только серверно-построенные литеральные массивы.
    - **Урок для следующих items:** `$guarded=['*']` в Laravel — Eloquent's «totally guarded» состояние: `fill()` с ЛЮБЫМ непустым массивом бросает `MassAssignmentException` (НЕ молчаливый discard, как у частичного `$guarded`/`$fillable`-списка) — тесты на такие модели нужно писать как `expect(fn () => ...)->toThrow(MassAssignmentException::class)`, не как проверку отсутствия ключа в атрибутах. Пивоты с кастомным `->using()` (`FileVersionBlob`) у Laravel 13 маршрутизируют атрибуты `attach()` через mass-assignment — Eloquent-relation `attach()` на fully-guarded pivot-модели бросит то же исключение; продакшен-путь (query-builder `updateOrInsert` в выделенном `*StoreRepository`) вне guard'а в принципе, тесты должны бить именно через него, не через голую `->attach()`.
    - Существующие Schema/Uploads-тесты и хелперы, писавшие в модели НАПРЯМУЮ мимо репозиториев (`Model::query()->create([...])`), пришлось чинить тем же паттерном (`forceFill`) — это НЕ регресс нового кода, это преэкзистентная зависимость тестов от `$guarded=[]`, вскрытая гейтом валидации.

**Remaining:** P2 (3 items, скелет), P3 (6, скелет), P4 (10, скелет), P5 (3, скелет). Развёртывание КАЖДОЙ фазы — `/task:plan-design VLT-REMED Pn` перед её первым item'ом (Execution Rules: скелет-фаза не исполняется напрямую). P2.1 именован в `plan.md` §Routing как opus/high (ядро `AccessResolver`, инвариант H.2/deny-вето).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D16) ·
`plans/VLT-REMED/phases/P0.md` (закрыта) · `plans/VLT-REMED/phases/P1.md` (ЗАКРЫТА, терминальна 4/4, Phase Handoff заполнен) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.3-sharing-download,P1.8-secrets-config-http,P4.2-nplus1-recursion-pagination,P1.4-uploads,P1.5-cas-blobs,P1.7-outbound-webhooks}.md`
(доказательства находок W1 с `file:line`).

**Open risks:**
- P1 закрывает быстрые высоко-эксплуатируемые, но авторизация/изоляция (W2), надстройки (W3), качество/CI (W4) ещё не тронуты — пакет не «чист».
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. `packages/core` при этом не несёт СОБСТВЕННЫХ composer-скриптов — root-прогон и есть его гейт. Items, трогающие `drive`/`documents`/`media`/`filament`/`permissions-azgard` — гнать их own-прогон дополнительно, пока item их трогает.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым.
- **`CHANGELOG.md` заблокирован для Read/Bash-инспекции** этой сессией (`.claude/settings.local.json` → `deny: ["Read(**/CHANGELOG.md)"]`) с P1.2 — долг накопился за P1.2 И P1.4 (оба обновили только `UPGRADE.md`). Следующая сессия с доступом к чтению `CHANGELOG.md` должна догнать обе пропущенные записи разом.
- `packages/permissions-azgard/composer.json` не определяет `test:types`-скрипт (пред-существующая характеристика пакета, не долг P1.4) — если будущий item коснётся этого пакета, гнать только `analyse`/`test`/`lint:check` там.
- В рабочем дереве присутствует НЕ моя правка — `plans/VLT-HSEAM/` (новый, не относящийся к VLT-REMED план), `.claude/workflows/vlt-remed-drive.js`/`.claude/workflows/vlt-remed-auto.js` и модификация `.gitignore`/`.claude/.maind-feature-log.last` — появились независимо от исполнения items этой фазы (конкурентная сессия/процесс/хук), НЕ созданы и НЕ закоммичены этими items, оставлены как есть.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/media/documents/filament — их own `composer analyse`/`test`/`test:types` (root покрывает только core, core сам без скриптов); `permissions-azgard` без `test:types`-скрипта. PG-прогоны (если понадобятся downstream) — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (см. P0.3 handoff); для W1 PG не требовался. Testbench-дефолтный `cache.default=database` не имеет миграции таблицы `cache` — throttle-middleware требует `cache.default => 'array'` в тестовом `defineEnvironment()` (уже применено в core/drive/documents `TestCase.php`, P1.3). Резолюция актора из голого id (`granted_by`) — через `Auth::createUserProvider($providerName)` с провайдером ИЗ дефолтного guard'а (P1.2 Completion Notes). `$guarded=['*']` throws `MassAssignmentException` на fill() (не silent-discard) — тесты на такие модели пишутся через `->toThrow()`, не через отсутствие ключа (P1.4 урок). Пивот-модели с `->using()` — тестировать mass-assignment через их выделенный `*StoreRepository`, не через голую Eloquent-relation `attach()`.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация уже выданных `signing_secret` (Q3, P0.2 Pending Work) — отдельный item по решению владельца. `CHANGELOG.md` записи за P1.2 и P1.4 — следующая сессия с доступом к файлу. Пред-существующие незакоммиченные диффы драйвера/чужого плана — как есть.
- open_questions: Q3 (`open-questions.md`) — ротация уже выданных `signing_secret`; к W1 отношения не имеет. Q1/Q2 — `Resolved → D1/D10`.
