# HANDOFF — 2026-07-14 — after P2.7

**Next:** `P2.8` — DoD-прогон на тире **S** (lab-box): baseline 1× + доказательство потоковости
5 ГиБ + фиксация известного красного L-01.
**Exec = manual** (§3 Routing: P2 items — `plan-exec`, но P2.8 гоняется на lab-box'е с cpuset,
это не CI-механика; дизайн замера — решение). ЗАПУСК ВРУЧНУЮ.

| Параметр | Значение |
|:--|:--|
| Model | **opus** |
| Thinking | **high** — RSS-тренд по трём размерам файла и вердикт «стримит/не стримит» — решение, а не транскрипция |
| Context | **continue (/clear) — ручной item** |
| Суть | Прогон профиля `lab` на тире S (7 физических P-ядер, `reference-env.md` §1.1, условия L-1…L-7); RSS × {5 MiB, 500 MiB, 5 GiB} × {генератор, app} — тренд обязан быть плоским на tus-пути; `s3_complete` на 500 MiB/5 GiB — зафиксировать пик и код ответа (L-01, не обходить); `load/baselines/lab-S.json` (медиана-3); `findings/P2.8-baseline-S.md`; Phase Handoff P2 |

```
Ты исполняешь item P2.8 мастер-плана VLT-LOAD (план-протокол, `plans/VLT-LOAD/`) —
модель opus, effort high (Exec = manual, `plan.md` §3 Routing).

Прочитай СТРОГО в этом порядке:
1. plans/VLT-LOAD/plan.md — Context, Execution Rules, §3 Routing, §4 Status Board, §5 (D25, D29)
2. plans/VLT-LOAD/handoff.md (этот файл) — Done/Remaining/Open risks
3. plans/VLT-LOAD/phases/P2.md — Phase Context + item P2.8 целиком + Completion Notes P2.7
4. Required Reads item'а P2.8 + findings/P2.7-cv-runner.md (что вскрыл реальный раннер)

Item — Exec=manual: работай напрямую в сессии, НЕ через /task:plan-exec.

Ключевое из P2.7 (важно для P2.8):
- Гейт на раннере был сломан тремя способами, все починены (composer install; k6 не мог
  писать summary.json; `compose run` пересоздавал app и СТИРАЛ seed перед замером). На
  lab-box'е эти дефекты не воспроизводятся — но `load/scripts/run.sh` теперь ходит с
  `--no-deps`, учитывай при ручных прогонах.
- Класс B — advisory навсегда на S0 (D29). На тире S (профиль `lab`) он блокирующий —
  и именно там δ = 10% ещё не проверен. P2.8 обязан снять CV латентности на cpuset-стенде:
  это единственное оставшееся измерение, которое может подтвердить или отменить δ.
- Ожидаемые красные, НЕ чинить: streaming-пороги на S0 (калибровка масштаба), L-01
  (S3 complete() в RAM). Три открытых хвоста из P2.7 Pending Work (download-throughput
  без сэмплов; квантование порога error-rate; окно 32s вместо 60s) — вход в P2.8.

Закрой P2.8 по полному циклу plan-protocol: статус → работа строго в Scope Included →
Validation → item-коммит по Files → bookkeeping плана → Phase Handoff P2 → handoff →
plan-lint --baseline HEAD → bookkeeping-коммит.
```

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка, RAG-провенанс инструментов, мастер-план (D1–D11).
- **P1 🟢 Done (5/5)** — `reference-env.md` · `operations-catalog.md` (47 `OP-nn`) ·
  `slo.md` (81 `SLO-nn`) · `slo-gates.md` (96 гейтов, класс A/B, P8-минимум) · `slo-signoff.md`.
- **P2 — 7/8** (5 🟢 + P2.6/P2.7 🟠).
- **P2.1 🟢** — `harness.md`. **P2.2 🟢** — стенд `load/` (7 сервисов, S0/S/M).
  **P2.3 🟢** — seed (объёмный + «грязный» конверт, снапшот/restore).
  **P2.4 🟢** — `load/k6/` (библиотека + 7 сценариев + smoke). **P2.5 🟢** — двухплоскостной гейт
  (`thresholds.js` + `perf-assert.py` + `coverage.py`) + первый baseline.
- **P2.6 🟠** — `.github/workflows/load.yml` (smoke-perf / regression / baseline-guard) +
  gated-профили `run.sh`. Красный по классу A на честных S0-числах (калибровка, не регрессия).
- **P2.7 🟠 Done with deviations** (2026-07-14, manual opus/high): **5 прогонов на реальном
  `ubuntu-latest`** (один commit `e7ddf9a`, один `dataset_id`, разнос ~15 мин) →
  **CV латентности 26–56 %** (`http_req_duration` p95 = 34.6 %, худшая `iteration_duration`
  p95 = 56.3 %) против **CV шумоустойчивых 0.4–4 %** ⇒ **D29: класс B остаётся advisory**, δ = 10 %
  не подтверждён (и не повышен — это была бы декорация), §4.4 получил предусловие.
  `findings/P2.7-cv-runner.md`. По пути починены **шесть дефектов лейна**, невидимых локально:
  нет `composer install`; `up.sh` возвращается до того, как SUT слушает; k6 не мог писать
  `summary.json` (uid 12345 vs root-каталог); **`compose run` пересоздавал `app` → `migrate:fresh`
  стирал seed перед замером** (гейт печатал 86 % 404-ов как «класс A красный»); в `regression`
  цикл умирал на первом красном прогоне (median-merge пропускался) и не мог записать медиану
  (тихо подсовывал summary последнего прогона). **Долг P2.6 закрыт: median-merge верифицирован
  на реальном раннере** (run `29368703568` — медиана из 3 реально попадает в `summary.json`,
  `meta.runs=3`). На `main` положен инертный регистрационный стаб `load.yml` (иначе
  `workflow_dispatch` не существует — 404).

**Remaining:**
1. **P2 exec:** P2.1…P2.7 ✅ → **P2.8 (DoD-прогон на тире S — следующий шаг, ЗАПУСК ВРУЧНУЮ)**.
2. **P8-минимум** — сразу после P2 (гейт P3–P7, D9): без серверных метрик 66 из 96 строк гейта
   вычисляются как `not-checkable` — подтверждено пятью прогонами подряд.
3. **P3–P7** — скелеты; разворачиваются `/task:plan-design VLT-LOAD Pn` в порядке
   `P8-минимум → {P3,P4,P5,P6} → P7`.
4. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` — весь файл в RAM); срез
   заблокирован **D11** (VLT-MUT drive ≥ 95).
5. **Калибровка S0-масштаба** (P2.6/P2.7): streaming class-A числа унаследованы от тира S
   (наблюдаемая пропускная S0 — 5–15 MiB/s против порогов 25–100) ⇒ `smoke-perf` на PR
   органически красный по методике, не по регрессии.

**Sources of truth:**
- `plans/VLT-LOAD/plan.md` — §3 Routing, §4 доска (P2 7/8), §5 Decision Log (D1–D29), §7 Findings-долг.
- `plans/VLT-LOAD/phases/P2.md` — ТЗ восьми items; Completion Notes P2.1–P2.7 (P2.6/P2.7 несут
  Known Deviations — читать перед P2.8).
- `plans/VLT-LOAD/findings/P2.7-cv-runner.md` — **числа CV, три дефекта раннера, побочные находки**.
- `plans/VLT-LOAD/slo-gates.md` — §4 (baseline, δ, CV-guard, условия §4.4 + новое предусловие), §7 профили.
- `load/README.md` · `load/gates/` · `load/baselines/smoke-perf-S0.json` ·
  `.github/workflows/load.yml` · `load/scripts/run.sh` (теперь с `--no-deps`).
- Ветка `load/harness` в origin = рабочая ветка (push разрешён D27); на `main` — только
  инертный стаб `load.yml` (регистрация `workflow_dispatch`).

**Open risks:**
- **δ = 10 % не проверен там, где он работает** — класс B блокирующий только в `lab` (тир S),
  а CV тира S не измерен. Прямое задание P2.8.
- **Класс B нечем вычислять и в `lab`, если не эмитить сабметрики**: `thresholds.js` объявляет
  латентные пороги только в `lab` — там сабметрики появятся, но в `smoke-perf`/`regression`
  их нет (54/54 `not-checkable`). Любое решение «включить класс B в CI» требует сперва починить
  эмиссию, и это правка чисел гейта (владелец/P2.8), а не механика.
- **Порог класса A `http_req_failed{class:interactive}` (0.005) флапает на S0** — квантование:
  в 32-секундном окне ~145 интерактивных запросов, разрешение 1/145 ≈ 0.69 % > порога.
- **`stream_throughput_mibs{scenario:download}` — 0 сэмплов** во всех 5 прогонах; его пороги
  падают «за отсутствием данных». Причина не установлена (вход в P2.8).
- **Гейт никогда не отрабатывает заявленное 60-секундное окно** (abortOnFail на 30-й секунде,
  пока класс A красный) — фактическое окно 32 с.
- **`LOAD_APP_PORT` обязан быть одинаковым на каждый `docker compose`-вызов** (шире: любое
  расхождение конфига пересоздаёт `app` и стирает seed; `--no-deps` закрывает главный путь,
  но не отменяет правило).
- **Тир M — публичное обещание без прогона (Q4/D25).** **Тиры S/M живьём не прогнаны** (только S0).
- **Ожидаемый красный:** S3-`complete()` (L-01) — `perf-assert.py` печатает `known-red`.

**Workarounds/Deferred/Open questions:**
- **Workarounds:** пиковая память — `collect-container-stats.sh` (до P8); G-4/G-5 считает
  `perf-assert.py`, не k6; gated-профили собираются в `run.sh` stdin-пайпом; на `main` —
  инертный стаб `load.yml` ради регистрации `workflow_dispatch` (снимается мержем харнесса).
- **Deferred:** roadmap-кандидаты `slo-signoff.md` §3 (в т.ч. lab-box под тир M); полный набор
  P8-метрик; калибровка streaming-порогов под смоук-масштаб; живой прогон seed'а на тирах S/M;
  `-e TIER`/`-e DURATION` для non-gated веток `run.sh`.
- **Open questions:** **Q4** — где снимаются авторитетные числа тира M (`Decision pending`).
