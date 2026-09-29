# P0 — RAG-провенанс: инструменты и практика нагрузочного тестирования (2026)

> Захват внешних фактов, верифицированных через `perplexity-web` (RAG) 2026-07-13 при
> проектировании VLT-LOAD. Держится отдельно от `plan.md`, чтобы решения (D-блок плана)
> ссылались на первоисточник, а не на память модели. При правках плана — обновлять и это.

## RAG-1 — Выбор инструмента: k6 vs Gatling vs Locust (tus/S3-multipart, multi-GB)

**Вывод:** k6 — дефолт для tus + S3-multipart + multi-GB и stateful multi-step сценариев;
Locust — второй выбор, когда нужна «протокольная гимнастика» на Python-экосистеме (boto3,
SigV4); Gatling слабее всех именно на больших стримингах тела и resumable-протоколах.

Ключевое, что меняет план:
- Ни один из трёх НЕ имеет встроенного tus-клиента или S3-multipart-клиента — flow
  реализуется руками поверх HTTP-клиента (POST create → PATCH offset+chunk → complete).
- k6: JS-скриптинг, per-VU state (upload_id, offset, token), thresholds/scenarios хорошо
  ложатся на длинные upload-flow; Go-рантайм эффективен на десятках тысяч VU.
- Locust: Python task-classes — естественная state-machine на пользователя; тяжелее k6 по
  памяти на воркер при multi-GB, обычно ниже concurrency, но реалистичнее «поведение клиента».

Источники: omeronal «k6 vs Gatling vs Locust 2026»; ranorex load-testing-tools; testguild
load-testing-tools.

## RAG-2 — Критично: k6 и стриминг тела запроса с диска

**Вывод (ловушка, прямо влияет на профиль нагрузки):** core-k6 (OSS, ≥ v0.47) НЕ умеет
стримить HTTP-тело с диска без загрузки в память. `open()`/`SharedArray` грузят файл целиком
в RAM. Экспериментальный `k6/experimental/streams` (WHATWG Streams) — это JS-обработка
данных, НЕ `http.request({body: ReadableStream})`; HTTP-стриминг-API всё ещё в разработке.

Следствия для VLT-LOAD:
- multi-GB файл на VU × сотни VU в стоковом k6 = OOM генератора нагрузки. Обход:
  1) **xk6-fasthttp** (`github.com/domsolutions/xk6-fasthttp`, в extension-registry) — «file
     streaming on HTTP/1.1 requests»: стримит тело с диска прогрессивно. Сборка кастомного
     бинарника через `xk6`.
  2) Либо chunk на уровне сценария: слать PATCH-ями по `chunk_size`, не держа файл целиком.
     Для tus это и есть натуральный профиль (offset-приращения), для S3 — по part'ам.
- Для распределённого прогона на k8s — **k6 Operator**; кастомный бинарник (xk6-fasthttp +
  prometheus-remote) кладётся образом в `TestRun.spec.image`.

Источники: github grafana/k6 issues #2976, #2974 («we currently only have a way to load the
whole content of a file in memory»); grafana docs k6-experimental/streams; grafana
k6-extension-registry `registry.yaml` (xk6-fasthttp); grafana docs set-up-distributed-k6.

## RAG-3 — Экспорт метрик k6

`xk6-output-prometheus-remote` — remote-write в Prometheus / Grafana Cloud Metrics;
компилируется в тот же кастомный бинарник; работает и под k6 Operator. Core-k6 умеет также
InfluxDB/StatsD/New Relic из коробки. Источники: grafana blog «k6 introduces … Prometheus
support»; grafana docs distributed-k6 extensions.

## RAG-4 — Профилирование Postgres 16 под нагрузкой (2026)

Методология: гнать production-подобный ворклоад (не только pgbench), свипать 0.5×…20–50× до
насыщения, инструментировать. Инструменты и приёмы:
- **pg_stat_statements** — первичная линза горячих путей; `pg_stat_statements.max` высоко;
  reset per run для сравнимых дельт. Визуализация: PoWA / PGAnalyze / PMM / pgDash.
- **EXPLAIN (ANALYZE, BUFFERS, VERBOSE)** на топ-запросах; визуализация планов — PEV2 /
  pgMustard. **HypoPG** — гипотетические индексы без создания, для итерации в цикле замера.
- **Recursive CTE для деревьев:** держать `WITH RECURSIVE ... UNION ALL`, обязательный
  cycle-guard и depth-cap (отсутствие — трактуется как баг корректности); btree-индекс на
  `parent_id` чтобы каждый шаг рекурсии был index-fast; тяжёлые join'ы — ПОСЛЕ того как CTE
  выдал минимальный набор id. Для read-hot иерархий `ltree`/materialized-path обгоняют CTE
  (traversal → один index range scan). Смотреть в EXPLAIN: WorkTable Scan cycles, rows visited.
- **Advisory-lock contention:** транзакционные (`pg_advisory_xact_lock`), короткие транзакции,
  ключ на resource-id (не глобальный). Мониторинг: `pg_locks ⋈ pg_stat_activity` через
  `pg_blocking_pids()`; `log_lock_waits=on`, низкий `deadlock_timeout` (1s) на время тестов;
  `pg_stat_database.deadlocks` counter; pgLockTracker.
- **Пул соединений:** PgBouncer transaction-mode; `pool_size` от ядер Postgres (не от числа
  инстансов app); `reserve_pool`/`max_client_conn`/таймауты; мониторить очередь ожидания и
  `numbackends`/«too many clients already»; сценарии со spike-бёрстом.

Источники: oneuptime 2026 (lock-contention monitoring; pgbouncer-pooling); yonk «pg retest
real workload»; monpg «postgresql cte performance»; vela «recursive cte».

## RAG-5 — Очереди Laravel и наблюдаемость под завалом (2026)

- **SLO очереди:** p95/p99 queue-time (enqueue→start) и process-time на класс job'ы;
  устойчивый throughput без нарушения latency-SLO; error-budget failed_jobs/день.
- **Horizon** метрики per-queue (pending/reserved/completed, wait-time, runtime, throughput);
  выделенные очереди по доменам, per-queue concurrency caps; синтетические job'ы, имитирующие
  реальную CPU/DB/IO-нагрузку; гнать 5–10× пик 15–30 мин, мерить рост backlog vs capacity.
- **DLQ/ретраи/идемпотентность:** failed_jobs — только диагностика; бизнес-DLQ отдельно;
  экспоненциальный backoff с потолком (3–5) на transient (S3/сеть/внешние API); min backoff
  10–30s; на CPU-bound logic-bug ретраи не помогают → сразу fail+DLQ; idempotency-key =
  business-entity-id + operation + version; execution-log per key.
- **OpenTelemetry PHP SDK** → OTLP → **Collector** → Jaeger/Tempo + Prometheus. Спаны:
  HTTP→Controller→dispatch(job)→worker→job-exec; атрибуты queue.name/job.name/tenant/attempt/
  idempotency_key/S3-bucket. Метрики: `queue_jobs_total{queue,job,status}`,
  `queue_wait_seconds`(hist), `queue_processing_seconds`(hist), `queue_backlog_size`(gauge).
- **Chaos:** S3 5xx/latency/недоступность; disk-full (cgroups / фикс-размер FS + мусор);
  network partition к БД/внешним API; throttling; крэш пула воркеров. Проверять: retries/
  backoff как задумано, объём DLQ ожидаем, idempotency держит, observability даёт root cause.

Источники: itmarkerz «Laravel queues & Horizon at scale 2026»; opentelemetry.io PHP exporters;
laraveldaily queues-performance-test.

## Замечание о применимости к vaulter (мост RAG → факты рекона)

RAG говорит «Horizon / OTel / Prometheus». Рекон (findings рекона в chat-контексте P0) показал:
в пакете **ничего этого нет** — ни Horizon, ни spatie-health, ни метрик, ни трейсинга; единственный
queued-класс — `DeliverWebhook` (без `$tries`/`$timeout`), ретрай владеет relay-sweep. Значит фаза
наблюдаемости VLT-LOAD — это в т.ч. **добавление seam'ов метрик/таймингов**, которых сегодня нет,
а не «подключить дашборд к существующему». Это зафиксировано в D-блоке и фазе 8 плана.
