# HANDOFF — 2026-07-11 — after P4.0

**Next:** /task:plan-exec VLT-REMED P4.1

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium + `perplexity-web`/`WebFetch` (RAG) — P4.1 (B24) первым в W4: гейты-страховка волны. CVE-статусы (`firebase/php-jwt` CVE-2025-45769, `happy-dom` GHSA-37j7-fg3j-429f) и «текущий мажор» (`@tiptap` 3.x, hocuspocus v3/v4) ПЕРЕВЕРЯЮТСЯ RAG на момент исполнения (§Routing/D12), не берутся из памяти |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P4.1 (B24)** строго по ТЗ `phases/P4.md`: включить mutation-/arch-/type-гейты + Rector CI + честная Laravel-матрица + политика версий + устранить CVE (firebase/php-jwt, happy-dom) + e2e-healthcheck + пиннинг образа. Мажоры `@tiptap`/hocuspocus 2.x→3.x/4.x — ПОД ЭСКАЛАЦИОННЫМ ГАРДОМ (D6): при разрастании за механический бамп → эскалация и вынос в отдельный план, F-P4.5-01/02 закрываются `⛔` с диспозицией, НЕ форсировать. |

```
/task:plan-exec VLT-REMED P4.1
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) ТЕРМИНАЛЬНА (6/6 🟢).**
- **Фаза P4 (W4) РАЗВЁРНУТА ИЗ СКЕЛЕТА (plan-design/opus, 2026-07-11):** 10 items детализированы
  (все 16 полей), скелет-маркер снят, DoR закрыт (5/5 ✅). 45 находок W4 (батчи B24, B15-B18, B20,
  B21, B19) + 2 структурных (P4.9 канон+ADR+arch-тесты, P4.10 namespace-refactor). Несущие решения:
  **D22** (направление разрешения семи «молчащих» швов P4.4 — код vs докблок по каждому),
  **D23** (канонический breaking-changes-файл = существующий `UPGRADE.md`; F-P2.1-06 — через конфиг
  split-пайплайна, а не наполнение заблокированного `CHANGELOG.md`). Порядок исполнения жёсткий:
  **P4.1 первым** (гейты страхуют волну и рефактор), **P4.9→P4.10 последними** (opus/high, D10),
  P4.2–P4.8 автономны.

**Remaining:** (порядок исполнения P4)
1. **P4.1** (B24, sonnet/medium+RAG) — supply-chain/CI/arch-mutation-гейты. **ПЕРВЫМ** (D2).
2. **P4.2** (B15) — LIKE-экранирование + cycle-detection на чтении (red-before, PG).
3. **P4.3** (B16) — индекс `trashed_at`, снятие дублей, `withMaxDepth` на предках, тай-брейкер курсора (PG).
4. **P4.4** (B17) — семь молчащих швов по D22 (5 код + 2 докблок).
5. **P4.5** (B18) — расширяемость `DriveNameRules`/lang-ключ/`filenameFor`.
6. **P4.6** (B20) — докблок фасада (12 методов), FQCN в доке, статкеш за инстанс.
7. **P4.7** (B21) — split-changelog-конфиг, deprecation-policy, `@vaulter/vue` peerDeps, сток `UPGRADE.md`.
8. **P4.8** (B19, sonnet/low) — docs-drift (9 находок, doc-guard-инварианты).
9. **P4.9** (opus/high, D10) — канон A: инвентарь + ADR-0017 + arch-тесты (БЕЗ переноса).
10. **P4.10** (opus/high, D10) — namespace-refactor к канону (gate: P4.9 закрыт; `namespace-refactor-safety`).

Затем **P5** (3 items, СКЕЛЕТ — требует `/task:plan-design VLT-REMED P5`, после P4). Скелет-фаза не
исполняется напрямую (`plan.md` §Execution Rules).

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board — P3
терминальна 6/6, P4 детализирована 0/10 / Decision Log D1–D23 / Update Log с записью P4-design) ·
`plans/VLT-REMED/phases/P4.md` (P4.1–P4.10 детализированы, DoR закрыт, Phase Context + Scope + оси) ·
`plans/VLT-REMED/phases/{P0,P1,P2,P3}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P4) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (N4 — TipTap-линия/CVE-2025-14284, вход P4.1) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P4.1-query-indexes,P4.2-nplus1-recursion-pagination,P4.3-test-strategy,`
`P4.4-static-ci-supplychain,P4.5-frontend-e2e-docker}.md` (детальные находки батчей W4 —
Required Reads items P4.1/P4.2/P4.3) ·
`plans/AUDIT-VLT/findings/{P3.1-naming-seam,P3.2-panel-seam,P3.3-handler-exporter-seam,`
`P3.5-driver-seams,P1.2-role-drivers}.md` (семь швов P4.4/P4.5) ·
`plans/AUDIT-VLT/findings/{P2.1-public-surface,P2.4-host-integration,P2.5-dx}.md` (API/release/docs
P4.6/P4.7/P4.8) · `plans/AUDIT-VLT/artifacts/repro/P4.4/{composer-audit-root,npm-audit-vue,`
`npm-audit-hocuspocus}.txt` (repro-логи supply-chain, вход P4.1).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P4-items, трогающие
  documents/drive/media/filament, обязаны гонять свои `composer analyse && composer test &&
  composer test:types` из директории пакета. P4.1/P4.9/P4.10 трогают ВСЕ пакеты — прогон по каждому.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой
  read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым
  (`vendor/bin/pint --test <файлы>`).
- **`CHANGELOG.md` заблокирован для Read/Write** (`deny: Read(**/CHANGELOG.md)`) — долг записей за
  P1.2/P1.4/P2.1/P2.2/P2.3/P3.1-P3.6 и далее. **F-P2.1-06 (P4.7) чинится КОНФИГОМ split-пайплайна**
  (D23), не наполнением файла; наполнение — Pending Work, сессия с доступом. `UPGRADE.md` (не
  `UPGRADING.md`, D23) — канонический сток breaking-changes.
- **P4.10 — межпакетный namespace-refactor (closed-enum escalation-триггер, opus/high, D10).** Самый
  вероятный провал (pre-mortem R-refactor) — забытый morph-алиас: полиморфные строки в БД держат старый
  FQCN, связи молча ломаются в проде при зелёных тестах на свежей БД. Обязателен morph-инвариант-тест на
  PG. Доказательство неизменности поведения — зелёный сьют БЕЗ правки тестов сверх `use`-строк.
- **@tiptap/hocuspocus мажоры (F-P4.5-01/02) в P4.1** — при разрастании за механический бамп: эскалация
  и вынос в отдельный план (D6), закрытие `⛔` с диспозицией, НЕ форсировать зелёное.
- Testbench-снаги (унаследованы из P1.3/P3.1): `cache.default => 'array'` в тестовом
  `defineEnvironment()`; feature-флаги маршрутов — в `defineEnvironment()` (до boot), не `beforeEach`;
  `Auth::logout()` перед in-process `$this->artisan(...)`. PG-прогон для FK/SERIALIZABLE/индексов/CTE —
  `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (127.0.0.1:5455, роль `vaulter`).
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-feature-log.last`,
  `.gitignore`, `.claude/workflows/vlt-remed-*.js`, `plans/ACTIVE.md`, `plans/AUDIT-VLT/*`,
  `plans/VLT-HSEAM/`) из конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а.
- Пред-существующий PG-only флейк `NodeCommentSchemaTest` (jsonb `anchor` key-order round-trip) — вне
  scope, ещё не заведён отдельной находкой. Модели `UploadSession`/`UploadPart`/`NodeComment`/
  `NodeDocumentVersion` не подключают `ResolvesVaulterConnection` (P3.6 Pending Work).

**Workarounds/Deferred/Open questions:**
- workarounds: см. Open risks (per-package гейты; pint-точечно; `cache.default=array`; feature-флаги в
  `defineEnvironment`; PG-контейнер `agelto_postgres`; когда файл несёт МНОГО call site — одна точка
  резолва + глобальный find/replace, P3.6-урок; `getConnectionName()` override через `enum_value()`).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — `/task:plan-close archive AUDIT-VLT`. Ротация
  `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца.
  `CHANGELOG.md`-наполнение — сессия с доступом. Подключение хуков панели `fillNode/presentNode/pipes`
  к пайплайну (F-P3.2-02: P4.4 только помечает `@unimplemented`, D22) — фича при «go» владельца.
  Мажоры `@tiptap`/hocuspocus при разрастании — отдельный план (D6).
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) — оба
  `Decision pending`, ни один P4-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
