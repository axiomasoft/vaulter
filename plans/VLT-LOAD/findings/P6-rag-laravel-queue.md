# P6 RAG — Laravel Redis queue и PostgreSQL claim semantics

Дата проверки: 2026-09-20. Метод: один точечный Perplexity MCP query для discovery, затем сверка
load-bearing утверждений с официальной документацией Laravel/PostgreSQL и текущим lock/source tree.
Perplexity synthesis сам по себе не считается authority.

## Verified facts

1. **Queue lease vs timeout.** Laravel возвращает job в очередь после `retry_after`, если она не
   удалена/освобождена; worker `--timeout` должен быть на несколько секунд меньше, иначе job может
   исполняться дважды. Job-level timeout имеет приоритет, а blocking I/O должен иметь собственный
   timeout. RAG:✅ 2026-09-20 https://laravel.com/docs/13.x/queues#job-expirations-and-timeouts
2. **Redis queue planes.** Текущий Laravel source разделяет pending list, delayed/reserved sorted
   sets и мигрирует expired reserved jobs обратно в primary queue. Поэтому total size нельзя
   выдавать за pending backlog. RAG:✅ 2026-09-20
   https://github.com/laravel/framework/blob/13.x/src/Illuminate/Queue/RedisQueue.php
3. **PostgreSQL row claims.** `FOR UPDATE` блокирует конфликтующие lockers/writers до конца
   транзакции. `SKIP LOCKED` пропускает занятые строки и пригоден для queue-like consumers, но даёт
   неполное представление данных. RAG:✅ 2026-09-20
   https://www.postgresql.org/docs/16/explicit-locking.html#LOCKING-ROWS ·
   https://www.postgresql.org/docs/16/sql-select.html#SQL-FOR-UPDATE-SHARE

## Repository facts and design consequences

- RAG:— root `composer.lock` фиксирует Laravel `v13.17.0`; package contracts поддерживают 12/13.
- RAG:— load worker: Redis, `retry_after=90`, default worker timeout 60, `--tries=1`; P6 сохраняет
  точные effective values в manifest.
- RAG:— `DeliverWebhook` не задаёт queue `$tries/$timeout/$backoff`; HTTP timeout=5 s и connect=2 s,
  а package retry хранится в DB и владеется `WebhookRetryDispatcher`.
- RAG:— HTTP/transport failures ловятся job и превращаются в DB `failed|dead`, поэтому Laravel
  `failed_jobs` не является счётчиком package DLQ.
- RAG:— `lockForUpdate()` используется без `SKIP LOCKED`: overlapping ticks должны измерять
  serialization/wait, а не предполагать parallel claim.
- RAG:— `vaulter_webhook_queue_wait_seconds` выводится из delivery `created_at`; для retry это age
  delivery, не enqueue→start текущей попытки. P6 ведёт отдельный lifecycle ledger.
- RAG:— текущий `vaulter_outbox_endpoints_scanned_total` инкрементируется после фильтрации только для
  subscribed endpoints. P6 не использует его как доказательство всех просканированных active rows
  без producer reconciliation; cardinality curve и raw counts остаются обязательными.

## Rejected shortcuts

- DB `delivered` как доказательство ровно одного HTTP POST.
- Redis total size как pending depth.
- `failed_jobs` как package DLQ.
- Serial terminal no-op как concurrent exactly-once.
- Изменённый `retry_after`/timeout control как verdict текущего production-like envelope.
