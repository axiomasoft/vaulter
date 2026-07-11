# HANDOFF — 2026-07-11 — after P4.1

**Next:** /task:plan-exec VLT-REMED P4.2

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium (дефолт §Routing — локальный код по готовому ТЗ, корень известен) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Исполнить **P4.2 (B15)** строго по ТЗ `phases/P4.md`: экранировать LIKE-wildcard (`%`/`_`/`\`) в `NodeReadRepository::search()` (F-P1.8-03) + включить `Node::enableCycleDetection(): true` (F-P4.2-02). Обе находки — red-before на PG (`agelto_postgres`), F-id в описании тестов. |

```
/task:plan-exec VLT-REMED P4.2
```

**Done:**
- **Фаза P0 (W0 — Blocker'ы) ТЕРМИНАЛЬНА (3/3 🟢).**
- **Фаза P1 (W1 — быстрые высоко-эксплуатируемые) ТЕРМИНАЛЬНА (4/4 🟢).**
- **Фаза P2 (W2 — авторизация и изоляция) ТЕРМИНАЛЬНА (3/3, 🟠 Done with deviations).**
- **Фаза P3 (W3 — надстройки и интеграция) ТЕРМИНАЛЬНА (6/6 🟢).**
- **Фаза P4 (W4) В РАБОТЕ (0/10 🟢, 1/10 🔴 Blocked) — P4.1 исполнен, но несёт формальный
  ESCALATION-статус (plan-lint инвариант: Escalation Needed=yes ⇒ Status=🔴 Blocked).**
  **P4.1 (B24 — Supply-chain, CI-матрица, arch- и mutation-гейты) — 🔴 Blocked
  (коммит `74294e0`), 13 из 14 находок B24 ЗАКРЫТЫ ШТАТНО, заблокирована только F-P4.5-02:**
  - **Гейты волны (F-P4.3-01..05):** `infection.json5` (root) + 4 новых per-package конфига
    (`packages/{drive,media,documents,filament}/infection.json5`) с ненулевым `minMsi`/
    `minCoveredMsi` (провизорный порог 50/70 — реальный MSI не измерим локально, см. Open risks);
    `App\*`-запрет (двойная форма: `arch()->not->toUse('App')` для grep + file-scan `test()` как
    реально исполняемый гейт) и E3.1-граница публичного API (`toOnlyBeUsedIn`) во всех пяти
    `ArchTest.php`; `composer test:types` подключён к `tests.yml`; Rector в CI (root `code-style.yml`
    + package-local `packages/filament/rector.php` + шаг в `filament.yml`); root `rector.php`
    расширен на `packages/{drive,media,documents}/src`.
  - **Честная CI-матрица (F-P4.4-02):** `matrix.include` (L12+TB^10 / L13+TB^11) добавлена в
    `drive.yml`/`media.yml`/`documents.yml`/`permissions-azgard.yml`; `filament.yml` НЕ получил
    матрицу (композер-констрейнт пакета честно требует Testbench `^11.0`/L13-only) — вместо неё
    явная документация в README (F-P4.4-03).
  - **CVE:** `firebase/php-jwt` `^6.0`→`^7.0` (CVE-2025-45769 закрыт, `composer audit` → 0
    advisories) — v7 добавил проверку минимальной длины HS256-ключа, 3 тестовых фикстуры
    documents несли короткие секреты, удлинены до ≥32 байт (значение фикстуры, не ассерт).
    `happy-dom` `^15.11.0`→`^20.10.6` (critical RCE GHSA-37j7-fg3j-429f закрыт) + `vitest`
    `^2.1.0`→`^4.1.10`; 3 теста `drive-components.test.ts` получили `window.prompt`-стаб (happy-dom
    ≥16 больше не реализует `window.prompt`).
  - **Инфра:** `hocuspocus-server/docker-compose.yml` требует `VAULTER_COLLAB_IMAGE_TAG` (без
    floating `:latest`, F-P1.8-05); `e2e/docker-compose.yml` — healthcheck на `laravel`/`hub`/
    `vite` + `depends_on: condition: service_healthy` (F-P4.5-03).
  - **Мажоры (D6-гард):** TipTap `2.x→3.x` (F-P4.5-01) — 🟢 чистый механический бамп
    (`packages/vue` + `e2e/`, build+typecheck+102 теста зелёные без правки src). Hocuspocus
    `2.x→4.x` (F-P4.5-02) — **ЭСКАЛИРОВАН**: ломает внутренний контракт регресс-теста
    `websocket-hardening.test.js` (бесконечный hang, не просто красный) — реверчен назад на
    `^2.15`/`^2.13`, диспозиция `⛔ Skipped by decision`. См. ESCALATION-блок в `phases/P4.md` P4.1.
  - Полные Completion Notes/Known Deviations/Pending Work — `phases/P4.md` P4.1.

**Remaining:** (порядок исполнения P4)
1. ~~**P4.1**~~ — исполнен, 🔴 Blocked ТОЛЬКО формально (plan-lint ESCALATION-инвариант; F-P4.5-02
   эскалирован, остальные 13 находок закрыты штатно).
2. **P4.2** (B15) — LIKE-экранирование + cycle-detection на чтении (red-before, PG). **← следующий**.
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

**Открытый пункт (не блокирует P4.2, требует внимания владельца/будущей сессии):**
**F-P4.5-02** (hocuspocus `2.x→4.x`) остаётся на `⛔ Skipped by decision` — см. ESCALATION-блок
`phases/P4.md` P4.1 и Q-заводку ниже. `/task:plan-design VLT-REMED` (новый отдельный план на
hocuspocus-мажор) — по решению владельца, вне текущего remaining-списка P4.

**Sources of truth:** `plans/VLT-REMED/plan.md` (Context/Execution Rules/Routing/Status Board — P3
терминальна 6/6, P4 в работе 0/10🟢+1/10🟠 / Decision Log D1–D23 / Update Log с записью P4.1) ·
`plans/VLT-REMED/phases/P4.md` (P4.1 закрыт с ESCALATION-блоком; P4.2–P4.10 детализированы,
готовы к исполнению) · `plans/VLT-REMED/phases/{P0,P1,P2,P3}.md` (закрыты; P2 🟠) ·
`plans/VLT-REMED/open-questions.md` (Q3/Q4 pending — не блокируют P4; F-P4.5-02-эскалация — см.
`phases/P4.md`, отдельного Q не заводилось, ESCALATION-блок сам по себе SSOT) ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` (N4 — TipTap-линия/CVE-2025-14284) ·
`plans/AUDIT-VLT/findings/REGISTER.md` (SSOT 103 находок, append-only, VLT-REMED в него НЕ пишет) ·
`plans/AUDIT-VLT/findings/{P4.1-query-indexes,P4.2-nplus1-recursion-pagination,P4.3-test-strategy,`
`P4.4-static-ci-supplychain,P4.5-frontend-e2e-docker}.md` (детальные находки батчей W4 —
Required Reads items P4.1/P4.2/P4.3 — P4.1 их RAG уже переверил на 2026-07-11) ·
`plans/AUDIT-VLT/findings/{P3.1-naming-seam,P3.2-panel-seam,P3.3-handler-exporter-seam,`
`P3.5-driver-seams,P1.2-role-drivers}.md` (семь швов P4.4/P4.5) ·
`plans/AUDIT-VLT/findings/{P2.1-public-surface,P2.4-host-integration,P2.5-dx}.md` (API/release/docs
P4.6/P4.7/P4.8) · `UPGRADE.md` (4 новых записи P4.1: php-jwt/секреты, happy-dom/vitest,
hocuspocus-образ-пиннинг, TipTap-мажор+hocuspocus-диспозиция).

**Open risks:**
- Root `composer.json` phpstan/phpunit покрывают ТОЛЬКО `packages/core`. P4-items, трогающие
  documents/drive/media/filament, обязаны гонять свои `composer analyse && composer test &&
  composer test:types` из директории пакета. P4.9/P4.10 трогают ВСЕ пакеты — прогон по каждому.
- Root `composer lint:check` красный на пред-существующем `plans/AUDIT-VLT/artifacts/repro/**` (чужой
  read-only план, вне scope) — closure НЕ блокирует; свой новый код обязан быть pint-чистым
  (`vendor/bin/pint --test <файлы>`).
- **`CHANGELOG.md` заблокирован для Read/Write** (`deny: Read(**/CHANGELOG.md)`) — долг записей за
  P1.2/P1.4/P2.1/P2.2/P2.3/P3.1-P3.6/P4.1 и далее. **F-P2.1-06 (P4.7) чинится КОНФИГОМ split-пайплайна**
  (D23), не наполнением файла; наполнение — Pending Work, сессия с доступом. `UPGRADE.md` (не
  `UPGRADING.md`, D23) — канонический сток breaking-changes (4 новые записи P4.1 уже там).
- **Локальная песочница exec-сессий не несёт coverage-драйвера (xdebug/pcov)** — ни `php` (8.5), ни
  `php8.4` не имеют модуля; `sudo`/`pecl` заблокированы harness'ом. Реальный измеренный MSI для
  P4.1's `infection.json5` (root + 4 per-package) НЕДОСТИЖИМ локально — используется провизорный
  порог 50/70 (advisory job, не блокирует CI). **Кто бы ни продолжал P4 в этой же песочнице —
  тот же лимит действует**; фактический MSI можно узнать только из реального прогона `mutation.yml`/
  сателлитных workflow в CI (там PHP 8.4 + xdebug).
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
  для happy-dom ≥16 в `vi.spyOn`-фикстурах (P4.1).
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
