# HANDOFF — 2026-07-14 — after P2.5

**Next:** `P2.6` — CI-лейн: `.github/workflows/load.yml` (`smoke-perf` на PR, `regression`
nightly). Обычный item-by-item exec (оркестрация не заявлена).

| Параметр | Значение |
|:--|:--|
| Model | **sonnet** (§3 Routing: P2.1–P2.6, P2.8 — sonnet/medium, Exec = `plan-exec`; решения фазы уже приняты в дизайне — D18/D22–D28) |
| Thinking | **medium** — item транскрибирует `slo-gates.md` §7/§6.2 в GitHub Actions workflow; профиль (S0), пороги (класс A блокирующий/B advisory), правило baseline-меток — уже зафиксированы |
| Context | **continue (/clear) — manual item** |
| Суть | `.github/workflows/load.yml`: job `smoke-perf` (PR, профиль S0, ≤6 мин, класс A блокирующий) + job `regression` (nightly на `main`, медиана-3, обновление baseline отдельным PR с меткой `perf-rebaseline`) |

```
/task:plan-exec VLT-LOAD P2.6
```

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка, RAG-провенанс инструментов, мастер-план (D1–D11).
- **P1 🟢 Done (5/5)** — `reference-env.md` · `operations-catalog.md` (47 `OP-nn`) ·
  `slo.md` (81 `SLO-nn`) · `slo-gates.md` (97/96 гейтов, класс A/B, P8-минимум) ·
  `slo-signoff.md`.
- **P2 — в работе, 5/8** — `phases/P2.md`, DoR пройден.
- **P2.1 🟢** — `harness.md` (9 разделов, транскрипция D22–D28).
- **P2.2 🟢** — стенд `load/` живьём (compose 7 сервисов, env-профили S0/S/M, SUT nginx+php-fpm).
- **P2.3 🟢** — seed (`load:seed`/`load:activity-write`, объёмный+«грязный» конверт, снапшот/restore).
- **P2.4 🟢** — `load/k6/` — библиотека + 7 сценариев + `smoke.js`, `load/scripts/run.sh`.
- **P2.5 🟢 Done** (2026-07-14, `/task:plan-exec`): `load/gates/{thresholds.js,perf-assert.py,
  coverage.py,_slo_table.py}` — двухплоскостной гейт (D18): `thresholds.js` — k6
  `options.thresholds` (G-1..G-3 + streaming class-A SLO-40..54, тир-числа буквально из
  `slo-gates.md` §3.2, S0 наследует тир S); `perf-assert.py` — server plane (RSS/фон/
  class-B baseline vs `summary.json`+Prometheus+baseline, вердикты pass/fail/
  not-checkable/incomparable/known-red); `coverage.py` подтверждает 96/96 строк
  §3 имеют место вычисления (15 также в thresholds.js). `load/k6/lib/summary.js` —
  `handleSummary` пишет `meta` (`commit`/`profile`/`tier`/`env_fingerprint`/`dataset_id`)
  в новый writable mount `/results` (`/scripts` — `:ro`). Первый baseline-артефакт —
  `load/baselines/smoke-perf-S0.json`, снят с живого контрактного smoke (tier S0).
  Живая проверка на docker-стенде: `k6 inspect` все 8 файлов, `coverage.py` → 96/96,
  `perf-assert.py` → `pass=0 fail=0 not-checkable=66 incomparable=0 known-red=3`, exit 0;
  негативный тест (`tus_patch.S0.med` временно 25→10000 на `mixed.js`) → k6 явно называет
  breached-метрику, exit 99, значение возвращено. Живьём найден и исправлен реальный баг:
  кастомные метрики (`stream_throughput_mibs`/`ttfb_ms`) несут только базовый `TAGS`
  (без `op`) — фильтр порога с `op` никогда не матчил ни одного сэмпла (исправлено в
  `thresholds.js`, `data_sent`/`data_received` `op` сохраняют — они читают теги самого
  HTTP-запроса). Стенд снесён (`down -v`) после проверки — детали см. phases/P2.md P2.5
  Completion Notes/Known Deviations (5 деклараций: новый парсер `_slo_table.py`, `smoke.js`
  получил `handleSummary` без `options.thresholds`, новый writable volume `/results` в
  compose, `run.sh` дополнен стейджингом `thresholds.js`+`ENV_FINGERPRINT`/`GIT_COMMIT_SHA`,
  G-4/G-5 перенесены в `perf-assert.py`, тег-словарь `slo-gates.md` адаптирован под
  реальные теги P2.4-сценариев).

**Remaining:**
1. **P2 exec:** P2.1 ✅ → P2.2 ✅ → P2.3 ✅ → P2.4 ✅ → P2.5 ✅ → **P2.6 (CI)** →
   **P2.7 (CV/δ — opus/high, `ЗАПУСК ВРУЧНУЮ`)** → P2.8 (DoD-прогон на тире S).
2. **P8-минимум** — сразу после P2 (гейт P3–P7, D9): без серверных метрик бо́льшая часть
   класса A вычисляется как `not-checkable` — подтверждено живьём в P2.5 (66/69
   применимых строк not-checkable/known-red на smoke-perf-профиле сегодня).
3. **P3–P7** — скелеты; разворачиваются `/task:plan-design VLT-LOAD Pn` в порядке
   `P8-минимум → {P3,P4,P5,P6} → P7`.
4. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM);
   срез заблокирован **D11** (VLT-MUT drive ≥ 95). `perf-assert.py`'s `KNOWN_RED_IDS`
   уже готов признать SLO-46-48 небблокирующими до фикса.

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §1 контекст, §3 Routing, §4 доска (P2 5/8), §5 Decision Log
  (D1–D28), §7 Findings-долг (L-01…L-08), §8 Обсуждение.
- `plans/VLT-LOAD/phases/P2.md` — ТЗ восьми items; P2.1–P2.5 Completion Notes (P2.4/P2.5
  несут живые фиксы и Known Deviations — читать перед P2.6).
- `plans/VLT-LOAD/harness.md` — SSOT механики харнесса.
- `plans/VLT-LOAD/slo-gates.md` — §3 (96 строк гейта, SSOT для `_slo_table.py`), §4
  (baseline/δ), §7 (три профиля — вход для P2.6).
- `load/README.md` — как поднять/снести стенд + seed + k6-сценарии + **новая секция
  «Gate: thresholds + perf-assert (P2.5)»** (usage, находки калибровки, ограничение
  container-peak) + предупреждение про `LOAD_APP_PORT`.
- `load/gates/` — `thresholds.js`/`_slo_table.py`/`perf-assert.py`/`coverage.py`.
- `load/baselines/smoke-perf-S0.json` — первый baseline (S0, commit `d71071f`).
- `load/k6/lib/summary.js` — `handleSummary`/`summaryOutputs`.
- RAG: `findings/P0-rag-load-tooling.md` · `findings/P1-rag-slo-ci-gate.md` ·
  `findings/P1.4-rag-k6-threshold-syntax.md` · `findings/P2-rag-k6-harness.md`.
- `open-questions.md` — Q1–Q3 разрешены, **Q4 открыт** (lab-box под тир M).

**Open risks:**
- **`LOAD_APP_PORT` обязан быть ОДИНАКОВЫМ на каждый `docker compose`-вызов** (см. P2.4 —
  не переоткрыто здесь, тот же риск).
- **Тир M — публичное обещание без прогона (Q4/D25).**
- **δ = 10 % не измерен** — до P2.7 относительный гейт класса B не имеет доказанной
  чувствительности; `perf-assert.py` уже реализует сравнение с этим δ как гипотезой.
- **S0-числа streaming class-A (унаследованные от тира S) не откалиброваны на смоук-
  масштабе** — `single_shot`/`download` реально ломались в живом негативном тесте P2.5
  по причинам, не связанным с искусственной правкой (маленький файл/короткое discovery-
  окно). P2.6 (CI-профиль `smoke-perf`) обязан либо использовать более длинный/крупный
  прогон, либо P2.7/P2.8 обязаны откалибровать эти числа заново под реальный S0/S объём —
  иначе CI-гейт будет флапать не из-за регрессии, а из-за самой методики.
- **k6-порог на метрике/теге без сэмплов в окне прогона фейлится, а не пропускается** —
  P2.6 обязан гарантировать, что `smoke-perf`-профиль реально прогоняет ВСЕ гейтуемые
  операции в своём окне (иначе гейт красный по конструкции, не по регрессии).
- **Бо́льшая часть гейта в P2 — `not-checkable`** (серверные метрики появляются в P8);
  подтверждено численно (66/69 применимых строк).
- **Ожидаемый красный в P2.6/P2.8:** S3-`complete()` (L-01) — `perf-assert.py` уже
  печатает `known-red`, не `fail`, для SLO-46-48.
- **Тиры S/M живьём не прогнаны в P2.5** (только S0) — как и в P2.2–P2.4.

**Workarounds/Deferred/Open questions:**
- **Workarounds:** пиковая память — `collect-container-stats.sh` (`memory.peak` per
  контейнер), явный «грубый заменитель» до P8; G-4/G-5 (доля от другого счётчика) —
  вычисляются в `perf-assert.py`, а не в k6-thresholds (RAG-8b — k6 не умеет сравнивать
  две метрики между собой); тег-словарь `slo-gates.md` (`tus_patch` и т.п.) транслируется
  в реальные `scenario`/`op`-теги P2.4 через `SCENARIO_TAG_MAP`.
- **Deferred:** roadmap-кандидаты `slo-signoff.md` §3 (в т.ч. lab-box под тир M); полный
  набор P8-метрик; калибровка streaming-порогов под смоук-масштаб (см. Open risks);
  составные PromQL-выражения в `perf-assert.py` (сегодня `not-evaluated`, метрик всё
  равно нет); живой прогон seed'а+сценариев на тирах S/M.
- **Open questions:** **Q4** — где снимаются авторитетные числа тира M (`Decision
  pending`, не блокирует P2).
