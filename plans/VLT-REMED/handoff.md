# HANDOFF — 2026-07-10 — after P1.2

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — локальный код по готовому ТЗ (Routing: остальные items P1–P4 → sonnet/medium дефолт) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить следующий item развёрнутой фазы **P1 (W1)** — `P1.3 (B14 — rate-limit и DoS-поверхность листингов)`. Независим от P1.1/P1.2 (свои маршруты/N+1/лента `shared`), общих швов не делит. |

```
/task:plan-exec VLT-REMED P1.3
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`.
- P0.1 (B1 — stored XSS в TipTap) 🟢 — коммит `86a60a6`. P0.2 (B3 — SSRF/webhook) 🟢 — `296fb6d`/`a31462d`/`e49c890`. P0.3 (B5 — целостность загрузок/CAS) 🟢 — `69f589a`.
- **Фаза P1 (W1) развёрнута** (4 items с 16 полями, скелет-маркер снят, DoR закрыт). Несущие решения зафиксированы в `plan.md` §5: D14 (issuer-re-check привязан к `Ability::Share`), D15 (публичный node-id опакован `hash_hmac`), D16 (лента `shared` — keyset-если-возможно-иначе-документированный-offset).
- **P1.1 (B2 — заголовки скачивания: nosniff + whitelist Content-Type) 🟢 закрыт.** Закрыты F-P1.3-04, F-P1.11-03. Новый `final class DownloadHeaders` (`packages/core/src/Http/Support/DownloadHeaders.php`) — единственный шов сборки заголовков раздачи, применён в `DownloadController::streamFromDisk` (drive) и `PublicShareController::streamFromDisk` (core). Новый конфиг `vaulter.downloads.inline_mime_whitelist` + `Config::downloadInlineMimeWhitelist()`. Детали — `phases/P1.md` P1.1 Completion Notes.
- **P1.2 (B8 — публичная share-поверхность) 🟢 закрыт.** Коммит `d855df7`. Закрыты F-P1.3-01, F-P1.3-02, F-P1.3-03, F-P1.3-05:
  - **F-P1.3-01:** ветка `temporaryUrl → RedirectResponse` удалена из `DownloadController::stream()` (drive) и `PublicShareController::stream()` (core) — раздача байтов ВСЕГДА через PHP-stream, даже на S3-совместимом диске (D15). Возвращаемые типы сужены до `StreamedResponse`.
  - **F-P1.3-02:** `ShareResolver` перепроверяет на каждом потреблении, что издатель ссылки (`granted_by`) всё ещё держит `Ability::Share` на узле — через `AccessResolver` (не грепом по грантам). Издатель резолвится auth user provider'ом ДЕФОЛТНОГО guard'а хоста (`Auth::createUserProvider($providerName)` с провайдером из `auth.guards.<default>.provider` — важная ловушка: `Auth::createUserProvider()` БЕЗ аргумента читает несуществующий `auth.defaults.provider` и всегда возвращает null, см. Known Deviations phases/P1.md). `granted_by === null` (легаси-строка/системный минт) пропускает проверку — НЕ 404.
  - **F-P1.3-03:** `config('vaulter.share.default_ttl_hours')` (72) / `max_ttl_hours` (720) + аксессоры в `Config`; `CreateShareLinkAction` подставляет дефолт при `expiresAt === null`; `StoreShareLinkRequest` отклоняет `expires_at` выше потолка.
  - **F-P1.3-05:** `SharedNodeResource` отдаёт опакованный `hash_hmac`-проекцию `id`/`parent_id` вместо `Node::getKey()`/`parent_id` — на `id_type=bigint` больше не течёт последовательный числовой id анонимному держателю ссылки.
  - Полные детали, включая ловушку `Auth::createUserProvider()` и реализационное уточнение null-`granted_by` — `phases/P1.md` P1.2 Completion Notes / Known Deviations.
  - **Урок для следующих items:** `AccessTestHelpers::user()` (нефиксируемый TestUser-стенд с `exists=true` без реальной строки) НЕПРИГОДЕН там, где нужен `retrieveById()` (auth user provider) — для этого в `ShareResolverTest.php` заведён `persistedIssuer()`, создающий реальную БД-таблицу под `TestUser::getTable()` (не `'users'` — имя таблицы модели, `test_users`) и реальную строку.

**Remaining:** P1 (2 item'а — P1.3/P1.4, `⬜ Not started`), P2 (3), P3 (6), P4 (10), P5 (3). Фазы P2–P5 — скелеты; развёртывание КАЖДОЙ — `/task:plan-design VLT-REMED Pn` перед её первым item'ом (Execution Rules: скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D16) ·
`plans/VLT-REMED/phases/P0.md` (закрыта) · `plans/VLT-REMED/phases/P1.md` (развёрнута — ТЗ 4 items, P1.1/P1.2 закрыты) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.3-sharing-download,P1.8-secrets-config-http,P4.2-nplus1-recursion-pagination,P1.4-uploads}.md`
(доказательства находок W1 с `file:line`).

**Open risks:**
- P1 закрывает быстрые высоко-эксплуатируемые, но авторизация/изоляция (W2), надстройки (W3), качество/CI (W4) ещё не тронуты — пакет не «чист».
- **P1.3 (B14) независим** от P1.1/P1.2 (свои маршруты throttle, N+1 в `AccessResolver::storageOf`, лента `shared`) — общих швов с уже закрытыми items не делит, но трогает `AccessResolver`/`StorageReadRepository`, которые P2.1 (W2, ядро ACL) тоже будет менять — при исполнении P1.3 держать диффы узкими (памоизация, не переписывание алгоритма H.2), чтобы не расширять поверхность конфликта с ещё не спроектированной P2.1.
- **P1.4 (B13) закрывается ТОЛЬКО при зелёном ПОЛНОМ сьюте всех 6 PHP-пакетов** (pre-mortem R9): `cd packages/<pkg> && composer test` для core/drive/media/documents/filament/permissions-azgard. НИКОГДА не добавлять служебную/security-колонку в `$fillable` ради зелёного (ре-открывает уязвимость) — чинить конкретный write на явный `fill([...])`.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. `packages/core` при этом не несёт СОБСТВЕННЫХ composer-скриптов (`analyse`/`test`/`test:types` не определены в его `composer.json`) — root-прогон и есть его гейт. Items, трогающие `drive` (P1.1, P1.2 — оба гоняли `cd packages/drive && composer analyse && composer test && composer test:types` дополнительно) — гнать этот же own-прогон и дальше, пока item трогает drive.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым.
- **`CHANGELOG.md` заблокирован для Read/Bash-инспекции** этой сессией (`.claude/settings.local.json` → `deny: ["Read(**/CHANGELOG.md)"]`) — P1.2 не смог дописать туда breaking-changes запись (риск сломать формат вслепую), обновил только `UPGRADE.md` полностью. Следующая сессия с доступом к чтению `CHANGELOG.md` должна догнать пропущенные записи (минимум P1.2, возможно и более ранние — не проверялось).
- В рабочем дереве присутствует НЕ моя правка — `plans/VLT-HSEAM/` (новый, не относящийся к VLT-REMED план), `.claude/workflows/vlt-remed-drive.js` и модификация `.gitignore`/`.claude/.maind-feature-log.last` — появились независимо от исполнения P1.1/P1.2 (конкурентная сессия/процесс/хук), НЕ созданы и НЕ закоммичены этими items, оставлены как есть.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/media/documents/filament — их own `composer analyse`/`test`/`test:types` (root покрывает только core, core сам без скриптов). PG-прогоны (если понадобятся downstream) — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (см. P0.3 handoff); для W1 PG не требуется. `StorageFacade::fake('local')` минтит signed URL по умолчанию — P1.2 сделало это НЕ актуальным для download-путей (presign-ветка удалена целиком), но остальные швы (upload-presign) это всё ещё касается. Резолюция актора из голого id (`granted_by`) — через `Auth::createUserProvider($providerName)` с провайдером ИЗ дефолтного guard'а (`auth.guards.<default>.provider`), НЕ через `Auth::createUserProvider()` без аргумента (читает несуществующий `auth.defaults.provider`, всегда null) — см. `phases/P1.md` P1.2 Completion Notes.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация уже выданных `signing_secret` (Q3, P0.2 Pending Work) — отдельный item по решению владельца. `CHANGELOG.md` запись за P1.2 (и возможный аудит более ранних items) — следующая сессия с доступом к файлу. Пред-существующие незакоммиченные диффы драйвера/чужого плана — как есть.
- open_questions: Q3 (`open-questions.md`) — ротация уже выданных `signing_secret`; к W1 отношения не имеет. Q1/Q2 — `Resolved → D1/D10`.
