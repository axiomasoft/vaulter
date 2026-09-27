# B07 — независимый аудит R02–R08

Дата: 2026-09-27. Основание: раздел 7 [REMAINING-WORK.md](../REMAINING-WORK.md), строка B07 и условия допуска B08. Проверен dirty worktree при HEAD `9bcded42e1b7fae30f8cce61b8f3ceab09868e07`; HEAD сам по себе не является fingerprint всех проверенных исходников.

**Disposition: B07 не пройден; допуск к зависимому B08 не выдан.** Входные условия полного аудита отсутствуют: текущий cold score R07 и измерения R05/R06/R08 ещё не получены. Выполнен ограниченный аудит доступного кода и исторических raw-артефактов. Найдены два дефекта R05 capture/verdict; ниже отдельно перечислены недостающие доказательства. Отсутствие измерений не представлено как новый установленный дефект продукта.

Записан только этот отчёт. Код, R-статусы, пороги, raw runs и исторические verdicts не изменены. Live, mutation, reset и PostgreSQL/Pest-прогоны не запускались. B02 и R09/R10 не переаудировались; P6 telemetry не использована как замена доказательствам B07.

## Открытые findings

### B07-01 — Major: live-patch-overlap не запускает sweeper

**Места:** `load/gates/p5_live_matrix.py:396` (`execute_cell`), `:693` (`run_k6`), `:804` (`create_shared_upload`), `:943` (`verdict_for`); `load/k6/scenarios/concurrency-live.js:129`; каталог `load/gates/p5_concurrency.py:81`.

Для `product-realistic.sweeper-overlap.live-patch-overlap.n8` диспетчер вызывает только `run_k6`. Вызов `run_sweeper` предусмотрен для отдельной плоскости `dirty-cardinality-probe`. JavaScript для этой surface делает PATCH общей сессии; параллельного Artisan sweep в этом пути нет. Setup создаёт обычную новую сессию и меняет `created_by`, но не доказывает её попадание в sweepable set или удержание PATCH-lock во время sweep.

**Воспроизведение:** при вызове настоящего `execute_cell` с моками внешнего I/O и восемью успешными PATCH-ответами получено `k6_calls=1`, `sweeper_calls=0`; настоящий `verdict_for` вернул `evidence=valid, slo=green`. Запись файлов была замокана. Это тест диспетчеризации в памяти, не runtime proof и не новый product verdict. Присутствующее `barrier_skew_ns=0` также не доказывает фактическое пересечение операций.

**Влияние:** будущая GREEN-ячейка с этим именем не установит безопасность sweep при активном PATCH, сохранность staged bytes или race/retry поведение R08. Успешный PATCH без sweep не является такой проверкой.

**Disposition:** OPEN, исправление в load/R05; согласование с финальным R08-путём после измеренной причины R06. Для закрытия нужны отрицательный контроль «sweeper не запускался / операции не пересеклись», настоящий concurrent sweep/PATCH с идентификацией сессии и проверкой итогового состояния, затем независимый re-review. Изменение drive этим finding не разрешается.

### B07-02 — Major: заявленное N не сверяется с фактическим участием actors

**Места:** `load/gates/p5_live_matrix.py:784` (`response_evidence`), `:760` (сбор результатов), `:943` (`verdict_for`); `load/gates/p5_concurrency.py:295` (валидация cell).

`response_evidence` проверяет диапазон actor ID и равенство HTTP counters собранным ответам, но не полноту набора actors. `run_k6` записывает `actor_ids=list(range(base, base+n))` из конфигурации. `verdict_for` достаточно одного успешного operation; структурный validator не сверяет фактическое участие с N.

**Воспроизведение без I/O:**

```python
responses = p5.response_evidence(
    'P5_HTTP|101|operation|200||END',
    {'p5_status_2xx': {'values': {'count': 1}}}, 101, 8,
)
cell = next(c for c in p5.gate.catalog()
            if c['surface'] == 'collab-issuance' and c['n'] == 8)
result = p5.verdict_for(cell, [
    {'statuses': {'2xx': 1}, 'http_responses': responses},
])
```

Фактический результат: один actor `[101]`, заявлено N=8, `evidence=valid, slo=green`. Это отрицательный контроль capture/verdict в памяти, не утверждение, что реальный k6 уже потерял семь VU, и не полный matrix run.

**Влияние:** неполный либо усечённый захват может быть принят как успешная N=8/N=200 нагрузка. Назначение разных ID в исходнике само по себе не доказывает, что все заявленные actors участвовали в измеренном опыте. R05/R15 нельзя принимать по такому локальному GREEN.

**Disposition:** OPEN, load/R05. Нужна сверка observed actors и завершённых/прерванных итераций с заявленным N, с учётом явных setup/create failures, и отрицательные контроли отсутствующего actor/неполной итерации. Успех создания upload не должен заменять успешный operation. Затем новые затронутые product cells и re-review.

### B07-03 — Blocker допуска: нет новых интерпретируемых R04/R05 baseline

**Места:** [R03](R03.md), [R04](R04.md), [R05](R05.md); `plans/VLT-LOAD/artifacts/P3/query-trace.jsonl`, `pgss-top20.json`, `explain/`; исторический P5 run `2aeca12f-6180-4595-8466-31397c4ed15d`.

- R03: текущий readback `load/results/summary.json` даёт 31 982,349285 ms при объявленных 60 s, `full_window=false`, `proof_ready=false`; class-B набор пуст, sparse trends unevaluated. Это старый `smoke-perf`, а не новый lab/S0 full-window. Исторический tier-S throughput RED и отсутствие измеренной атрибуции SLO-40/43/49/52 сохраняются.
- R04: trace действительно 0 bytes; по join имён EXPLAIN к 20 query IDs — 14 `ok`, 6 `rejected`. Новый capture, trace-backed N+1/OP↔SQL отчёт и actual ANALYZE ещё не представлены. Рабочий verifier опубликованных P3 artifacts отвергает их с `TRACE_EMPTY`.
- R05: подготовлены новые actors/status/lock/pool capture, но carrier прямо оставляет live proof unevaluated. Исторический byte-stable P5 успешно проходит старую структурную проверку; это не проверка выполнения новых capture-контрактов и не закрытие L-P5-01/02/04 или L-07. Новых affected product cells на проверенном source snapshot нет.

**Влияние:** явное условие B08 «R04/R05 valid evidence» не выполнено. B07-01/02 также должны быть закрыты до использования новых R05 результатов. Недостаток измерений не исправляется положительным exit offline tests.

**Disposition:** OPEN. До live R04 нужен scoped commit либо точный source archive/digest dirty harness по §7; затем новые immutable runs под отдельными IDs, валидный trace/plans и R05 status/actor/held-window evidence. R03 throughput остаётся RED/неатрибутированным до собственного измеренного disposition. Уже записанное отдельное измерение R14 этим аудитом не переписывается и не отменяется автоматически.

### B07-04 — Blocker допуска: причина dirty-20k и R08 SLO-70 не измерены

**Места:** [R06](R06.md); `plans/VLT-LOAD/artifacts/P5/sweeper/dirty-cardinality-probe.sweeper-overlap.dirty-20k.n1.a{1,2,3}.json`; `packages/drive/src/Console/SweepUploadsCommand.php:61`; строка R08 в плане.

Raw dirty-20k: все три `exit_code=1`, `remaining=20000`, elapsed 0,317 / 0,180 / 0,166 s. Dirty-1m: `exit_code=1`, `remaining=1000000`, 0,184 s. В этих файлах нет stderr, PHP peak или batch series. Они подтверждают исторический failure/нулевой progress, но не конкретную причину. Наличие `$sessions->sweepable($now)->get()` не доказывает, что именно materialization вызвала exit 1.

R06 capture из `ecb9a26` сохраняет stderr/stdout, exit, PHP peak и nullable progress/metrics; его offline-контроли прошли. Нового dirty-20k runtime с этой диагностикой нет. `R08.md` отсутствует, строка R08 остаётся waiting; нового SLO-70/race/retry evidence нет. PHP `memory_get_peak_usage(true)` не следует называть process RSS.

**Влияние:** причина для выбора R08 поведения неизвестна; historical SLO-70 RED не закрыт, SLO-72/tier-L не доказан.

**Disposition:** OPEN. Сначала эксклюзивное disposable dirty-20k измерение с диагностикой и классификацией failure; затем только обоснованный R08 scope, race/retry проверки и новый tier-S SLO-70 run. D42 допускает R08 code после установленной причины R06 до cold R07 ≥95, но не разрешает угадывать причину или принимать финальный drive gate.

### B07-05 — Blocker допуска: R07 не имеет текущего cold ≥95 и финального workflow proof

**Места:** [R07](R07.md), [R07 checks](R07-checks/code-20260927/checks.json), `.github/workflows/drive.yml:149`, [R02 drive raw](R02-checks/raw/drive-pgsql/summary.json).

Независимый readback R07 raw logs подтверждает focused 36 passed / 174 assertions, PG suite 217 passed / 2 risky / 739 assertions, exit 0. Контроль удаления `freed_bytes` действительно дал 1 failed, exit 1. Это доказательство чувствительности конкретного обычного теста, не прохождение финального mutation workflow. Два risky теста не объявлены passed.

Все 73 drive `src` файла побайтно соответствуют R02 и R07 source manifests. Исторический sequential baseline остаётся 48,90% (820 tested + 3 timeout из 1683 по raw summary). Нового cold прогона после подготовки тестов нет. Текущий workflow использует sequential `--min=43`; обязательный ≥95 и regression-failing gate при этом пороге пока не доказаны. Старый P4 `--parallel` 100%/25,77 s не принимается.

**Влияние:** вход B07 «current R07 cold score» и финальный drive/D11 gate не выполнены. Совпадение product source не переносит результат старого тестового набора на новые тесты и не создаёт score ≥95.

**Disposition:** OPEN, R07. Нужен cold sequential PG run и требуемый regression-failing workflow gate на согласованном source/test snapshot; после R08 — актуализация proof для изменённого drive source. Порог 43 в ходе аудита не менялся.

## Что подтверждено в доступных доказательствах

- **R02:** raw logs и runner подтверждают cold sequential `--covered-only --clear-cache`, без `--parallel` и без baseline `--min`; PG использует отдельные `vaulter_r02_*_test`. Filament 59,26%, media SQLite 63,75% / PG 67,37%, drive 48,90%, core SQLite 63,25%. Documents PG (2 failed / 215 passed) и core PG (ошибки suite) завершились exit 2 до mutation; core union unevaluated. Более поздние R16–R19 результаты не подменяют этот исторический snapshot.
- **Media union:** независимо пересчитан по ID из двух `cache-results.json`: 430 IDs, 290 killed, 140 escaped, 67,44%. Log totals и число cache IDs не тождественны (например, drive 1683 против 1682; media SQLite 411 против 410, PG 426 против 425). Здесь сохранены исходные определения: per-driver reported score и отдельный unique-ID union; отсутствующим IDs не присвоен выдуманный статус. Полнота per-mutant cache относительно log остаётся ограничением архива, а не новым score.
- **R05:** исходник выдаёт отдельные VU actors и setup actor; пишет реальные status/Retry-After, отвергает cross-actor/missing/mismatched counters. Сессия с `created_by=NULL` — явная system-minted fixture; это не proof разрешённого доступа другого пользователя к owned session. Empty locks остаются empty; post-only collector sample отвергается. 429-only collision остаётся unconfirmed, create-only progress не даёт GREEN. Эти положительные offline-контроли не устраняют B07-01/02.
- **R06:** локальные контроли подтверждают сохранение diagnostics при failed post-probe, null при отсутствующей/reset histogram, остановку matrix после Docker timeout с неизвестным product exit. PHP shutdown peak проверен отдельным локальным success/exception subprocess. Это не sweeper runtime на 20k строк.
- **R07:** просмотрены новые контракты outbox/payload, copy accounting, tus headers и staged-upload boundaries, raw success/failure logs и source manifests. Cold mutation, полнота покрытия и Location residual не объявлены закрытыми.

## Проверки, выполненные этим аудитом

Все Python-команды запускались с `-B`/`PYTHONDONTWRITEBYTECODE=1`; temporary fixtures offline-тестов не публиковались как evidence runs. Node preflight: pnpm 9.15.0, Node v24.12.0; локальный PHP helper: PHP 8.4.1. Это среда audit controls, не среда архивного PG suite.

| Проверка | Фактический результат |
|:--|:--|
| `PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=load/gates python3 -B -m unittest load/gates/test_r03_summary.py load/gates/test_r04_explain_bindings.py load/gates/test_r04_trace_validation.py load/gates/test_r05_capture.py load/gates/test_r05_r06_capture.py load/gates/test_r06_capture.py` | 28 tests, OK, exit 0 |
| Два дополнительных in-memory controls B07-01/02 | Оба воспроизвели дефект; контрольный скрипт exit 0. Это не product PASS |
| `python3 -B load/gates/r03_summary.py --summary load/results/summary.json --declared-duration 60s` | exit 2; full_window/proof_ready false |
| `python3 -B load/gates/p3_workload.py verify-artifacts --artifact-dir plans/VLT-LOAD/artifacts/P3` | exit 1; `TRACE_EMPTY` |
| `python3 -B load/gates/p3_profile.py verify --run-dir plans/VLT-LOAD/artifacts/P3` | exit 1; `UNKNOWN_FIELDS` у опубликованного workload manifest. Этот другой schema-validator не использован вместо `p3_workload.py` |
| `python3 -B load/gates/p5_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P5 --prior-digest fb6343c5504b3f95aabe1db12260643877fcef2d41cead8ef225523621f1efdf` | exit 0, digest совпал; только историческая структурная/byte-stability проверка |
| Raw R02/R07 readback, media ID-union и SHA-256 сверки | Результаты выше; новые PG/mutation прогоны не выполнялись |

`test_p5_concurrency.py` не запускался: его setup перезаписывает repository fixtures. Сохранённые результаты прежних checks не названы новыми прогонами.

## Source identity и сохранность

R05 manifest содержит более ранний `p5_live_matrix.py`; его отличие объясняется последующим R06. Все четыре записи [R06 source manifest](R06-checks/source-sha256.json) совпали с worktree. Все 73 записи [R07 source manifest](R07-checks/code-20260927/source-sha256.json) и [R02 drive manifest](R02-checks/source-drive.json) совпали; digest последнего `f95726f2179412ee125f7d099e0651c92c906d060570b2eecad46145b8ca6586`.

Проверенные текущие SHA-256 ключевых источников:

```text
2368e4461fea338c289e2b2c03ef96beeaf0ea06a22d68b2a61d018aa7551eb9  plans/REMAINING-WORK.md
04d27a3dda1504e301a2a949d0d5c752f96e515945b749aceed7c0af56ac6b9e  load/gates/r03_summary.py
1158b5eedb6d63d17dc836926c090c5fb6d7b1ef05d4b6134bf2eef91721d4ee  load/gates/thresholds.js
f73073e6a6b1357ed3dd12c5e65be59f36062f52b8749a9b55ee698f926784c2  load/gates/p3_workload.py
9622b51cdfe6998ba32058fd1215f14f13822f9617b2a3131ed713ad2ae4901b  load/gates/p3_profile.py
a496e9c5d10eba78034c823dacc08bfcea915b42a8eed88a4bf7a9ef80637c13  load/gates/p3_explain.py
e6dff65aeb37bcaffa8162128c78cef3d49e2286b65ac237e0c5c371e0d3176d  load/gates/p3_trace_report.py
356f4444dc4038d0059ba91eb5f179c8798741bb39da57f1a36e1a62e2e1d888  load/app/Observability/QueryTraceRecorder.php
e1201981f223105d56b068449fb750aa64ab544b42554f7622cfba1a02ab347b  load/gates/p5_live_matrix.py
2e36e5e0eaf19413daf5655cdc531ca63898ac14b2d4fc26d74a0b99fa7e95f2  load/gates/p5_concurrency.py
bf5b75a4b115f2ba839bf2df73de2555c1cb8632ac2a05185715461f37c85c59  load/k6/scenarios/concurrency-live.js
5642aaf6feaec602f8fc9626f6ef04ecca9558796a66cd6a148b1208be29ce41  load/scripts/p5-php-peak.php
94210031ee9718e2aea46f7d6e0d9a8260e640c27baa54733b94f5d448f2926e  packages/drive/src/Console/SweepUploadsCommand.php
ceb97cbc774c2c91a75babf834312ee394cb9c5b6b3403e49f6d5acf9fd2257a  .github/workflows/drive.yml
9937e79fb85e919a875ca5452fe9c528bdc4ac4b6e21bafccfd47733ea14aa4f  packages/drive/tests/Feature/MutationContractsTest.php
ff9d5784f4f07685bef22244352fe7698f8d0208c4d145076bcc4133c09f2466  packages/drive/tests/Feature/TusResponseContractsTest.php
866fe4b7ed646b77b14af5f409581b438ff267347d5c23271f60c81b01056e77  packages/drive/tests/Uploads/StagedUploadBoundaryTest.php
736e4c480c453384297d181df840a9fea91fc7f5fec9165a3546866119a02abd  packages/drive/tests/Drive/CopyMutationContractsTest.php
```

Контроль сохранности: 7196 существующих tracked/untracked nonignored файлов в `load/`, `packages/`, `plans/` и `AGENTS.md`, `composer.json`, `tests/Pest.php`, исключая этот отчёт. SHA-256 от сортированных `path + NUL + SHA256(file bytes)` до/после проверки: `c0f846c3df11fc1babcc86d9ce3a56bcdaf54cd26f6851d98da5692ea018bf9d`. Включены R-статусы, B02 audit и исторические raw/verdicts. Это проверка сохранности ограниченного дерева, не fingerprint всего runtime deployment.

## Условия повторного аудита

B07-01/02 исправляются в исходном load/R05 scope с отрицательными контролями. Затем нужны недостающие immutable measurement evidence R04/R05, установленная причина R06, обоснованный R08 и SLO-70 proof, текущий cold R07 ≥95 с финальным workflow gate. Повторный независимый аудит должен проверить source/run identity и фактические held-window/actor/sweeper доказательства. До этого зависимый B08 не получает допуск; live baseline и неизмеренные R остаются pending/unevaluated без изменения их строк этим отчётом.
