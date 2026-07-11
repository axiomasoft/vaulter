# HANDOFF — 2026-07-11 — after P4.6

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium (дефолт §Routing — локальный код по готовому ТЗ, корень известен) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P4.7 (B21)** строго по ТЗ `phases/P4.md`: release-readiness — split-changelog-конфиг для PHP-пакетов (F-P2.1-06, конфиг, НЕ содержимое CHANGELOG.md), секция deprecation-policy, `@vaulter/vue` `yjs`/`@tiptap/pm` → `peerDependencies`, README-формулировка `vaulter:install`, сток `UPGRADE.md` всех breaking плана. Node-манифест-правка (`packages/vue/package.json`) требует `cd packages/vue && npm run typecheck`-эквивалента в дополнение к root-гейту. |

```
/task:plan-exec VLT-REMED P4.7
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) ТЕРМИНАЛЬНА (6/6 🟢).**
- **Фаза P4 (W4) В РАБОТЕ (5/10 🟢, 1/10 🔴 Blocked-формально):**
  - **P4.1 (B24 — Supply-chain, CI-матрица, arch- и mutation-гейты) — 🔴 Blocked**
    (коммит `74294e0`), 13 из 14 находок B24 закрыты штатно, заблокирована только
    F-P4.5-02 (hocuspocus-мажор, эскалирован по D6-гарду, `⛔ Skipped by decision`,
    реверчен на `^2.15`/`^2.13`). Полные детали — `phases/P4.md` P4.1 Completion Notes
    + ESCALATION-блок.
  - **P4.2 (B15 — Безопасность и корректность запросов) — 🟢 Done** (коммит `cd31116`).
  - **P4.3 (B16 — Индексы и пагинация) — 🟢 Done** (коммит `408e17f`).
  - **P4.4 (B17 — Молчащие швы: докблок ≠ код) — 🟢 Done** (коммиты `f3b51b7`, `2764d13`).
  - **P4.5 (B18 — Расширяемость швов именования и экспорта) — 🟢 Done** (коммит `4bbaa7f`).
  - **P4.6 (B20 — Гигиена публичного API) — 🟢 Done** (коммит `e4277a3`):
    - **F-P2.1-01**: докблок фасада `Vaulter.php` дополнен 5 недостающими
      `@method`-строками (`registerHandler`, `handlers`, `registerExporter`,
      `exporter`, `exporterFormats`), сверено рефлексией `VaulterManager` (12/12
      публичных методов). Guard-тест `FacadeMethodDocblockTest.php` собирает список
      методов рефлексией (не хардкод), сверяет regex-паттерном на каждый.
    - **F-P2.1-04**: FQCN `UploadProtocolHandler` в `docs/reference/contracts.md:419`
      выправлен на фактический `Vaulter\Drive\Uploads\Contracts\UploadProtocolHandler`.
      Guard-тест `ContractsDocFqcnGuardTest.php` сверяет доку с реальным `::class`.
    - **F-P2.1-05**: `EnumCaseAttributeResolver` — процесс-широкий `static $cache` →
      кеш на инстансе, забинженном singleton'ом в `VaulterServiceProvider`
      (рядом с `IdStrategyResolver`/`PanelRegistry`). Выбран ПРЕДПОЧТИТЕЛЬНЫЙ вариант
      ТЗ (не fallback-докблок) — всего 3 статических call site во всём монорепо
      (`HasIcon.php`, `HasLabel.php` в `core`; `HasTiptapExtensions.php` в
      `documents`), каждый переведён на `app(EnumCaseAttributeResolver::class)`
      (паттерн уже используется в пакете). Red-before подтверждён `git stash`:
      оба новых теста в `EnumCaseAttributeResolverInstanceCacheTest.php` падали
      на коде до фикса (второй инстанс наследовал static-кеш первого).
    - **Cross-package touch**: единственный call site резолвера вне `core` —
      `packages/documents/src/Support/Concerns/HasTiptapExtensions.php` — прогнан
      отдельный гейт `packages/documents` (lint/analyse/test/test:types, все
      зелёные, тестовый счётчик 136+1skip не изменился).
    - 4 новых теста (root 457/457, было 453). Полные Completion Notes —
      `phases/P4.md` P4.6.

**Remaining:** (порядок исполнения P4)
1. ~~**P4.1**~~ — исполнен, 🔴 Blocked ТОЛЬКО формально (plan-lint ESCALATION-инвариант).
2. ~~**P4.2**~~ — исполнен, 🟢 Done.
3. ~~**P4.3**~~ — исполнен, 🟢 Done.
4. ~~**P4.4**~~ — исполнен, 🟢 Done.
5. ~~**P4.5**~~ — исполнен, 🟢 Done.
6. ~~**P4.6**~~ — исполнен, 🟢 Done.
7. **P4.7** (B21) — split-changelog-конфиг, deprecation-policy, `@vaulter/vue` peerDeps, сток `UPGRADE.md`. **← следующий**.
8. **P4.8** (B19, sonnet/low) — docs-drift (9 находок, doc-guard-инварианты).
9. **P4.9** (opus/high, D10) — канон A: инвентарь + ADR-0017 + arch-тесты (БЕЗ переноса).
10. **P4.10** (opus/high, D10) — namespace-refactor к канону (gate: P4.9 закрыт; `namespace-refactor-safety`).

Затем **P5** (3 items, СКЕЛЕТ — требует `/task:plan-design VLT-REMED P5`, после P4). Скелет-фаза не
исполняется напрямую (`plan.md` §Execution Rules).

**Открытый пункт (не блокирует P4.7, требует внимания владельца/будущей сессии):**
**F-P4.5-02** (hocuspocus `2.x→4.x`) остаётся на `⛔ Skipped by decision` — см. ESCALATION-блок
`phases/P4.md` P4.1 и Q-заводку ниже. `/task:plan-design VLT-REMED` (новый отдельный план на
hocuspocus-мажор) — по решению владельца, вне текущего remaining-списка P4.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board — P3
терминальна 6/6, P4 в работе 5/10🟢+1/10🔴 / Decision Log D1–D23 / Update Log с записью P4.6) ·
`plans/VLT-REMED/phases/P4.md` (P4.1 закрыт с ESCALATION-блоком; P4.2–P4.6 закрыты 🟢;
P4.7–P4.10 детализированы, готовы к исполнению) ·
`plans/VLT-REMED/phases/{P0,P1,P2,P3}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P4) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P2.1-public-surface,P2.5-dx,P4.3-test-strategy}.md` (F-P2.1-06/07,
F-P2.5-07/04 — Required Reads P4.7) · `UPGRADE.md` (8 записей: 4 P4.1 + 1 P4.2 + 2 P4.4 + 1 P4.5 —
P4.6 новую запись НЕ добавила, behaviour-preserving для внешних потребителей: `Vaulter::registerHandler()`
и т.д. и так были вызываемы через фасад, докблок только догнал видимость; резолвер — внутренний Support-класс,
не публичный контракт).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P4.6 тронула
  `packages/documents/src/Support/Concerns/HasTiptapExtensions.php` (единственный call site резолвера
  вне core) — documents-гейт прогнан отдельно и зелёный. **P4.7 трогает `packages/vue/package.json`**
  (Node-манифест, не PHP) — нужен `cd packages/vue && npm run typecheck` (или эквивалент) В ДОПОЛНЕНИЕ
  к root PHP-гейту, не вместо него. P4.9/P4.10 трогают ВСЕ пакеты — прогон по каждому.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой
  read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым
  (`vendor/bin/pint --test <файлы>`).
- **`CHANGELOG.md` заблокирован для Read/Write** (`deny: Read(**/CHANGELOG.md)`) — долг записей за
  P1.2/P1.4/P2.1/P2.2/P2.3/P3.1-P3.6/P4.1/P4.2/P4.4/P4.5 и далее. **F-P2.1-06 (P4.7) чинится КОНФИГОМ
  split-пайплайна** (D23), не наполнением файла; наполнение — Pending Work, сессия с доступом.
  `UPGRADE.md` (не `UPGRADING.md`, D23) — канонический сток breaking-changes (8 записей).
- **P4.5-урок для будущих items, задевающих arch-гейты P4.1 (H.5 и другие):** ArchTest'ы,
  добавленные P4.1 как «анти-самообман»-гейт волны, — ТВЁРДАЯ рамка, не рекомендация; item, чьё
  ТЗ предполагает нарушение, обязан сверить Scope Included на альтернативный OR-вариант ДО
  импровизации/эскалации.
- **P4.6-урок:** когда ТЗ item'а называет «предпочтительно X, fallback Y при большом числе call site»
  — исполнитель ОБЯЗАН фактически посчитать call site грепом ДО выбора fallback (не доверять
  прогнозу предыдущего handoff «item не тронет пакет N» — это прогноз планирования, не факт кода).
  Здесь предыдущий handoff предполагал «P4.6 не тронет `packages/documents`», но фактический грep
  показал 1 call site там же — вариант «предпочтительный» (singleton) остался дешёвым (3 call site
  всего, не «много»), выполнен буквально, с дополнительным documents-гейтом.
- **Локальная песочница exec-сессий не несёт coverage-драйвера (xdebug/pcov)** — ни `php` (8.5), ни
  `php8.4` не имеют модуля; `sudo`/`pecl` заблокированы harness'ом. Реальный измеренный MSI для
  P4.1's `infection.json5` НЕДОСТИЖИМ локально — используется провизорный порог 50/70.
- **Пред-существующий count-зависимый PG-флейк тестового харнесса (обнаружен в P4.3, вне scope
  P4.3–P4.6 чинить).** Полный прогон `composer test` НА POSTGRES даёт спорадический 1 failure —
  `MassAssignmentHardeningTest`, последний кейс. Триггер — НЕ логика P4.x. `composer test` (SQLite,
  дефолтный гейт Validation) НЕ подвержен.
- **P4.4 добавила новую `tests/Pest.php`-биндинг-директорию** (`$core.'/PanelBoot'` →
  `BrokenPanelBootTestCase`) — будущие тесты со сломанным boot кладутся туда же.
- **P4.10 — межпакетный namespace-refactor (closed-enum escalation-триггер, opus/high, D10).** Самый
  вероятный провал — забытый morph-алиас: полиморфные строки в БД держат старый FQCN. Обязателен
  morph-инвариант-тест на PG.
- **F-P4.5-02 (hocuspocus-мажор) эскалирован в P4.1** — стек РЕВЕРЧЕН обратно на `^2.15`/`^2.13`.
- **Пред-существующий Rector-долг** (112 файлов core/drive/media/documents + 2 filament) — вне Scope
  Included P4.1, Rector CI-шаг остаётся advisory (`|| true`).
- **Остаточный `npm audit --audit-level=high` в `packages/vue`** (1 high, `vite<=6.4.2`↔esbuild
  dev-server-only, GHSA-67mh-4wv8-2f99) — critical happy-dom RCE ЗАКРЫТ; **актуально для P4.7**,
  которое также трогает `packages/vue/package.json` (peerDeps) — не путать это низкоприоритетное
  npm-audit-остаточное с самой задачей P4.7.
- Testbench-снаги (унаследованы из P1.3/P3.1): `cache.default => 'array'` в `defineEnvironment()`;
  фиче-флаги маршрутов — в `defineEnvironment()` (до boot); `Auth::logout()` перед in-process
  `$this->artisan(...)`. PG-прогон — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres`
  (127.0.0.1:5455, роль/пароль `vaulter`).
- **`packages/core/tests/Drive/` НЕ забинжен ни к какому `TestCase`** — новые core-level тесты модели/
  репозитория кладутся в `packages/core/tests/Schema/`, НЕ в `Drive/`.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-feature-log.last`,
  `.gitignore`, `.claude/workflows/vlt-remed-*.js`, `plans/ACTIVE.md`, `plans/AUDIT-VLT/*`,
  `plans/VLT-HSEAM/`) из конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а.
  Подтверждено ещё раз в P4.6: `git status` до и после коммита показывает эти пути нетронутыми.
- Пред-существующий PG-only флейк `NodeCommentSchemaTest` (jsonb `anchor` key-order round-trip) — вне
  scope. Модели `UploadSession`/`UploadPart`/`NodeComment`/`NodeDocumentVersion` не подключают
  `ResolvesVaulterConnection` (P3.6 Pending Work).

**Workarounds/Deferred/Open questions:**
- workarounds: см. Open risks (per-package гейты; pint-точечно; `cache.default=array`; feature-флаги в
  `defineEnvironment`; PG-контейнер `agelto_postgres`; провизорный MSI-порог 50/70; PG count-зависимый
  флейк тестового харнесса ИЗОЛИРУЕТСЯ прогоном целевого `--filter`; boot-time fail-loud проверяется
  через `Container::resolved()`/выделенный `TestCase`; **P4.6-урок:** «предпочтительный X vs fallback Y
  при многих call site» — считать call site ГРЕПОМ по факту кода перед выбором, не доверять
  прогнозу предыдущего launch-block о том, какие пакеты item затронет; red-before для инстанс-vs-static
  кеша верифицируется `git stash` фикса (не самого теста) + прогон на голом коде.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — `/task:plan-close archive AUDIT-VLT`. Ротация
  `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца.
  `CHANGELOG.md`-наполнение — сессия с доступом. Подключение хуков панели `fillNode/presentNode/pipes`
  к пайплайну (F-P3.2-02) — фича при «go» владельца. **Hocuspocus-мажор (F-P4.5-02, D6)** —
  эскалирован в P4.1, отдельный план по решению владельца. Пред-существующий Rector-долг — отдельный
  item/бэклог. Реальный измеренный MSI — фактический прогон в CI. Остаточный `npm audit high` в
  `packages/vue` — апстрим `vite-plugin-dts`. Пред-существующий count-зависимый PG-флейк
  (`MassAssignmentHardeningTest`) — отдельная сессия/item. CAS-точки в drive/media НЕ обёрнуты в
  `MissingPackageException` — отдельный item/бэклог.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) — оба
  `Decision pending`, ни один P4-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
