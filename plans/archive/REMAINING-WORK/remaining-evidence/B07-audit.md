# B07-02 — focused offline re-review после R05-B07-02

Дата: 2026-09-28. Основание: шаг 19a [REMAINING-WORK.md](../REMAINING-WORK.md), явно заказанный corrective/recheck. База primary: `3059c8e`, исходный worktree чистый. Независимый read-only reviewer **GPT-6 Astra** проверил final source/test snapshot, fingerprints ниже. Этот раздел записал primary executor по заключению reviewer; оба прежних отчёта B07-R и B07 ниже сохранены побайтно как история.

**B07-02 CLOSED в bounded persisted participation admission scope.** Оставшихся дефектов в заказанном scope независимый reviewer не выявил. Structural admission `r05-b07/v2` в `p5_concurrency.py` и `verdict_for` в `p5_live_matrix.py` теперь вызывают существующий participation validator для обоих product plane. Устранено воспроизведённое принятие worst-case N=200 с одним actor/HTTP 200 и `participation=None`. Primitive/dirty-cardinality contracts, capture workload, thresholds и исторические verdicts сохранены.

**Это evidence gate closure.** Stored run `adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe` проходит read-only replay всех **56 cells** (23 realistic, 16 worst-case, 15 primitive, 2 dirty); рассчитанные verdicts совпадают с сохранёнными. Historical collab HTTP 403, collision unconfirmed, SLO-70 RED, current-source R03/R04 RED/REJECTED/unevaluated и deferred mutation/live proof gaps не переписаны. Product GREEN, live/B08 admission и новые SLO measurements не выданы. Dispositions остальных findings из B07-R остаются прежними.

## Проверки primary executor

Все Python commands: `PYTHONDONTWRITEBYTECODE=1`, `python3 -B`; focused unittest: `PYTHONPATH=load/gates`. Репозиторные raw files только читались, отрицательные/синтетические inputs заменялись в памяти через `Path.read_text`.

| Команда / проверка | Фактический результат |
|:--|:--|
| `python3 -B -m unittest test_r05_capture.R05PersistedParticipationTest.test_verdict_rejects_incomplete_product_participation test_r05_capture.R05PersistedParticipationTest.test_structural_rejects_incomplete_product_participation` до исправления gate | exit 1: 22 ожидаемых worst-case failures; realistic controls уже отклонялись |
| `python3 -B -m unittest -v test_r05_capture.R05PersistedParticipationTest test_r05_capture.R05CaptureTest.test_missing_actor_and_setup_only_are_rejected_by_verdict test_r05_capture.R05CaptureTest.test_complete_iterations_join_actual_responses_and_metrics test_r05_capture.R05CaptureTest.test_no_sweep_and_no_overlap_controls` на final snapshot | **8 tests, exit 0**; 110 rejection checks в новой regression class |
| `python3 -B load/gates/p5_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe --prior-digest a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074` | verifier прошёл; raw digest совпал |
| Direct read-only `verdict_for` для 15 primitive и 2 dirty cells | exit 0: все сохраняют `not-a-product-slo` без participation |
| `git diff --check` | exit 0 |

Во время подготовки положительного create-failure control один промежуточный запуск дал fixture timestamp mismatch: representative realistic cell содержит attempt 3, а начальный control подставлял attempt 1. Test readback исправлен на representative attempt; это не gate defect и не переписанное raw evidence. Выше указан реально прошедший final snapshot.

## Независимый focused re-review — GPT-6 Astra

Reviewer независимо прочитал diff, контракт finding и raw run; собственный script не использует helper regression class. Точные команды из корня:

```bash
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=load/gates python3 -B -m unittest -v test_r05_capture.R05PersistedParticipationTest test_r05_capture.R05CaptureTest.test_missing_actor_and_setup_only_are_rejected_by_verdict test_r05_capture.R05CaptureTest.test_complete_iterations_join_actual_responses_and_metrics
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=load/gates python3 -B /tmp/b07_02_independent_recheck.py > /tmp/b07_02_independent_recheck.log
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=load/gates python3 -B load/gates/p5_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe --prior-digest a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074
git diff --check -- load/gates/p5_concurrency.py load/gates/p5_live_matrix.py load/gates/test_r05_capture.py
```

Все четыре команды: **exit 0**, unittest **7 tests**. Собственные controls/replay:

- **110 rejection checks:** 11 вариантов × 2 product plane × structural/verdict/full-cell-only/full-attempt-only/full-both. Все отклонены через `EvidenceInvalid`: missing actor; absent/null/empty participation; incomplete started/completed/iteration count; aborted iteration; setup-only; create201 без operation; исходное one-actor воспроизведение B07-02. Полный verifier проверен отдельно при повреждении только representative cell, только attempt и обоих.
- **18 valid create-failure admissions:** 403/429/503 × 2 product plane × structural/verdict/full. Evidence остаётся valid по действующему capture contract. Прежняя SLO-семантика: realistic red; worst-case subtree 403/429 green, 503 red. Это синтетические controls admission, не новый product GREEN.
- **4 primitive rejection checks:** HTTP duration и неверный NOWAIT attempt count отклонены structural/full. Все 17 primitive/dirty stored cells сохраняют отдельные контракты без participation.
- **56/56 stored verdicts** совпали с рассчитанными; CLI replay exit 0. Raw tree SHA-256 до/после совпал у обоих исполнителей.

Disposition независимого reviewer: **CLOSED, findings в заданном B07-02 scope нет**. Целые unrelated suites, PHP/package/PG tests, Docker, DB, HTTP, live/chaos/mutation не запускались. Recheck ограничен этим finding; полного B07 или B14 здесь нет.

## Fingerprints final source/test и сохранности

| Объект | SHA-256 |
|:--|:--|
| `load/gates/p5_concurrency.py` | `61eb8435e522762d5d75beaf185f702a15ba9986d4954d04ad901fe57225df6d` |
| `load/gates/p5_live_matrix.py` | `19eb896ff41e64d42463e39f3463960d7704eb065c3a9127d40043f4059b3f62` |
| `load/gates/test_r05_capture.py` | `3ad97599c25b512d01ecce9191682c09afaec8dedf8352c7418b2a1c4cd013a0` |
| Raw run `adcdcdb7…` до/после | `a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074` |
| Независимый `/tmp/b07_02_independent_recheck.py` | `03bdc35d9a727ad1020a0033ec62ee5116b09702316bfd7bd228df03c3d08e79` |
| Независимый `/tmp/b07_02_independent_recheck.log` | `50414f9792b8e533e5bd2941686d87e70b7a39b7b1eb4f79d66b69779cdf1bd8` |
| Прежний полный `B07-audit.md`, сохранённый ниже как suffix | `e833f33bc8e9eb1df6a593f8c52711847c377513767feb0b973522ea16cd150a` |

Script/log reviewer находятся только в `/tmp`; постоянные regression controls — существующий `test_r05_capture.py`. Stored source manifest/digest остаётся историческим и не приравнивается к исправленному source. Новый верхний раздел не меняет байты обоих прежних отчётов.

Primary синхронизирует исполнительный лист в этом же шаге: **19a ГОТОВО**, **32/B14 СЛЕДУЮЩИЙ**; active 7 → 6, done 25 → 26, deferred 12 → 12, conditional 1 → 1. Новые строки не добавлены; следующий аудит здесь не запускается.

---

# B07-R — offline-переаудит доступного R03–R08 scope

Дата: 2026-09-28. Основание: шаг 18 [REMAINING-WORK.md](../REMAINING-WORK.md), решение владельца от 2026-09-28. Проверен чистый worktree при HEAD `a244602`; source/raw fingerprints ниже фиксируют проверенные байты. Для B07-01/02 привлечён один независимый verifier GPT-6 Astra; его воспроизведение повторено основным аудитором.

**Ограниченный offline-аудит выполнен с одним actionable Major: B07-02 частично OPEN.** B07-01 закрыт в bounded R05 scope, отсутствие диагностики B07-04 устранено R06/R08. Current-source R03/R04 product proof остаётся RED/REJECTED/unevaluated, mutation отложен. Product GREEN, live/B08 admission не выданы. До перехода к зависимому B14 нужен corrective code/test route B07-02 либо явное решение владельца по этому finding.

**Живого стенда до конца этого плана не будет.** Live/load/chaos/SLO и cold mutation исключены; тесты ради мутантов не запускать до фиксации API перед 1.0. Отсутствие исключённых измерений не мешает завершению ограниченного аудита/handoff. В этой задаче изменён только данный отчёт; прежний отчёт сохранён ниже побайтно как история. Код, исполнительный лист, R-статусы, raw runs, пороги и исторические verdicts не изменены.

## Actionable finding

### B07-02 — Major / высокая уверенность: persisted participation не проверяется для product-worst-case

**Места:** `load/gates/p5_live_matrix.py:1056` (`verdict_for`), `load/gates/p5_concurrency.py:348` (structural admission); compare `load/gates/p5_live_matrix.py:841` (capture проверяет участие независимо от product plane).

Исправление R05 проверяет participation в обычном capture, однако оба readback gate ограничивают эту проверку `plane == 'product-realistic'`. Для каталожной `product-worst-case.subtree-move.overlapping-tree.n200` отсутствие 199 акторов и `participation=None` допускаются. `verdict_for` возвращает `evidence=valid, slo=green` при одном HTTP 200. Полный `verify_artifacts()` тоже принимает такую запись, если согласованно усечены cell/attempt; исходный GREEN verdict этой ячейки менять не требуется.

**Сценарий и влияние:** усечённый импорт либо сохранённая запись без участия N акторов может пройти независимый offline readback как valid N=200 и получить локальный GREEN. Исправленный `run_k6` защищает штатный capture, но не закрывает первоначальный B07-02 на persisted worst-case evidence. Это дефект gate, а не установленная потеря акторов в run `adcdcdb7`: его реальные попытки независимо проверены и полны.

**Воспроизведение:** все изменения только в памяти; Docker, HTTP и запись evidence не используются. Запуск из корня с `PYTHONDONTWRITEBYTECODE=1 python3 -B`:

```python
import copy, json, sys
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, 'load/gates')
import p5_live_matrix as p5
root = Path('plans/VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe')
cid = 'product-worst-case.subtree-move.overlapping-tree.n200'
cell = next(c for c in p5.gate.catalog() if c['cell_id'] == cid)
path = root / 'attempts' / f'{cid}.attempt-1.json'
bad = copy.deepcopy(json.loads(path.read_text()))
actor = bad['actor_ids'][0]
bad.update(actor_ids=[actor], participation=None, http_5xx=0,
           statuses={'2xx': 1, '409': 0, '429': 0, '5xx': 0, 'other': 0},
           http_responses=[{'actor_id': actor, 'phase': 'operation',
                            'status': 200, 'retry_after': None}])
print(p5.verdict_for(cell, [bad]))  # valid / green — false acceptance
original = Path.read_text
def read_override(self, *args, **kwargs):
    if self == path:
        return json.dumps(bad)
    if self == root / 'cells.json':
        rows = json.loads(original(self, *args, **kwargs))
        return json.dumps([bad if r['cell_id'] == cid else r for r in rows])
    return original(self, *args, **kwargs)
with patch.object(Path, 'read_text', read_override):
    print(p5.verify_artifacts(root))  # accepts original GREEN verdict
```

Фактический результат: `valid / green`, full verify принимает и возвращает raw digest `a248913b…074`; exit 0. Независимый полный parser и повтор основного аудитора дали одинаковый результат. 15 отрицательных controls для realistic participation/overlap правильно отклонены, но контроль worst-case принят.

**Disposition / named route:** B07-02 **PARTIALLY FIXED / OPEN → R05 corrective code/test для worst-case persisted participation**. Распространить structural/verdict admission на оба product plane; добавить отрицательный контроль missing actor/incomplete iteration именно для worst-case и read-only replay сохранённого valid run. Primitive и dirty-cardinality planes должны сохранить собственные контракты. Live и mutation для этой правки не нужны. `B07-R-sync` должен отразить finding и corrective route перед зависимым B14; аудитор лист и код не правит. Это остаток существующего finding, не новое product failure.

## Disposition прежних findings

| Finding | Текущее решение и граница |
|:--|:--|
| **B07-01** | **CLOSED в bounded R05 scope.** Настоящий sweep вызывается внутри held PATCH window; сохранённый run подтверждает все 9 попыток N=1/8/32 × 3. Natural race frequency, throughput и SLO-70 этим не доказаны. |
| **B07-02** | **PARTIALLY FIXED / OPEN, Major.** Realistic checks и actual stored actors подтверждены; worst-case readback bypass описан выше. |
| **B07-03** | **P5 missing-evidence portion устранён**, с сохранением B07-02 gate finding. R03/R04 current qualified proof gaps **DEFERRED / retained RED/REJECTED/unevaluated → R20**. До конца плана пересъём не требуется; live/B08 admission отсутствует. |
| **B07-04** | **Missing-diagnostic/proof aspect CLOSED по R06/R08**, без product GREEN. R06 измерил harness argv failure, R08 выполнил настоящий sweep и сохранил SLO-70 RED. SLO-72/tier-L proof не получен. |
| **B07-05** | **DEFERRED решением владельца до фиксации API перед 1.0.** Последний записанный score 86.20%, gate 95 exit 1; прежние scores/исторический workflow claim не переписаны. Не является активным blocker offline-аудита; нового mutation/workflow proof нет. |

### R05: независимая сверка первичных данных

Проверен [run adcdcdb7](../../../../VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe), а не только JSON verdicts. Все **85 product attempts** (69 realistic, 16 worst-case), **4702 actor iterations**, 170 stdout/stderr k6 logs: actor IDs соответствуют N, started/completed совпадают, aborted пусты, iterations и HTTP status counters совпадают с metrics и attempts. Все 56 cells проходят текущий verifier; это его bounded verdict, не all-SLO PASS.

Во всех 9 overlap attempts подтверждены `sweepable=true`, sweep exit 0, target-specific `P5_SWEEP_TARGET|<upload_id>|refused|END`, lock до/после sweep и строгий `held < sweep_start < sweep_end < release`. Итог `uploading`/4 bytes, retry 204 от участвовавшего actor. Held windows 498–585 ms, sweep 255–328 ms. Источники: `p5_live_matrix.py:845` (`run_patch_overlap`), load-only `P5PatchWindow.php:15`, attempts/sweeper/k6 raw данного run. B07-01 не требует нового стенда: исправление и сохранённый первичный run согласуются.

Сохранённые RED и границы P5 не отменены: collab `other` содержит HTTP 403; 429-only advisory collision остаётся **unconfirmed**; same-resource не становится 5%-budget proof; primitive/dirty-cardinality planes не дают product SLO. R05 dirty-20k не заменяет R08 измерение.

### R03/R04: code/gates и отсутствующий current product proof

R03 исправления H01…H06 проверены в исходниках и offline controls: отдельные OP-06/07, accepted payload bytes/durations без двойного sent-byte счёта completion, rejected responses без accepted bytes, обязательные download/docs pools, run/source identity, >=180s full window, >=100 class-B samples, неизменённые floors, L-1…L-7 admission и numeric-string memory limits. Новых actionable code findings в этом bounded review не установлено. Gate использует native k6 threshold results; offline unit fixtures не являются измерением SLO или лаборатории.

Повторный readback исторических `s0-mixed-ab5edbe8` / `s-mixed-740fb7e0` из [R03 campaign](../../../../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/run-manifest.json): 190344.87191 / 202539.092642 ms, оба full-window, **proof_ready=false, lab admitted=false, dense class-B=0**, оба summary audit exit 2. Exact historical k6 exit 99 сохранён. Observed RED и missing operation/attribution proof не заменены исправленным harness.

R04 `326f69b`: finite writers joined до reset, k6/writer/maintenance failures прекращают publication, fresh validated snapshot публикуется без overwrite под lock; HTTP trace/run/dataset/invocation/ordinal identity проверяется. `ok` plans требуют actual ANALYZE metrics; rejected/missing планы в trace report перечисляются явно, повторённый SQL обозначается candidate, а не автоматический N+1 product verdict. 13 source files совпадают с сохранённым R04 scope manifest. Новых actionable code findings не установлено.

Исторический [P3](../../../../VLT-LOAD/artifacts/P3) по-прежнему rejected: workload verifier exit 1 `TRACE_EMPTY`, trace report exit 1 `TRACE_EMPTY: no N+1 verdict from zero rows`. Current-source trace/actual ANALYZE/top-20/N+1 и OP↔SQL остаются **unevaluated**, live step 17a исключён до конца плана. Записанные прежним исполнителем PHP checks 7 tests / 35 assertions относятся к R04 snapshot; в этом аудите PHP/package suites не перезапускались.

### R06/R08: установленная причина и retained RED

Независимо прочитаны все три [R06 diagnostic attempts](../../../../VLT-LOAD/artifacts/R06/03f5d89d-e882-41e9-86e0-43eb0f93cd2e/sweeper): extra argv `artisan`, stdout `Command "artisan" is not defined`, exit 1, processed 0 / remaining 20000; elapsed 0.324 / 0.234 / 0.170 s, PHP peak 36175872 bytes. **Product sweep не запускался**. Это измеренная причина данного R06, а не восстановленный stderr оригинального P5 run и не ошибка drive.

[R08 dirty-20k](../../../../VLT-LOAD/artifacts/R08/8a9c70cf-c531-48ac-bdce-d566726aed20/sweeper/dirty-20k.json): argv без `artisan`, exit 0, processed 20000 / remaining 0 за **80.501 s**, пересчитано **248.444 rows/s**. PHP `memory_get_peak_usage(true)` **132644864 bytes / 126.5 MiB**, не process RSS. Drive file SHA256 совпадает с сохранённым `94210031ee9718e2aea46f7d6e0d9a8260e640c27baa54733b94f5d448f2926e`; code fix из R06 не обоснован. SLO-70 RED сохранён.

**Граница batch evidence:** `batch_metric_before/after=null`, оба metrics scrape содержат `HTTP Error 404: Not Found`. `batch=20000` в листе допустимо читать как source-derived cardinality: текущая команда materializes candidates одним `get()` и данный run обработал 20000; это **не наблюдённая Prometheus batch series**. В sync/R20 сохранить эту квалификацию. R08 race/retry raw показывает target refusal/final uploading/4/retry204, но `sweepable=false`; усиленное sweepable/held-lock доказательство предоставляет R05, а не эта старая запись. Ни race, ни PHP peak не дают tier-L/SLO-72 admission.

## Реально выполненные проверки

Все новые логи/контроли писались только в `/tmp/vaulter-b07-review-g9ash090` и `/tmp/b07_p5_independent.*`. Ни один тест не использовал живые services; Docker в runner/capture tests заменён temp fake executables. `PYTHONDONTWRITEBYTECODE=1` задан, Python checks запускались с `PYTHONPATH=load/gates` и `-B`.

| Команда / проверка | Результат |
|:--|:--|
| `python3 -B -m unittest discover -s load/gates -p 'test_r03_*.py'` | 18 tests, exit 0 |
| `python3 -B -m unittest discover -s load/gates -p 'test_r04_*.py'` | 15 tests, exit 0 |
| `python3 -B -m unittest discover -s load/gates -p 'test_p3_*.py'` | 14 tests, exit 0 |
| `python3 -B -m unittest test_r06_capture` | 7 tests, exit 0 |
| `node --experimental-vm-modules --test load/gates/tests/r03-k6.test.mjs` | 7 tests, exit 0; preflight pnpm 9.15.0 / Node v24.12.0 |
| `bash -n load/scripts/p3-profile.sh load/scripts/p3-capture-workload.sh load/scripts/p3-explain-capture.sh`; `sh -n load/scripts/run.sh` | оба exit 0 |
| R05 verifier `python3 -B load/gates/p5_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe` | независимый verifier exit 0, raw digest совпал |
| Независимый raw parser + 15 rejection controls + worst-case false acceptance; повтор основным аудитором | оба exit 0; **false acceptance остаётся finding**, не успешной защитой |
| Четыре focused `R05CaptureTest`: real overlap orchestration, no-sweep/no-overlap, completed iteration join, missing actor/setup-only | независимый verifier: 4 tests, exit 0 |
| Current R03 summary gate на двух исторических 180s summaries | оба exit 2, ожидаемый proof rejection |
| Current P3 workload verifier / trace report на историческом P3 | оба exit 1 `TRACE_EMPTY`, ожидаемый rejection |
| R06/R08 readback, 13 R04 scope hashes, snapshot preservation | exit 0; исходные 5493 файла вне этого отчёта неизменны |

Сохранённые offline checks R03/R04 прочитаны и сверены с повторёнными проверками; прежние PHP/static checks обозначены как исторические, а не выполненные аудитором. Package/PG suites, Docker/live, host tuning, mutation и новые SLO measurements не запускались.

Fingerprints:

- R05 current six-file harness digest совпал с source данного run: `667a9345f2c5644e82a63a14961a34b00c4b4e82bc31f008fa8c24bfb44c407f`.
- R05 raw tree до/после: `a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074`.
- R04 сохранённый scope digest: `05784267919305cd59a82c72d13f0346753a90774186999166829fe508771aeb`; все 13 перечисленных файлов совпали.
- Snapshot остальных 5493 файлов: SHA256 sorted `path + NUL + file_sha256 + LF` = `ad932ebc4567eea4121070d75a8735f593728e24f1b4e08fd1d5c20e39ab09bb`, до/после совпал.
- Независимый parser script `/tmp/b07_p5_independent.py`: `adefb2dcbb9590c301108f3d8d661c7104578da269d30cfd1c4c0b6b5ae1fd28`; его log: `7dae620034d6964db290924895b6e3aba18d634c8047f9846f0b045be5ae9797`. Репродуктор выше позволяет повторить finding без сохранности `/tmp`.

## Передача по исполнительному листу

Следующий запуск — **шаг 19 / B07-R-sync**. Прочитать этот результат, синхронизировать affected statuses/routes и поставить R05 corrective B07-02 перед B14 с явным scope и изменением активного счётчика. После offline исправления и focused re-review продолжить B14; не возвращать исключённые live/mutation строки. Основной аудитор R-статусы не менял и следующую задачу не запускал.

```text
$ task:dev plans/REMAINING-WORK.md B07-R-sync
```

---

## История: прежний отчёт B07 от 2026-09-27

Ниже исходный отчёт сохранён без изменений. Его OPEN/Blocker/next-route отражают прежний scope; актуальный bounded disposition задан разделом B07-R выше.

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
