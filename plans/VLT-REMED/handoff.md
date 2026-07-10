# HANDOFF — 2026-07-10 — after P1.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — локальный код по готовому ТЗ (Routing: остальные items P1–P4 → sonnet/medium дефолт) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить следующий item развёрнутой фазы **P1 (W1)** — `P1.2 (B8 — публичная share-поверхность)`. P1.1↔P1.2 делят шов раздачи (`DownloadController`/`PublicShareController::streamFromDisk`) — P1.2 удаляет ветку `temporaryUrl → RedirectResponse` из тех же методов, где P1.1 добавила `DownloadHeaders`; конфликта нет (разные ветки), но при ребейзе/мерже сверить оба изменения в одном методе. |

```
/task:plan-exec VLT-REMED P1.2
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`.
- P0.1 (B1 — stored XSS в TipTap) 🟢 — коммит `86a60a6`. P0.2 (B3 — SSRF/webhook) 🟢 — `296fb6d`/`a31462d`/`e49c890`. P0.3 (B5 — целостность загрузок/CAS) 🟢 — `69f589a`.
- **Фаза P1 (W1) развёрнута** (4 items с 16 полями, скелет-маркер снят, DoR закрыт). Несущие решения зафиксированы в `plan.md` §5: D14 (issuer-re-check привязан к `Ability::Share`), D15 (публичный node-id опакован `hash_hmac`), D16 (лента `shared` — keyset-если-возможно-иначе-документированный-offset).
- **P1.1 (B2 — заголовки скачивания: nosniff + whitelist Content-Type) 🟢 закрыт.** Закрыты F-P1.3-04, F-P1.11-03. Новый `final class DownloadHeaders` (`packages/core/src/Http/Support/DownloadHeaders.php`) — единственный шов сборки заголовков раздачи, применён в `DownloadController::streamFromDisk` (drive) и `PublicShareController::streamFromDisk` (core). Новый конфиг `vaulter.downloads.inline_mime_whitelist` + `Config::downloadInlineMimeWhitelist()`. 4 регрессионных теста (F-id в описании): 2 в `packages/drive/tests/Feature/DriveApiTest.php`, 2 новых в `packages/core/tests/Sharing/PublicShareDownloadHeadersTest.php`. Red-before подтверждён `git stash` прогоном на коде до фикса. Полные детали — `phases/P1.md` P1.1 Completion Notes.
  - Урок для P1.2/следующих items: `StorageFacade::fake('local')` по умолчанию МИНТИТ signed `temporaryUrl` (302-редирект) — «диск без `providesTemporaryUrls()`» на практике требует явного override `StorageDriver`-биндинга (см. `StreamingOnlyStorageDriver` в `DriveApiTest.php` / новый `NullTemporaryUrlStorageDriver` в `PublicShareDownloadHeadersTest.php`), а не голого `fake('local')`.
  - Урок про arch-границу: `packages/core` НЕ вправе импортировать/`@see`-ить `Vaulter\Drive\...` классы даже в докблоках — `ArchTest::core source never references the extracted vaulter-drive package` (P2 #5/D1) ловит это текстовым сканом файла, не только `use`-импортами.

**Remaining:** P1 (3 items — P1.2/P1.3/P1.4, `⬜ Not started`), P2 (3), P3 (6), P4 (10), P5 (3). Фазы P2–P5 — скелеты; развёртывание КАЖДОЙ — `/task:plan-design VLT-REMED Pn` перед её первым item'ом (Execution Rules: скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D16) ·
`plans/VLT-REMED/phases/P0.md` (закрыта) · `plans/VLT-REMED/phases/P1.md` (развёрнута — ТЗ 4 items, P1.1 закрыт) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.3-sharing-download,P1.8-secrets-config-http,P4.2-nplus1-recursion-pagination,P1.4-uploads}.md`
(доказательства находок W1 с `file:line`).

**Open risks:**
- P1 закрывает быстрые высоко-эксплуатируемые, но авторизация/изоляция (W2), надстройки (W3), качество/CI (W4) ещё не тронуты — пакет не «чист».
- **P1.2 ребейзит шов `streamFromDisk` обоих контроллеров**, который P1.1 только что тронула (добавила `DownloadHeaders` в headers-массив). P1.2 удаляет соседнюю ветку `temporaryUrl → RedirectResponse` в тех же методах — при исполнении свериться, что `DownloadHeaders::forDeclaredMime(...)` в headers-массиве streamFromDisk остаётся нетронутым.
- **P1.4 (B13) закрывается ТОЛЬКО при зелёном ПОЛНОМ сьюте всех 6 PHP-пакетов** (pre-mortem R9): `cd packages/<pkg> && composer test` для core/drive/media/documents/filament/permissions-azgard. НИКОГДА не добавлять служебную/security-колонку в `$fillable` ради зелёного (ре-открывает уязвимость) — чинить конкретный write на явный `fill([...])`.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. `packages/core` при этом не несёт СОБСТВЕННЫХ composer-скриптов (`analyse`/`test`/`test:types` не определены в его `composer.json`) — root-прогон и есть его гейт. Items, трогающие `drive` (P1.1 уже, вероятно P1.2/P1.3) — гнать их own `cd packages/drive && composer analyse && composer test && composer test:types` дополнительно (workaround с P0.1).
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым.
- В рабочем дереве присутствует НЕ моя правка — `plans/VLT-HSEAM/` (новый, не относящийся к VLT-REMED план) и `.claude/workflows/vlt-remed-drive.js` — появились независимо от исполнения P1.1 (конкурентная сессия/процесс), НЕ созданы и НЕ закоммичены этим item'ом; оставлены как есть.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/media/documents/filament — их own `composer analyse`/`test`/`test:types` (root покрывает только core, core сам без скриптов). PG-прогоны (если понадобятся downstream) — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (см. P0.3 handoff); для W1 PG не требуется. `StorageFacade::fake('local')` минтит signed URL по умолчанию — форсировать stream-путь через `StorageDriver`-декоратор, возвращающий `null` из `temporaryUrl()` (см. P1.1 Completion Notes).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация уже выданных `signing_secret` (Q3, P0.2 Pending Work) — отдельный item по решению владельца. Пред-существующие незакоммиченные диффы драйвера/чужого плана — как есть.
- open_questions: Q3 (`open-questions.md`) — ротация уже выданных `signing_secret`; к W1 отношения не имеет. Q1/Q2 — `Resolved → D1/D10`.
