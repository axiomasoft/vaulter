# HANDOFF — 2026-07-12 — after P5.6

**⭐ САМОЕ СВЕЖЕЕ (2026-07-12) — `P5.6` ОСТАНОВЛЕН ЭСКАЛАЦИЕЙ (🔴): диспатч №1 `mutation.yml` НЕ измерил
MSI — Infection падает на расхождении путей JUnit-отчёта, независимо от `APP_KEY` (P5.5)/D32/D34/D35.**

**Что сделано.** Предусловие P5.6 (зелёный шаг coverage из P5.5) подтверждено. Локальный неотправленный
коммит `2525fe9` (закрытие P5.5) — запушен (`de3007a..2525fe9`). Диспатч №1 `gh workflow run mutation.yml
--ref remediation/audit-vlt` → `run id 29200519816` (headSha `2525fe9`), job зелёный (`|| true` ещё на
месте — ожидаемо для диспатча №1). Шаг `Generate coverage for Infection` — зелёный (487 passed, 0
`MissingAppKeyException`, P5.5 держит). Шаг `Run Infection` — **упал ДО начала мутационного прогона**:
`CoverageChecker.php:240` / `JUnitReportLocator.php:108` — не найден JUnit-отчёт по пути
`build/coverage/junit.xml`; coverage-шаг `mutation.yml` пишет его в `build/junit.xml` — расхождение путей
между двумя шагами лейна, замаскированное `|| true` на КАЖДОМ прошлом прогоне (включая `29199532724` из
P5.5, где проверялся только шаг coverage). MSI/Covered Code MSI в логе не напечатаны — метрики физически
нет. Code Guidance P5.4 (наследуется P5.6 дословно) прямо запрещает закрывать критерий 5 по прогону, где
Infection не отработал — item остановлен, `infection.json5`/`mutation.yml` НЕ тронуты. Правка пути
`--log-junit` выходит за Scope Included P5.6 (ограничен D32 — только снятием `|| true`) → эскалация, а не
самостоятельная починка. Полная фактура — `plans/VLT-REMED/findings/P5.6-msi-measurement.md`.

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | **opus** |
| Thinking | **high** — неясные acceptance: расширять ли Scope Included P5.6 (правка `--log-junit`-пути) или заводить отдельный item; плюс ретроспективная переоценка «все прошлые зелёные mutation-прогоны маскировали тот же сбой» — архитектурное решение о доверии к прошлым closure-записям |
| Context | continue (/clear) — ручной item |
| Суть | Решить: (а) починка расхождения путей JUnit в `.github/workflows/mutation.yml` — в рамках расширенного Scope P5.6 или новый item (по аналогии с P5.4→P5.5/D33); (б) как трактовать факт, что ВСЕ прошлые «зелёные» прогоны `mutation.yml` (включая любые предыдущие closure-упоминания «гейт настроен») никогда не измеряли MSI — нужна ли ретроспективная правка `P5.3-closure-matrix.md` §4 п.4 формулировки. После решения — новый item (или расширенный P5.6) повторяет диспатч, измеряет MSI, закрывает критерии 4/5. |

```
/task:plan-design VLT-REMED P5
```

**Done:**
- **Фазы P0 (3/3 🟢) · P1 (4/4 🟢) · P3 (6/6 🟢) · P4 (10/10 🟢) — ТЕРМИНАЛЬНЫ.** P2 (3/3 🟠) — терминальна.
- **Фаза P5 — 2/6 🟢:** P5.1 (🔴 — наследие собственной находки-эскалации, разрешена D27), P5.2 (🟠 —
  10+1 находок ревью закрыты фиксом), P5.3 (🟢 — closure-матрица 103/103), P5.4 (🔴 — остановлен
  эскалацией, разрешена D33/D34/D35), P5.5 (🟢 — CI-паритет доказан диспатчем, run `29199532724`),
  **P5.6 (🔴 — остановлен НОВОЙ эскалацией: расхождение путей JUnit в `mutation.yml`, MSI не измерен)**.
- `findings/P5.3-closure-matrix.md` — 103/103, 0 дублей, 0 пропусков, все sha живые; вердикт «НЕ ГОТОВ К
  РЕЛИЗУ» (критерий 5 ❌ по НОВОЙ причине — не отсутствие `xdebug`, а сам Infection физически не
  запускается на CI; критерий 4 не переоценивался — P5.6 до него не дошёл). **НЕ пересобран этой сессией.**
- `UPGRADE.md` — построчно сверен против Known Deviations всех 29 items дважды, пропусков не осталось.

**Remaining:**
1. **Решение владельца по P5.6** (см. Next) — расширить Scope Included P5.6 на починку `--log-junit`-пути
   ИЛИ завести новый item; затем повторить диспатч, измерить MSI, закрыть критерии 4/5 в
   `findings/P5.3-closure-matrix.md`.
2. **Формальный seal фазы P4** (`/task:plan-close VLT-REMED P4`, sonnet/low) — подтверждающий шаг
   (RUNBOOK 2.1), независим от P5.
3. После закрытия MSI-измерения → закрытие фазы P5 → `/task:plan-close VLT-REMED` → `archive` (RUNBOOK 2.4/2.5).
4. Вне вердикта по существу: `CHANGELOG.md`-наполнение (сессия с доступом к файлу, deny-правило, D23).

**Sources of truth:**
- `plans/VLT-REMED/phases/P5.md` — ТЗ item **P5.6** (полное, включая ESCALATION-блок) + Completion Notes
  P5.1–P5.6.
- `plans/VLT-REMED/findings/P5.6-msi-measurement.md` — доказательная база остановки P5.6 (run `29200519816`,
  расхождение путей JUnit, ретроспективный риск по прошлым «зелёным» прогонам).
- `plans/VLT-REMED/findings/P5.5-ci-parity-appkey.md` — доказательная база D33–D35 (закрыта P5.5, остаётся
  валидной — эта находка ортогональна `APP_KEY`).
- `plans/VLT-REMED/findings/P5.4-msi-ci.md` — механика измерения (триггеры лейнов, семантика
  `workflow_dispatch --ref`, формат вывода MSI) — унаследованная фактура, часть остаётся валидной (п.1–3),
  п.6 (предсказуемый режим отказа) дополнен новым классом отказа.
- `plans/VLT-REMED/findings/P5.3-closure-matrix.md` — 103 находки + шесть критериев вердикта
  (пересборка №4; критерий 5 = ❌ по новой причине, критерий 4 не переоценён).
- `plans/VLT-REMED/plan.md` — §Routing (P5.6 — sonnet/medium + `gh`/сеть, факт исполнения) · Decision Log
  **D1–D35** · Status Board (P5 = `2/6`) · Update Log.
- `plans/RUNBOOK.md` — durable-цепочка закрытия планов (Часть 2 — VLT-REMED; НЕ синхронизирована этой
  сессией — работа не завершена).
- `plans/archive/AUDIT-VLT/findings/REGISTER.md` — 103 находки, append-only (D13), НЕ править.

**Open risks:**
- **НОВОЕ:** `.github/workflows/mutation.yml` несёт расхождение путей между шагами `Generate coverage for
  Infection` (пишет JUnit в `build/junit.xml`) и `Run Infection` (Infection ищет JUnit в
  `build/coverage/junit.xml`) — Infection физически не запускает ни одной мутации, `|| true` маскирует это
  на каждом прогоне. Вероятно, справедливо для ВСЕХ прошлых «зелёных» прогонов лейна с момента P4.1 —
  `P5.3-closure-matrix.md` §4 п.4 («провизорный порог, но НЕ измеренный факт») формально верна, но
  недооценивает степень: гейт не просто advisory, он никогда физически не измерял MSI.
- `tests.yml` на `main` красен последние 5 прогонов по порогу покрытия (`79.2 % < 80 %`) — вероятный
  честный ❌ критерия 4 на ветке (D35: не чиним, фиксируем). Диспатч `tests.yml` на ветке для критерия 4
  (D34) — НЕ запущен этой сессией (заблокирован тем же предусловием «обе метрики из ОДНОГО прогона»).
- Ветка `remediation/audit-vlt` **запушена** в `origin` (D31, актуальный HEAD — `2525fe9`); `--force`,
  push в `main`, теги, PR — ЗАПРЕЩЕНЫ.
- Пред-существующий SQLite-флейк `StorageManagerTest` (панель `team`) — не встретился на диспатче №1
  P5.6, но может всплыть на будущих диспатчах того же шага coverage.
- Residual `npm audit --audit-level=high` в `packages/vue` (1 high, `vite`/esbuild dev-server-only).
- В рабочем дереве живут НЕ относящиеся к VLT-REMED правки конкурентных сессий/хуков (`.claude/**`,
  `plans/ACTIVE.md`, `plans/VLT-CANON/`, `plans/VLT-HSEAM/`, `.swissknife.json`) — НЕ трогать.

**Workarounds/Deferred/Open questions:**
- **deferred:** `CHANGELOG.md`-наполнение (deny-правило, D23) · residual `npm audit high` в `packages/vue`
  · два пред-существующих БД-флейка (SQLite `StorageManagerTest`, PG `NodeCommentSchemaTest`) · подъём
  покрытия до порога 80 % (D35 — отдельное решение владельца вне этого плана).
- **open_questions:** Q3 (ротация `signing_secret`) и Q4 (абсолютный wall-deny) — `Decision pending`, не
  блокируют P5.6. Q1/Q2/Q5 — `Resolved → D1/D10/D27`. **НОВЫЙ:** входит ли починка пути `--log-junit` в
  `.github/workflows/mutation.yml` в scope плана VLT-REMED, или это отдельная CI-инфра-находка вне плана —
  `Decision pending`, владелец.
