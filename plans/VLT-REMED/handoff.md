# HANDOFF — 2026-07-11 — after P4.7

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low (дефолт §Routing — P4.8 механическая docs-drift-сверка, thinking вреден) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P4.8 (B19)** строго по ТЗ `phases/P4.md`: механическая синхронизация 9 находок docs-drift (мёртвый `permission_resolver`, удалённый API `facade`/`contracts.md`, 7 недокументированных контрактов, `vaulter:prune-webhooks`, 5 маршрутов Drive, 9 битых якорей `errors.md`, `gc.orphan_grace_hours`, CORS-гайд, PHPStan-level в CONTRIBUTING.md). Doc-guard-инварианты (≥3 с F-id), не рассуждение. |

```
/task:plan-exec VLT-REMED P4.8
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) ТЕРМИНАЛЬНА (6/6 🟢).**
- **Фаза P4 (W4) В РАБОТЕ (6/10 🟢, 1/10 🔴 Blocked-формально):**
  - **P4.1 (B24 — Supply-chain, CI-матрица, arch- и mutation-гейты) — 🔴 Blocked**
    (коммит `74294e0`), 13 из 14 находок B24 закрыты штатно, заблокирована только
    F-P4.5-02 (hocuspocus-мажор, эскалирован по D6-гарду, `⛔ Skipped by decision`,
    реверчен на `^2.15`/`^2.13`). Полные детали — `phases/P4.md` P4.1 Completion Notes
    + ESCALATION-блок.
  - **P4.2 (B15 — Безопасность и корректность запросов) — 🟢 Done** (коммит `cd31116`).
  - **P4.3 (B16 — Индексы и пагинация) — 🟢 Done** (коммит `408e17f`).
  - **P4.4 (B17 — Молчащие швы: докблок ≠ код) — 🟢 Done** (коммиты `f3b51b7`, `2764d13`).
  - **P4.5 (B18 — Расширяемость швов именования и экспорта) — 🟢 Done** (коммит `4bbaa7f`).
  - **P4.6 (B20 — Гигиена публичного API) — 🟢 Done** (коммит `e4277a3`).
  - **P4.7 (B21 — Release-readiness и UPGRADE.md) — 🟢 Done** (коммит `e00f82c`):
    - **F-P2.1-06**: `.github/workflows/monorepo-split.yml` получил шаг «Copy root
      CHANGELOG.md into packages/${{ matrix.package }}» (`cp CHANGELOG.md
      packages/${{ matrix.package }}/CHANGELOG.md`) ПЕРЕД `monorepo-split-github-action`
      — split-репо (Packagist-артефакт) теперь несёт `CHANGELOG.md` в своей
      subtree-копии. `.changeset/config.json` НЕ тронут — он управляет только
      npm-changesets, к PHP-split не относится (D23: достаточно конфига пайплайна,
      не наполнения `CHANGELOG.md`).
    - **F-P2.1-07**: секция «## Deprecation policy» в `CONTRIBUTING.md` — конкретный
      срок («`@deprecated` держится ≥1 минорный релиз, удаляется в следующий мажор»),
      плюс уточнение для pre-1.0 состояния пакета (D3: breaking без депрекейт-цикла
      до первого `1.0`).
    - **F-P2.5-07**: `packages/vue/package.json` — `yjs` (`^13.6`) и `@tiptap/pm`
      (`^3.0.0`) перенесены из `dependencies` в `peerDependencies` (диапазоны
      неизменны, реально протестированные); оставлены также в `devDependencies`
      для локальной сборки/тестов пакета. `vue` остаётся peer. `version: "0.0.0"`
      НЕ тронута (зафиксирована как осознанное состояние, не «баг»).
    - **F-P2.5-04**: `README.md:67` — «`vaulter:install # publishes config +
      migrations, runs them`» → «`… asks to run them`» (выровнено с
      `docs/guide/installation.md:48`).
    - **Сток `UPGRADE.md`**: грепом `F-P` сверено — все закрытые breaking-находки
      P0.1–P4.5 присутствуют (46 упоминаний F-id, P4.6 сознательно без записи —
      behaviour-preserving). Добавлена новая секция (newest-first) про
      peerDeps-перенос как breaking-последствие для npm-потребителей `@vaulter/vue`.
    - **Новый тест**: `packages/core/tests/Support/ReleaseReadinessGuardTest.php` —
      4 green guard-теста (D19, red-before подтверждён `git stash`).
    - **Node-гейт**: `cd packages/vue && npm run typecheck` (`vue-tsc --noEmit`) —
      passed; `npm test` (`vitest run`) — 9 файлов/102 теста passed, без регрессии.
    - Root: `composer test` 461/461 (было 457, +4), `test:types` 100%, `analyse` 0
      errors, `lint:check` красный ТОЛЬКО на пред-существующем
      `plans/AUDIT-VLT/artifacts/repro/**` (вне scope), новый файл pint-чист.
    - Полные Completion Notes — `phases/P4.md` P4.7.

**Remaining:** (порядок исполнения P4)
1. ~~**P4.1**~~ — исполнен, 🔴 Blocked ТОЛЬКО формально (plan-lint ESCALATION-инвариант).
2. ~~**P4.2**~~ — исполнен, 🟢 Done.
3. ~~**P4.3**~~ — исполнен, 🟢 Done.
4. ~~**P4.4**~~ — исполнен, 🟢 Done.
5. ~~**P4.5**~~ — исполнен, 🟢 Done.
6. ~~**P4.6**~~ — исполнен, 🟢 Done.
7. ~~**P4.7**~~ — исполнен, 🟢 Done.
8. **P4.8** (B19, sonnet/low) — docs-drift (9 находок, doc-guard-инварианты). **← следующий**.
9. **P4.9** (opus/high, D10) — канон A: инвентарь + ADR-0017 + arch-тесты (БЕЗ переноса).
10. **P4.10** (opus/high, D10) — namespace-refactor к канону (gate: P4.9 закрыт; `namespace-refactor-safety`).

Затем **P5** (3 items, СКЕЛЕТ — требует `/task:plan-design VLT-REMED P5`, после P4). Скелет-фаза не
исполняется напрямую (`plan.md` §Execution Rules).

**Открытый пункт (не блокирует P4.8, требует внимания владельца/будущей сессии):**
**F-P4.5-02** (hocuspocus `2.x→4.x`) остаётся на `⛔ Skipped by decision` — см. ESCALATION-блок
`phases/P4.md` P4.1 и Q-заводку ниже. `/task:plan-design VLT-REMED` (новый отдельный план на
hocuspocus-мажор) — по решению владельца, вне текущего remaining-списка P4.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board — P3
терминальна 6/6, P4 в работе 6/10🟢+1/10🔴 / Decision Log D1–D23 / Update Log с записью P4.7) ·
`plans/VLT-REMED/phases/P4.md` (P4.1 закрыт с ESCALATION-блоком; P4.2–P4.7 закрыты 🟢/🟠;
P4.8–P4.10 детализированы, готовы к исполнению) ·
`plans/VLT-REMED/phases/{P0,P1,P2,P3}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P4) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P2.4-host-integration,P2.5-dx,P2.1-public-surface,P1.8-secrets-config-http}.md`
(F-P2.4-01, F-P2.5-01/02/03/05/08, F-P2.1-02/03, F-P1.8-04 — Required Reads P4.8) ·
`UPGRADE.md` (9 записей: 4 P4.1 + 1 P4.2 + 2 P4.4 + 1 P4.5 + 1 P4.7 — P4.6 новую запись НЕ
добавила, behaviour-preserving).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. **P4.8** трогает только
  `docs/**`/`CONTRIBUTING.md`/`phpstan.neon` (нет PHP-кода вне возможного удаления мёртвого
  `permission_resolver`-ключа, который живёт в доке, не в коде) — вероятно root-гейта достаточно;
  если находка F-P2.4-01 всё же требует правки PHP в `packages/documents`/`drive`, прогнать их
  собственный `composer analyse/test/test:types` тоже. P4.9/P4.10 трогают ВСЕ пакеты — прогон по
  каждому.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**`
  (чужой read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым
  (`vendor/bin/pint --test <файлы>`).
- **`CHANGELOG.md` заблокирован для Read/Write** (`deny: Read(**/CHANGELOG.md)`) — долг записей за
  P1.2/P1.4/P2.1/P2.2/P2.3/P3.1-P3.6/P4.1/P4.2/P4.4/P4.5/P4.7 и далее. **F-P2.1-06 (P4.7) закрыта
  КОНФИГОМ split-пайплайна** (D23) — split-репо теперь получает `CHANGELOG.md` целиком
  (copy-step), не фильтрованный по пакету фрагмент; наполнение самого файла — по-прежнему
  Pending Work, сессия с доступом. `UPGRADE.md` (не `UPGRADING.md`, D23) — канонический сток
  breaking-changes (9 записей).
- **P4.5-урок для будущих items, задевающих arch-гейты P4.1 (H.5 и другие):** ArchTest'ы,
  добавленные P4.1 как «анти-самообман»-гейт волны, — ТВЁРДАЯ рамка, не рекомендация; item, чьё
  ТЗ предполагает нарушение, обязан сверить Scope Included на альтернативный OR-вариант ДО
  импровизации/эскалации.
- **P4.6-урок:** когда ТЗ item'а называет «предпочтительно X, fallback Y при большом числе call site»
  — исполнитель ОБЯЗАН фактически посчитать call site грепом ДО выбора fallback (не доверять
  прогнозу предыдущего handoff — это прогноз планирования, не факт кода).
- **P4.7-урок:** «сток UPGRADE.md — свести ВСЕ breaking плана» проверяется грепом `F-P` в
  `UPGRADE.md` против Completion Notes закрытых items, НЕ по памяти; будущий item, добавляющий
  собственный breaking (как peerDeps-перенос здесь), обязан добавить СВОЮ секцию в UPGRADE.md
  тоже — сток не только «наследие P0–P4.6», но и текущий item, если его фикс breaking.
- **Локальная песочница exec-сессий не несёт coverage-драйвера (xdebug/pcov)** — ни `php` (8.5), ни
  `php8.4` не имеют модуля; `sudo`/`pecl` заблокированы harness'ом. Реальный измеренный MSI для
  P4.1's `infection.json5` НЕДОСТИЖИМ локально — используется провизорный порог 50/70.
- **Пред-существующий count-зависимый PG-флейк тестового харнесса (обнаружен в P4.3, вне scope
  P4.3–P4.7 чинить).** Полный прогон `composer test` НА POSTGRES даёт спорадический 1 failure —
  `MassAssignmentHardeningTest`, последний кейс. Триггер — НЕ логика P4.x. `composer test` (SQLite,
  дефолтный гейт Validation) НЕ подвержен.
- **P4.4 добавила новую `tests/Pest.php`-биндинг-директорию** (`$core.'/PanelBoot'` →
  `BrokenPanelBootTestCase`) — будущие тесты со сломанным boot кладутся туда же.
- **P4.10 — межпакетный namespace-refactor (closed-enum escalation-триггер, opus/high, D10).** Самый
  вероятный провал — забытый morph-алиас: полиморфные строки в БД держат старый FQCN. Обязателен
  morph-инвариант-тест на PG.
- **F-P4.5-02 (hocuspocus-мажор) эскалирован в P4.1** — стек РЕВЕРЧЕН обратно на `^2.15`/`^2.13`.
- **Пред-существующий Rector-долг** (112 файлов core/drive/media/documents + 2 filament) — вне
  Scope Included P4.1, Rector CI-шаг остаётся advisory (`|| true`).
- **Остаточный `npm audit --audit-level=high` в `packages/vue`** (1 high, `vite<=6.4.2`↔esbuild
  dev-server-only, GHSA-67mh-4wv8-2f99) — critical happy-dom RCE ЗАКРЫТ; не путать с P4.7
  (peerDeps-перенос не затрагивает этот остаточный npm-audit-пункт).
- Testbench-снаги (унаследованы из P1.3/P3.1): `cache.default => 'array'` в `defineEnvironment()`;
  фиче-флаги маршрутов — в `defineEnvironment()` (до boot); `Auth::logout()` перед in-process
  `$this->artisan(...)`. PG-прогон — `php8.4` + БД `vaulter_test` в контейнере `agelto_postgres`
  (127.0.0.1:5455, роль/пароль `vaulter`).
- **`packages/core/tests/Drive/` НЕ забинжен ни к какому `TestCase`** — новые core-level тесты модели/
  репозитория кладутся в `packages/core/tests/Schema/`, НЕ в `Drive/`.
- В рабочем дереве присутствуют НЕ относящиеся к VLT-REMED правки (`.claude/.maind-feature-log.last`,
  `.gitignore`, `.claude/workflows/vlt-remed-*.js`, `plans/ACTIVE.md`, `plans/AUDIT-VLT/*`,
  `plans/VLT-HSEAM/`) из конкурентной сессии/хука — НЕ трогать, коммитить только scoped-пути item'а.
  Подтверждено ещё раз в P4.7: `git status` до и после коммита показывает эти пути нетронутыми.
- Пред-существующий PG-only флейк `NodeCommentSchemaTest` (jsonb `anchor` key-order round-trip) — вне
  scope. Модели `UploadSession`/`UploadPart`/`NodeComment`/`NodeDocumentVersion` не подключают
  `ResolvesVaulterConnection` (P3.6 Pending Work).

**Workarounds/Deferred/Open questions:**
- workarounds: см. Open risks (per-package гейты; pint-точечно; `cache.default=array`; feature-флаги в
  `defineEnvironment`; PG-контейнер `agelto_postgres`; провизорный MSI-порог 50/70; PG count-зависимый
  флейк тестового харнесса ИЗОЛИРУЕТСЯ прогоном целевого `--filter`; boot-time fail-loud проверяется
  через `Container::resolved()`/выделенный `TestCase`; **P4.6-урок:** считать call site ГРЕПОМ по
  факту кода перед выбором fallback-варианта; **P4.7-урок:** UPGRADE.md-сток сверяется грепом
  `F-P` против Completion Notes, не по памяти, и требует записи от ТЕКУЩЕГО item'а тоже, если он
  сам breaking; red-before для doc/config/manifest-alignment guard-тестов верифицируется `git stash`
  изменённых не-тестовых файлов (не самого теста) + прогон на голом коде.
- deferred: `docs/`-миграция и архивация AUDIT-VLT — `/task:plan-close archive AUDIT-VLT`. Ротация
  `signing_secret` (Q3) и абсолютный барьер wall-deny (Q4) — отдельные items по решению владельца.
  `CHANGELOG.md`-наполнение (содержимое) — сессия с доступом. Подключение хуков панели
  `fillNode/presentNode/pipes` к пайплайну (F-P3.2-02) — фича при «go» владельца.
  **Hocuspocus-мажор (F-P4.5-02, D6)** — эскалирован в P4.1, отдельный план по решению владельца.
  Пред-существующий Rector-долг — отдельный item/бэклог. Реальный измеренный MSI — фактический
  прогон в CI. Остаточный `npm audit high` в `packages/vue` — апстрим `vite-plugin-dts`.
  Пред-существующий count-зависимый PG-флейк (`MassAssignmentHardeningTest`) — отдельная
  сессия/item. CAS-точки в drive/media НЕ обёрнуты в `MissingPackageException` — отдельный
  item/бэклог.
- open_questions: Q3 (ротация `signing_secret`), Q4 (абсолютный барьер wall-deny) — оба
  `Decision pending`, ни один P4-item не блокируют. Q1/Q2 — `Resolved → D1/D10`.
