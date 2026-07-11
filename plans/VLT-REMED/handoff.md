# HANDOFF — 2026-07-11 — after P4.2

**Next:** /task:plan-exec VLT-REMED P4.3

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium (дефолт §Routing — локальный код по готовому ТЗ, корень известен) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P4.3 (B16)** строго по ТЗ `phases/P4.md`: новая миграция индекс `(trashed_at)` на `v_nodes` + снятие избыточных/дубль-индексов (`v_nodes`, `v_blobs`, `v_activity`) (F-P4.1-01/02); `withMaxDepth` на обход ПРЕДКОВ в `AccessResolver::ancestorChain()`/`NodeReadRepository::ancestors()`/`breadcrumb()` (F-P4.2-01); уникальный тай-брейкер `id` в трёх курсор-фидах `search`/`trashed`/`forNode` (F-P4.2-05). Red-before на PG (`agelto_postgres`), F-id в описании тестов. |

```
/task:plan-exec VLT-REMED P4.3
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) ТЕРМИНАЛЬНА (6/6 🟢).**
- **Фаза P4 (W4) В РАБОТЕ (1/10 🟢, 1/10 🔴 Blocked-формально):**
  - **P4.1 (B24 — Supply-chain, CI-матрица, arch- и mutation-гейты) — 🔴 Blocked**
    (коммит `74294e0`), 13 из 14 находок B24 закрыты штатно, заблокирована только
    F-P4.5-02 (hocuspocus-мажор, эскалирован по D6-гарду, `⛔ Skipped by decision`,
    реверчен на `^2.15`/`^2.13`). Полные детали — `phases/P4.md` P4.1 Completion Notes
    + ESCALATION-блок.
  - **P4.2 (B15 — Безопасность и корректность запросов) — 🟢 Done** (коммит `cd31116`):
    (1) `NodeReadRepository::search()` экранирует `%`/`_`/`\` в пользовательском терме
    (`addcslashes` + `LIKE ... ESCAPE '\'`) — раньше `%`/`_` работали как wildcard'ы
    (F-P1.8-03); (2) `Node::enableCycleDetection(): true` — обход предков/потомков
    останавливается на повторной строке цикла вместо раздутой/бесконечной рекурсии
    (F-P4.2-02). Red-before подтверждён вручную (`git stash` фикса → прогон → `git
    stash pop`): LIKE-тест падал на assertion (2 из 4), cycle-тест валил процесс
    OOM-крашем (`memory_limit=512M`, `strace` подтвердил рост до предела и
    `exit_group(1)`, НЕ бесконечный hang) на форсированном 2-узловом цикле
    `parent_id` (raw `DB::table('v_nodes')->update()`, в обход `MoveNodeAction`).
    Тесты — `packages/core/tests/Schema/{NodeSearchLikeEscapingTest,
    NodeCycleDetectionTest}.php` (в `Schema/`, не `Drive/` — `tests/Pest.php` не
    биндит `Drive/` к `TestCase`, только `DriveTestHelpers`, drive-suite переехал в
    пакет `vaulter-drive`). Прогнано на SQLite (мгновенно) И PostgreSQL
    (`agelto_postgres`, `php8.4`, 675мс) — оба зелёные. `UPGRADE.md` — новая секция
    (behaviour-change, не breaking API). Known Deviation: трейт-guard staudenmeir —
    bounded silent termination (`not(is_cycle)`), не throw, как буквально
    сформулировано в Code Guidance «управляемое исключение» — цель находки (не
    допустить раздутую рекурсию) достигнута тем же механизмом; расхождение
    формулировки, не поведения. Полные Completion Notes — `phases/P4.md` P4.2.

**Remaining:** (порядок исполнения P4)
1. ~~**P4.1**~~ — исполнен, 🔴 Blocked ТОЛЬКО формально (plan-lint ESCALATION-инвариант).
2. ~~**P4.2**~~ — исполнен, 🟢 Done.
3. **P4.3** (B16) — индекс `trashed_at`, снятие дублей, `withMaxDepth` на предках, тай-брейкер курсора (PG). **← следующий**.
4. **P4.4** (B17) — семь молчащих швов по D22 (5 код + 2 докблок).
5. **P4.5** (B18) — расширяемость `DriveNameRules`/lang-ключ/`filenameFor`.
6. **P4.6** (B20) — докблок фасада (12 методов), FQCN в доке, статкеш за инстанс.
7. **P4.7** (B21) — split-changelog-конфиг, deprecation-policy, `@vaulter/vue` peerDeps, сток `UPGRADE.md`.
8. **P4.8** (B19, sonnet/low) — docs-drift (9 находок, doc-guard-инварианты).
9. **P4.9** (opus/high, D10) — канон A: инвентарь + ADR-0017 + arch-тесты (БЕЗ переноса).
10. **P4.10** (opus/high, D10) — namespace-refactor к канону (gate: P4.9 закрыт; `namespace-refactor-safety`).

Затем **P5** (3 items, СКЕЛЕТ — требует `/task:plan-design VLT-REMED P5`, после P4). Скелет-фаза не
исполняется напрямую (`plan.md` §Execution Rules).

**Открытый пункт (не блокирует P4.3, требует внимания владельца/будущей сессии):**
**F-P4.5-02** (hocuspocus `2.x→4.x`) остаётся на `⛔ Skipped by decision` — см. ESCALATION-блок
`phases/P4.md` P4.1 и Q-заводку ниже. `/task:plan-design VLT-REMED` (новый отдельный план на
hocuspocus-мажор) — по решению владельца, вне текущего remaining-списка P4.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board — P3
терминальна 6/6, P4 в работе 1/10🟢+1/10🔴 / Decision Log D1–D23 / Update Log с записью P4.2) ·
`plans/VLT-REMED/phases/P4.md` (P4.1 закрыт с ESCALATION-блоком; P4.2 закрыт 🟢; P4.3–P4.10
детализированы, готовы к исполнению) · `plans/VLT-REMED/phases/{P0,P1,P2,P3}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P4) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P4.1-query-indexes,P4.2-nplus1-recursion-pagination,P4.3-test-strategy,`
`P4.4-static-ci-supplychain,P4.5-frontend-e2e-docker}.md` (детальные находки батчей W4 — Required
Reads P4.3 — F-P4.1-01/02, F-P4.2-01/05) · `plans/AUDIT-VLT/findings/{P3.1-naming-seam,`
`P3.2-panel-seam,P3.3-handler-exporter-seam,P3.5-driver-seams,P1.2-role-drivers}.md` (семь швов
P4.4/P4.5) · `plans/AUDIT-VLT/findings/{P2.1-public-surface,P2.4-host-integration,P2.5-dx}.md`
(API/release/docs P4.6/P4.7/P4.8) · `UPGRADE.md` (4 записи P4.1 + 1 новая запись P4.2 — LIKE-escape
+ cycle-detection behaviour-change) · `docs/adr/0005-unified-node-tree.md` (норма `withMaxDepth` —
Required Read P4.3).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P4-items, трогающие
  documents/drive/media/filament, обязаны гонять свои `composer analyse && composer test &&
  composer test:types` из директории пакета. P4.9/P4.10 трогают ВСЕ пакеты — прогон по каждому.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой
  read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым
  (`vendor/bin/pint --test <файлы>`).
- **`CHANGELOG.md` заблокирован для Read/Write** (`deny: Read(**/CHANGELOG.md)`) — долг записей за
  P1.2/P1.4/P2.1/P2.2/P2.3/P3.1-P3.6/P4.1/P4.2 и далее. **F-P2.1-06 (P4.7) чинится КОНФИГОМ
  split-пайплайна** (D23), не наполнением файла; наполнение — Pending Work, сессия с доступом.
  `UPGRADE.md` (не `UPGRADING.md`, D23) — канонический сток breaking-changes (5 записей: 4 из P4.1
  + 1 из P4.2).
- **Локальная песочница exec-сессий не несёт coverage-драйвера (xdebug/pcov)** — ни `php` (8.5), ни
  `php8.4` не имеют модуля; `sudo`/`pecl` заблокированы harness'ом. Реальный измеренный MSI для
  P4.1's `infection.json5` (root + 4 per-package) НЕДОСТИЖИМ локально — используется провизорный
  порог 50/70 (advisory job, не блокирует CI). Фактический MSI можно узнать только из реального
  прогона `mutation.yml`/сателлитных workflow в CI (там PHP 8.4 + xdebug).
- **P4.10 — межпакетный namespace-refactor (closed-enum escalation-триггер, opus/high, D10).** Самый
  вероятный провал (pre-mortem R-refactor) — забытый morph-алиас: полиморфные строки в БД держат старый
  FQCN, связи молча ломаются в проде при зелёных тестах на свежей БД. Обязателен morph-инвариант-тест на
  PG. Доказательство неизменности поведения — зелёный сьют БЕЗ правки тестов сверх `use`-строк.
- **F-P4.5-02 (hocuspocus-мажор) эскалирован в P4.1** — стек РЕВЕРЧЕН обратно на `^2.15`/`^2.13`
  (hocuspocus-server, packages/vue, e2e). Если будущая сессия/владелец решит развернуть отдельный
  план на этот мажор — начинать с `hocuspocus-server/test/websocket-hardening.test.js` (v2.x-специфичная
  форма `listen(port, host, websocketOptions)`), это первое, что ломается на v4.
- **Пред-существующий Rector-долг** (112 файлов core/drive/media/documents + 2 filament),
  обнаруженный подключением гейта в P4.1, НЕ вычищен (вне Scope Included P4.1) — Rector CI-шаг
  остаётся advisory (`|| true`) именно по этой причине. Не заводить как «регрессию P4.1» в ревью —
  долг пред-существовал, просто не был виден без CI-гейта.
- **Остаточный `npm audit --audit-level=high` в `packages/vue`** (1 high, `vite<=6.4.2`↔esbuild
  dev-server-only, GHSA-67mh-4wv8-2f99) — critical happy-dom RCE ЗАКРЫТ, это отдельный, более
  старый и менее опасный (dev-server-only, moderate по факту) остаток; полное закрытие требует
  `vite-plugin-dts` релиза с поддержкой `vite@8` (апстрим, отслеживать).
- Testbench-снаги (унаследованы из P1.3/P3.1): `cache.default => 'array'` в тестовом
  `defineEnvironment()`; feature-флаги маршрутов — в `defineEnvironment()` (до boot), не `beforeEach`;
  `Auth::logout()` перед in-process `$this->artisan(...)`. PG-прогон для FK/SERIALIZABLE/индексов/CTE —
  `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres` (127.0.0.1:5455, роль `vaulter`).
- **`packages/core/tests/Drive/` НЕ забинжен ни к какому `TestCase`** (`tests/Pest.php`: только
  `DriveTestHelpers`, drive-suite переехал в пакет `vaulter-drive`, комментарий явно фиксирует «не
  тест-кейс») — новые core-level тесты модели/репозитория (не HTTP drive-контроллеры) кладутся в
  `packages/core/tests/Schema/` (biндится к `TestCase`), по образцу `NodeAdjacencyTest.php`, НЕ в
  `Drive/` — иначе Pest молча не находит `config`-контейнер и падает `Target class [config] does
  not exist` на каждом тесте (P4.2-урок).
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-feature-log.last`,
  `.gitignore`, `.claude/workflows/vlt-remed-*.js`, `plans/ACTIVE.md`, `plans/AUDIT-VLT/*`,
  `plans/VLT-HSEAM/`) из конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а.
- Пред-существующий PG-only флейк `NodeCommentSchemaTest` (jsonb `anchor` key-order round-trip) — вне
  scope, ещё не заведён отдельной находкой. Модели `UploadSession`/`UploadPart`/`NodeComment`/
  `NodeDocumentVersion` не подключают `ResolvesVaulterConnection` (P3.6 Pending Work).

**Workarounds/Deferred/Open questions:**
- workarounds: см. Open risks (per-package гейты; pint-точечно; `cache.default=array`; feature-флаги в
  `defineEnvironment`; PG-контейнер `agelto_postgres`; когда файл несёт МНОГО call site — одна точка
  резолва + глобальный find/replace, P3.6-урок; `getConnectionName()` override через `enum_value()`);
  провизорный MSI-порог 50/70 при отсутствии локального coverage-драйвера (P4.1); `window.prompt`-стаб
  для happy-dom ≥16 в `vi.spyOn`-фикстурах (P4.1); red-before для «раздутой/бесконечной рекурсии»
  безопасно верифицируется временным `git stash` фикса + прогон (никогда не оставлять сессию с
  реверченным фиксом) — на SQLite/малом cyclic-фикстуре она ограничена PHP `memory_limit`, не
  бесконечным hang (P4.2-урок).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — `/task:plan-close archive AUDIT-VLT`. Ротация
  `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца.
  `CHANGELOG.md`-наполнение — сессия с доступом. Подключение хуков панели `fillNode/presentNode/pipes`
  к пайплайну (F-P3.2-02: P4.4 только помечает `@unimplemented`, D22) — фича при «go» владельца.
  **Hocuspocus-мажор (F-P4.5-02, D6)** — эскалирован в P4.1, отдельный план по решению владельца.
  Пред-существующий Rector-долг (112+2 файла) — отдельный item/бэклог по решению владельца.
  Реальный измеренный MSI — фактический прогон в CI (xdebug доступен там, не локально).
  Остаточный `npm audit high` в `packages/vue` (vite/esbuild dev-server) — апстрим `vite-plugin-dts`.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) — оба
  `Decision pending`, ни один P4-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
