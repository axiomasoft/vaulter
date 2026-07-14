# HANDOFF — 2026-07-14 — after P2.6

**Next:** `P2.7` — CV на `ubuntu-latest` (5 прогонов) → δ и вердикт по классу B.
**Exec = manual** (§3 Routing: opus/high — δ — единственное число гейта класса B, которое
сегодня «рабочая гипотеза»; цена ошибки делает выбор δ решением, не механикой). ЗАПУСК ВРУЧНУЮ.

| Параметр | Значение |
|:--|:--|
| Model | **opus** |
| Thinking | **high** — δ = 10% — рабочая гипотеза (D19), нужно измерить CV и решить, подтвердить или пересмотреть |
| Context | **continue (/clear) — ручной item** |
| Суть | 5 прогонов `smoke-perf` на реальном `ubuntu-latest` (`load/harness/load.yml`, `workflow_dispatch` на ветке `load/harness`, push разрешён D27), один commit/dataset_id; посчитать CV по ключевым метрикам; подтвердить/пересмотреть δ; вынести вердикт — класс B становится блокирующим на S0 при CV < 10% (§4.4 условие 2) |

```
Ты исполняешь item P2.7 мастер-плана VLT-LOAD (план-протокол, `plans/VLT-LOAD/`) —
модель opus, effort high (`Exec = manual`, `plan.md` §3 Routing).

Прочитай СТРОГО в этом порядке (без повторного чтения, без «на всякий случай»):
1. plans/VLT-LOAD/plan.md — Context, Execution Rules, §3 Routing, §4 Status Board
2. plans/VLT-LOAD/handoff.md (этот файл) — Done/Remaining/Open risks
3. plans/VLT-LOAD/phases/P2.md — Phase Context + весь item P2.7 (Intent…Escalation Needed)
4. Required Reads item'а P2.7: slo-gates.md §4 целиком, slo-signoff.md §2,
   findings/P1-rag-slo-ci-gate.md (RAG-6)

Исполни P2.7 по протоколу plan-exec (skill `plan-protocol`, только если нужно уточнение
схемы закрытия — не читай его вслепую, схема уже описана в этом промпте и в plan.md).
Item — Exec=manual, поэтому НЕ используй /task:plan-exec (он пинит sonnet/medium и по
routing-гейту обязан отклонить ручной item); работай напрямую в этой сессии.

Ключевое из P2.6 (только что закрыт, 🟠 Done with deviations — важно для P2.7):
- `.github/workflows/load.yml` существует, job `smoke-perf` реален и рабочий
  (`load/scripts/run.sh smoke-perf` — gated-профиль mixed.js+thresholds.js).
- ЖИВЬЁМ ПОДТВЕРЖДЕНО: на честных S0-числах `thresholds.js` streaming class-A
  (`single_shot`/`tus_patch`) органически КРАСНЫЕ на ≤256 MiB фикстуре S0/60s окне — это
  находка P2.6 (Pending Work), НЕ P2.7. Твои 5 прогонов почти наверняка увидят те же
  красные класса A вперемешку с реальным CV классa B — не путай эти два сигнала: класс A
  красный — это pre-existing, не то, что ты меряешь. Единственное, что тебе нужно из
  прогонов — CV (σ/μ) латентных метрик и throughput/checks-rate по Implementation Rules
  item'а (5 прогонов, разнесённых по времени, каждый — один и тот же commit/dataset_id).
- Median-merge шага `regression` (инлайн-python в load.yml) НЕ верифицирован на реальном
  ubuntu-latest — если по ходу P2.7 заметишь баг в нём на реальном раннере, это П2.7 может
  чинить (тот же файл, смежная зона), либо завести как находку — на твоё решение.

Закрой P2.7 по полному циклу 1–8 (plan-protocol): статус → работа строго в Scope Included
→ Validation → ОБЯЗАТЕЛЬНОЕ закрытие (item-коммит по Files item'а → bookkeeping плана →
handoff → plan-lint --baseline HEAD → bookkeeping-коммит) → Expected Output формат.
```

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка, RAG-провенанс инструментов, мастер-план (D1–D11).
- **P1 🟢 Done (5/5)** — `reference-env.md` · `operations-catalog.md` (47 `OP-nn`) ·
  `slo.md` (81 `SLO-nn`) · `slo-gates.md` (97/96 гейтов, класс A/B, P8-минимум) ·
  `slo-signoff.md`.
- **P2 — в работе, 6/8** — `phases/P2.md`, DoR пройден.
- **P2.1 🟢** — `harness.md` (9 разделов, транскрипция D22–D28).
- **P2.2 🟢** — стенд `load/` живьём (compose 7 сервисов, env-профили S0/S/M, SUT nginx+php-fpm).
- **P2.3 🟢** — seed (`load:seed`/`load:activity-write`, объёмный+«грязный» конверт, снапшот/restore).
- **P2.4 🟢** — `load/k6/` — библиотека + 7 сценариев + `smoke.js`, `load/scripts/run.sh`.
- **P2.5 🟢** — `load/gates/{thresholds.js,perf-assert.py,coverage.py,_slo_table.py}` —
  двухплоскостной гейт (D18), первый baseline (`load/baselines/smoke-perf-S0.json`).
- **P2.6 🟠 Done with deviations** (2026-07-14, `/task:plan-exec`, item-commit `d9965e7`):
  `.github/workflows/load.yml` — job `smoke-perf` (PR, tier S0, класс A блокирующий) +
  `regression` (nightly на `main`, медиана-3 через инлайн-python median-merge) +
  `baseline-guard` (метка `perf-rebaseline` на диф `load/baselines/*`). `load/scripts/run.sh`
  получил gated-профили `smoke-perf`/`regression` — привязка `thresholds.js` (P2.5) к
  сустейнед-прогону `mixed.js` (P2.4) через **stdin-пайп** k6-скрипт (`--workdir /scripts`,
  реэкспорт `setup`/`interactive`/`upload`/`download` под `options.thresholds`+`handleSummary`),
  НИ один из файлов P2.4/P2.5 не тронут (Files item'а — только `load.yml`/`run.sh`/`README.md`).
  Живая проверка: `actionlint` (docker `rhysd/actionlint`) — 0 issues; `run.sh smoke-perf` на
  живом S0-стенде реально абортирует по классу A (exit 99) на честных числах `thresholds.js`
  (streaming-калибровка тира S не держит S0-масштаб — уже задокументированная находка P2.5/
  handoff, ПОДТВЕРЖДЕНА, не починена — Implementation Rules item'а прямо запрещают трогать
  числа); `coverage.py` 96/96, `perf-assert.py` pass=0/fail=0/not-checkable=66/known-red=3
  exit 0. Отсюда 🟠, не 🟢 — Validation-пункт «Local dry-run … зелёный» не выполнен буквально
  (материальное отклонение по Status-rule), при том что сам механизм гейта (правильно
  вычисляет, правильно абортирует, правильно пишет артефакты) подтверждён — детали см.
  phases/P2.md P2.6 Completion Notes/Pending Work/Known Deviations.

**Remaining:**
1. **P2 exec:** P2.1 ✅ → P2.2 ✅ → P2.3 ✅ → P2.4 ✅ → P2.5 ✅ → P2.6 ✅ (🟠) →
   **P2.7 (CV/δ — opus/high, ЗАПУСК ВРУЧНУЮ, следующий шаг)** → P2.8 (DoD-прогон на тире S).
2. **P8-минимум** — сразу после P2 (гейт P3–P7, D9): без серверных метрик бо́льшая часть
   класса A вычисляется как `not-checkable` — подтверждено живьём в P2.5/P2.6 (66/69
   применимых строк not-checkable/known-red на smoke-perf-профиле сегодня).
3. **P3–P7** — скелеты; разворачиваются `/task:plan-design VLT-LOAD Pn` в порядке
   `P8-минимум → {P3,P4,P5,P6} → P7`.
4. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM);
   срез заблокирован **D11** (VLT-MUT drive ≥ 95). `perf-assert.py`'s `KNOWN_RED_IDS`
   уже готов признать SLO-46-48 небблокирующими до фикса.
5. **Калибровка S0-масштаба (новое, из P2.6 Pending Work):** streaming class-A числа
   (`single_shot`/`tus_patch`) унаследованы от тира S и не держатся на ≤256 MiB/60s окне
   S0 — до калибровки (P2.7/P2.8 или решение владельца о выделенной S0-строке)
   `smoke-perf` на PR будет органически красным по классу A, не по регрессии кода.

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §1 контекст, §3 Routing, §4 доска (P2 6/8), §5 Decision Log
  (D1–D28), §7 Findings-долг (L-01…L-08), §8 Обсуждение.
- `plans/VLT-LOAD/phases/P2.md` — ТЗ восьми items; P2.1–P2.6 Completion Notes (P2.4/P2.5/
  P2.6 несут живые фиксы и Known Deviations — читать перед P2.7).
- `plans/VLT-LOAD/harness.md` — SSOT механики харнесса.
- `plans/VLT-LOAD/slo-gates.md` — §3 (96 строк гейта, SSOT для `_slo_table.py`), §4
  (baseline/δ — вход P2.7), §7 (три профиля).
- `load/README.md` — как поднять/снести стенд + seed + k6-сценарии + гейт + **новая секция
  «CI: how the gate behaves (P2.6)»** (какой job что валит, что советует, известные красные).
- `load/gates/` — `thresholds.js`/`_slo_table.py`/`perf-assert.py`/`coverage.py`.
- `load/baselines/smoke-perf-S0.json` — baseline (S0, commit `d71071f`) — P2.7 сравнивает
  CV именно по прогонам ЭТОГО гейта, но δ/baseline остаются P2.5-артефактом.
- `.github/workflows/load.yml` — `smoke-perf`/`regression`/`baseline-guard` (P2.6).
- `load/scripts/run.sh` — `smoke`/`scenarios/*.js`/`smoke-perf`/`regression` (P2.4/P2.5/P2.6).
- RAG: `findings/P0-rag-load-tooling.md` · `findings/P1-rag-slo-ci-gate.md` ·
  `findings/P1.4-rag-k6-threshold-syntax.md` · `findings/P2-rag-k6-harness.md`.
- `open-questions.md` — Q1–Q3 разрешены, **Q4 открыт** (lab-box под тир M).

**Open risks:**
- **`LOAD_APP_PORT` обязан быть ОДИНАКОВЫМ на каждый `docker compose`-вызов** (P2.4/P2.6 —
  несовпадение форсирует recreate `app` и молча стирает seed; CI сам не подвержен — там
  `LOAD_APP_PORT` не варьируется между шагами одного job'а).
- **Тир M — публичное обещание без прогона (Q4/D25).**
- **δ = 10% не измерен — это буквально предмет P2.7.** `perf-assert.py` уже реализует
  сравнение с этим δ как гипотезой.
- **S0-числа streaming class-A (унаследованные от тира S) органически красные —
  ПОДТВЕРЖДЕНО живьём в P2.6** (не только гипотеза из P2.5): 60s сустейнед-прогон на живом
  S0-стенде дважды подряд абортировал по `single_shot`/`tus_patch`/`http_req_failed`.
  P2.7/P2.8 обязаны либо откалибровать эти числа, либо владелец принимает выделенную
  S0-строку — иначе `smoke-perf` навсегда красный по методике, не по регрессии.
- **k6-порог на метрике/теге без сэмплов в окне прогона фейлится, а не пропускается** —
  `mixed.js` (P2.6's потребитель) должен реально прогонять каждую гейтуемую операцию.
- **Полный CI job (checkout+build+seed+run+assert+teardown) не измерен на реальном
  `ubuntu-latest`** — локально (тёплый кэш) укладывается в ~3 мин, но GH-раннер холоднее;
  бюджет «≤6 мин» правдоподобен, не подтверждён (P2.6 Pending Work).
- **Median-merge `regression`-job'а (инлайн-python) не прогонялся на реальном раннере** —
  только на 3 копиях одного summary.json локально (P2.6 Pending Work → входит в P2.7).
- **Бо́льшая часть гейта в P2 — `not-checkable`** (серверные метрики появляются в P8);
  подтверждено численно (66/69 применимых строк).
- **Ожидаемый красный в P2.6/P2.8:** S3-`complete()` (L-01) — `perf-assert.py` уже
  печатает `known-red`, не `fail`, для SLO-46-48.
- **Тиры S/M живьём не прогнаны в P2.5/P2.6** (только S0).

**Workarounds/Deferred/Open questions:**
- **Workarounds:** пиковая память — `collect-container-stats.sh` (`memory.peak` per
  контейнер), явный «грубый заменитель» до P8; G-4/G-5 (доля от другого счётчика) —
  вычисляются в `perf-assert.py`, а не в k6-thresholds (RAG-8b); тег-словарь `slo-gates.md`
  транслируется в реальные `scenario`/`op`-теги P2.4 через `SCENARIO_TAG_MAP`; gated-профили
  P2.6 (`smoke-perf`/`regression`) собираются в `run.sh` stdin-пайпом, не новым файлом в
  `load/k6/` — чтобы не выйти за Files ни одного из P2.4/P2.5/P2.6.
- **Deferred:** roadmap-кандидаты `slo-signoff.md` §3 (в т.ч. lab-box под тир M); полный
  набор P8-метрик; калибровка streaming-порогов под смоук-масштаб (см. Open risks); живой
  прогон seed'а+сценариев на тирах S/M; починка `-e TIER`/`-e DURATION` для non-gated
  `run.sh`-веток (пред-существующий пробел, найден в P2.6, не в Files/Scope).
- **Open questions:** **Q4** — где снимаются авторитетные числа тира M (`Decision
  pending`, не блокирует P2).
