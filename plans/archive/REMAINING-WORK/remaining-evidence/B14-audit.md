# B14 — независимый offline-аудит P7/code/evidence

**Дата:** 2026-09-28. **Reviewer:** Codex / GPT-6, отдельная B14 review-сессия.
**Вход:** `plans/REMAINING-WORK.md`, шаг 32/B14 после закрытого 19a; HEAD `9bd90da11eb63ab276d68e4834ff34a73813f10e`, исходный worktree чистый.
**РЕЖИМ:** независимый read-only review исходников и evidence; единственный изменяемый repository artifact — этот отчёт.
**СКОУП:** prepared R11/P7 runner, fault/revert/reconcile/verification/publication paths, R24 claim/settlement и R25 historical classification.
**НЕ ТРОГАТЬ:** package/harness source, тесты, исторические raw files, thresholds, verdicts, execution sheet. Live/load/chaos/mutation не запускались.

**Вердикт: REPAIRS REQUIRED — 1 Blocker, 5 Major.** Это ошибки подготовленного harness и его evidence gates, выявленные offline. Отсутствие исключённых владельцем R11/R12 live measurements и current mutation proof не является новым blocker. Product GREEN, допуск live и закрытие VLT-LOAD не выдаются.

## Findings

### B14-01 — Blocker / issue: восстановление приложения очищает базовую БД после integrity snapshot

**Место:** [`p7_live_matrix.py:400`](../../../../load/gates/p7_live_matrix.py#L400), особенно `restore_base():415–416`; также `enable_chaos():394–395`, [`entrypoint.sh:16`](../../../../load/docker/entrypoint.sh#L16), `docker-compose.load.yml:157–163`, `docker-compose.chaos.yml:55`.

`restore_base()` из `run_matrix()`/`finally` выполняет base-compose `up --force-recreate app queue-worker`. Base compose не задаёт `LOAD_SKIP_MIGRATIONS`; entrypoint с default `0` запускает `migrate:fresh --force` на `postgres/vaulter_load`. Это не ограничено изолированным `vaulter_chaos`. Аналогичный default остаётся при начальном `enable_chaos()`. Исправление `route_app(ha=False)` передаёт skip только своему вызову и не защищает финальный base-compose recreate.

При непустом stand входной reset теряет предыдущие данные до `integrity-before`; финальный reset происходит **после** `integrity-after`, `locks.json` и terminal marker. Равенство сохранённых snapshots не доказывает состояние, оставленное пользователю. Нарушены run-scoped cleanup из P7.1 и утверждение R11 о безопасном возврате app route. Это статически прослеженный destructive path; реальная БД в аудите не запускалась и не очищалась. Offline control перехватил точный вызов `restore_base` без migration skip.

**Маршрут:** `R11-B14-01`, load-only corrective. Все переключения маршрута/restore должны явно исключать миграции базовой БД; bootstrap разрешать только на отдельной HA database. Final readback должен следовать за фактическим restore. Проверить shell/compose/entrypoint path с fake executables и sentinel state, включая exception/finally.

### B14-02 — Major / issue: полный verifier принимает неполные и противоречащие друг другу доказательства

**Место:** [`p7_live_matrix.py:877`](../../../../load/gates/p7_live_matrix.py#L877), [`p7_chaos.py:293`](../../../../load/gates/p7_chaos.py#L293), [`p7_chaos.py:453`](../../../../load/gates/p7_chaos.py#L453), `verify_run_dir():534–564`.

`verify_artifacts()` проверяет число attempt **files** и доверяет `cell-verdicts.json`, но не требует полный набор первичных streams на каждый `(run, cell, attempt, fault)`. `_check_phases()` обходит лишь присутствующие группы. `_check_reconcile()` группирует только по cell, допускает baseline как recovery probe и достаточно одного resolved join. Checksum проверяется на формат, а не на соответствие DB↔object. У attempt files не сверяются cell/attempt/evidence с именем и первичным stream. Prometheus reader принимает любое значение `1`, не проверяя `metric.job`.

На synthetic positive fixture с 51 cells / 77 attempts полный **неподменённый** `verify_artifacts()` принял каждый независимый отрицательный control:

- из всех десяти JSONL streams удалены события 76 attempts, оставлен только первый;
- удалены все recovery readiness probes, сохранены только baseline;
- файл attempt-2 содержит `cell_id=wrong-cell`, `attempt=99`, `evidence=invalid`;
- object checksum заменён с `b…b` на `c…c`, DB checksum и stored reconcile оставлены прежними;
- все Prometheus samples относятся к `job=prometheus`, без `vaulter-sut`.

Результат каждого — `ACCEPTED`, exit контрольного скрипта 0. Это воспроизведение false admission на искусственных файлах, не новые product measurements и не изменение исторического evidence. Нарушены adopted P7.1 identity/recovery/checksum contracts и R11 baseline/metrics gate.

**Маршрут:** `R11-B14-02`, gate + ordinary offline regressions. Требовать точное покрытие всех expected attempts во всех обязательных streams; проверять identity, baseline/recovery phases и временной порядок внутри attempt; сверять оба join и checksums по durable identity; вычислять admission по raw, сравнивать stored verdict; проверять series labels и принадлежность sample измеряемому окну. Сохранить допуск явно корректной expected absence и отказ старому labelled-GET evidence.

### B14-03 — Major / issue: ACK не доказывает fault, а lock-holder стартует после воздействия

**Место:** [`p7_live_matrix.py:504`](../../../../load/gates/p7_live_matrix.py#L504), `run_window():613–633`, `arm():454–463`; [`p7_product_ops.py:140`](../../../../load/gates/p7_product_ops.py#L140), [`ChaosOperationCommand.php:147`](../../../../load/app/Console/ChaosOperationCommand.php#L147).

Toxiproxy barrier подтверждает только HTTP 200 от `/proxies`, не состояние нужного proxy/toxic. Offline ответ с обоими proxy `enabled=true` и `toxics=[]` получает ACK. `target` у PostgreSQL transport/product cells при этом записывается как `minio` (`run_window():584–587`).

Для `product-realistic.lock-holder.disconnect` fixture ещё не открывает DB transaction и не держит lock. Runner сначала отключает proxy и получает ACK, затем синхронно вызывает command, который только тогда пытается открыть транзакцию и взять `pg_advisory_xact_lock`. Поэтому сценарий проверяет отказ нового подключения, а не потерю действующего lock-holder. В `primary-transition.lock-holder.fence-then-promote` lock аналогично приобретается после promotion. Это статическое доказательство порядка вызовов; реальное disconnect/promotion не выполнялось. Нельзя закрывать named lock-holder/fault-boundary proof только фактом запуска одноимённой команды.

**Маршрут:** `R11-B14-03`, load-only fault/barrier corrective. Привязать ACK к точному target и подтверждённому состоянию injector; для lock-holder подготовить удерживаемую операцию, подтвердить достижение lock boundary, затем воздействовать и записать исход. Проверить порядок и неверный ACK управляемыми offline peers; live proof остаётся deferred.

### B14-04 — Major / issue: корректный `matched-absent` превращается в invalid evidence

**Место:** [`p7_live_matrix.py:726`](../../../../load/gates/p7_live_matrix.py#L726), [`p7_live_matrix.py:736`](../../../../load/gates/p7_live_matrix.py#L736).

Reader признаёт `matched-absent` resolved, но producer `integrity_ok` этот статус исключает. Когда первая PATCH отклонена до записи, session остаётся `initiated`, `received_bytes=0`, object отсутствует и dependency recovery успешен, оба join получают `matched-absent`, а attempt — `evidence=invalid`. То же правило затрагивает корректно очищенный abort/expiry. Матрица не сможет пройти даже при корректном product outcome; это отдельная producer ошибка, противоположная false admission B14-02.

Вызов реального `run_window()` с fake HTTP/DB/object boundaries воспроизвёл `['matched-absent', 'matched-absent'] evidence=invalid`. Product код и реальные dependencies не запускались.

**Маршрут:** `R11-B14-04`, producer + focused offline cases для отказа до записи, корректного abort/expiry и отрицательных missing-required-object/orphan случаев. Согласовать producer/reader status contract без ослабления integrity gate.

### B14-05 — Major / issue: object-to-DB reconciliation не обследует оставшиеся staging/part objects

**Место:** [`p7_live_matrix.py:673`](../../../../load/gates/p7_live_matrix.py#L673), `run_window():704–734`, [`p7_live_matrix.py:311`](../../../../load/gates/p7_live_matrix.py#L311).

Когда найден assembled blob, runner читает только его `storage_key`. Staging key и multipart keys больше не обследуются; оба направления reconcile получают один и тот же `object_status` без отдельного object inventory. Затем `cleanup_fixture()` удаляет staging/part keys, не записав их состояние до cleanup. `object_sample()` также превращает любой nonzero `mc cat` exit в `exists=false`, не отличая отсутствие от ошибки наблюдения.

Offline control завершённого multipart с blob и part row подтвердил ровно один sample — `cas/final`, два `matched`, `evidence=valid`. Возможный остаток `tmp/uploads/<id>.parts/1` вообще не является входом решения. **Это статически доказанная слепая зона capture**, а не утверждение, что текущий package оставил orphan при настоящем complete. Ошибка продукта, оставившая staging bytes, будет скрыта последующей очисткой harness. Нарушено требование L-P7-02/R11 о двустороннем package DB↔object join; исправление только verifier B14-02 недостающий capture не восстановит.

**Маршрут:** `R11-B14-05`, scoped capture/reconcile corrective. До cleanup получить все ожидаемые и фактические attempt-owned staged/part/final identities; раздельно определить required absence, required presence, observation error и orphan. Добавить offline leftover-part, extra-object, missing-object и collector-error controls; не сканировать/удалять чужие объекты.

### B14-06 — Major / issue: публикация удаляет предыдущий evidence tree

**Место:** [`p7-chaos.sh:142`](../../../../load/scripts/p7-chaos.sh#L142), также `:162–165` для lane publication.

После verification `run --matrix` делает `rm -rf "$dest"` и затем `mv`. Default destination — существующий `plans/VLT-LOAD/artifacts/P7` с историческим run `7b255888-43bb-4071-b106-45f70a7f475c`. Нет отказа при destination conflict или выбора нового run-specific destination; операция также не атомарна относительно старого tree. Будущий разрешённый measurement уничтожит source evidence, на которое ссылаются historical report/deferred findings. Это противоречит текущему правилу REMAINING-WORK: «A correction never overwrites a rejected or RED artifact» и общему сохранению raw evidence.

Реальный shell publication path выполнен с fake Python, отключающим любой live запуск/verification, и destination только внутри `TemporaryDirectory`: exit **0**, `historical_preserved=False`, `new_published=True`. Ни один repository artifact в этом control не затронут. Проверялся только publisher, не доказательство validity fake run.

**Маршрут:** `R11-B14-06`, shell publisher corrective. Публиковать в новый immutable run path, отклонять occupied destination, сохранить staging при failed verify/publication; проверить conflict и конкурирующие publishers offline. Исторический P7 tree сохранить побайтно.

## R24/R25 и retained evidence

**R24:** новых findings в проверенном claim/settlement/recovery scope нет. Проверены CAS по status/attempts/lease, token-fenced settlement в транзакции configured model connection, HTTP вне транзакции, retry exclusion/expiry, fresh/upgrade schema и существующие regression tests. Все **8 source/test hashes** и **19 log hashes** совпали с `R24-checks/checks.json`; rollup `8968089f8db89fa943b7147e79620bf9b3280e110eb0a1fbf24cc029cb638ba0` пересчитан и совпал.

Сохранённые logs действительно показывают concurrent red-before **3 failures**, final concurrent **3 passed / 36 assertions**, final focused PostgreSQL **81 passed / 298 assertions**, package SQLite **582 passed**, PostgreSQL **581 passed**. Это **чтение сохранённых результатов**, не повторный запуск PHP suites в B14. Rector exit 2 и type coverage 99.8%/exit 1 сохранены как прежние ограничения. At-least-once и возможный повтор POST после expiry/crash остаются; R24 не закрывает historical 2-dispatch/2-HTTP и current R26 proof gap.

**R25:** повторно выполнен read-only `readback.py` с распаковкой archives только во временные каталоги. JSON observations совпали с сохранённым `readback.json` после нормализации лишь временных argv paths. Counts, UUID joins, p95, failed-job deltas, sink multiplicity и raw/archive digests прошли встроенные assertions. Новых package diagnoses из historical RED не получено.

| Historical family | RED cells / attempts | Current verifier exit | Raw tree SHA-256 |
|:--|--:|--:|:--|
| P6 `59dfb7e1…` | 36 / 108 | 0 | `d48c8ffd07fb11847cff3a5f80d4c56cfe9b2e3459ed78ce251ab9a9b66f3766` |
| R01 `721dd40b…` | 19 / 55 | 1 | `48f8d50e7f6a7e4910da909518cb3be7884507e75c7166ebd54e2c5a7c7b50b6` |
| R09 `3df3b9e1…` | 9 / 25 | 1 | `7bac6725d3af18ac57789e7f70f1fdc3d78b4f28fdc051ff7de2dda39124c270` |
| R09 follow-up `bc199ced…` | 8 / 24 | 1 | `bbfa77d90edc2664a158b37aa7cab0a8a936c7af8bfe90cefac3e745a184a0e6` |
| R10 `56823709…` | 9 / 26 | 1 | `100d368f90e4c985a40fb4bb624128bda8ee6e483727dc4a7d4db302c9a2dad7` |

Exit 0 старого P6 verifier не означает product GREEN. R01 остаётся rejected; R09/R10 не удовлетворяют нынешним telemetry gates. Recovery RED, SLO-61 RED, неизвестные identities двух первоначальных failed jobs и M/L/daily-budget unevaluated сохранены. Новый live stand исключён до конца плана; прежние исторические указания ждать lab не являются текущим Next.

**P7 historical:** run `7b255888-43bb-4071-b106-45f70a7f475c`, tree digest `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62` совпадает с chaos report. Current live verifier возвращает **1**, `recovered without dependency probe`; это ожидаемый отказ старому evidence, не успех R11. Старые 51 labelled cells / 77 attempts не превращены в named product fault proof. Старое утверждение evidence-valid остаётся историческим и не переносится на текущий gate.

Из 11 fingerprints в историческом `R11.md` совпали 10. `load/app/LoadServiceProvider.php` теперь имеет SHA-256 `790c86b4fe6e31ec2b95c60557cedb410b593670ac693fc01631d5499d7e9d46`; старый hash не переписан и не объявлен current-source proof. Основные P7 runner/gate/product-operation files совпадают с R11 fingerprint: обнаруженные defects присутствуют именно в подготовленном source, а не появились из-за drift этих файлов.

## Выполненные проверки и ограничения

Все команды запускались из repository root. Временные файлы — технические outputs, не новый статусный carrier; воспроизводимый control script полностью сохранён ниже в этом отчёте.

| Команда / проверка | Exit | Результат |
|:--|--:|:--|
| `PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p7*.py'` | 0 | **32 tests**, existing offline suite; сообщения shared endpoint / unfenced / tmpfs escape — ожидаемые negative controls |
| `PYTHONDONTWRITEBYTECODE=1 python3 -B /tmp/vaulter-b14-probes.py` | 0 | Appendix controls воспроизвели B14-01/02/03/04/05/06; принятие bad fixtures означает defect, не PASS gate |
| `PYTHONDONTWRITEBYTECODE=1 python3 -B plans/remaining-evidence/R25-checks/readback.py > /tmp/vaulter-b14-r25-readback.json` | 0 | Replay с исходными digests; subprocess verifier exits **0/1/1/1/1** |
| `PYTHONDONTWRITEBYTECODE=1 python3 -B load/gates/p7_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P7` | 1 | Expected historical rejection: `recovered without dependency probe` |
| `PYTHONDONTWRITEBYTECODE=1 TIER=S ./load/scripts/p7-chaos.sh dry-run` | 0 | 51 cells, no Docker/DB/object writes |
| `for f in load/scripts/p7-chaos.sh load/scripts/p7-injectors.sh load/scripts/p7-chaos-snapshot.sh load/docker/entrypoint.sh load/chaos/pg-failover.sh; do sh -n "$f" || exit; done` | 0 | Shell syntax |
| Python SHA-256/readback consistency assertions | 0 | R24 source/log rollup, R25 normalized observations, R11 identities и historical P7 digest сверены |
| `git diff --check` + final scope/raw readback | 0 | Единственное repository изменение — этот новый отчёт; historical P7/archive bytes сохранены |

Package/PHP/PostgreSQL suites в B14 не перезапускались; их результаты проверены по сохранённым source-bound logs. Новые live fault windows, ENOSPC, failover, SLO runs, cold mutation или tests ради mutants не выполнялись. Synthetic/fake controls доказывают приведённые ветви orchestration/admission, но не реальное состояние продукта под fault.

**Deferred, не новые findings:** R11 baseline `/ready`/metrics/named operations, R12 first/retry recovery matrix и DB↔object live joins, current R26 product verdict, current mutation proofs. Их передать в R20 как явно отсутствующие доказательства. Historical RED/REJECTED/unevaluated остаются. После code repairs нельзя выдавать product GREEN на основании offline tests.

**Рекомендация следующего шага:** `$ task:dev plans/REMAINING-WORK.md B14-sync`. Ответственный исполнитель переносит шесть findings в лист, назначает названные `R11-B14-01…06` code/test correctives (допустимо объединить совместимые исправления с раздельным evidence), выполняет focused recheck изменённых findings и затем ведёт к R20. R11/R12 live и mutation остаются ОТЛОЖЕНО. Этот review не меняет очередь и не запускает sync/repairs автоматически.

## Current source fingerprints

SHA-256 actual bytes при B14; R24 отдельный source map проверен выше.

| Path | SHA-256 |
|:--|:--|
| `load/gates/p7_product_ops.py` | `25fffe1de871ef5b6aa5b75506f643dcfe674b9e7f430abb6a1d86f6ccd38332` |
| `load/gates/p7_live_matrix.py` | `d47b5c33691fc7e11cbf22f368cda01ce7b47f5a1285a2b841f62bab82795303` |
| `load/gates/p7_chaos.py` | `442646f91954a925f0384b0fa0ea5fd63dd534ab636a5785094a82706652e14e` |
| `load/gates/p7_injectors.py` | `0c98491761753eb5b238277ee82a5972b05ef426e9cbbb06d57f275e3c95ff53` |
| `load/scripts/p7-chaos.sh` | `32b23b29b1c62c6850502785f7f9ad3bf757b010712026e0ceacf4b250726033` |
| `load/app/Console/ChaosOperationCommand.php` | `db64f06525d30c489f8e15a50860ef02d9fc2935633c5af8edefc80fbd865022` |
| `load/app/LoadServiceProvider.php` | `790c86b4fe6e31ec2b95c60557cedb410b593670ac693fc01631d5499d7e9d46` |
| `load/docker-compose.load.yml` | `7bcf2cb8b36fcaf06217fbbb8ecfe26db60392feae0655cc94fb23cf18cde2c2` |
| `load/docker-compose.chaos.yml` | `df1c753524b8fa0547917010861aec843533c96c007dd79946c63638eee14964` |
| `load/docker/entrypoint.sh` | `135f758dbdc40699cc96183e4aa91e7fc8cc32052561127b387a12d0cac32a9f` |
| `load/chaos/pg-failover.sh` | `cb89b81237dfb3b70c84bd981f465ddcab94049bebc49057b8075367f966aa37` |

## Воспроизводимые offline controls

Сохранить следующий блок как `/tmp/vaulter-b14-probes.py` и выполнить указанную выше команду из repository root. Все DB/HTTP/Docker boundaries `run_window` и `restore_base` подменены; shell publication использует fake Python и временный destination. Полный evidence verifier выполняется без подмены.

```python
import copy, json, sys, tempfile
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path.cwd() / 'load/gates'))
import p7_chaos as gate
import p7_live_matrix as live
import test_p7_chaos as fixture

def write(path, value):
    path.write_text(json.dumps(value))

def rows(path):
    return [json.loads(line) for line in path.read_text().splitlines()]

def write_rows(path, value):
    path.write_text(''.join(json.dumps(row) + '\n' for row in value))

def build(root):
    fixture.write_complete(root)
    manifest = json.loads((root / 'run-manifest.json').read_text())
    manifest['harness_version'] = 'r11-product-fidelity/v2'
    write(root / 'run-manifest.json', manifest)
    template = {name: rows(root / name) for name in gate.JSONL_FILES}
    cell_template = json.loads((root / 'cells.json').read_text())[0]
    streams = {name: [] for name in gate.JSONL_FILES}
    cells, verdicts = [], []
    for directory in ('attempts', 'prometheus', 'database'):
        (root / directory).mkdir()
    counter = 0
    for cell in gate.catalog():
        cid = cell['cell_id']
        cells.append({**cell_template, 'cell_id': cid, 'plane': cell['plane']})
        verdicts.append({'cell_id': cid, 'attempts': live.repeat_count(cell), 'evidence': 'valid'})
        for attempt in range(1, live.repeat_count(cell) + 1):
            counter += 1
            result = {'operation': cell['operation'], 'transport': 'http', 'status': 503,
                      'request_id': 'request-1', 'requested_bytes': 16, 'written_bytes': 0}
            results = [result]
            if cell['fault'] == 'enospc-then-retry':
                results.append({**result, 'status': 200, 'request_id': 'request-2', 'written_bytes': 16})
            for name, source_rows in template.items():
                for source in source_rows:
                    row = copy.deepcopy(source)
                    row.update(cell_id=cid, attempt=attempt, fault_id=f'fault-{counter}',
                               monotonic_ns=counter * 10000 + row['monotonic_ns'])
                    if name == 'attempts.jsonl':
                        row.update(schema='p7-attempt/v2', operation=cell['operation'], product_results=results)
                    if name == 'readiness.jsonl':
                        row['metrics_ok'] = True
                    streams[name].append(row)
            write(root / 'attempts' / f'{cid}.attempt-{attempt}.json', {
                'run_id': fixture.RUN, 'cell_id': cid, 'attempt': attempt, 'baseline_green': True,
                'product_results': results, 'retry_distinct': True, 'evidence': 'valid'})
            write(root / 'prometheus' / f'{cid}.attempt-{attempt}.json', {
                'status': 'success', 'data': {'result': [{'metric': {'job': 'vaulter-sut'}, 'value': [1, '1']}]}})
    for name, values in streams.items():
        write_rows(root / name, values)
    write(root / 'cells.json', cells)
    write(root / 'cell-verdicts.json', {'run_id': fixture.RUN, 'cells': verdicts})
    write(root / 'terminal.json', {'schema': 'p7-run-terminal/v1', 'run_id': fixture.RUN, 'monotonic_ns': 1000000})
    for name in ('integrity-before.json', 'integrity-after.json'):
        write(root / 'database' / name, {'v_upload_sessions': 0})
    write(root / 'database/locks.json', {'ungranted': 0})

def probe(label, change):
    with tempfile.TemporaryDirectory(prefix='b14-fixture-') as tmp:
        root = Path(tmp)
        build(root)
        live.verify_artifacts(root)
        change(root)
        try:
            live.verify_artifacts(root)
            print(label + ': ACCEPTED')
        except gate.EvidenceInvalid as error:
            print(label + ': REJECTED: ' + str(error))

def missing(root):
    for name in gate.JSONL_FILES:
        write_rows(root / name, [r for r in rows(root / name) if r['cell_id'] == fixture.CELL and r['attempt'] == 1])

def recovery(root):
    write_rows(root / 'readiness.jsonl', [r for r in rows(root / 'readiness.jsonl') if r['phase'] == 'baseline'])

def wrong_attempt(root):
    path = root / 'attempts' / f'{fixture.CELL}.attempt-2.json'
    row = json.loads(path.read_text())
    row.update(cell_id='wrong-cell', attempt=99, evidence='invalid')
    write(path, row)

def checksum(root):
    data = rows(root / 'objects.jsonl')
    data[0]['checksum'] = 'c' * 64
    write_rows(root / 'objects.jsonl', data)

def prom(root):
    for path in (root / 'prometheus').glob('*.json'):
        write(path, {'data': {'result': [{'metric': {'job': 'prometheus'}, 'value': [1, '1']}]}})

for label, change in [('positive-synthetic-51-cells-77-attempts', lambda _: None),
                      ('missing-76-attempt-streams', missing), ('baseline-only-recovery', recovery),
                      ('foreign-attempt-identity-and-invalid-verdict', wrong_attempt),
                      ('object-checksum-disagrees-with-database', checksum), ('wrong-prometheus-job', prom)]:
    probe(label, change)

# External boundaries replaced with deterministic fakes; no command, socket or DB call.
cell = gate.catalog()[0]
fixture_data = {'payload_sha256': 'b' * 64, 'upload_id': 'upload-1', 'marker': 'run-test', 'run_id': 'run-test'}
entities = [{'entity': 'upload_session', 'entity_id': '1', 'status': 'initiated', 'received_bytes': 0}]
streams = {name: [] for name in gate.JSONL_FILES}
with patch.object(live.product_ops.ProductOperationRunner, 'prepare', return_value=fixture_data), \
     patch.object(live.product_ops.ProductOperationRunner, 'execute', return_value={
         'status': 503, 'transport': 'http', 'operation': 'tus-patch', 'request_id': 'tus-patch-1',
         'requested_bytes': 4096, 'written_bytes': None}), \
     patch.object(live, 'host_status', return_value=200), \
     patch.object(live, 'host_probe', return_value=(200, 'vaulter_metric 1')), \
     patch.object(live, 'arm', return_value='s3-fault-proxy'), \
     patch.object(live, 'barrier_ack'), patch.object(live, 'revert'), \
     patch.object(live, 'sample_entities', return_value=entities), \
     patch.object(live, 'object_sample', return_value={'key': 'tmp/uploads/upload-1', 'exists': False, 'checksum': None, 'size': 0}), \
     patch.object(live, 'cleanup_fixture'):
    record = live.run_window(streams, live.Clock(), cell, 1, 'run-test', gate.config_fingerprint(), 'a' * 64)
    print('expected-absence:', [r['status'] for r in streams['reconcile.jsonl']], 'evidence=' + record['evidence'])

with patch.object(live, 'app_http', return_value={'status': 200, 'body': ''}), \
     patch.object(live, '_run'), patch.object(live, 'compose'), patch.object(live, 'compose_base') as base:
    live.restore_base('run-test')
    print('restore-base-compose:', base.call_args)
with patch.object(live, 'app_http', return_value={'status': 200, 'body': '{"minio":{"enabled":true,"toxics":[]},"pgbouncer":{"enabled":true,"toxics":[]}}'}):
    live.barrier_ack('toxiproxy', 'run-test')
    print('toxiproxy-with-no-fault: ACKNOWLEDGED')

# Verify that completed multipart reconciliation samples only its final blob.
cell = next(c for c in gate.catalog() if c['cell_id'] == 'protocol-control.s3-complete.503.on-recovery')
entities = [
    {'entity': 'upload_session', 'entity_id': '1', 'status': 'committed', 'received_bytes': 4096},
    {'entity': 'blob', 'entity_id': '2', 'status': 'committed', 'checksum': 'b' * 64, 'storage_key': 'cas/final', 'size': 4096},
    {'entity': 'upload_part', 'entity_id': '1:1', 'status': 'recorded', 'checksum': 'b' * 64, 'size': 4096},
]
streams = {name: [] for name in gate.JSONL_FILES}
with patch.object(live.product_ops.ProductOperationRunner, 'prepare', return_value=fixture_data), \
     patch.object(live.product_ops.ProductOperationRunner, 'execute', return_value={
         'status': 201, 'transport': 'http', 'operation': 's3-complete', 'request_id': 's3-complete-1',
         'requested_bytes': 0, 'written_bytes': None}), \
     patch.object(live, 'host_status', return_value=200), \
     patch.object(live, 'host_probe', return_value=(200, 'vaulter_metric 1')), \
     patch.object(live, 'arm', return_value='s3-fault-proxy'), \
     patch.object(live, 'barrier_ack'), patch.object(live, 'revert'), \
     patch.object(live, 'sample_entities', return_value=entities), \
     patch.object(live, 'object_sample', return_value={'key': 'cas/final', 'exists': True, 'checksum': 'b' * 64, 'size': 4096}) as sample, \
     patch.object(live, 'cleanup_fixture') as cleanup:
    record = live.run_window(streams, live.Clock(), cell, 1, 'run-test', gate.config_fingerprint(), 'a' * 64)
    print('multipart-object-sample-keys:', [call.args[0] for call in sample.call_args_list],
          'joins=', [r['status'] for r in streams['reconcile.jsonl']], 'evidence=' + record['evidence'])
# Execute the publication shell only against disposable paths and a fake Python.
import os, subprocess
with tempfile.TemporaryDirectory(prefix='b14-publish-') as tmp:
    temp = Path(tmp)
    bin_dir = temp / 'bin'
    bin_dir.mkdir()
    fake = bin_dir / 'python3'
    fake.write_text('''#!/bin/sh
case "$2" in
  run)
    while [ "$#" -gt 0 ]; do
      if [ "$1" = "--out" ]; then shift; printf '%s\\n' new > "$1/new-evidence"; break; fi
      shift
    done
    ;;
esac
exit 0
''')
    fake.chmod(0o755)
    dest = temp / 'published'
    dest.mkdir()
    (dest / 'historical-raw').write_text('must be preserved')
    env = dict(os.environ, PATH=str(bin_dir) + ':' + os.environ['PATH'], TIER='S',
               P7_ARTIFACTS=str(dest), TMPDIR=str(temp))
    result = subprocess.run(['sh', 'load/scripts/p7-chaos.sh', 'run', '--matrix', '--run-id', 'b14-offline-fake'],
                            env=env, capture_output=True, text=True)
    print('publication-with-existing-destination:', 'exit=' + str(result.returncode),
          'historical_preserved=' + str((dest / 'historical-raw').exists()),
          'new_published=' + str((dest / 'new-evidence').exists()))
```

Фактический stdout, exit 0:

```text
positive-synthetic-51-cells-77-attempts: ACCEPTED
missing-76-attempt-streams: ACCEPTED
baseline-only-recovery: ACCEPTED
foreign-attempt-identity-and-invalid-verdict: ACCEPTED
object-checksum-disagrees-with-database: ACCEPTED
wrong-prometheus-job: ACCEPTED
expected-absence: ['matched-absent', 'matched-absent'] evidence=invalid
restore-base-compose: call('up', '-d', '--force-recreate', '--no-deps', '--wait', '--wait-timeout', '180', 'app', 'queue-worker', timeout=240)
toxiproxy-with-no-fault: ACKNOWLEDGED
multipart-object-sample-keys: ['cas/final'] joins= ['matched', 'matched'] evidence=valid
publication-with-existing-destination: exit=0 historical_preserved=False new_published=True
```
