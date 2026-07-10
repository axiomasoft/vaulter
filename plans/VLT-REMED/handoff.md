# HANDOFF — 2026-07-10 — after P1.3

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — локальный код по готовому ТЗ (Routing: остальные items P1–P4 → sonnet/medium дефолт) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить последний item развёрнутой фазы **P1 (W1)** — `P1.4 (B13 — mass-assignment-харденинг всех 16 моделей)`. ЗАКРЫВАЕТ фазу P1 (4/4) — после его закрытия синхронизировать Phase Handoff в phases/P1.md и Phase Index & Status Board в plan.md. |

```
/task:plan-exec VLT-REMED P1.4
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`.
- P0.1 (B1 — stored XSS в TipTap) 🟢 — коммит `86a60a6`. P0.2 (B3 — SSRF/webhook) 🟢 — `296fb6d`/`a31462d`/`e49c890`. P0.3 (B5 — целостность загрузок/CAS) 🟢 — `69f589a`.
- **Фаза P1 (W1) развёрнута** (4 items с 16 полями, скелет-маркер снят, DoR закрыт). Несущие решения зафиксированы в `plan.md` §5: D14 (issuer-re-check привязан к `Ability::Share`), D15 (публичный node-id опакован `hash_hmac`), D16 (лента `shared` — keyset-если-возможно-иначе-документированный-offset).
- **P1.1 (B2 — заголовки скачивания: nosniff + whitelist Content-Type) 🟢 закрыт.** Закрыты F-P1.3-04, F-P1.11-03. Новый `final class DownloadHeaders` (`packages/core/src/Http/Support/DownloadHeaders.php`) — единственный шов сборки заголовков раздачи, применён в `DownloadController::streamFromDisk` (drive) и `PublicShareController::streamFromDisk` (core). Новый конфиг `vaulter.downloads.inline_mime_whitelist` + `Config::downloadInlineMimeWhitelist()`. Детали — `phases/P1.md` P1.1 Completion Notes.
- **P1.2 (B8 — публичная share-поверхность) 🟢 закрыт.** Коммит `d855df7`. Закрыты F-P1.3-01, F-P1.3-02, F-P1.3-03, F-P1.3-05:
  - **F-P1.3-01:** ветка `temporaryUrl → RedirectResponse` удалена из `DownloadController::stream()` (drive) и `PublicShareController::stream()` (core) — раздача байтов ВСЕГДА через PHP-stream, даже на S3-совместимом диске (D15).
  - **F-P1.3-02:** `ShareResolver` перепроверяет на каждом потреблении, что издатель ссылки (`granted_by`) всё ещё держит `Ability::Share` на узле — через `AccessResolver`. Издатель резолвится auth user provider'ом ДЕФОЛТНОГО guard'а хоста (`Auth::createUserProvider($providerName)` с провайдером из `auth.guards.<default>.provider` — важная ловушка: `Auth::createUserProvider()` БЕЗ аргумента читает несуществующий `auth.defaults.provider` и всегда возвращает null, см. Known Deviations phases/P1.md). `granted_by === null` пропускает проверку — НЕ 404.
  - **F-P1.3-03:** `config('vaulter.share.default_ttl_hours')` (72) / `max_ttl_hours` (720) + аксессоры в `Config`; `CreateShareLinkAction` подставляет дефолт при `expiresAt === null`; `StoreShareLinkRequest` отклоняет `expires_at` выше потолка.
  - **F-P1.3-05:** `SharedNodeResource` отдаёт опакованный `hash_hmac`-проекцию `id`/`parent_id` вместо `Node::getKey()`/`parent_id`.
  - **Урок:** `AccessTestHelpers::user()` НЕПРИГОДЕН там, где нужен `retrieveById()` (auth user provider) — заведён `persistedIssuer()`.
- **P1.3 (B14 — rate-limit и DoS-поверхность листингов) 🟢 закрыт.** Коммиты `0a1644d` (код) / `fbeac3b` (docs). Закрыты F-P1.8-01, F-P1.4-09, F-P4.2-03, F-P4.2-04:
  - **F-P1.8-01:** два именованных лимитера пакета — `vaulter-public` (по IP) и `vaulter-uploads` (по актору, IP-фолбэк) — регистрируются ИДЕМПОТЕНТНО в `VaulterServiceProvider::registerRateLimiters()` (`packageBooted()`): `if (RateLimiter::limiter($name) === null) { RateLimiter::for(...) }` — хост, определивший лимитер под тем же именем, всегда побеждает. Лимиты — `config('vaulter.rate_limits.{public,uploads}.per_minute')` (дефолты 60/120), аксессоры `Config::publicRateLimitPerMinute()`/`uploadsRateLimitPerMinute()`. `throttle:vaulter-public` — на `share/{token}*` (core) и `collab/authenticate`/`collab/store` (documents), ПЕРЕД `ResolveShareToken`/`ResolveCollaborationCall`. `throttle:vaulter-uploads` — на всю resumable-группу (tus + S3-multipart) в drive.
  - **F-P1.4-09:** `Config::maxActiveUploadSessions()` (дефолт 20, `vaulter.uploads.max_active_sessions`, 0=выкл); `UploadSessionReadRepository::countActiveForActor()`; `UploadSessionManager::initiate()` бросает `TooManyRequestsHttpException` (429) при превышении — ДО стейджинга байт.
  - **F-P4.2-03:** `StorageReadRepository::find()` мемоизирует по id (identity-map), забинжен `scoped()`-синглтоном (НЕ `singleton()` — сбрасывается между запросами/джобами на Octane/queue). `AccessResolver`/`ancestorChain()` НЕ тронуты (узкий дифф — их трогает будущая P2.1).
  - **F-P4.2-04:** решение D16 (fallback) — `NodeController::shared()` остаётся offset-пагинацией (её `filterSharedWith()` не может честно бэкать курсор); фикс чисто документационный — новая секция «Shared with me» в `docs/reference/rest-api.md`.
  - **Урок для следующих items:** Testbench-дефолтный `cache.default=database` не имеет миграции таблицы `cache` — ЛЮБОЙ throttle-middleware 500-ит в тестах без `cache.default => 'array'` в `defineEnvironment()`; уже пофикшено в core/drive/documents `TestCase.php`. Кросс-пакетный `vendor/bin/pest packages/core/tests packages/drive/tests --filter=...` из корня НЕСТАБИЛЕН («Target class [filesystem] does not exist» — воспроизводится и на пред-существующих тестах, не связано с P1.3) — гонять целевые фильтры РАЗДЕЛЬНО по пакетам (`cd packages/<pkg> && vendor/bin/pest --filter=...`), не смешивая пути из разных пакетов в одном вызове `vendor/bin/pest` из корня.

**Remaining:** P1 (1 item — P1.4, `⬜ Not started`, ПОСЛЕДНИЙ в фазе), P2 (3), P3 (6), P4 (10), P5 (3). Фазы P2–P5 — скелеты; развёртывание КАЖДОЙ — `/task:plan-design VLT-REMED Pn` перед её первым item'ом (Execution Rules: скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D16) ·
`plans/VLT-REMED/phases/P0.md` (закрыта) · `plans/VLT-REMED/phases/P1.md` (развёрнута — ТЗ 4 items, P1.1/P1.2/P1.3 закрыты, P1.4 остаётся) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.3-sharing-download,P1.8-secrets-config-http,P4.2-nplus1-recursion-pagination,P1.4-uploads,P1.5-cas-blobs,P1.7-outbound-webhooks}.md`
(доказательства находок W1 с `file:line`).

**Open risks:**
- P1 закрывает быстрые высоко-эксплуатируемые, но авторизация/изоляция (W2), надстройки (W3), качество/CI (W4) ещё не тронуты — пакет не «чист».
- **P1.4 (B13) — ПОСЛЕДНИЙ item фазы P1, закрывается ТОЛЬКО при зелёном ПОЛНОМ сьюте всех 6 PHP-пакетов** (pre-mortem R9): `cd packages/<pkg> && composer test` для core/drive/media/documents/filament/permissions-azgard. НИКОГДА не добавлять служебную/security-колонку в `$fillable` ради зелёного (ре-открывает уязвимость) — чинить конкретный write на явный `fill([...])`. После закрытия P1.4 фаза P1 становится терминальной (4/4) — заполнить Phase Handoff в `phases/P1.md` и синхронизировать `## 4. Phase Index & Status Board` в `plan.md` (статус фазы → 🟢 Done), затем следующий шаг — `/task:plan-design VLT-REMED P2` (скелет-фаза).
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. `packages/core` при этом не несёт СОБСТВЕННЫХ composer-скриптов — root-прогон и есть его гейт. Items, трогающие `drive`/`documents` — гнать их own-прогон дополнительно, пока item их трогает (P1.3 гоняло оба).
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым.
- **`CHANGELOG.md` заблокирован для Read/Bash-инспекции** этой сессией (`.claude/settings.local.json` → `deny: ["Read(**/CHANGELOG.md)"]`) — P1.2 не смог дописать туда breaking-changes запись, обновил только `UPGRADE.md` полностью. P1.3 не вносил breaking changes (все новые config-ключи с обратно-совместимыми дефолтами), CHANGELOG-долг P1.2 остаётся непогашенным. Следующая сессия с доступом к чтению `CHANGELOG.md` должна догнать пропущенные записи.
- В рабочем дереве присутствует НЕ моя правка — `plans/VLT-HSEAM/` (новый, не относящийся к VLT-REMED план), `.claude/workflows/vlt-remed-drive.js` и модификация `.gitignore`/`.claude/.maind-feature-log.last` — появились независимо от исполнения P1.1/P1.2/P1.3 (конкурентная сессия/процесс/хук), НЕ созданы и НЕ закоммичены этими items, оставлены как есть.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/media/documents/filament — их own `composer analyse`/`test`/`test:types` (root покрывает только core, core сам без скриптов). PG-прогоны (если понадобятся downstream) — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (см. P0.3 handoff); для W1 PG не требовался. Testbench-дефолтный `cache.default=database` не имеет миграции таблицы `cache` — throttle-middleware требует `cache.default => 'array'` в тестовом `defineEnvironment()` (уже применено в core/drive/documents `TestCase.php`, P1.3). Кросс-пакетный `vendor/bin/pest <pkgA>/tests <pkgB>/tests --filter=...` из корня нестабилен («Target class [filesystem] does not exist» на некоторых тестах, воспроизводится и на пред-существующих) — гонять целевые фильтры раздельно по пакету. Резолюция актора из голого id (`granted_by`) — через `Auth::createUserProvider($providerName)` с провайдером ИЗ дефолтного guard'а, НЕ через `Auth::createUserProvider()` без аргумента — см. `phases/P1.md` P1.2 Completion Notes.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация уже выданных `signing_secret` (Q3, P0.2 Pending Work) — отдельный item по решению владельца. `CHANGELOG.md` запись за P1.2 (и возможный аудит более ранних items) — следующая сессия с доступом к файлу. Пред-существующие незакоммиченные диффы драйвера/чужого плана — как есть.
- open_questions: Q3 (`open-questions.md`) — ротация уже выданных `signing_secret`; к W1 отношения не имеет. Q1/Q2 — `Resolved → D1/D10`.
