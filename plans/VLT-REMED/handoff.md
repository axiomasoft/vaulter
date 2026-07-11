# HANDOFF — 2026-07-11 — after P4.9

**⭐ САМОЕ СВЕЖЕЕ (2026-07-11, коммит `9fc9121`) — P4.9 ЗАКРЫТ (🟢). Канон структуры пакетов ОБЪЯВЛЕН
(кода НЕ перенесено — это P4.10).** Вариант A (D10: layer-first + доменный под-namespace, эталон
`vaulter-core`) формализован как ADR-0017 + arch-тесты (Pest Arch) как исполняемая спецификация.
Два машинно-проверяемых закона: **Law 1** — технический слой (`Actions`/`Models`/`Repositories`/
`Enums`/`Events`/`Data`/`Contracts`/`Http`) живёт первым сегментом под корнем пакета, НИКОГДА не
вложен в домен-модуль; **Law 2** — классы в слое сгруппированы доменным под-namespace, не свалены
плоско. `core` — эталон (оба закона green). `drive` расходится по Law 1 (`Uploads/*` несёт вложенное
слоевое поддерево → 10 offenders), `documents` — по Law 2 (плоские `Actions`/`Enums`/`Events`/`Data`/
`Repositories`/`Models` → 31 offender). Оба дивергентных теста помечены `->todo('unblocked by P4.10')`
(сьют зелёный), **red-before доказан прогоном** (снятие todo → FAIL с точным списком offenders).
`filament` — ЯВНОЕ исключение (Filament-конвенция), `media`/`permissions-azgard` — терпимо.
Инвентарь шести пакетов — `findings/P4.9-structure-inventory.md`. **Гейт:** корень `analyse` 0 ·
`test` 487/487 · `test:types` 100% · `lint:check` красный ТОЛЬКО на `plans/AUDIT-VLT/artifacts/repro/**`;
`documents`/`drive` `analyse` 0 / `test:types` 100%; `core` Arch 26 passed. `git diff packages/*/src`
ПУСТ. **P4 → 9/10 🟡** (остаётся P4.10 ⬜).

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | **opus** |
| Thinking | **high** — межпакетный namespace-refactor, closed-enum escalation-триггер плана (D10/§Routing), необратимый blast-radius, morph-алиасы; дешёвой модели не отдаётся |
| Context | fresh session — handoff свежий, рекомендован `/clear` |
| Суть | Исполнить **P4.10** (сам перенос по канону ADR-0017): `drive` — распустить `Uploads/*` в слои (`Actions/Uploads`, `Models/Uploads`, …); `documents` — ввести доменный под-namespace в плоские слои + разложить `Documents/`; `media`/`filament`/`permissions-azgard` — сверить/выровнять. Снять `->todo()` с канон-тестов P4.9 (они позеленеют). БЕЗ изменения поведения (зелёный сьют без правки тел тестов сверх `use`-строк). ОБЯЗАТЕЛЬНО: morph-алиасы (`Vaulter\Drive\Uploads\Models\UploadSession` полиморфна!), FQCN-дефолты `config/vaulter.php`, ссылки `docs/`, `UPGRADE.md`. ТЗ детализировано в `phases/P4.md` item P4.10 (16 полей). Дисциплина — `php:namespace-refactor-safety` (three-phase alias → use → hard rename; якорь на полный FQCN). Под явным надзором владельца (необратимый blast-radius, D10). |

```
/task:plan-exec VLT-REMED P4.10
```

**Done:**
- **Фазы P0 (3/3 🟢) · P1 (4/4 🟢) · P2 (3/3 🟠) · P3 (6/6 🟢) — ТЕРМИНАЛЬНЫ** (без изменений).
- **Фаза P4 (W4) — 9/10 🟡, НЕ терминальна:** P4.1–P4.8 🟢, **P4.9 🟢 Done** (коммит `9fc9121`,
  канон + ADR-0017 + arch-тесты, кода не перенесено); **P4.10 — ⬜ Not started** (последний item фазы,
  после него P4 терминальна). P5 прошла поверх незакрытой P4 по директиве владельца (D26) — НЕ
  подменяет и НЕ закрывает P4.10.
- **Фаза P5 (сквозной adversarial code review) — функционально терминальна** (Status Board `1/3`,
  `🔴 Blocked` формально наследует 🔴 P5.1; все три item'а сданы, 11/11 находок ревью закрыты фиксом,
  closure-матрица 103/103 построена). Без изменений с прошлого handoff.

**Remaining:**
1. **P4.10** (opus/high) — namespace-refactor по канону ADR-0017. ТЗ готово в `phases/P4.md` item P4.10.
   Красные-`todo` канон-тесты P4.9 (drive Law 1, documents Law 2) — целевое состояние: снять todo,
   позеленить переносом.
2. После P4.10 — `/task:plan-close` фазы P4 (тогда P4 терминальна), при желании владельца — переоценка
   release-readiness (новый item P5.4 или отдельный аудит) с учётом закрытого канона.
3. Вне этого плана: архивация AUDIT-VLT (`/task:plan-close archive AUDIT-VLT`).

**Sources of truth:**
- `docs/adr/0017-package-structure-canon.md` — **канон структуры (ADR, accepted)**: два закона,
  allow-list, исключение filament, BC-заметка на P4.10. **Целевое состояние переноса P4.10.**
- `plans/VLT-REMED/findings/P4.9-structure-inventory.md` — инвентарь шести пакетов «как есть» против
  канона = ТЗ объёма P4.10 (что и куда переносить).
- `plans/VLT-REMED/phases/P4.md` item P4.10 — готовое ТЗ следующего шага (16 полей заполнены).
- `plans/VLT-REMED/findings/P5.3-closure-matrix.md` — closure-матрица 103 находок + вердикт
  release-readiness («НЕ ГОТОВ К РЕЛИЗУ», критерий 6 «все items терминальны» станет ✅ после P4.10).
- `plans/AUDIT-VLT/findings/REGISTER.md` — 103 находки, append-only (D13), НЕ править.
- `plans/VLT-REMED/plan.md` — §Routing · Decision Log **D1–D30** · Status Board (P4 = `9/10 🟡`) ·
  Update Log.
- Канон-arch-тесты: `packages/core/tests/Arch/ArchTest.php` (Law-1 green-guard + Law-2 split-roots),
  `packages/drive/tests/Arch/ArchTest.php` (Law-1 `->todo()`), `packages/documents/tests/Arch/ArchTest.php`
  (Law-2 `->todo()`).

**Open risks:**
- **P4.10 — необратимый blast-radius (главный риск pre-mortem R-refactor):** забытый morph-алиас
  (полиморфная строка в БД держит старый FQCN → связь молча ломается в проде, тест на свежей БД зелён).
  Митигация обязательна: явно проверить `morphMap`/`getMorphClass` КАЖДОГО переехавшего Model +
  morph-инвариант-тест (PG-прогон). `Vaulter\Drive\Uploads\Models\UploadSession` — первый кандидат.
- **Доказательство неизменности P4.10 — зелёный сьют БЕЗ правки тел тестов сверх `use`-строк.** Если
  тест требует правки ассерта — перенос ЗАДЕЛ поведение → СТОП + разбор, НЕ подгонять (Code Guidance).
- **Канон arch-тестов — исполняемый, не косметика:** первый вариант Law-1 helper был инертным no-op
  (`RecursiveIteratorIterator` в LEAVES_ONLY выдаёт только файлы) — пойман и исправлен на `SELF_FIRST`
  ДО коммита (P4.9 Known Deviations 2). При добавлении арх-правил в P4.10 проверять, что правило
  реально краснеет на инъекции нарушения, а не «зеленеет по недосмотру».
- **`packages/drive` `composer test` (весь пакет, SQLite) несёт ПРЕД-СУЩЕСТВУЮЩИЙ красный**
  (`StorageManagerTest.php`, панель `team`) — вне scope VLT-REMED. (Целевой Arch-прогон это не задевает.)
- В рабочем дереве живут НЕ относящиеся к VLT-REMED правки (`.claude/`, `plans/ACTIVE.md`,
  `plans/AUDIT-VLT/*`, `plans/VLT-HSEAM/`) из конкурентной сессии/хука — НЕ трогать.

**Workarounds/Deferred/Open questions:**
- **workarounds:** core-тесты — из корня (`vendor/bin/pest packages/core/tests/Arch`), сателлиты —
  `cd packages/<pkg> && vendor/bin/pest tests/Arch` · Pest `->todo()`-тело НЕ исполняется даже с
  `--todos` (0 assertions) — red-before снимается временным удалением `->todo()`, прогоном, восстановлением ·
  PG: `php8.4` (root-CLI PHP 8.5 без `pdo_pgsql`), контейнер `agelto_postgres` (127.0.0.1:5455), БД
  `vaulter_test`, роль `vaulter` (пароль `secret`) · `lint:check` красный на
  `plans/AUDIT-VLT/artifacts/repro/**` — известный долг, гейт не блокирует.
- **deferred:** **P4.10** (namespace-refactor) · `media`/`permissions-azgard` канон-arch-тесты (P4.10
  Scope) · `permissions-azgard` без `tests/Arch/` вовсе · `CHANGELOG.md`-наполнение (deny-правило, D23) ·
  реальный измеренный MSI (провизорный 50/70) · `npm audit high` в `packages/vue` (residual,
  dev-server-only) · пред-существующий красный `StorageManagerTest` (панель `team`) · пред-существующий
  PG-флейк `NodeCommentSchemaTest` · построчная пере-сверка `UPGRADE.md` · архивация AUDIT-VLT.
- **open_questions:** Q5 — `Resolved → D27`. Q1/Q2 — `Resolved → D1/D10`. Q3 (ротация `signing_secret`)
  и Q4 (абсолютный wall-deny) — `Decision pending`, P4.10 не блокируют.
