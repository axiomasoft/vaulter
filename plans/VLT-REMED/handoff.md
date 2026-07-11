# HANDOFF — 2026-07-11 — after P4.1

**⭐ САМОЕ СВЕЖЕЕ (2026-07-11, коммит `02ee0df`) — P4.1 ЗАКРЫТ (🟢), эскалация F-P4.5-02 СНЯТА.**
По требованию владельца hocuspocus-мажор доведён до конца НА АКТУАЛЬНОЙ версии (не скип, не реверт).
RAG на момент исполнения (не из памяти): `npm view … dist-tags` → `@hocuspocus/* latest = 4.3.0`,
peer `yjs ^13.6.8` (latest 13.6.31), transport `crossws 0.4.9`; сдвиг API v4 сверен по context7
`/ueberdosis/hocuspocus` (`RELEASE_NOTES_V4`). Стек поднят `^2.15`/`^2.13`→`^4.3` согласованно во всех
трёх местах (`hocuspocus-server` server+extensions, `packages/vue` provider, `e2e` provider), `yjs`→`^13.6.8`.
Реальный сдвиг контракта v4 (из-за которого ночной бамп вешал тест): транспорт `ws`→`crossws`, `.listen()`
ушёл с ядра `Hocuspocus` на класс `Server`, frame-лимиты — config-ключ `websocketOptions` (форвардится в
`crossws({ serverOptions })`). `src/index.js` и `websocket-hardening.test.js` переписаны под ПУБЛИЧНЫЙ v4
API: тот же инвариант (oversized-кадр → close `1009` + негативный контроль + конфиг-шов + Throttle-guard),
НЕ `markTestSkipped`, ассерты не ослаблены, red-before подтверждён (снятие `websocketOptions` красит тест).
Docker-lock (`hocuspocus-server/package-lock.json`, tracked, `npm ci --omit=dev`) перегенерён под v4.
**Гейты:** `hocuspocus-server` `node --test` **51/51** + `node --check` — зелёные, БЕЗ hang (~1.6с);
backend-швы (JWT/webhook/store/Origin-gate/expiry) целы; `packages/vue` **102/102** + `build`; `e2e` `vite build`
(Playwright — live-стенд, вне песочницы); PHP-гейт из корня — `analyse` 0 · `test` **486** · `test:types` **100%** ·
`lint:check` красный только на `plans/AUDIT-VLT/artifacts/repro/**`. **P4 → 8/10 🟡** (остаются P4.9/P4.10 ⬜);
`Escalation Needed` P4.1 → `—`; closure-матрица: F-P4.5-02 «отложен»→«закрыт» (103/103 закрыт). Ниже —
предыдущий handoff после P5.3, актуален в части P4.9/P4.10/next.

---

**Актуализация после P5.3 (закрытие R-04/R-11 до конца):** контрольный второй круг ревью P5.2 нашёл ещё одну находку (**R-11** —
конфиг-шов TipTap заново открывал F-P1.11-01 через нераспознанное имя URL-атрибута) и подтвердил, что
**R-04** (markdown column-injection) была закрыта не до конца (codeBlock-фенс — да, `|` в ЦЕЛИ
ссылки/картинки — нет). Обе доведены до конца коммитами `c73116b` + `ff4d07d`; тем же `ff4d07d` найдена
и закрыта **R-12** (регресс сохранения легаси-контента — побочный эффект 1-го захода R-11).
`findings/P5.3-closure-matrix.md` актуализирована (§1, §3, §2 — 43 sha, 0 missing); вердикт
release-readiness **НЕ изменился** — «НЕ ГОТОВ К РЕЛИЗУ» (критерии 5/6 всё ещё `❌`, P4.9/P4.10 всё ещё
`⬜`). Гейт перепрогнан этой сессией: корень `analyse` 0 · `test` 486 passed · `test:types` 100 % ·
`lint:check` красный ровно на `plans/AUDIT-VLT/artifacts/repro/**`; `documents` — 205 passed/1 skipped;
`drive` — `test:types` 100 % (пакет фиксом не задет). Ниже — оригинальный handoff после P5.3, актуален
без изменений в части P4.9/P4.10/next steps.

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | **opus** |
| Thinking | **high** — межпакетный namespace-refactor — closed-enum escalation-триггер плана (D10/§Routing), необратимый blast-radius, дешёвой модели не отдаётся |
| Context | fresh session — handoff свежий, рекомендован `/clear` |
| Суть | Развернуть и исполнить **P4.9** (канон внутренней структуры пакетов: инвентарь шести пакетов + ADR `docs/adr/0017-*.md` + arch-тесты, кодирующие канон A — green на `core`, red/`todo` на `documents`/`drive`, **БЕЗ переноса кода**). ТЗ уже детализировано в `phases/P4.md` item P4.9 (16 полей заполнены, DoR закрыт при развёртке фазы P4). После P4.9 — **P4.10** (сам перенос, тоже opus/high, `php:namespace-refactor-safety`). Оба — под явным надзором владельца (необратимый blast-radius, D10). |

```
/task:plan-exec VLT-REMED P4.9
```

**~~Альтернативный следующий шаг: решение по F-P4.5-02~~ — СНЯТО 2026-07-11:** F-P4.5-02 (hocuspocus-мажор)
уже закрыт на актуальном `^4.3` (коммит `02ee0df`, см. банер вверху) — больше не требует решения владельца
и не висит в `MORNING-QUEUE.md` как открытый. Единственный оставшийся следующий шаг — P4.9 → P4.10.

**Done:**
- **Фазы P0 (3/3 🟢) · P1 (4/4 🟢) · P2 (3/3 🟠) · P3 (6/6 🟢) — ТЕРМИНАЛЬНЫ** (без изменений с прошлого handoff).
- **Фаза P4 (W4) — 8/10 🟡, НЕ терминальна:** P4.1 🟢 Done (F-P4.5-02 закрыт на актуальном мажоре `^4.3`
  2026-07-11, коммит `02ee0df`, эскалация снята); **P4.9 / P4.10 — ⬜ Not started.** P5 прошла поверх
  незакрытой P4 по директиве владельца (D26) — НЕ подменяет и НЕ закрывает их.
- **Фаза P5 (сквозной adversarial code review) — ТЕРМИНАЛЬНА (Status Board: `1/3`, `🔴 Blocked` —
  формальный статус фазы наследует 🔴 P5.1, plan-lint требует ровно это правило: «есть 🔴-item → статус
  фазы = 🔴 Blocked»; фаза при этом функционально завершена, все три item'а сданы):**
  - **P5.1 — 🔴 Blocked.** Работа сдана полностью: реестр `findings/P5.1-review-findings.md` — 10 находок
    (1 Blocker · 6 Major · 3 Minor), все `CONFIRMED`. Эскалация по R-01 разрешена решением владельца **D27**
    (+ следствия D28/D29/D30). Статус `🔴`/`Escalation Needed: yes` задним числом не переписывается (D11/D13).
  - **P5.2 — 🟠 Done with deviations.** Все 10 находок ревью закрыты ФИКСОМ (0 «принято») — коммиты
    `f7159e6` (G2), `8f96577` (G1), `4c609cb` (G3), `3f4687f` (G4), `8c77c40` (усиление форк-теста).
    Контрольное ревью P5.2 нашло ещё 4 дефекта (RV-01…RV-04) — все закрыты фиксом (коммиты `ff93042`,
    `7d22274`, `e412964`). **Второй круг того же контрольного ревью нашёл R-11** (конфиг-шов TipTap
    заново открывал F-P1.11-01) **и показал, что R-04 закрыта не до конца** (codeBlock-фенс — да, `|` в
    цели ссылки/картинки, сдвигающий GFM-таблицу, — нет) — обе доведены до конца коммитами `c73116b`
    (R-11, 1-й заход) и `ff4d07d` (R-11 fail-closed по значению + R-04 column-injection + попутно
    найденная и закрытая **R-12** — регресс сохранения легаси-контента). Итог оси ревью: **11/11 фикс,
    0 «принято»**.
  - **P5.3 — 🟢 Done.** Построена `plans/VLT-REMED/findings/P5.3-closure-matrix.md`: **103/103 находок
    AUDIT-VLT**, машинно сверено (`grep -c` = 103, `diff` множеств F-id пуст, `uniq -d` пуст, 45 sha
    проверены `git cat-file -e`, severity-счёт 3/37/58/5 совпадает с шапкой `REGISTER.md`). **Итог статусов:
    102 «закрыт» + 1 «отложен» (F-P4.5-02) = 103, 0 «принято»** _(на момент P5.3; обновлено 2026-07-11 →
    103 «закрыт», F-P4.5-02 доведён на `^4.3`, коммит `02ee0df`)_. Отдельная секция — диспозиция 10 находок
    ревью P5.1 (R-01…R-10, все ФИКС). Отдельная секция — честный перечень отложенного (P4.9/P4.10,
    провизорный MSI, `CHANGELOG.md`-наполнение, residual `npm audit high`, два пред-существующих БД-флейка,
    Q3/Q4). **Вердикт release-readiness: «НЕ ГОТОВ К РЕЛИЗУ»** — механический вывод из шести критериев
    (критерии 5 «измеренный MSI» и 6 «все items терминальны» — фактическое `❌`; критерий 3 «`UPGRADE.md`
    покрывает все breaking» — `⚠️` частично). Это ожидаемый и корректный результат (D26), не провал:
    три Blocker'а, ради которых план был начат, закрыты и дважды перепроверены сквозным ревью.
- **Гейт (D12) — последний зафиксированный полный прогон (P5.2-ПОПРАВКА, на `/usr/bin/php8.4`, CI-версия):**
  корень `analyse` 0 errors · `pest --ci` 486 passed · `test:types` 100% · `lint:check` красный **ровно** на
  `plans/AUDIT-VLT/artifacts/repro/**`. Сателлиты (`analyse` 0 · `test:types` 100% все): `documents`
  178/1 skipped, `drive` 165/5, `media` 72/5, `filament` 64/1. PG-лейн `drive` — 170 passed, 0 skipped,
  форк-тесты гонки PATCH↔sweep исполнены. P5.3 кода не меняла — этот прогон цитируется как доказательство
  критерия (4) вердикта, свежий прогон этой сессией не требовался (документирующий item).

**Remaining:**
1. **P4.9** (opus/high) — канон структуры + ADR + arch-тесты, БЕЗ переноса. ТЗ готово в `phases/P4.md`.
2. **P4.10** (opus/high, после P4.9) — сам namespace-refactor. ТЗ готово в `phases/P4.md`.
3. ~~**Решение владельца по F-P4.5-02** (hocuspocus-мажор)~~ — **ЗАКРЫТО 2026-07-11** (коммит `02ee0df`, `^4.3`).
4. После P4.9/P4.10 — `/task:plan-close` фазы P4, при желании владельца — переоценка release-readiness
   (новый item P5.4 или отдельный аудит).
5. Вне этого плана: архивация AUDIT-VLT (`/task:plan-close archive AUDIT-VLT`).

**Sources of truth:**
- `plans/VLT-REMED/findings/P5.3-closure-matrix.md` — **финальная closure-матрица 103 находок +
  диспозиция 10 находок ревью + вердикт release-readiness.** Единственный артефакт, переживающий план.
- `plans/AUDIT-VLT/findings/REGISTER.md` — 103 находки, ось матрицы, append-only (D13), НЕ править.
- `plans/VLT-REMED/phases/P4.md` items P4.9/P4.10 — готовое ТЗ следующего шага (16 полей заполнены).
- `plans/VLT-REMED/plan.md` — §Routing · Decision Log **D1–D30** · Status Board (P5 = `1/3`, `🔴 Blocked`
  формально, функционально терминальна) · Update Log.
- `plans/VLT-REMED/MORNING-QUEUE.md` — принятый долг (не переоткрывать); **F-P4.5-02 из него ИСКЛЮЧЁН** —
  закрыт на `^4.3` 2026-07-11 (коммит `02ee0df`), уже не отложенный долг.
- `plans/VLT-REMED/findings/P5.1-review-findings.md` + Completion Notes P5.2 (`phases/P5.md`) — 10 находок
  ревью, диспозиция, red-before-доказательства.

**Open risks:**
- **Строка «закрыт» без sha И без имени теста — это `НЕ ПОДТВЕРЖДЁН`.** Ни одна строка
  `P5.3-closure-matrix.md` такого дефекта не несёт (машинная сверка sha прошла 45/45).
- **P4.9/P4.10 — необратимый blast-radius.** Требуют opus/high и надзора владельца, не автономного прогона
  (D10/§Routing) — как и весь цикл ремедиации до этого места.
- **`packages/drive` `composer test` (весь пакет, SQLite) несёт ПРЕД-СУЩЕСТВУЮЩИЙ красный**
  (`StorageManagerTest.php`, панель `team`) — вне scope VLT-REMED, кандидат в отдельный item.
- В рабочем дереве могут жить НЕ относящиеся к VLT-REMED правки (`.claude/`, `plans/ACTIVE.md`,
  `plans/AUDIT-VLT/*`, `plans/VLT-HSEAM/`) из конкурентной сессии/хука — НЕ трогать.

**Workarounds/Deferred/Open questions:**
- **workarounds:** core-тесты — из корня (`vendor/bin/pest --filter=X`), сателлиты — `cd packages/<pkg>` ·
  PG: `php8.4` (root-CLI PHP 8.5 без `pdo_pgsql`), контейнер `agelto_postgres` (127.0.0.1:5455), БД
  `vaulter_test`, роль `vaulter` (пароль `secret`, как в CI-лейне `drive.yml`) · `lint:check` красный на
  `plans/AUDIT-VLT/artifacts/repro/**` — известный долг, гейт не блокирует.
- **deferred:** `CHANGELOG.md`-наполнение (deny-правило, D23) · **P4.9/P4.10** · ~~F-P4.5-02 (hocuspocus v4)~~
  **— закрыт 2026-07-11 на `^4.3`, коммит `02ee0df`, БОЛЬШЕ НЕ deferred** · реальный измеренный MSI (нет coverage-драйвера локально — провизорный порог 50/70) ·
  `npm audit high` в `packages/vue` (residual, `vite`/esbuild dev-server-only) · пред-существующий красный
  `StorageManagerTest` (панель `team`) · пред-существующий PG-флейк `NodeCommentSchemaTest` · count-зависимая
  хрупкость PG-харнесса (`MassAssignmentHardeningTest`) · построчная пере-сверка `UPGRADE.md` против Known
  Deviations всех 27 items (не грепом по F-id) · архивация AUDIT-VLT.
- **open_questions:** Q5 — `Resolved → D27`. Q1/Q2 — `Resolved → D1/D10`. Q3 (ротация `signing_secret`)
  и Q4 (абсолютный wall-deny) — `Decision pending`, не блокируют P4.9/P4.10.
