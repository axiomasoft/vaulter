# HANDOFF — 2026-07-10 — after P1.0

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — локальный код по готовому ТЗ (Routing: остальные items P1–P4 → sonnet/medium дефолт) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить первый item развёрнутой фазы **P1 (W1)** — `P1.1 (B2 — заголовки скачивания: nosniff + whitelist Content-Type)`. Фаза P1 РАЗВЁРНУТА (все 4 items детализированы, DoR закрыт), можно `plan-exec` напрямую. Далее по порядку P1.2, P1.3, P1.4 (порядок внутри фазы произволен, но P1.1↔P1.2 делят шов раздачи — см. их Implementation Rules). |

```
/task:plan-exec VLT-REMED P1.1
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).** По D2 допустим промежуточный мерж/релиз ветки `remediation/audit-vlt`.
- P0.1 (B1 — stored XSS в TipTap) 🟢 — коммит `86a60a6`. P0.2 (B3 — SSRF/webhook) 🟢 — `296fb6d`/`a31462d`/`e49c890`. P0.3 (B5 — целостность загрузок/CAS) 🟢 — `69f589a`.
- **Фаза P1 (W1) РАЗВЁРНУТА** (`/task:plan-design VLT-REMED P1`): 4 items с 16 полями, скелет-маркер снят, DoR закрыт. Несущие решения зафиксированы в plan.md §5: D14 (issuer-re-check привязан к `Ability::Share`), D15 (публичный node-id опакован `hash_hmac` — безопасно, т.к. клиент не присылает node-id), D16 (лента `shared` — keyset-если-возможно-иначе-документированный-offset). Свежей внешней RAG не потребовалось (нормы W1 унаследованы датированными выжимками AUDIT-VLT).

**Remaining:** P1 (4 items — детализированы, `⬜ Not started`), P2 (3), P3 (6), P4 (10), P5 (3). Фазы P2–P5 — скелеты; развёртывание КАЖДОЙ — `/task:plan-design VLT-REMED Pn` перед её первым item'ом (Execution Rules: скелет-фаза не исполняется напрямую).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board/Decision Log D1–D16) ·
`plans/VLT-REMED/phases/P0.md` (закрыта) · `plans/VLT-REMED/phases/P1.md` (развёрнута — ТЗ 4 items) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (нормы W0, неизменны) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P1.3-sharing-download,P1.8-secrets-config-http,P4.2-nplus1-recursion-pagination,P1.4-uploads}.md`
(доказательства находок W1 с `file:line`).

**Open risks:**
- P1 закрывает быстрые высоко-эксплуатируемые, но авторизация/изоляция (W2), надстройки (W3), качество/CI (W4) ещё не тронуты — пакет не «чист».
- **P1.1↔P1.2 делят шов** `DownloadController::stream`/`PublicShareController::stream`: P1.1 добавляет helper заголовков на PHP-stream-путь, P1.2 удаляет presign-редирект. Оба order-независимы, но второй ребейзит. P1.1-тесты гонять на диске БЕЗ `providesTemporaryUrls()` (иначе S3-редирект минует helper — это предмет P1.2, не регресс P1.1).
- **P1.4 (B13) закрывается ТОЛЬКО при зелёном ПОЛНОМ сьюте всех 6 PHP-пакетов** (pre-mortem R9): `cd packages/<pkg> && composer test` для core/drive/media/documents/filament/permissions-azgard. НИКОГДА не добавлять служебную/security-колонку в `$fillable` ради зелёного (ре-открывает уязвимость) — чинить конкретный write на явный `fill([...])`.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. Items P1.1/P1.2/P1.3 трогают drive → гнать их own `cd packages/drive && composer analyse && composer test && composer test:types` дополнительно (workaround с P0.1).
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым.

**Workarounds/Deferred/Open questions:**
- workarounds: drive/media/documents/filament — их own `composer analyse`/`test`/`test:types` (root покрывает только core). PG-прогоны (если понадобятся downstream) — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (см. P0.3 handoff); для W1 PG не требуется (гонок нет, N+1-счётчик достаточно на SQLite).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный `/task:plan-close archive AUDIT-VLT`. Ротация уже выданных `signing_secret` (Q3, P0.2 Pending Work) — отдельный item по решению владельца. Пред-существующие незакоммиченные диффы драйвера/чужого плана — как есть.
- open_questions: Q3 (`open-questions.md`) — ротация уже выданных `signing_secret`; к W1 отношения не имеет. Q1/Q2 — `Resolved → D1/D10`.
