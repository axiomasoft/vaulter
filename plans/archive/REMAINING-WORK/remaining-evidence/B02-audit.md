# B02 — независимый аудит R09/R10

## Повторный аудит оставшихся нарушений B02-01 — 2026-09-27

**Вердикт: оба оставшихся нарушения B02-01 — proved fixed / closed в проверенном scope. Открытых находок по этим двум пунктам нет. B02-02 остается closed; повторная приемка этого пункта не выполнялась.** Этот вывод заменяет open / Major из раздела от 2026-09-26 ниже; прежний отчет сохранен как история. Закрытие дефектов не принимает старый полный run с добавленной в памяти telemetry и не создает нового полного P6/SLO-вердикта.

Основание: [последний раздел R09](R09.md#b02-01-remaining-seams--2026-09-27) и runtime [52cc42c8-1cb5-48a5-8e5b-0a3c0b9b0201](../../../../VLT-LOAD/artifacts/P6-R09/52cc42c8-1cb5-48a5-8e5b-0a3c0b9b0201/). Проверен worktree на HEAD `9bcded42e1b7fae30f8cce61b8f3ceab09868e07`; источник runtime — его архив и manifest, в котором HEAD `668b84f5360f07f7b961fd54ea20be5f14f38968`, `git_dirty=true`. Авторский `verification.json` сверен с заново выполненным readback, собственным пересчетом JSONL и дополнительными отрицательными контролями. Live stand не запускался.

### Остаток 1: retry-command memory seam — proved fixed

[QueueProbeCommand.php:291](../../../../load/app/Console/QueueProbeCommand.php#L291) вызывает `Artisan::call('vaulter:retry-webhooks', [], $output)`, проверяет exit и текстовый dispatch count. Это реальная команда [RetryWebhooksCommand.php:29](../../../../packages/core/src/Console/RetryWebhooksCommand.php#L29), вызывающая dispatcher. `CommandFinished` зарегистрирован в load provider и проходит через [RecordCommandPeakMemory.php:28](../../../../load/app/Listeners/RecordCommandPeakMemory.php#L28), который записывает PHP peak под точным именем команды. Probe возвращает внутренний `status`; [probe():59](../../../../load/gates/p6_live_matrix.py#L59) учитывает его при вычислении `ok`, даже если внешняя обертка завершилась с exit 0.

В новом runtime независимо подтверждены пять retry ticks по 1000 и шестой пустой tick, все с `exit=0`, `status=0`, `ok=true`. Получены 5000 уникальных завершенных UUID и 5000 уникальных sink event/slot pairs. Каждому UUID соответствуют ровно по одному queueing/queued/processing/processed, с допустимым порядком времени. Итоговая память:

| Источник | Измерено, B |
|:--|--:|
| Direct `vaulter_command_peak_memory_bytes{command="vaulter:retry-webhooks"}` | 40 370 176 |
| Сохраненный Prometheus query той же команды | 40 370 176 |
| Максимальный PHP peak в retry ticks | 40 370 176 |
| Максимальный OS RSS из `/usr/bin/time` | 59 961 344 |

Последний пустой tick имеет PHP peak 36 175 872 B. Registry использует `aggregation=max` и атомарный max для этой gauge ([RedisMetricsRegistry.php:134](../../../../load/app/Observability/RedisMetricsRegistry.php#L134)), поэтому он не затирает измерение заполненных ticks. PHP peak и OS RSS — разные величины; равенство между ними не требуется и не заявляется. Этот audit подтверждает seam, не вводит новый SLO-64 verdict.

[exposition_totals():112](../../../../load/gates/p6_evidence.py#L112) выбирает только series `command="vaulter:retry-webhooks"`; положительная gauge другой команды ее не заменяет. [validate_p6_metrics():150](../../../../load/gates/p6_evidence.py#L150) требует обязательную, конечную и положительную величину. [verify_v2():1012](../../../../load/gates/p6_live_matrix.py#L1012) применяет проверку к direct exposition и сохраненному query. Отсутствующая family/series/query, ноль, отрицательное и non-finite значение отклонены в выполненных ниже контролях.

### Остаток 2: relay counter против volume + steady — proved fixed

В неизмененном полном run `bc199ced-5b0f-4e77-b5ca-1a73055c0bfa` собственный пересчет первичного `queue/lifecycle.jsonl` подтвердил **10 000** UUID в `volume-relay`, attempt 1, и **36 000** UUID в `product-realistic.relay.i20.f1.2xx`, attempt 1. Множества не пересекаются; для каждого UUID проверены четыре уникальных lifecycle события и порядок timestamps без вызова `p6_evidence.lifecycle()`. Следовательно, подтвержденный минимум — **46 000**, независимо от сохраненного summary counter.

[confirmed_relay_rows():134](../../../../load/gates/p6_evidence.py#L134) вычисляет этот минимум по первичному ledger, отказывает при неполных окнах или пересечении UUID. Его используют и финальный [capture_prometheus():349](../../../../load/gates/p6_live_matrix.py#L349), и `verify_v2()`. Обе metric copies проверяются против `max(volume ticks, confirmed ledger)`. Начальный polling `p6_metrics_reached()` обоснованно сохраняет volume-only минимум: steady window в этой точке еще не выполнено.

В новом **volume-only** runtime подтверждены только 10 000 relay UUID, 101 tick и 10 000 уникальных sink pairs; relay counter direct=query=10 000 соответствует его scope. Минимум 46 000 проверен на старом полном ledger, а не приписан новому runtime. Новое и старое evidence не объединялись в один якобы полноценный run.

### Отрицательные контроли и граница overlay

Контроли запускались через `verify_v2()` на неизмененных файлах старого полного run. Только для изоляции второго дефекта `Path.read_text` подменял в памяти нулевую retry gauge и отсутствующий memory query положительным измерением 40 370 176 B. Все остальные файлы baseline оставались исходными. **Успех этого baseline означает проверку verifier на тестовых входах, а не приемку исторического runtime.** Положительный baseline проходит 118 attempt checks; неизмененный исторический run отклоняется как не имеющий рабочего memory seam.

| Контроль | Фактический результат |
|:--|:--|
| Исторический run без overlay | Отклонен: `vaulter_command_peak_memory_bytes=0.0 ... minimum 1` |
| Только memory overlay | Принят как тестовый baseline |
| Нет memory family; нет retry series при наличии других команд | Отклонены: `missing P6 metrics` |
| Удалена retry series, а другой команде задано 90 000 000 B | Отклонен; чужая положительная gauge не маскирует отсутствие seam |
| Нулевые direct+query; нулевой query при положительном direct; отсутствующий query | Все отклонены |
| Direct NaN; query NaN, Infinity или −1 | Все отклонены |
| Relay direct/query/legacy одновременно 0, 10 000 или 45 999 | Все отклонены с `minimum 46000` |
| Relay query-only 10 000 или 45 999; direct-only 45 999 | Все отклонены |
| Relay direct/query/legacy ровно 46 000 | Принят как граничный тестовый контроль |
| Нет steady window или удалены его processed events | Отклонены: `incomplete confirmed relay ledger` |
| Пересечение UUID volume и steady | Отклонено в сфокусированном unit test |

Readback также повторяет прежний stale retry-summary контроль B02-02; он остался rejected. Это регрессионная проверка при исполнении существующего скрипта, а не переоткрытие закрытой находки.

### Выполненные проверки и сохранность

Команды выполнены из корня репозитория, Python запускался с `-B`:

| Проверка | Exit / результат |
|:--|:--|
| `python3 -B plans/remaining-evidence/R09-B02-seam-checks/readback.py plans/VLT-LOAD/artifacts/P6-R09/52cc42c8-1cb5-48a5-8e5b-0a3c0b9b0201` | **0**; runtime и все заявленные readback controls совпали с сохраненным `verification.json` |
| `python3 -B -m unittest discover -s load/gates -p 'test_p6_evidence.py'` | **0; 18 tests, OK** |
| `python3 -B load/gates/p6_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa --prior-digest bbfa77d90edc2664a158b37aa7cab0a8a936c7af8bfe90cefac3e745a184a0e6` | **1**, ожидаемое отклонение нулевой retry gauge |
| Собственный inline Python: JSONL → UUID/source groups; независимый SHA-256 и tarfile readback | **0**; 10k/5k нового runtime, 10k+36k старого, архивы и ticks подтверждены |
| Собственные inline `verify_v2()` overlays: non-finite/negative query, чужая gauge, separate direct/query relay corruption; неполный ledger | **0**; все отрицательные случаи отклонены, положительный baseline принят только как тест |

Полный P6 unittest discovery не повторялся: `test_p6_queue.py` материализует fixtures в репозитории, что выходит за read-only scope этого аудита. PHP/Pest, package suites, Pint, PHPStan, PostgreSQL/live load, миграции и reset не запускались. Записанные автором 35 Python tests и 8 PHP tests остаются его проверками; в этом аудите заново выполнены именно 18 чистых evidence tests выше.

Независимо проверены все **22** файла raw archive против дерева runtime и **299** source hashes против runtime source archive; сохраненный launcher также совпал с текущим `runtime.py`. Шесть manifest source paths отличаются от текущего worktree: `load/app/LoadServiceProvider.php`, `load/gates/p5_live_matrix.py`, `load/gates/p7_chaos.py`, `load/gates/p7_injectors.py`, `load/gates/p7_live_matrix.py`, `load/gates/test_p7_chaos.py`. В provider diff содержит только import/registration `ChaosOperationCommand`; retry listener не изменен. Проверенные QueueProbeCommand, P6 evidence/verifier, listener, recorder и registry побайтно совпали с runtime archive. Исторический запуск не объявляется доказательством всех последующих изменений worktree.

| Идентичность | SHA-256 |
|:--|:--|
| Runtime raw tree `52cc42c8…` | `50c97d1e52a025a8131982fbc6925900391132ccd4617106562f1a2da4f38e7a` |
| Его raw archive | `8cf2f23fc85ee184cc5b60f760eca6b253caab665147a191a6eb628a8283f186` |
| Его runtime source archive | `e63366f867c96db0c72dfa0dfa89a914c4e33f5e81c7baf970fb85902d249b90` |
| Исторический полный raw tree `bc199ced…` | `bbfa77d90edc2664a158b37aa7cab0a8a936c7af8bfe90cefac3e745a184a0e6` |
| `load/app/Console/QueueProbeCommand.php` | `ee93d5596155008011cca5706651cd454d358d74ef600d86e5daf0f9f27e7953` |
| `load/gates/p6_evidence.py` | `e58014c932bd82cd5bb6ea87f7308d1cfcf03de1942e087a31b2bcbd4de913cf` |
| `load/gates/p6_live_matrix.py` | `f9f61a4e8aa8b01a5886c0f07950d30258c473f1d6861d7cd9d2e2b25007b2f6` |

Снимок SHA-256 до/после аудита охватывает 7195 существующих tracked/untracked файлов в `load/`, `packages/`, `plans/` и ключевые корневые инструкции/config, исключая только этот отчет. Digest отсортированных пар `path + NUL + raw SHA-256(file)` — `70ec9d70b02fd3b8877ae8e771a9a025dd3b63677613afc427dbefbf8df54a55`; неизменность проверена. Изменен только `plans/remaining-evidence/B02-audit.md`.

**Граница приемки:** оставшиеся дефекты B02-01 закрыты по scoped runtime и проверенным guards. Новый запуск доказывает 10k/5k volume и memory seam; он не содержит нового 30-минутного steady окна или полной матрицы. Старый полный run продолжает отклоняться усиленным gate без overlay. Его сохраненные SLO-61/recovery/duplicate RED и прочие исторические verdicts не переписываются. Новая приемка полного P6 evidence требует настоящего полного run на выбранном наборе исходников. R statuses, R09 carrier, raw runs и SLO-пороги не изменены.

---

## Повторный аудит только B02-01/02 — 2026-09-26

**Вердикт: B02-01 — частично исправлено, остается open / Major; B02-02 — proved fixed, closed.** Исправление привязки recorder к active run и пересчет volume подтверждены. Полная приемка P6 telemetry пока не подтверждена: обязательная метрика памяти retry-команды остается нулевой, а проверка согласованности relay counter с ledger ограничена volume и пропускает потерю последующих измерений. Это продолжение B02-01, не новый аудит core/R10. Статусы R не изменены.

Проверен фактический worktree на HEAD `668b84f5360f07f7b961fd54ea20be5f14f38968`, включая существовавшие dirty/untracked изменения. Основание — исходные B02-01/02 ниже и [R09 follow-up](R09.md#b02-0102-follow-up--2026-09-26). Новое evidence: [run `bc199ced-5b0f-4e77-b5ca-1a73055c0bfa`](../../../../VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa/). Выводы получены чтением исходников, независимым пересчетом raw files и отрицательными контролями, а не принятием авторского `verification.json` на доверии.

### B02-01 — остаток Major / issue

**Исправленная часть.** [LoadExpositionRecorder.php](../../../../load/app/Observability/LoadExpositionRecorder.php), строки 24–29, 70–82: resolver читает `RedisMetricsRegistry::activeRunId()` при каждой записи вместо захваченного environment. `/metrics?run_id=…` выбирает указанный registry run. В новом сохраненном exposition relay counter равен **57 506**, а не историческому нулю; worker histograms ненулевые. Во всех девяти проверяемых sample totals Prometheus и direct exposition совпали. Контроль с нулевым relay counter теперь отклоняется. Отдельный [rejected run `aecd7112…`](../../../../VLT-LOAD/artifacts/P6-R09/aecd7112-8d5c-4fbc-a7ae-a4fe468ced57/rejected.json) сохранен как rejected; причину старого worker runtime заново на live stand не воспроизводили.

**Остаток 1 — обязательный memory seam не доказан.** В [новом `exposition.prom`](../../../../VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa/prometheus/exposition.prom), строка 648, сохранено `vaulter_command_peak_memory_bytes{command="vaulter:retry-webhooks"} 0`. Все семь command-memory series равны нулю. При этом max OS RSS шести retry ticks — **59 752 448 B**, PHP peak также ненулевой. Механизм виден в [QueueProbeCommand.php](../../../../load/app/Console/QueueProbeCommand.php), строки 289–313: probe вызывает dispatcher напрямую. [RecordCommandPeakMemory.php](../../../../load/app/Listeners/RecordCommandPeakMemory.php), строки 15–42, записывает gauge только для перечисленных Artisan-команд; `load:queue-probe` в список не входит.

[P6_METRIC_NAMES / validate_p6_metrics](../../../../load/gates/p6_evidence.py), строки 7–17, 128–150, вообще не требуют эту family; [verify_v2](../../../../load/gates/p6_live_matrix.py), строки 1005–1020, использует тот же список. **Отрицательный контроль:** удалить из читаемой in-memory копии exposition все samples `vaulter_command_peak_memory_bytes` — `verify_v2()` завершается без исключения. Штатный verifier принял и исходный run с нулевой gauge, exit 0.

**Принятый контракт:** [slo-gates.md, SLO-64, §5.3 и §8](../../../../VLT-LOAD/slo-gates.md) включает `vaulter_command_peak_memory_bytes` в обязательные seams SLO-64…66 и не разрешает соответствующий SLO-вердикт без реализованной метрики. Это тот же контракт исходного B02-01. Значения RSS из `/usr/bin/time` остаются самостоятельным измерением; они не доказывают работу отсутствующей metric seam и не должны сравниваться с PHP gauge как идентичные величины. Нарушение потолка памяти этим аудитом **не** обнаружено.

**Остаток 2 — gate принимает telemetry только от volume при полном ledger.** `validate_p6_metrics()` требует для `vaulter_outbox_relayed_total` только сумму `volume.relay_ticks`, то есть **10 000**. В том же run независимо подтверждены еще **36 000** завершенных UUID steady window `product-realistic.relay.i20.f1.2xx`, attempt 1. Контроль заменил relay counter на 10 000 одновременно в direct exposition, scalar query и legacy `relayed.json`, сохранив все lifecycle/ticks/attempts. `verify_v2()` принял этот набор без исключения. Следовательно, исчезновение relay telemetry после volume не обнаруживается даже при минимум **46 000** подтвержденных relay rows. Сравнение `Prometheus <= exposition` это расхождение не устраняет.

**Влияние и disposition.** Ненулевые samples нового run доказывают существенную часть исправления, но повторно используемый gate еще допускает неполное покрытие обязательных seams и несогласованность с известным ledger. В настоящем run relay counter **57 506**, а не 10 000: контроль доказывает дефект проверки, не реальную потерю этих измерений. Для закрытия B02-01 нужны evidence работающего memory seam на нужном пути и отказ gate при отсутствии этого seam и при счетчике ниже подтвержденного volume + steady ledger. Пороги SLO, raw runs и исторические verdicts не менять; после исправления нужен scoped re-review.

### B02-02 — proved fixed / closed

[volume_measurements / validate_volume / slo_report](../../../../load/gates/p6_evidence.py), строки 70–107, 160–183, вычисляют rate/p95 из измерений и сравнивают четыре производных summary-поля; SLO использует пересчитанные значения. [verify_v2](../../../../load/gates/p6_live_matrix.py), строки 981–984, сверяет обе embedded tick lists с первичными JSONL.

- Исходный отрицательный сценарий — все шесть retry ticks по 3 s при старом summary — теперь отклонен с `stale volume summary: retry_rows_per_second`; отдельный `slo_report()` возвращает SLO-64 **red** (277.777778 rows/s, p95 3 s).
- После согласования embedded summary с измененными ticks, но при исходном JSONL, verifier отклоняет `volume retry ticks differ from primary JSONL`.
- Независимые изменения relay rate и relay p95 отклонены с `stale volume summary` соответствующего поля.
- На настоящем новом run raw JSONL и embedded ticks совпадают, независимый пересчет всех четырех summary-полей совпал.

Знаменатель relay rate сохранен по принятому collector-контракту: **37.615169278986286 s wall duration**, включая вызовы CLI, а не **19.928162041 s** суммы внутренних ticks. `relay_seconds` — первичное wall measurement; независимых start/end timestamps для его повторного измерения нет. Retry использует сумму tick durations **1.813568856 s**. Закрытие B02-02 не утверждает, что relay wall duration заново измерена из JSONL.

### Независимые результаты и выполненные проверки

| Volume | Rows / ticks / completed UUID | Rate, rows/s | Tick p95, s | Max OS RSS, B |
|:--|--:|--:|--:|--:|
| Relay | 10 000 / 101 / 10 000 | 265.8501926664597 | 0.235588967 | 57 458 688 |
| Retry | 5 000 / 6 / 5 000 | 2756.994852143623 | 0.382402887 | 59 752 448 |

Volume lifecycle пересчитан без `p6_evidence.lifecycle()`: для каждого UUID проверено наличие ровно одного queueing/queued/processing/processed. В steady attempt 1 — 36 000 таких UUID. Volume sink содержит 10 000 / 5 000 строк; все lifecycle/sink rows относятся к новому run. Это scoped readback, не повторный аудит всех concurrency/interleaving случаев.

| Sample total | Direct exposition = Prometheus query |
|:--|--:|
| `vaulter_outbox_relayed_total` | 57 506 |
| `vaulter_outbox_relay_duration_seconds_count` | 2 012 |
| `vaulter_outbox_endpoints_scanned_total` | 126 626 |
| `vaulter_webhook_queue_wait_seconds_count` | 96 669 |
| `vaulter_webhook_delivery_duration_seconds_count` | 96 669 |
| `vaulter_webhook_delivered_total` | 76 322 |
| `vaulter_webhook_retry_tick_seconds_count` | 10 |
| `vaulter_webhook_retry_lockset_rows_count` | 10 |
| `vaulter_webhook_retry_lockset_rows_sum` | 7 003 |

Это девять **sample totals**, не девять отдельных metric families и не полный набор обязательных P6 seams. Равенство query/exposition проверено независимым парсером; полная сверка каждого outcome с ledger в этом re-review не заявляется.

Все команды запускались из корня репозитория с `python3 -B`; отрицательные контроли меняли только объекты/ответы чтения в памяти.

| Проверка re-review | Фактический результат |
|:--|:--|
| `python3 -B -m unittest discover -s load/gates -p 'test_p6*.py'` | **exit 0, 33 tests, OK**; `integrity defects` — ожидаемый вывод отрицательного fixture test |
| `python3 -B load/gates/p6_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa --prior-digest bbfa77d90edc2664a158b37aa7cab0a8a936c7af8bfe90cefac3e745a184a0e6` | **exit 0**, digest совпал; 46 cells / 118 attempts в принятом verifier наборе. Ограничения B02-01 выше сохраняются |
| Неизмененный новый run через `verify_v2()` | Принят без исключения — baseline отрицательных контролей |
| Stale retry ticks, stale relay rate/p95, embedded/raw mismatch | Отклонены; B02-02 подтвержден исправленным |
| Relay counter = 0 | Отклонен: `below ledger/tick minimum 10000` |
| Удаление command-memory samples; relay counter = volume-only 10 000 | **Оба приняты** — воспроизведение оставшегося дефекта B02-01, не успешный guard |
| Старые R09/R10 через тот же CLI verifier с прежними `--prior-digest` | **Оба exit 1**, `run-scoped P6 metric exposition is missing`; digest проверен отдельно, до verifier rejection |
| Независимый SHA-256 всех 299 файлов из нового `source_sha256` | Все совпали с текущими исходниками; ни одного расхождения |

Короткое воспроизведение оставшегося B02-01 без записи файлов (обе проверки должны были бы отклонить неполную telemetry, но завершаются `ACCEPTED`):

```bash
python3 -B - <<'PY'
import json, sys
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, 'load/gates')
import p6_live_matrix as p
r = Path('plans/VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa')
m = json.loads((r / 'run-manifest.json').read_text())
c = json.loads((r / 'cell-verdicts.json').read_text())
v = json.loads((r / 'database/tier-s-volume.json').read_text())
ex = r / 'prometheus/exposition.prom'
body, read = ex.read_text(), Path.read_text
def check(label, replacements):
    def read_override(path, *args, **kwargs):
        return replacements[path] if path in replacements else read(path, *args, **kwargs)
    with patch.object(Path, 'read_text', new=read_override):
        p.verify_v2(r, m, c, v)
    print(label, 'ACCEPTED')
check('missing memory seam', {ex: '\n'.join(
    line for line in body.splitlines()
    if not line.startswith('vaulter_command_peak_memory_bytes')) + '\n'})
replacements = {ex: '\n'.join(
    'vaulter_outbox_relayed_total 10000'
    if line.startswith('vaulter_outbox_relayed_total ') else line
    for line in body.splitlines()) + '\n'}
for filename in ('vaulter_outbox_relayed_total.json', 'relayed.json'):
    path = r / 'prometheus' / filename
    payload = json.loads(path.read_text())
    payload['data']['result'][0]['value'][1] = '10000'
    replacements[path] = json.dumps(payload)
check('volume-only relay telemetry', replacements)
PY
```

### Идентичность и границы re-review

Новый raw-tree digest независимо подтвержден: `bbfa77d90edc2664a158b37aa7cab0a8a936c7af8bfe90cefac3e745a184a0e6`. Новый manifest сохраняет `git_dirty=true`, tier S и fingerprint `e4d77160ccfe36e5acb2894ef136a67146b206a1b91772f1ff9ce0c1103334dc`. Проверка source hashes подтверждает совпадение файлов, но не является новым наблюдением загруженного кода исторических worker-процессов.

```text
bf040e1c264065cee801dcb502d00d6b2e03642696e54daa1470486e90553f0d  load/app/Observability/LoadExpositionRecorder.php
f7ad25558b673ace5c82db2aefc435d198ba212f68e34cb45acc793971122981  load/gates/p6_evidence.py
95c6e0aeaad8d7b1ec726b7b3c8d4af65b8b72106cdd3aad5ccdf5e972657f3a  load/gates/p6_live_matrix.py
```

Старые raw-tree digests заново вычислены и совпали: R09 — `7bac6725d3af18ac57789e7f70f1fdc3d78b4f28fdc051ff7de2dda39124c270`, R10 — `100d368f90e4c985a40fb4bb624128bda8ee6e483727dc4a7d4db302c9a2dad7`. SHA-256 старого R09 raw archive также прежний: `fc689be535f63e7ef57ca65a01701639ecf84695baea0b7681fd52254fd93662`. Эти деревья включают сохраненные verdicts; исторические результаты не переписаны и не получили ретроактивного telemetry admission.

Сохраненный новый SLO report остается **SLO-58 GREEN / SLO-61 RED / SLO-64 GREEN**, с limiting delivery throughput **16.152479405588757 jobs/s**. Числа volume подтверждены; безусловная приемка обязательных seams не следует из этого GREEN до закрытия B02-01. Исторические recovery RED, concurrent duplicate residual, неизвестные старые failed-job identities, M/L и суточный budget сохраняются. Новых выводов об исправлении core/R10 этот re-review не дает.

PHP/Pest, Pint, PHPStan, PostgreSQL lane, live load и изменение stand в этом re-review **не запускались**. Существующий PHP-тест recorder прочитан; авторские результаты PHP-проверок из R09 не выдаются за новые проверки аудитора. Старые failed checks и ограничения первого аудита ниже остаются историческими фактами. Изменен только этот отчет; код, carriers, R states, raw evidence, journal/state не редактировались. Commit/push не выполнялись. Зависимой работе, требующей принятой P6 telemetry, clean admission пока не дан.

## Исторический аудит — 2026-09-24

Ниже сохранен исходный отчет без переоценки его прошлых наблюдений. Его две open-находки, команды, hashes и результаты относятся к состоянию **до** исправления R09; актуальные disposition B02-01/02 указаны выше. Историческая команда B02-02 больше не воспроизводит прием stale summary на исправленном verifier.

Дата: 2026-09-24. Основание: [REMAINING-WORK.md, §7](../REMAINING-WORK.md#7-owner-execution-batches-and-model-routing). Аудированный HEAD: `de65b562ed4a8d8f9c578150706233ec2d1ba044`, с существовавшими до аудита dirty/untracked изменениями. Проверен фактический runtime-код, а не только HEAD. Единственный сохраняемый результат аудита — этот файл.

**Вердикт: две открытые находки Major в доказательном контуре R09, унаследованные прогоном R10. Безусловная приемка tier-S proof не подтверждена.** В ограниченном изменении core R10 нового доказанного дефекта не обнаружено: SQL-фильтр и страницы по 100 работают в проверенных сценариях. Числа drain/timing из отчетов воспроизводятся по архивам, но этого недостаточно для закрытия принятого контракта наблюдаемости.

## Находки

### B02-01 — Major / issue: живой scrape принят за работающие метрики текущего run

**Место:** [p6_live_matrix.py](../../../../load/gates/p6_live_matrix.py), строки 51–53, 135–160, 292–299, 310–327, 918–937; [LoadExpositionRecorder.php](../../../../load/app/Observability/LoadExpositionRecorder.php), строки 25–30, 68–82. Владелец исправления: R09 / load harness.

**Наблюдение.** В обоих принятых прогонах `prometheus/relayed.json` содержит успешный ответ Prometheus и единственный `vaulter_outbox_relayed_total` со значением **0**:

- [R09 raw](../../../../VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52/prometheus/relayed.json): timestamp `1790201949.435`;
- [R10 raw](../../../../VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb/prometheus/relayed.json): timestamp `1790234756.896`.

Это не соответствует обработанным событиям: только volume и steady window дают 10 000 + 36 000 relay rows в каждом прогоне, подтвержденных ledger. Файлов с измерениями retry lock-set/tick и остальными обязательными P6 metric families в этих raw trees нет. Сохраненные 915/934 здоровых scrape samples доказывают доступность exporter, но не поступление измерений продукта.

**Механизм по исходникам.** `metrics_open()` открывает run в Redis; `RedisMetricsRegistry::openRun()` заранее создает нулевые серии, а `/metrics` экспортирует active run. Однако recorder берет идентификатор только из `env('LOAD_RUN_ID')`. `probe()` передает CLI-процессу `LOAD_QUEUE_CONTEXT`, запуск второго worker тоже не задает текущий `LOAD_RUN_ID`; файл контекста используется listener'ом lifecycle, а не recorder'ом. Перезапуск существующих контейнеров не обновляет их environment. Поэтому ledger может относиться к новому run, а метрики — пропускаться либо записываться в другой run. Конкретное историческое значение environment не сохранено; утверждение о точном значении не делается. Нулевой сохраненный счетчик и отсутствие привязки recorder к текущему run подтверждены независимо от этой неопределенности.

**Нарушенный контракт:** [slo-gates.md, §5/§5.3/§8](../../../../VLT-LOAD/slo-gates.md) требует обязательные metric seams для SLO-58…66 и запрещает выносить соответствующий SLO-вердикт без них; [R09 card](../REMAINING-WORK.md) требует живой SUT scrape и исправление P6 proof sources. `verify_v2()` проверяет только `up`/freshness и не проверяет даже уже сохраненный `relayed.json`. Обе штатные проверки вернули exit 0 при указанном расхождении.

**Влияние и disposition.** Нельзя считать закрытым L-P6-04 / runtime metrics proof или переносить без оговорок SLO-58/64 GREEN как полностью принятое доказательство. Подтвержденные ledger/tick measurements сохраняются; результат не переклассифицируется в доказанное нарушение производительности. SLO-61 RED сохраняется как наблюдавшийся отрицательный результат, не становится GREEN или закрытым. Исправление в R09 должно связать recorder всех процессов с run, сохранить ненулевые релевантные counters/histogram counts и проверить их согласованность с ledger; нужны новое доказательство на исправленном runtime и Astra re-review. Старые raw runs не переписывать.

### B02-02 — Major / issue: verifier доверяет агрегатам volume вместо пересчета из тиков

**Место:** [p6_evidence.py](../../../../load/gates/p6_evidence.py), строки 58–70, 80–98; [p6_live_matrix.py](../../../../load/gates/p6_live_matrix.py), строки 905–931, 975–976. Владелец исправления: R09 / load harness.

**Сценарий и воспроизведение.** В оперативной памяти загружен volume R10; у всех шести retry ticks `elapsed_ns` заменен на `3_000_000_000`, а сохраненные агрегаты оставлены прежними. `verify_v2()` завершился без исключения, exit 0. Реальные агрегаты этого контрольного примера: p95 **3 s > 2 s**, throughput **5000 / 18 = 277.78 rows/s < 500**, но `slo_report()` продолжает возвращать SLO-64 GREEN из старых полей summary.

`validate_volume()` проверяет положительную длительность, суммы строк и drain flags. `slo_report()` читает готовые `retry_tick_p95_seconds` и `retry_rows_per_second`; он не вычисляет их из `retry_ticks`. Сам verifier также не сверяет `database/volume-{relay,retry}-ticks.jsonl` с копиями тиков в `tier-s-volume.json`. Для relay p95/throughput используется тот же подход доверия готовым полям.

**Влияние.** Ошибка collector/агрегации или устаревший summary может пройти публикационный gate с ложным GREEN. Digest подтверждает неизменность байтов после вычисления digest, но не соответствие summary исходным тикам. Это дефект повторно используемой проверки, а не утверждение, что реальные R09/R10 имели тики по 3 s: независимый пересчет настоящих артефактов совпал с опубликованными числами.

**Нарушенный контракт:** [REMAINING-WORK.md, §2](../REMAINING-WORK.md) запрещает подмену числителей/знаменателей и требует фактический verifier outcome; [R09 carrier](R09.md) заявляет пересчет volume completion и SLO results. [slo.md, §3.4](../../../../VLT-LOAD/slo.md) фиксирует ≥500 rows/s и p95 ≤2 s. Проверка этих порогов должна опираться на измеренные тики, а не на несверенный summary.

**Disposition: open.** В R09 нужен пересчет и сопоставление производных значений с первичными артефактами, с отказом при расхождении; добавить отрицательный контроль для stale summary. После исправления повторно проверить сохраненные runs и получить Astra re-review. Сам по себе B02-02 не требует нового load run, если все необходимые исходные измерения сохранены; B02-01 требует отдельного runtime evidence.

Воспроизводящая команда из корня репозитория; ничего не записывает:

```bash
python3 -B - <<'PY'
import json, copy, sys
from pathlib import Path
sys.path.insert(0, 'load/gates')
import p6_live_matrix as p
root = Path('plans/VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb')
manifest = json.loads((root / 'run-manifest.json').read_text())
verdicts = json.loads((root / 'cell-verdicts.json').read_text())
volume = copy.deepcopy(json.loads((root / 'database/tier-s-volume.json').read_text()))
for tick in volume['retry_ticks']:
    tick['elapsed_ns'] = 3_000_000_000
p.verify_v2(root, manifest, verdicts, volume)
print('ACCEPTED: retry p95=3 s, rate=277.78 rows/s, stored SLO-64 GREEN')
PY
```

## Проверенная область и независимые результаты

Прочитаны R09/R10 cards и carriers, SLO/gate contracts, P6 findings, scoped commit `de65b56`, сохраненные R09 patches и фактические реализации collector/verifier/listener/sink. Core-проверка охватывает subscription filter, keyset enumeration, claim/fan-out boundary, retry claim и terminal guard доставки. Исследованы существующие тесты до их запуска.

Изменение R10 использует `whereJsonContains('events', $event)`, выбирает ключ модели и `lazyById(100)`. Проверена установленная реализация Laravel `orderedLazyById()`: default key берется из модели, следующая страница идет после последнего ключа. Сценарий 205 подходящих + 120 неподходящих + один inactive endpoint проходит; новый interleaved test проверяет второй tick **после** первого claim. Он не моделирует две независимые транзакции, одновременно пытающиеся захватить одну строку.

Отдельно сверены raw overlap controls: R09 имеет шесть enqueue confirmations без processing/processed для barrier; R10 имеет шесть enqueue/processing/processed и шесть sink rows с `cell_id=overlap`. Это ограниченная проверка текущего поведения, не доказательство всех crash/retry interleavings. `claimPending()` и `fanOut()` по-прежнему разделены commit boundary; доказательства crash recovery внутри этого промежутка B02 не добавляет.

| Независимо проверенный показатель | R09 | R10 |
|:--|--:|--:|
| Attempts / product attempts | 118 / 108 | 118 / 108 |
| Volume relay: ticks / rows / remaining | 101 / 10 000 / 0 | 101 / 10 000 / 0 |
| Relay rows/s; tick p95 | 275.387705; 0.226789 s | 275.481835; 0.208460 s |
| Volume retry: ticks / rows / remaining | 6 / 5 000 / 0 | 6 / 5 000 / 0 |
| Retry rows/s; tick p95 | 2887.840801; 0.360452 s | 2851.013775; 0.362344 s |
| Max retry count per tick; OS peak RSS | 1000; 60 108 800 B | 1000; 60 170 240 B |
| Steady UUID enqueue/processing/completion | 36 000 / 36 000 / 36 000 | 36 000 / 36 000 / 36 000 |
| Steady queue-wait p95 | 0.955181230 s | 0.963841210 s |
| Steady process p95 | 0.011666312 s | 0.010821322 s |
| Limiting 200-event/50-ms cell, jobs/s | 16.637551 | 16.464971 |
| Unrecovered product attempts | 25 | 26 |
| Serial duplicate control: dispatch / HTTP | 2 / 1 | 2 / 1 |
| Concurrent duplicate control: dispatch / HTTP | 2 / 2 | 2 / 2 |
| `up=1` range samples, interval | 915, 5 s | 934, 5 s |

Независимый Python read-only пересчет не вызывал `p6_evidence.lifecycle()` для таблицы: JSONL группировался по run/cell/attempt/UUID, проверялись уникальность lifecycle sources, разности timestamp и nearest-rank p95. Для volume проверены равенство JSONL ticks и вложенных ticks, суммы строк и формулы throughput/p95. В volume sink подтверждены 10 000 и 5 000 HTTP без повторных event/slot pairs; в steady — 36 000. Проверены принадлежность всех lifecycle/sink rows соответствующему run и непрерывная нумерация sink ordinal. Для остальных product attempts пересчет UUID joins, failure deltas и multiplicity выполнен штатным verifier; blanket-проверка всех его derived fields не заявляется.

## Выполненные проверки

Все команды запускались из `/home/vostrikov/projects/packages/vaulter`. Live load, mutation, PostgreSQL tests, reset stand и миграции shared DB не запускались.

| Проверка B02 | Exit / результат |
|:--|:--|
| `python3 -B -m unittest discover -s load/gates -p 'test_p6*.py'` | 0; 31 tests. Сообщение fixture `integrity defects` относится к ожидаемому отрицательному тесту; итог `OK`. |
| `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync ./vendor/bin/pest packages/core/tests/Webhooks/OutboxRelayTest.php packages/core/tests/Observability/P85/P85Section53CoreMetricsTest.php --compact --do-not-cache-result` | 0; 22 passed, 59 assertions, **7 risky**. PHP 8.4.1. |
| Та же env-комбинация и `./vendor/bin/pest load/tests/Feature/QueueLifecycleLedgerTest.php load/tests/Feature/QueueProbeEndpointTest.php load/tests/Feature/QueueSinkLedgerTest.php --compact --do-not-cache-result` | 0; 7 passed, 38 assertions. |
| `python3 -B load/gates/p6_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52 --prior-digest 7bac6725d3af18ac57789e7f70f1fdc3d78b4f28fdc051ff7de2dda39124c270` | 0; digest совпал. Ограничения verifier — B02-01/02. |
| `python3 -B load/gates/p6_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb --prior-digest 100d368f90e4c985a40fb4bb624128bda8ee6e483727dc4a7d4db302c9a2dad7` | 0; digest совпал. Те же ограничения. |
| Python `tarfile` + SHA-256: сравнение архивов с raw trees без распаковки на диск | 0; по **147 файлов** в каждом raw archive совпали побайтно, наборы путей равны. |
| Python `tarfile` + SHA-256: runtime sources против manifest и текущего worktree | 0; по **292 файла** в каждом source archive совпали с manifest. R10 целиком совпал с текущими 292 source files; R09 отличается только `OutboxRelay.php`. |
| Независимый read-only пересчет таблицы выше | 0; совпадение реальных volume summary с тиками. |
| Контроль B02-02, только in-memory copy | 0; некорректные тики приняты — подтверждение дефекта, не успешность gate. |

Перед PHP-тестами проверены `phpunit.xml`, `tests/Pest.php`, `packages/core/tests/TestCase.php`, `load/tests/LoadTestCase.php`: выбранные тесты разрешают БД в SQLite `:memory:`, queue/cache изолированы; load test storage направлен в локальный testbench testing disk. Рекомендации навыков `laravel-testing:test-isolation-guard` и `laravel-testing:laravel-testing` применены к проверке изоляции; конфигурация проекта не менялась.

Полный core suite, PostgreSQL lane, Pint и PHPStan в B02 не повторялись. Их результаты в R10 остаются результатами автора R10, не новыми проверками аудитора. Из R09 сохраняются явные ограничения: полный load suite — **exit 2, 42 passed / 3 failed**; PHPStan — **exit 1, 60 findings** в before/after. Целевые проверки B02 не превращают эти результаты в GREEN.

## Идентичность источников и артефактов

Исходная ревизия обоих runs — `4d59d61a7b1ee2ada90f660d76536fdc8c155c84`, `git_dirty=true`. Их runtime source archive, а не один git SHA, определяет проверенный код. Сохраненный `config_fingerprint` у обоих — `e4d77160ccfe36e5acb2894ef136a67146b206a1b91772f1ff9ce0c1103334dc`. Это fingerprint настроек queue lane; он сам по себе не доказывает фактические cpuset/memory limits исторических контейнеров. Исторический stand в B02 не переизмерялся.

| Артефакт | SHA-256 |
|:--|:--|
| [R09 raw archive](R09-P6-3df3b9e1-32b8-4e15-8b8f-c4efffe28f52.tar.gz) | `fc689be535f63e7ef57ca65a01701639ecf84695baea0b7681fd52254fd93662` |
| [R10 raw archive](R10-P6-56823709-fe47-4173-b965-dc51ea523bcb.tar.gz) | `f6bc65f884473eb9f07b50672f955a329bc789d992220de44d9953bbac267923` |
| [R09 runtime archive](../../../../VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52-runtime-source.tar.gz) | `591c0c8ec66525396538a37041984ecb43309db5eda2c97989885b3ccbd36fdf` |
| [R10 runtime archive](../../../../VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb-runtime-source.tar.gz) | `dd2d180c06a338e1719e7d78201d09b02d105f92d51b6a3586c20ec0ae72b5b5` |
| R09 `source_sha256` map, canonical JSON¹ | `8c3c9da1ca40c677f62695dd5dcccdd60eef603d46e4a75310d54401b9f23239` |
| R10 `source_sha256` map, canonical JSON¹ | `86d54995f2b4e8c317819120108dae72960c2060ef0c2ab8b20c8c4af677aba7` |

¹ `sha256(json.dumps(source_sha256, sort_keys=True, separators=(',', ':')).encode())`; полный список индивидуальных hashes находится в соответствующем `run-manifest.json`.

Дополнительные точные hashes основных аудированных файлов:

```text
5bc6b5672e4e008f1e7c8c36ab8011bcb03621c4b214c3a74a75cf47b90a9a69  packages/core/src/Webhooks/OutboxRelay.php
051ead0c4d9a1c7d4400efacea67fc061f73643051992e9f691b5269bc1ffa3a  OutboxRelay.php в архиве R09
0d08065fb952b8ed4475ad581d9a2e0b6819b12e8b3d8319829f815295527d0e  packages/core/src/Webhooks/WebhookRetryDispatcher.php
d142de488e91ae162f4d11070a7aa02c5b9e17e6b490d2dc7e1dace9e9ea76cb  packages/core/src/Jobs/Webhooks/DeliverWebhook.php
abfd21719ef02f2a738b53fe29197daf35ad50886795457a9a18f0171c1194d5  packages/core/tests/Webhooks/OutboxRelayTest.php
d6e3bff393c6f259f7365e9c2ff4ad015ae5e9060cc342cc7cc948ce9b2d64d3  packages/core/tests/Observability/P85/P85Section53CoreMetricsTest.php
13fbdc4c281d4580d5db373b8c8d62adda067a766a8f2055ae011818f314d459  load/gates/p6_live_matrix.py
32dfa1b6ad322ab8076ca332abcc5b7168ee8b4cc5d39de0b3f8d4fb89fda6fc  load/gates/p6_evidence.py
c2ab8958887ca30223e92f3b16ab437a38c4b1342d93b4f39baf167648471548  load/app/Observability/LoadExpositionRecorder.php
068a75a333c6df434d16917383d36c6f9a24fe41bf8b0d75f578b440da22f99e  plans/REMAINING-WORK.md
db967a4b1cea5eeeafba244fffb08ee6e75ac12eb0ecfd02bab11e9a90ac162b  plans/VLT-LOAD/slo.md
9e67c6b42275c827ab5a69589b38c5fb0038662f31d199aab3baa66bfd552fa9  plans/VLT-LOAD/slo-gates.md
0d767b079cbf7d01efb364d33e4d72edfcf7e3029095dd81c649bf6f64daea1a  plans/remaining-evidence/R09.md
9898357886906667a525c538151d6a611066d9afeeced36e08fe1847c59586b3  plans/remaining-evidence/R10.md
```

## Остаточные риски и передача

- **SLO-61 tier S остается RED**; serial PHP sink ограничивает измеренный throughput. Эти числа не локализуют bottleneck исключительно в package code.
- **Concurrent duplicate остается:** два разных queue UUID одновременно проходят terminal check и дают два HTTP для одной delivery; serial control дает один HTTP. R10 не исправляет этот отдельный путь `DeliverWebhook`. Exactly-once не доказан.
- **Recovery RED остается:** 25 product attempts R09 и 26 R10. Полный drain volume не закрывает recovery остальных cells.
- Исходные два `failed_jobs` P6 после удаления в R01 по доступным артефактам не восстанавливаются. Нулевые deltas новых runs не отменяют исторический RED и не идентифицируют старые payload/classes.
- M/L, суточный failed-job budget и cost SQL scan при большой cardinality остаются unevaluated/unconfirmed. Диагностические one-row probes 200/1000 endpoints не являются M/L SLO proof. Новая семантика `endpoints_scanned_total` считает гидратированные подходящие модели, не строки, исследованные СУБД.

**Передача:** исправления B02-01/02 выполнять ответственным исполнителем в исходном scope R09, затем Astra re-review перед зависимой работой, которая использует принятую tier-S telemetry/evidence или этот verifier как prerequisite. B02 не дает clean audit admission для такого использования. Отдельного требования менять core R10 по результатам этого аудита нет; независимый B03 не блокируется. Новые изменения core должны получить собственные проверки/digests, прежде чем опираться на них в mutation или последующих продуктовых runs.

Код, R states, carriers R09/R10, исторические journal/state и raw evidence не изменены. Commit/push не выполнялись. Проверенные ограничения и открытые находки остаются в этом отчете; никакой автоматической смены статусов или приемки отклонений не произведено.
