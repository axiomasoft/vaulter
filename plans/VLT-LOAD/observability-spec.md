# VLT-LOAD — исполнимая спецификация наблюдаемости

**Статус:** normative design contract для P8.2–P8.7

**Решения:** `PLAN1.D35`, `PLAN1.D36`, `PLAN1.D37`, `PLAN1.D38`; аддитивность — `PLAN1.D31`

**Источник реестра:** `plans/VLT-LOAD/slo-gates.md` §5.1–§5.3

Эта спецификация фиксирует публичный seam, полный реестр P8-minimum, точки измерения и health-boundary.
Она не разрешает реализацию P8.2: сначала нужен независимый GREEN design review в
`plans/VLT-LOAD/findings/P8.1-design-review.md`.

## 1. Публичный контракт и DI

P8.2 добавляет `Vaulter\Contracts\Observability\MetricsRecorder`. Его публичные методы дословно:

```php
counter(string $name, int|float $amount = 1, array $labels = []): void
gauge(string $name, int|float $value, array $labels = []): void
histogram(string $name, int|float $value, array $labels = []): void
timing(string $name, callable $operation, array $labels = []): mixed
```

Нормативная семантика:

- `counter()` увеличивает монотонный counter на `amount`; отрицательное значение недопустимо.
- `gauge()` принимает абсолютное наблюдаемое значение. Эмуляция gauge вызовами `+1`/`-1` запрещена.
- `histogram()` записывает одно наблюдение в базовой единице family (`seconds`, `bytes`, rows/nodes/count).
- `timing()` измеряет callback монотонными часами, пишет ровно одно histogram-наблюдение в секундах,
  возвращает неизменённый результат callback и пробрасывает тот же объект исключения без подмены.
- `NullMetricsRecorder::timing()` всегда выполняет callback, возвращает его результат или пробрасывает его
  исключение, но не создаёт эмиссию. Остальные методы Null — no-op без I/O.
- `FakeMetricsRecorder` хранит упорядоченные immutable emissions (`type`, `name`, `value`, normalized labels)
  в памяти и повторяет callback-семантику `timing()`; он не обращается к backend.
- Host adapter преобразует нейтральные вызовы контракта в backend API. Ошибка, timeout или отказ backend
  не меняют бизнес-результат и не заменяют исключение callback; диагностирование отказа ограничено по частоте
  и не вызывает этот же recorder рекурсивно.
- В public API нет типов конкретного telemetry/backend SDK, facade или service locator. Зависимость приходит
  только constructor injection через контракт.

Планируемые артефакты P8.2:

| Роль | Path / symbol |
|:--|:--|
| Contract | `packages/core/src/Contracts/Observability/MetricsRecorder.php::MetricsRecorder` |
| Null | `packages/core/src/Observability/NullMetricsRecorder.php::NullMetricsRecorder` |
| Fake | `packages/core/src/Testing/FakeMetricsRecorder.php::FakeMetricsRecorder` |
| Default binding | `packages/core/src/VaulterServiceProvider.php::packageRegistered` |

Пакет регистрирует один factory-binding контракта в `register()`, с резолвом config **только внутри
замыкания**. Нормативная truth table (P8.2 обязан покрыть каждый ряд тестом):

| `vaulter.observability.enabled` | `vaulter.observability.recorder` | Результат `app(MetricsRecorder::class)` |
|:--|:--|:--|
| `false` / unset | любой, включая отсутствующий | `NullMetricsRecorder` |
| `true` | отсутствующий, пустой, несуществующий class или class, не реализующий контракт | `NullMetricsRecorder` + rate-limited diagnostic; **не** exception из factory |
| `true` | FQCN host adapter, реализующий контракт | этот adapter |

Запрещено: `$app->make($configuredClass)` без чтения `enabled`; exception из composition root при
битом class; `if (config('vaulter.observability.enabled'))` в seam-коде. Host adapter живёт в `load/`,
не в `packages/core`. Ошибка, timeout или отказ backend после успешного resolve не меняют бизнес-результат.

## 2. Labels, cardinality, sampling и overhead

- Допустимы только labels, перечисленные у family в §3. Ключи фиксированы, значения — bounded strings.
  Bool нормализуется строго в строки `true` и `false`.
- Запрещены raw URL/path/query, произвольные route strings, ID/UUID, user/tenant/email, token, filename,
  exception class/message/stack и иной пользовательский ввод. `route`, `operation`, `command`, `verb`,
  `format`, `driver` и сходные labels берутся только из закрытого application vocabulary.
- Adapter сверяет имя, type, набор ключей и **значения** с реестром §3 и словарём §9. Неизвестная family,
  лишний ключ, значение вне vocabulary, `NaN`, `INF`, `-INF` или отрицательный `counter` amount отбрасывают
  только telemetry emission и дают rate-limited diagnostic; бизнес-вызов не падает.
- Все P8-minimum observations несемплированные. Histogram **bucket schema** задан в §8 этой спеки (classic
  Prometheus); retention и scrape принадлежат backend. Package не держит process-global агрегаты, кроме
  обязательного shared registry в load SUT (§7), который нужен, чтобы несколько FPM/CLI процессов были
  одним scrape-target.
- Disabled/Null path не выполняет clock, network, disk, serialization или label allocation сверх уже
  переданных аргументов; для `timing()` добавляется только прямой вызов callback. Enabled path делает не более
  одной backend emission на вызов recorder (у `timing()` — одной после callback). P8.7 измеряет delta
  enabled/disabled и применяет существующие SLO, не вводя скрытый новый порог.
- Ошибки и telemetry не должны менять transaction boundary, response streaming или порядок доменных side
  effects. Метрики ставятся вокруг существующих seam'ов, а не создают второй бизнес-путь.

## 3. Реестр P8-minimum

`Owner` имеет только значения `package`, `host`, `harness`. `Current` означает проверенный существующий
symbol; `future` — точный планируемый path/symbol. Owning item — единственный item, который вправе добавить
эмиссию. Consumer везде — metrics backend и соответствующий SLO gate; в последней колонке уточнён способ
получения сигнала.

### 3.1 Паритет с §5.1 — 12 families

| SSOT | Family | Type | Labels | Owner | Anchor / symbol | Owning item | Acquisition / consumer |
|:--|:--|:--|:--|:--|:--|:--|:--|
| 5.1 | `vaulter_http_request_duration_seconds` | histogram | `operation`, `tier`, `outcome` | package | future `packages/core/src/Http/Middleware/RecordVaulterRequestMetrics.php::handle` | P8.3 | Named Vaulter route, response/exception outcome; SLO-01…39 |
| 5.1 | `vaulter_tree_query_duration_seconds` | histogram | `query_kind` (`children`/`subtree`/`breadcrumb`/`search`), `tier` | package | current `packages/core/src/Repositories/Drive/NodeReadRepository.php:89/125/166/199::children/subtree/breadcrumb/search` | P8.3 | Time each repository query family; SLO-04…09 |
| 5.1 | `vaulter_tree_cte_anchors` | histogram | `tier` | package | current `packages/core/src/Repositories/Drive/NodeReadRepository.php:454/501::candidateNodeIds/descendantIdsOf` | P8.3 | Count anchors supplied to recursive CTE; SLO-04…09 |
| 5.1 | `vaulter_subtree_nodes_touched` | histogram | `operation` | package | current `packages/drive/src/Actions/MoveNodeAction.php:85::assertNoCycle` | P8.3 | Count checked subtree nodes, operation from closed vocabulary; SLO-13…15 |
| 5.1 | `vaulter_activity_feed_duration_seconds` | histogram | `filtered` (bool), `tier` | package | current `packages/core/src/Http/Controllers/Activity/ActivityController.php:44/62::forStorage/forNode` | P8.3 | Time query plus pagination; SLO-16…18 |
| 5.1 | `vaulter_activity_write_total` | counter | `verb` | package | current `packages/core/src/Activity/ActivityRecorder.php:150::record` | P8.3 | Increment after successful record, canonical verb only; SLO-16…18 |
| 5.1 | `vaulter_export_duration_seconds` | histogram | `format`, `tier` | package | current `packages/documents/src/Export/NodeExportService.php:58::export` | P8.3 | Time export operation, registered format only; SLO-25…27 |
| 5.1 | `vaulter_collab_session_issued_total` | counter | `driver`, `ability` | package | current `packages/documents/src/Collaboration/CollaborationSessionService.php:40::issue` | P8.3 | Increment after issuance, configured driver and enum ability; SLO-28…30 |
| 5.1 | `vaulter_collab_snapshot_duration_seconds` | histogram | `tier` | package | current `packages/documents/src/Actions/Collaboration/PersistYjsSnapshotAction.php:44::execute` | P8.3 | Time persisted snapshot; SLO-31…33 |
| 5.1 | `vaulter_collab_room_active` | gauge | — | host | future `load/app/Observability/CollaborationRoomMetrics.php::observeActiveRooms` | P8.3 | Absolute room count from collaboration runtime, not package deltas; SLO-31…33 |
| 5.1 | `vaulter_broadcast_queue_wait_seconds` | histogram | — | host | future `load/app/Listeners/RecordBroadcastQueueWait.php::handle` | P8.3 | Enqueue timestamp to Laravel job start for queued broadcast; SLO-34…36 |
| 5.1 | `vaulter_upload_session_active` | gauge | `protocol` | host | future `load/app/Observability/LoadStateMetrics.php::observeUploadSessions` | P8.3 | Absolute open-session DB count grouped by protocol; SLO-37…39 |

### 3.2 Паритет с §5.2 — 10 families

| SSOT | Family | Type | Labels | Owner | Anchor / symbol | Owning item | Acquisition / consumer |
|:--|:--|:--|:--|:--|:--|:--|:--|
| 5.2 | `vaulter_request_peak_memory_bytes` | gauge | `operation`, `tier` | host | future `load/app/Http/Middleware/RecordRequestPeakMemory.php::terminate` | P8.4 | Per-request `memory_get_peak_usage(true)` written as run-max into the shared registry (§7); scrape must observe the max of the run, not a later worker overwrite; SLO-40…54 |
| 5.2 | `vaulter_upload_bytes_total` | counter | `protocol` (`tus`/`s3`/`single`) | package | current `packages/drive/src/Uploads/TusUploadHandler.php:83::patch`; `packages/drive/src/Uploads/S3MultipartHandler.php:92::putPart`; `packages/drive/src/Actions/UploadFileAction.php:54::execute` | P8.4 | Add accepted bytes only; SLO-40…51 |
| 5.2 | `vaulter_upload_chunk_duration_seconds` | histogram | `protocol`, `tier` | package | current `packages/drive/src/Uploads/TusUploadHandler.php:83::patch`; `packages/drive/src/Uploads/S3MultipartHandler.php:92::putPart`; `packages/drive/src/Actions/UploadFileAction.php:54::execute` | P8.4 | Time accepted chunk/direct upload; SLO-40…51 |
| 5.2 | `vaulter_upload_conflict_total` | counter | `protocol` | package | current `packages/drive/src/Uploads/TusUploadHandler.php:83::patch`; `packages/drive/src/Uploads/S3MultipartHandler.php:92::putPart` | P8.4 | Increment recognized protocol conflict before 409 mapping; §0.4 error budget |
| 5.2 | `vaulter_assemble_bytes_total` | counter | `size_bucket` | package | current `packages/drive/src/Uploads/S3MultipartHandler.php:152::complete` | P8.4 | Add bytes successfully assembled, bounded size bucket; SLO-46…48 |
| 5.2 | `vaulter_assemble_duration_seconds` | histogram | `size_bucket` | package | current `packages/drive/src/Uploads/S3MultipartHandler.php:152::complete` | P8.4 | Time assembly around existing completion seam; SLO-46…48 |
| 5.2 | `vaulter_download_bytes_total` | counter | `source` (`file`/`version`/`share`) | package | current `packages/drive/src/Http/Controllers/DownloadController.php:112::streamFromDisk`; `packages/core/src/Http/Controllers/Sharing/PublicShareController.php:191::streamFromDisk` | P8.4 | Add bytes **read/attempted by PHP** from disk/CAS before nginx/socket buffering. Client-received bytes remain k6 `data_received`; SLO-52…54 |
| 5.2 | `vaulter_download_stream_active` | gauge | — | host | future `load/app/Observability/DownloadStreamMetrics.php::observeActiveStreams` | P8.4 | Absolute count of tagged FPM workers in an active download, from host/php-fpm status or equivalent collector. Package code must not `+1/-1` this gauge and must not emit it from `DownloadController`; SLO-52…54 |
| 5.2 | `vaulter_download_ttfb_seconds` | histogram | `tier` | package | current `packages/drive/src/Http/Controllers/DownloadController.php:112::streamFromDisk`; `packages/core/src/Http/Controllers/Sharing/PublicShareController.php:191::streamFromDisk` | P8.4 | Request acceptance to **first PHP write/flush** (server first-write). Client TTFB remains k6 `ttfb_ms`; SLO-52…54 |
| 5.2 | `vaulter_throttled_total` | counter | `route` | host | future `load/app/Listeners/RecordThrottledResponse.php::handle` | P8.4 | Count 429 response using named-route vocabulary; §0.4 error budget |

### 3.3 Паритет с §5.3 — 28 families

| SSOT | Family | Type | Labels | Owner | Anchor / symbol | Owning item | Acquisition / consumer |
|:--|:--|:--|:--|:--|:--|:--|:--|
| 5.3 | `vaulter_outbox_backlog` | gauge | — | host | future `load/app/Observability/LoadStateMetrics.php::observeOutboxBacklog` | P8.5 | Absolute pending outbox DB count; SLO-58…60 |
| 5.3 | `vaulter_outbox_stage_duration_seconds` | histogram | — | package | current `packages/core/src/Webhooks/OutboxRecorder.php:131::stage` | P8.5 | Time idempotent stage operation; SLO-55…57 |
| 5.3 | `vaulter_outbox_relay_duration_seconds` | histogram | — | package | current `packages/core/src/Webhooks/OutboxRelay.php:38::process` | P8.5 | Time one relay invocation; SLO-58…60 |
| 5.3 | `vaulter_outbox_relayed_total` | counter | — | package | current `packages/core/src/Webhooks/OutboxRelay.php:38::process` | P8.5 | Add successfully relayed rows; SLO-58…60 |
| 5.3 | `vaulter_outbox_endpoints_scanned_total` | counter | — | package | current `packages/core/src/Webhooks/OutboxRelay.php:100::subscribedEndpoints` | P8.5 | Add endpoint rows inspected per outbox row; SLO-58…60 |
| 5.3 | `vaulter_webhook_queue_wait_seconds` | histogram | `outcome` | package | current `packages/core/src/Jobs/Webhooks/DeliverWebhook.php:55::handle` | P8.5 | Stored enqueue time to job start; bounded outcome; SLO-61…63 |
| 5.3 | `vaulter_webhook_delivery_duration_seconds` | histogram | `outcome` | package | current `packages/core/src/Jobs/Webhooks/DeliverWebhook.php:55::handle` | P8.5 | Time guarded delivery attempt; SLO-61…63 |
| 5.3 | `vaulter_webhook_delivered_total` | counter | `outcome` | package | current `packages/core/src/Jobs/Webhooks/DeliverWebhook.php:181/203::recordSuccess/recordFailure` | P8.5 | Increment exactly once for terminal attempt outcome; SLO-61…63 |
| 5.3 | `vaulter_failed_jobs_total` | counter | `outcome` | host | future `load/app/Listeners/RecordFailedJob.php::handle` | P8.5 | Laravel `JobFailed` event, bounded outcome classifier; SLO-61…63/G-6 |
| 5.3 | `vaulter_webhook_retry_tick_seconds` | histogram | — | package | current `packages/core/src/Webhooks/WebhookRetryDispatcher.php:37::redispatchDue` | P8.5 | Time one retry dispatcher tick; SLO-64…66 |
| 5.3 | `vaulter_webhook_retry_lockset_rows` | histogram | — | package | current `packages/core/src/Webhooks/WebhookRetryDispatcher.php:54::claimDue` | P8.5 | Observe rows locked per tick; SLO-64…66 |
| 5.3 | `vaulter_sweep_tick_seconds` | histogram | — | package | current `packages/drive/src/Console/SweepUploadsCommand.php:32::handle` | P8.5 | Time one sweep tick; SLO-70…72 |
| 5.3 | `vaulter_sweep_batch_rows` | histogram | — | package | current `packages/drive/src/Console/SweepUploadsCommand.php:32::handle` | P8.5 | Observe candidate batch size; SLO-70…72 |
| 5.3 | `vaulter_sweep_expired_total` | counter | — | package | current `packages/drive/src/Console/SweepUploadsCommand.php:32::handle` | P8.5 | Add expired sessions committed by tick; SLO-70…72 |
| 5.3 | `vaulter_sweep_skipped_total` | counter | — | package | current `packages/drive/src/Console/SweepUploadsCommand.php:32::handle` | P8.5 | Add sessions skipped by tick; SLO-70…72 |
| 5.3 | `vaulter_gc_duration_seconds` | histogram | `phase` (`mark`/`sweep`) | package | current `packages/media/src/Cas/BlobGarbageCollector.php:76/110::mark/sweep` | P8.5 | Time each GC phase; SLO-76…78 |
| 5.3 | `vaulter_gc_batch_rows` | histogram | — | package | current `packages/media/src/Cas/BlobGarbageCollector.php:293::candidates` | P8.5 | Observe rows at each cursor checkpoint/batch; SLO-76…78 |
| 5.3 | `vaulter_gc_rows_scanned_total` | counter | — | package | current `packages/media/src/Cas/BlobGarbageCollector.php:76/293::mark/candidates` | P8.5 | Add rows scanned by mark; SLO-76…78 |
| 5.3 | `vaulter_gc_objects_deleted_total` | counter | — | package | current `packages/media/src/Cas/BlobGarbageCollector.php:110::sweep` | P8.5 | Add objects deleted by sweep; SLO-76…78 |
| 5.3 | `vaulter_purge_duration_seconds` | histogram | — | package | current `packages/drive/src/Console/PurgeTrashCommand.php:46::handle` | P8.5 | Time purge command work; SLO-73…75 |
| 5.3 | `vaulter_purge_batch_nodes` | histogram | — | package | current `packages/drive/src/Console/PurgeTrashCommand.php:46::handle` | P8.5 | Observe nodes in processed subtree/batch; SLO-73…75 |
| 5.3 | `vaulter_purge_nodes_total` | counter | — | package | current `packages/drive/src/Console/PurgeTrashCommand.php:46::handle` | P8.5 | Add nodes purged successfully; SLO-73…75 |
| 5.3 | `vaulter_prune_duration_seconds` | histogram | — | package | current `packages/core/src/Console/PruneWebhooksCommand.php:40::handle` | P8.5 | Time prune invocation; SLO-67…69 |
| 5.3 | `vaulter_prune_rows_total` | counter | — | package | current `packages/core/src/Console/PruneWebhooksCommand.php:40::handle` | P8.5 | Add rows pruned; SLO-67…69 |
| 5.3 | `vaulter_reconcile_duration_seconds` | histogram | — | package | current `packages/media/src/Cas/BlobReconciler.php:84::reconcile`; `packages/media/src/Console/ReconcileBlobsCommand.php:41::handle` | P8.5 | Time reconciliation invocation; SLO-79…81 |
| 5.3 | `vaulter_reconcile_objects_total` | counter | — | package | current `packages/media/src/Cas/BlobReconciler.php:84/150::reconcile/sweepOrphans` | P8.5 | Add inspected/reconciled objects according to command result; SLO-79…81 |
| 5.3 | `vaulter_command_peak_memory_bytes` | gauge | `command` | host | future `load/app/Listeners/RecordCommandPeakMemory.php::handle` | P8.5 | Per-invocation `memory_get_peak_usage(true)` stored as run-max in the shared registry (§7); SLO-64…81 |
| 5.3 | `vaulter_pg_lock_wait_seconds` | histogram | — | harness | future `load/scripts/collect-postgres-lock-waits.sh::main` | P8.5 | Poll `pg_stat_activity`/`pg_locks` wait duration and **write Prometheus text or remote-write to the same sink as §7**. Not a `MetricsRecorder` call from package code; P8.5 Files must include this script; SLO-67…69 |

## 4. Health boundary (`PLAN1.D36`, `PLAN1.D38`)

Оба endpoint принадлежат только load SUT; reusable packages не регистрируют routes, health-check classes
или suggestions.

- `GET /up` — Laravel-native process liveness. Он не обращается к DB, S3, queue или metrics backend и
  отвечает `200`, пока HTTP process способен обслужить запрос.
- `GET /ready` — load-owned readiness aggregator. Три независимых bounded-timeout probe:
  1. DB `SELECT 1` on the app connection.
  2. S3 `HEAD` заранее созданного sentinel object.
  3. Асинхронный queue round-trip против Redis (не `sync`/`null`/`defer`).

Queue probe (нормативный nonce protocol, F4):

| Шаг | Контракт |
|:--|:--|
| Connection | Laravel queue connection name `redis` (`QUEUE_CONNECTION=redis`) |
| Queue | named queue `load-health` only |
| Worker | compose service `queue-worker`: `php artisan queue:work redis --queue=load-health,default` |
| Nonce | probe генерирует `nonce` = UUID v4 и `issued_at` = monotonic/unix ms |
| Dispatch | `LoadQueueHeartbeat` job с payload `{nonce, issued_at}` на connection `redis` / queue `load-health` |
| Job | пишет cache key `vaulter:queue-ready:{nonce}` = `issued_at` с TTL ≤ probe timeout; не трогает чужие nonce |
| Poll | probe читает **только** свой ключ до deadline (connect timeout + read timeout + poll budget, каждый < `/ready` timeout) |
| Consume | успешное чтение **удаляет** ключ (`GETDEL` / `GET`+`DEL`); повторное чтение того же nonce не зелёное |
| Fail | Redis down, worker stopped, timeout, или найден только чужой/протухший ключ → `checks.queue.ok=false` |

Запрещено: общий ключ `vaulter:queue-ready` без nonce; `Queue::size()`; connection ping без worker;
повторное использование ACK предыдущего `/ready`.

JSON: `{"status":"ready|not_ready","checks":{"db":{"ok":bool},"s3":{"ok":bool},"queue":{"ok":bool}}}`.
Нет DSN, bucket/key, credentials, exception messages или stack traces.

Обязательные independent negative cases P8.6:

| Case | Fault injection | `/up` | `/ready` | Required component result |
|:--|:--|:--|:--|:--|
| DB down | DB connection refused/blackholed, S3 и queue healthy | 200 | 503 | `checks.db.ok=false`; остальные `true` |
| S3 down | sentinel `HEAD` timeout/failure, DB и queue healthy | 200 | 503 | `checks.s3.ok=false`; остальные `true` |
| Queue down | Redis unavailable **or** worker stopped **or** heartbeat timeout, DB и S3 healthy | 200 | 503 | `checks.queue.ok=false`; остальные `true` |
| Stale ACK | предыдущий nonce ещё в cache; текущий job не обработан | 200 | 503 | `checks.queue.ok=false` — чужой nonce не засчитывается |

Positive case: `/up=200`, `/ready=200`, три `ok=true`. Одновременный отказ нескольких dependencies
даёт 503, но не заменяет четыре независимых теста выше.

P8.6 Exact Files: `load/app/Http/Controllers/ReadyController.php`, `load/app/Jobs/LoadQueueHeartbeat.php`,
`load/app/LoadServiceProvider.php` (boot `/up` Laravel-native + `/ready` route), `load/docker-compose.load.yml`
(`redis`, `queue-worker`), `load/docker/Dockerfile.app` (PHP Redis extension; today only `pdo_pgsql`/`mbstring`),
`load/app/testbench.load.yaml` (queue/redis config), Pest tests for the four negatives + positive.

## 5. Машинная parity-проверка

Табличные строки §3 — machine-readable contract: первая колонка `5.1|5.2|5.3`, вторая — ровно одна family.
Следующая команда извлекает SSOT из `slo-gates.md`, разворачивает строки с `/`, извлекает registry из этой
спеки и требует точного равенства множеств и распределения 12/10/28:

```bash
python3 - <<'PY'
import re
from pathlib import Path

root = Path('plans/VLT-LOAD')
source = (root / 'slo-gates.md').read_text()
spec = (root / 'observability-spec.md').read_text()

expected = {}
for section, count in [('5.1', 12), ('5.2', 10), ('5.3', 28)]:
    body = re.search(rf'^### {re.escape(section)}\b(.*?)(?=^### 5\.[1234]\b|^## 5\.[45]\b)', source, re.M | re.S).group(1)
    names = re.findall(r'`(vaulter_[a-z0-9_]+)`', body)
    assert len(names) == len(set(names)) == count, (section, len(names), len(set(names)))
    expected[section] = set(names)

rows = re.findall(r'^\| (5\.[123]) \| `(vaulter_[a-z0-9_]+)` \|', spec, re.M)
actual = {section: set() for section in expected}
for section, name in rows:
    assert name not in actual[section], ('duplicate', section, name)
    actual[section].add(name)

assert {key: len(value) for key, value in actual.items()} == {'5.1': 12, '5.2': 10, '5.3': 28}
assert actual == expected, {
    section: {'missing': sorted(expected[section] - actual[section]),
              'extra': sorted(actual[section] - expected[section])}
    for section in expected if actual[section] != expected[section]
}
assert len(set().union(*actual.values())) == 50
print('PASS parity 50/50: §5.1=12, §5.2=10, §5.3=28')
PY
```

Нормативный ожидаемый результат: `PASS parity 50/50: §5.1=12, §5.2=10, §5.3=28`.

## 6. Acceptance для последующих items

- P8.2 реализует только контракт/Null/Fake/factory из §1 (включая truth table) и **не** реализует
  scrape/exporter; host adapter + shared registry принадлежат P8.7.
- P8.3–P8.5 добавляют только строки, которыми владеют. Host/harness Files обязательны для host/harness
  owners. Package seam не эмитит host gauges. `Files` каждого item — **точный superset** якорей §3
  и wiring §13–§14; обобщения («Collaboration/*», «команды») не разрешают запись.
- P8.6 реализует §4 в `load/` и доказывает positive + четыре independent negatives.
- P8.7 доказывает §7–§8 и §12: `/metrics` scrape, 50 registered families, trigger matrix, **исполняемый**
  gate reader по §8 PromQL (не голые имена), Lua histogram invariant, run isolation, disabled/enabled,
  отсутствие sampling, абсолютные gauge, backend failure isolation, overhead.
- Любая смена family name, type, labels keys, owner или anchor требует изменения этой спеки отдельным
  решением; молчаливый drift запрещён.

## 7. End-to-end transport and Redis registry (`PLAN1.D37`, `PLAN1.D38`)

```
package/host/harness seam
  → MetricsRecorder (Null | Fake | load LoadExpositionRecorder)
  → shared registry (Redis; run-scoped keys)
  → GET /metrics (Prometheus text exposition 0.0.4, load-owned)
  → Prometheus scrape job `vaulter-sut`
  → perf-assert PromQL (§8 / §12)
```

### 7.1 Run lifecycle

| Event | Owner | Contract |
|:--|:--|:--|
| Open | `load/scripts/p8-metric-matrix.sh` / `load/scripts/run.sh --p8-matrix` | Generate `LOAD_RUN_ID` (UUID). `POST /metrics/run/open` or CLI `vaulter:metrics-run-open` registers all 50 families at 0, sets `vaulter:metrics:{run_id}:open=1` with TTL = run budget + scrape lag. App/FPM/CLI/worker read `LOAD_RUN_ID` from env. |
| Observe | adapter | Writes **only** into that run_id. Unknown/missing run_id → drop emission + rate-limited diagnostic. |
| Scrape | Prometheus `vaulter-sut` | `GET /metrics?run_id=` current. Default `/metrics` uses the open run. |
| Finalize | harness | `POST /metrics/run/finalize`: freeze writes, keep scrape readable until TTL; next open uses a **new** run_id. Old MAX gauges cannot leak. |

Повторные on/off прогоны **никогда** не делят ключи. Gauge `MAX` (peak memory) — per-run.

### 7.2 Key schema and encoding

Hash-tag so Lua multi-key stays one slot:

```
vaulter:metrics:{<run_id>}:family:<name>
vaulter:metrics:{<run_id>}:meta          # HASH: HELP, TYPE, created_at
vaulter:metrics:{<run_id>}:manifest      # SET of 50 family names
vaulter:metrics:{<run_id>}:series:<name>:<canonical_labels>
```

`<canonical_labels>` — sorted `k=v` pairs joined by `,`; values escaped. Duplicate labelset → same series.
Unknown family / extra key / value outside §9 → drop that emission only.

Zero registration: open-run writes TYPE/HELP + zero samples (`_total 0`, histogram `_bucket`/`_sum`/`_count` 0,
gauge 0) so scrape shows the family before the first trigger.

### 7.3 Atomic histogram observation

Одно observation обновляет **все** cumulative buckets с `le >= value`, `+Inf`, `_count` и `_sum` одним
Redis `EVAL` Lua script (`load/app/Observability/lua/histogram_observe.lua`). KEYS несут hash-tag
`{run_id}`. Инвариант после script: `_bucket{le="+Inf"} == _count` для каждого labelset.

Запрещено: per-bucket `HINCRBY` из PHP без Lua; scrape, который видит `+Inf != _count`.

Gauges: `SET` для absolute snapshots (`*_active`, backlog); `MAX` Lua (`if tonumber(new) > tonumber(old) then SET`)
для peak-memory. Counters: `INCRBYFLOAT` on the series key.

Backend failure: Lua/Redis error → adapter swallows, **no partial write**, business result unchanged.
Missing series on scrape ≠ pass.

### 7.4 Exposition 0.0.4

`MetricsController` dumps:

1. Families in lexicographic name order.
2. Per family exactly one `# HELP` then one `# TYPE` (base name, not `_bucket`).
3. Samples grouped by family; histogram order `_bucket` (ascending `le`, `+Inf` last), then `_sum`, `_count`.
4. Label keys sorted; escape `\`, `\n`, `"` per Prometheus text format.
5. Duplicate labelsets forbidden.

Content-Type: `text/plain; version=0.0.4; charset=utf-8`.

### 7.5 Hop Files

| Hop | Owning item | Exact Files |
|:--|:--|:--|
| Contract, Null, Fake, factory | P8.2 | `packages/core/src/Contracts/Observability/MetricsRecorder.php`; `packages/core/src/Observability/NullMetricsRecorder.php`; `packages/core/src/Testing/FakeMetricsRecorder.php`; `packages/core/config/vaulter.php`; `packages/core/src/VaulterServiceProvider.php`; `packages/core/tests/Observability/` |
| Shared registry + Lua + text exposition + host adapter | P8.7 | `load/app/Observability/LoadExpositionRecorder.php`; `load/app/Observability/RedisMetricsRegistry.php`; `load/app/Observability/lua/histogram_observe.lua`; `load/app/Http/Controllers/MetricsController.php`; `load/routes/metrics.php`; `load/app/LoadServiceProvider.php` (bind adapter in `register()`, mount routes in `boot()`) |
| Scrape | P8.7 | `load/docker/prometheus.yml` (job `vaulter-sut` → SUT `/metrics`); `load/docker-compose.load.yml` |
| Gate reader | P8.7 | `load/gates/perf-assert.py`; `load/gates/_slo_table.py`; `load/gates/promql_map.py`; `load/gates/fixtures/` |
| Trigger matrix | P8.7 | `load/scripts/p8-metric-matrix.sh`; optional `--p8-matrix` on `load/scripts/run.sh` |
| Harness PG lock waits | P8.5 | `load/scripts/collect-postgres-lock-waits.sh` writing the same run-scoped registry |

Запреты: Fake as P8.7 proof; self-scrape-only Prometheus; smoke.js as sole 50-family proof; OTel SDK types in `packages/core`.

`tier` не читается package-кодом из load config. Host middleware `AssignLoadTier` кладёт bounded
`tier` (`S0`/`S`/`M`/`L`) из env `TIER` (compose `environment: TIER: ${TIER}`) в request/console
context; package seams читают только этот context.

## 8. Classic histogram model, buckets, PromQL (`PLAN1.D38`)

Classic Prometheus histograms only. Adapter writes `_bucket{le=…}`, `_sum`, `_count`. Native histogram
and base-name-as-scalar are forbidden in perf-assert.

### 8.1 Templates (`[run]` = `summary.json` duration)

| Intent | Valid PromQL | Expression class |
|:--|:--|:--|
| p95 duration | `histogram_quantile(0.95, sum by (le) (increase(<name>_bucket[run])))` | `histogram_quantile` |
| Class-A duration vs threshold `T` that **is** a bucket `le` | `increase(<name>_bucket{le="<T>"}[run]) / increase(<name>_count[run])` compared to 0.95 | `histogram_le_ratio` |
| Histogram cap (batch/lockset ≤ C, C is a `le`) | `increase(<name>_bucket{le="<C>"}[run]) == increase(<name>_count[run])` | `histogram_le_all` |
| Gauge run-max | `max_over_time(<name>[run])` | `gauge_max` |
| Gauge snapshot | `max_over_time(<name>[run])` | `gauge_max` |
| Counter rate | `rate(<name>[5m])` or `increase(<name>[run])` | `counter_rate` / `counter_increase` |
| Compound | AND of the above classes, each evaluated separately | `compound` |

`histogram_quantile(0.95, <base_name>)`, `max_over_time(<histogram_base>[run])` и scalar comparison
histogram base — невалидны. Class-A duration/cap **не** используют интерполяцию квантиля: порог обязан
быть bucket `le`, вердикт — `histogram_le_ratio` / `histogram_le_all`.

### 8.2 Family bucket sets (inclusive `le`, plus `+Inf`)

Каждый blocking threshold из `slo-gates.md` §3 для histogram families ниже **обязан** быть `le`.

| Set | Families | `le` |
|:--|:--|:--|
| `http_s` | `vaulter_http_request_duration_seconds`, `vaulter_tree_query_duration_seconds`, `vaulter_activity_feed_duration_seconds`, `vaulter_export_duration_seconds`, `vaulter_collab_snapshot_duration_seconds`, `vaulter_broadcast_queue_wait_seconds`, `vaulter_upload_chunk_duration_seconds`, `vaulter_download_ttfb_seconds`, `vaulter_webhook_queue_wait_seconds`, `vaulter_webhook_delivery_duration_seconds` | 0.005, 0.01, 0.025, 0.05, 0.1, 0.12, 0.15, 0.18, 0.225, 0.25, 0.3, 0.375, 0.45, 0.5, 0.525, 0.6, 0.75, 0.9, 1, 1.2, 1.5, 1.8, 2, 2.25, 2.5, 3, 3.75, 4.5, 5, 6, 9, 10, 30, 45, 90 |
| `stage_s` | `vaulter_outbox_stage_duration_seconds` | 0.005, 0.008, 0.01, 0.012, 0.025, 0.05, 0.1, 0.25, 0.5, 1 |
| `batch_s` | `vaulter_assemble_duration_seconds`, `vaulter_outbox_relay_duration_seconds`, `vaulter_webhook_retry_tick_seconds`, `vaulter_sweep_tick_seconds`, `vaulter_gc_duration_seconds`, `vaulter_purge_duration_seconds`, `vaulter_prune_duration_seconds`, `vaulter_reconcile_duration_seconds` | 0.05, 0.1, 0.25, 0.5, 1, 1.5, 2, 2.5, 5, 10, 15, 30, 60, 90, 120, 300, 600, 1800, 3600, 7200, 14400 |
| `lock_s` | `vaulter_pg_lock_wait_seconds` | 0.001, 0.005, 0.01, 0.05, 0.1, 0.25, 0.5, 1, 2, 5, 10 |
| `rows` | `vaulter_tree_cte_anchors`, `vaulter_subtree_nodes_touched`, `vaulter_webhook_retry_lockset_rows`, `vaulter_sweep_batch_rows`, `vaulter_gc_batch_rows`, `vaulter_purge_batch_nodes` | 1, 10, 50, 100, 500, 1000, 5000, 10000, 50000, 100000 |

Units remain those of §3. Host adapter applies this schema; package `histogram()` still sends one
observation in the family unit.

## 9. Closed label vocabularies

Кроме перечисленных значений adapter отбрасывает emission. Bool — только `true`/`false`.

| Label | Values |
|:--|:--|
| `tier` | `S0`, `S`, `M`, `L` |
| `outcome` (HTTP) | `success`, `client_error`, `server_error`, `exception` |
| `outcome` (webhook/job) | `success`, `failure`, `retry`, `timeout` |
| `query_kind` | `children`, `subtree`, `breadcrumb`, `search` |
| `verb` | `storage.created`, `node.created`, `file.uploaded`, `version.created`, `upload_session.initiated`, `upload_session.aborted`, `upload_session.expired`, `node.moved`, `node.renamed`, `node.copied`, `node.trashed`, `node.restored`, `node.force_deleted`, `grant.created`, `grant.revoked`, `share.created`, `share.revoked` |
| `format` | `html`, `markdown` (`Vaulter\Documents\Enums\Export\ExportFormat`; host-registered extra exporters are **not** P8-minimum labels — adapter drops them) |
| `driver` | `reverb`, `hocuspocus` |
| `ability` | `view`, `download`, `comment`, `edit` |
| `protocol` | `tus`, `s3`, `single` |
| `source` | `file`, `version`, `share` |
| `phase` | `mark`, `sweep` |
| `size_bucket` | `le_8mib`, `le_64mib`, `le_512mib`, `le_5gib` |
| `filtered` | `true`, `false` |
| `operation` | `point_read`, `tree_list`, `search`, `tree_mutate`, `subtree_mutate`, `activity_feed`, `doc_read`, `doc_write`, `doc_export`, `collab_issue`, `collab_persist`, `comment`, `upload_session`, `tus_patch`, `s3_putpart`, `s3_complete`, `single_shot`, `download` |
| `route` | `share.show`, `share.nodes`, `share.download`, `collab.authenticate`, `collab.store`, `uploads.tus_create`, `uploads.tus_patch`, `uploads.tus_status`, `uploads.s3_parts`, `uploads.s3_complete`, `uploads.single` — **logical ids**, not Laravel `->name()` (package routes are unnamed) |
| `command` | `vaulter:sweep-uploads`, `vaulter:gc-blobs`, `vaulter:reconcile-blobs`, `vaulter:purge-trash`, `vaulter:prune-webhooks`, `vaulter:retry-webhooks`, `vaulter:relay-outbox` |
| numeric values | finite numbers only; `NaN`/`INF` rejected; `counter` amount `> 0` |

§3 prose `command="webhook:retry"` / `uploads:sweep` / `blobs:gc` — non-normative shorthand; P8.7 map
uses the artisan signatures above.

### 9.1 Server-side `operation` map (P8.3)

`RecordVaulterRequestMetrics` maps `METHOD` + path **after** `Config::routePrefix()` (default
`api/vaulter`). k6 OP tags are **not** the source of truth. Unmapped routes do not emit
`vaulter_http_request_duration_seconds` (drop + diagnostic); they still serve.

| Method | Path after prefix | `operation` |
|:--|:--|:--|
| GET | `nodes/{node}` | `point_read` |
| GET | `storages/{storage}` | `point_read` |
| GET | `storages/{storage}/nodes` | `tree_list` |
| GET | `storages/{storage}/trash` | `tree_list` |
| GET | `storages/{storage}/search` | `search` |
| POST | `storages/{storage}/folders` | `tree_mutate` |
| PATCH | `nodes/{node}` | `tree_mutate` |
| POST | `nodes/{node}/copy` | `tree_mutate` |
| POST | `nodes/{node}/restore` | `tree_mutate` |
| DELETE | `nodes/{node}` | `tree_mutate` |
| DELETE | `nodes/{node}/force` | `tree_mutate` |
| POST | `nodes/{node}/move` | `subtree_mutate` |
| GET | `storages/{storage}/activity` | `activity_feed` |
| GET | `nodes/{node}/activity` | `activity_feed` |
| GET | `nodes/{node}/content` | `doc_read` |
| GET | `nodes/{node}/document-versions` | `doc_read` |
| GET | `nodes/{node}/handler` | `doc_read` |
| PATCH | `nodes/{node}/content` | `doc_write` |
| POST | `storages/{storage}/documents` | `doc_write` |
| POST | `nodes/{node}/document-versions` | `doc_write` |
| POST | `nodes/{node}/document-versions/{version}/restore` | `doc_write` |
| PATCH | `nodes/{node}/document-versions/{version}/keep-forever` | `doc_write` |
| GET | `nodes/{node}/export` | `doc_export` |
| POST | `nodes/{node}/collab/token` | `collab_issue` |
| POST | `collab/store` | `collab_persist` |
| GET/POST/DELETE | `nodes/{node}/comments`… | `comment` |
| POST | `storages/{storage}/resumable-uploads` | `upload_session` |
| GET/HEAD/DELETE/OPTIONS | `resumable-uploads/{upload_id}` | `upload_session` |
| PATCH | `resumable-uploads/{upload_id}` | `tus_patch` |
| POST | `resumable-uploads/{upload_id}/parts` | `s3_putpart` |
| POST | `resumable-uploads/{upload_id}/complete` | `s3_complete` |
| POST | `storages/{storage}/uploads` | `single_shot` |
| GET | `nodes/{node}/download` | `download` |
| GET | `nodes/{node}/versions/{version}/download` | `download` |
| GET | `share/{token}/download` | `download` |

`route` for `vaulter_throttled_total`: host listener maps the same prefix-stripped path of
`throttle:vaulter-public` / `throttle:vaulter-uploads` hits onto the `route` enum above.

## 10. Trigger matrix (minimum for P8.7)

| Class | How triggered | Families |
|:--|:--|:--|
| HTTP happy-path | k6 scenario tagged with OP; server maps §9.1 | HTTP/tree/activity/export/collab-issue/upload/chunk/assemble/download counters+histograms on success |
| Collaboration rooms | k6 `docs-collab.js` **opens WebSocket** to compose `hocuspocus` after token; collector scrapes hub `/stats` | `vaulter_collab_room_active` |
| Background | `php artisan` sweep/gc/purge/prune/relay/retry/reconcile against seeded dirty DB | all `sweep_*`, `gc_*`, `purge_*`, `prune_*`, `outbox_*`, `webhook_*` except failure-only |
| Error/conflict | concurrent PATCH, 429, failed job, lock wait | `upload_conflict_total`, `throttled_total`, `failed_jobs_total`, `webhook_*{outcome=failure\|timeout}`, `vaulter_pg_lock_wait_seconds` |
| Host gauges | collector tick during the same run | `*_active`, `outbox_backlog`, peak-memory gauges |
| FPM streams | php-fpm `pm.status_path` full listing during download scenario | `vaulter_download_stream_active` |
| Zero-ok | family present at 0 after registration | remaining conditional counters if trigger not fired |

P8.7 machine check: scraped names ⊇ 50 **and** each name has type-matching `_total` / `_bucket` / gauge
series as required by §3. «66 checkable» uses §8/§12, not invalid §3 drafts.

## 11. Known deviations of this repair

- Owner authorized this Cursor session (`cursor-grok-4.6-high`) for P8.1 repair after the second RED
  design audit. Cursor `command-routing.json` (2026-09-17) maps frontier only to
  `claude-opus-5-thinking-high`; route evidence is `provider-attestation` with owner
  `task:plan-run VLT-LOAD P8.1`.
- `slo-gates.md` §3 PromQL remains non-normative. P8.7 executes §8/§12.
- Package HTTP routes have no `->name()`; `route`/`operation` are logical maps in §9, not Laravel names.
- Load stand collaboration driver for P8-minimum is **hocuspocus** (existing `hocuspocus-server/`).
  `reverb` remains a valid `driver` label for hosts that bind it; the load SUT does not run Reverb.

## 12. Gate reader contract (F1)

`load/gates/perf-assert.py` **must** stop querying bare metric names. P8.7 rewrites it to:

1. Parse §3 rows via `_slo_table.py` (96 rows remain SSOT for SLO id/class/gate).
2. Join `load/gates/promql_map.py`: one record per §3 row that names a Prometheus family.
   Record fields: `slo_id`, `class`, `expression_class` (§8.1), `promql` (instantiated template),
   `comparator`, `threshold`, `labels`, `blocking_profiles`.
3. Substitute `[run]` from `summary.json` duration.
4. Execute PromQL against `--prometheus-url` `/api/v1/query`.
5. Fixtures in `load/gates/fixtures/` (checked in P8.7 tests **before** live harness):
   - classic histogram (bucket series + quantile + le-ratio);
   - counter increase/rate;
   - run-max gauge;
   - compound (AND of histogram_le_ratio + gauge_max + counter_rate).

66/66 checkable means: every row that today is `not-checkable` for missing/invalid PromQL becomes
evaluated (pass/fail/known-red/advisory) by this reader. k6-only class-B rows stay on the k6 plane.

## 13. Host signal sources (F3)

| Signal | Source in load stand | Owning Files | Notes |
|:--|:--|:--|:--|
| `tier` | compose `app.environment.TIER=${TIER}` → `AssignLoadTier` | `load/docker-compose.load.yml`, `load/app/Http/Middleware/AssignLoadTier.php`, `load/scripts/up.sh` (already exports TIER) | Values §9 |
| `operation` | §9.1 path map | `load/app/Http/Middleware/RecordVaulterRequestMetrics.php` **or** package middleware `packages/core/src/Http/Middleware/RecordVaulterRequestMetrics.php` | Package middleware reads context, not env |
| `collab_room_active` | Hocuspocus hub `GET /stats` → `{rooms: <int>}` | `hocuspocus-server/src/index.js` (add `/stats`; `/health` today has no count); `load/docker-compose.load.yml` service `hocuspocus` build `hocuspocus-server/Dockerfile`; `load/app/Observability/CollaborationRoomMetrics.php`; `load/k6/scenarios/docs-collab.js` (WS connect after token) | Do not emit 0 as a substitute when hub/clients are absent |
| `download_stream_active` | php-fpm status `full` | `load/docker/php-fpm.conf` (`pm.status_path=/fpm-status`); `load/docker/nginx.conf` (internal `/fpm-status`, deny public); `load/app/Observability/DownloadStreamMetrics.php` | Count workers whose last request URI matches §9.1 download paths. Ceiling `pm.max_children` |
| `upload_session_active` / `outbox_backlog` | SQL count | `load/app/Observability/LoadStateMetrics.php` | Absolute snapshots, `SET` |
| `vaulter_broadcast_queue_wait_seconds` | enqueue timestamp → job start | `load/app/Listeners/RecordBroadcastQueueWait.php` | Host listener |
| `vaulter_throttled_total` | 429 + §9 `route` | `load/app/Listeners/RecordThrottledResponse.php` | |
| `vaulter_failed_jobs_total` | `JobFailed` | `load/app/Listeners/RecordFailedJob.php` | |
| `vaulter_pg_lock_wait_seconds` | `pg_stat_activity`/`pg_locks` | `load/scripts/collect-postgres-lock-waits.sh` | Harness, not MetricsRecorder from package |
| Redis PHP ext | Dockerfile | `load/docker/Dockerfile.app` | Required for registry + queue |

## 14. Exact Files by item (copy into item specs)

### P8.2

`packages/core/src/Contracts/Observability/MetricsRecorder.php`; `packages/core/src/Observability/NullMetricsRecorder.php`; `packages/core/src/Testing/FakeMetricsRecorder.php`; `packages/core/config/vaulter.php`; `packages/core/src/VaulterServiceProvider.php`; `packages/core/tests/Observability/`

### P8.3

Package: `packages/core/src/Http/Middleware/RecordVaulterRequestMetrics.php`; `packages/core/src/Repositories/Drive/NodeReadRepository.php`; `packages/drive/src/Actions/MoveNodeAction.php`; `packages/core/src/Http/Controllers/Activity/ActivityController.php`; `packages/core/src/Activity/ActivityRecorder.php`; `packages/documents/src/Export/NodeExportService.php`; `packages/documents/src/Collaboration/CollaborationSessionService.php`; `packages/documents/src/Actions/Collaboration/PersistYjsSnapshotAction.php`.
Host: `load/app/Http/Middleware/AssignLoadTier.php`; `load/app/Observability/CollaborationRoomMetrics.php`; `load/app/Observability/LoadStateMetrics.php`; `load/app/Listeners/RecordBroadcastQueueWait.php`; `load/app/LoadServiceProvider.php`; `load/docker-compose.load.yml`; `load/k6/scenarios/docs-collab.js`; `hocuspocus-server/src/index.js`; `hocuspocus-server/Dockerfile`. Fake tests in the touched packages.

### P8.4

`load/app/Http/Middleware/RecordRequestPeakMemory.php`; `load/app/Observability/DownloadStreamMetrics.php`; `load/app/Listeners/RecordThrottledResponse.php`; `packages/drive/src/Uploads/TusUploadHandler.php`; `packages/drive/src/Uploads/S3MultipartHandler.php`; `packages/drive/src/Actions/UploadFileAction.php`; `packages/drive/src/Http/Controllers/DownloadController.php`; `packages/core/src/Http/Controllers/Sharing/PublicShareController.php`; `load/docker/php-fpm.conf`; `load/docker/nginx.conf`. Fake tests in drive/core.

### P8.5

`packages/core/src/Webhooks/OutboxRecorder.php`; `packages/core/src/Webhooks/OutboxRelay.php`; `packages/core/src/Jobs/Webhooks/DeliverWebhook.php`; `packages/core/src/Webhooks/WebhookRetryDispatcher.php`; `packages/drive/src/Console/SweepUploadsCommand.php`; `packages/media/src/Cas/BlobGarbageCollector.php`; `packages/drive/src/Console/PurgeTrashCommand.php`; `packages/core/src/Console/PruneWebhooksCommand.php`; `packages/media/src/Cas/BlobReconciler.php`; `packages/media/src/Console/ReconcileBlobsCommand.php`; `packages/media/src/Console/GcBlobsCommand.php`; `packages/core/src/Console/RelayOutboxCommand.php`; `packages/core/src/Console/RetryWebhooksCommand.php`; `load/app/Listeners/RecordFailedJob.php`; `load/app/Listeners/RecordCommandPeakMemory.php`; `load/app/Observability/LoadStateMetrics.php`; `load/scripts/collect-postgres-lock-waits.sh`. Fake tests in core/drive/media.

### P8.6

`load/app/Http/Controllers/ReadyController.php`; `load/app/Jobs/LoadQueueHeartbeat.php`; `load/app/LoadServiceProvider.php`; `load/docker-compose.load.yml`; `load/docker/Dockerfile.app`; `load/app/testbench.load.yaml`; `load/tests/` (positive + four negatives including stale-ACK).

### P8.7

`plans/VLT-LOAD/findings/P8.7-gate-closure.md`; `load/app/Observability/LoadExpositionRecorder.php`; `load/app/Observability/RedisMetricsRegistry.php`; `load/app/Observability/lua/histogram_observe.lua`; `load/app/Http/Controllers/MetricsController.php`; `load/routes/metrics.php`; `load/app/LoadServiceProvider.php`; `load/docker/prometheus.yml`; `load/docker-compose.load.yml`; `load/gates/perf-assert.py`; `load/gates/_slo_table.py`; `load/gates/promql_map.py`; `load/gates/fixtures/`; `load/scripts/p8-metric-matrix.sh`; `load/scripts/run.sh`; tests of Lua invariant, run isolation, exposition, PromQL fixtures.
