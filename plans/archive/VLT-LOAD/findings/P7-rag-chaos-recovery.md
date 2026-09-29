# P7 RAG — failure injection, recovery и fidelity

Дата проверки: 2026-09-20. Метод: один объединённый Perplexity MCP discovery query, затем сверка
несущих утверждений с первичными AWS, PostgreSQL 16, PgBouncer, Shopify Toxiproxy и Linux man-pages.
Perplexity synthesis сам по себе не считается authority.

## Verified external facts

1. **Toxiproxy — транспортный injector.** Он управляет TCP proxy через API и поддерживает latency,
   timeout, reset_peer, limit_data, disable/reset. Это не protocol-aware S3 503 и не PostgreSQL
   promotion. RAG:✅ 2026-09-20 https://github.com/Shopify/toxiproxy#toxics
2. **AWS multipart abort требует reconciliation.** In-flight part после abort может завершиться;
   abort иногда нужно повторить, а `ListParts` — проверить на пустоту. Это относится только к
   настоящему AWS UploadId. RAG:✅ 2026-09-20
   https://docs.aws.amazon.com/AmazonS3/latest/API/API_AbortMultipartUpload.html
3. **Complete может иметь нетривиальный ответ.** AWS документирует ошибку внутри initial `200 OK` и
   необходимость корректной обработки/retry; SDK обрабатывает embedded error по своей retry config.
   RAG:✅ 2026-09-20
   https://docs.aws.amazon.com/AmazonS3/latest/API/API_CompleteMultipartUpload.html
4. **Advisory lock lifetime.** PostgreSQL transaction-level advisory lock живёт до конца текущей
   транзакции; session-level — до unlock/session end. PgBouncer transaction pooling возвращает backend
   после транзакции и прямо не поддерживает session-level advisory locks. RAG:✅ 2026-09-20
   https://www.postgresql.org/docs/16/functions-admin.html#FUNCTIONS-ADVISORY-LOCKS ·
   https://www.pgbouncer.org/features.html
5. **Promotion требует fencing.** PostgreSQL описывает `pg_ctl promote`/`pg_promote()` и отдельно
   предупреждает, что старый primary должен быть уведомлён/остановлен, иначе два primary ведут к
   потере данных. RAG:✅ 2026-09-20
   https://www.postgresql.org/docs/16/warm-standby-failover.html
6. **ENOSPC может быть частичной/отложенной ошибкой.** `write(2)` вправе записать меньше запрошенного,
   включая нехватку места; ENOSPC/writeback error может проявиться на последующем write/fsync.
   RAG:✅ 2026-09-20 https://man7.org/linux/man-pages/man2/write.2.html ·
   https://man7.org/linux/man-pages/man2/fsync.2.html

## Repository facts and design consequences

- RAG:— `S3MultipartHandler` не использует AWS multipart API: part bytes живут в Flysystem keys, а
  complete собирает их сервером. P7 не требует AWS `ListParts` от текущего handler.
- RAG:— upload session lock — `pg_try_advisory_xact_lock(hashtextextended(...))`; PG disconnect должен
  завершить транзакцию/lock, но durable session/object state всё равно сверяется после reconnect.
- RAG:— `BlobStore::commit()` сначала пишет локальный temp через `Sha256ContentStore::stage()`, затем
  внутри DB transaction promotes объект. `stage()` сейчас не проверяет short/failed `fwrite`; P7
  обязан измерить outcome ENOSPC, а не заранее объявить его безопасным.
- RAG:— object-store `FlysystemStorageDriver::put()` возвращает false при failed write, но
  `Sha256ContentStore::promote()` не использует return value. S3 fault cells проверяют phantom DB
  commit/object absence как возможный product RED.
- RAG:— P1 задаёт steady-state error budgets, но не RTO/RPO для injected outage. P7 публикует recovery
  durations и integrity verdict без выдуманного временного порога.

## Rejected shortcuts

- Называть proxy disable/connection reset «реальным S3 outage» или «PG failover».
- Проверять успех только по HTTP, DB status или `/ready` без двустороннего DB/object reconciliation.
- Заполнять общий host/container `/tmp`; ENOSPC допускается только на выделенном bounded tmpfs.
- Автоматически повторять ambiguous commit до проверки durable identity.
- Считать отсутствие dangling advisory lock доказательством отсутствия partial product state.
