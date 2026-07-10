# HANDOFF — 2026-07-10 — after P0.2

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — гонка tus без наблюдаемого симптома, конкурентность на PG |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Закрыть последний Blocker P0.3 (F-P1.4-01: гонка offset в конкурентном tus-PATCH → тихая порча файла) + 5 находок целостности загрузок/CAS: сверка total-bytes в S3-complete, потоковый append + пер-чанк-лимит, magic-bytes при commit, temp+rename промоушен блоба, SERIALIZABLE+retry в sweepPending |

```
/task:plan-exec VLT-REMED P0.3
```

**Done:**
- P0.1 (B1 — stored XSS в TipTap) 🟢 — двухэшелонная санитизация (F-P1.11-01/02/04/05), коммит `86a60a6`.
- P0.2 (B3 — SSRF/webhook) 🟢 — коммит `296fb6d`. SSRF-guard `OutboundUrlGuard` (контракт + `DefaultOutboundUrlGuard`,
  host-swappable) на пути ДОСТАВКИ: резолв A+AAAA через инъектируемый `HostResolver`, отказ на любом адресе в
  закрытом наборе диапазонов (loopback/RFC-1918/link-local вкл. 169.254.169.254/IPv4-mapped), пиннинг IP через
  `CURLOPT_RESOLVE`, редиректы off, транспорт http(s); тот же guard — ранний 422 при регистрации. `signing_secret`
  → каст `encrypted` + колонка `text` + идемпотентная миграция (`SigningSecretEncryptor`). Outbox: с 4 kernel-событий
  снят `ShouldDispatchAfterCommit`, `event()` внутри `DB::transaction` доменных действий → строка outbox в одном
  коммите с мутацией (подписчики глотают сбои — mutation не откатывается). Единый `WebhookRetryDispatcher`
  (lockForUpdate + сдвиг next_retry_at, visibility-timeout) вместо двух независимых re-dispatch. Лимит тела ответа
  (`ResponseSizeLimit` + connect_timeout + maxmemory-sink). Закрыты F-P1.7-01 (Blocker), F-P1.7-02/03/05/06.
  19 регрессионных тестов, red-before подтверждён на каждый F-id. Детали — `phases/P0.md` P0.2 Completion Notes.

**Remaining:** P0.3 (B5 — resumable-uploads/CAS race, последний Blocker P0) → фаза P0 терминальна → развернуть и
исполнить P1 (4), P2 (3), P3 (6), P4 (10), P5 (3). Развёртывание каждой фазы P1–P5 — `/task:plan-design VLT-REMED Pn`
перед первым её item'ом (это скелеты).

**Sources of truth:** `plans/VLT-REMED/plan.md` · `plans/VLT-REMED/phases/P0.md` ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (N3 — tus-гонка) · `plans/AUDIT-VLT/findings/REGISTER.md` (SSOT
103 находок, append-only, VLT-REMED в него не пишет) · `plans/AUDIT-VLT/findings/P1.4-uploads.md` ·
`plans/AUDIT-VLT/findings/P1.5-cas-blobs.md` · `plans/AUDIT-VLT/findings/P0-upload-hardening.md` (U1–U7).

**Open risks:**
- Один Blocker ещё открыт: F-P1.4-01 (гонка offset → порча файла, P0.3). До закрытия всей фазы P0 релиз пакета
  невозможен (D7 плана AUDIT-VLT).
- P0.3 — «зелёное, но не починенное»: гонка НЕ проверяется последовательными вызовами. Тест конкурентного PATCH
  обязан гонять реальную параллельность на PostgreSQL (pre-mortem R4); SQLite сериализует запись и покажет зелёное
  на дефектном коде. ВНИМАНИЕ (перенято из P0.2): PHP CLI этого окружения БЕЗ `pdo_pgsql` (`php -m` → только
  pdo_mysql/pdo_sqlite) и без роли PG — для P0.3 PG-прогон конкурентного теста ОБЯЗАТЕЛЕН (D12), окружение нужно
  дооснастить (pdo_pgsql + тест-БД `vaulter_test`), иначе Blocker'а не доказать. Это блокирует закрытие P0.3.
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P0.3 трогает `packages/drive` и
  `packages/media` — гони их собственные `cd packages/<pkg> && composer analyse && composer test && composer test:types`
  дополнительно к root-гейту (workaround с P0.1, root-гейт их не покрывает).
- Root `composer lint:check` красный из-за пред-существующего форматирования в `plans/AUDIT-VLT/artifacts/repro/**`
  (чужой read-only план, вне scope VLT-REMED) — известно с P0.1, не блокирует closure (свой новый код обязан быть
  pint-чистым).

**Workarounds/Deferred/Open questions:**
- workarounds: (P0.3) root phpstan/phpunit — только core; для drive/media гони их own `composer analyse`/`test`/
  `test:types`. PG-прогон конкурентных сценариев требует pdo_pgsql в CLI (в текущем окружении отсутствует — см. Open risks).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный запуск `/task:plan-close archive AUDIT-VLT`. Ротация уже
  выданных `signing_secret` (Q3) — отдельный item по решению владельца (P0.2 Pending Work), не блокирует.
- open_questions: Q3 (`open-questions.md`) — нужна ли ротация уже выданных `signing_secret`; решение владельца.
  Q1/Q2 — `Resolved → D1/D10`.
