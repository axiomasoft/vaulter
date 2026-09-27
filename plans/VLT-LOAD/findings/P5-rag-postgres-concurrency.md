# P5 RAG — PostgreSQL advisory locks и PgBouncer saturation evidence

Дата проверки: 2026-09-20. Метод: точечный запрос к зарегистрированному Perplexity MCP для поиска
primary sources; browser backend завершился transport timeout и не дал пригодного synthesis.
Встроенный web-search также дважды вернул transport error. Нормативные факты ниже перепроверены
прямым чтением официальной документации PostgreSQL 16 и PgBouncer; неуспешные ответы Perplexity не
используются как authority.

## Verified facts

1. **Transaction advisory lifetime.** Transaction-level advisory lock автоматически освобождается
   в конце транзакции и не имеет explicit unlock; session- и transaction-level запросы одного id
   конфликтуют. Это совместимо с PgBouncer transaction pooling только пока Vaulter не вводит
   session-level lock. RAG:✅ 2026-09-20
   https://www.postgresql.org/docs/16/explicit-locking.html#ADVISORY-LOCKS
2. **Blocking/try forms и namespace.** PostgreSQL предоставляет xact lock как один `bigint` либо
   два `integer`; `pg_try_advisory_xact_lock` возвращает boolean немедленно вместо ожидания.
   RAG:✅ 2026-09-20
   https://www.postgresql.org/docs/16/functions-admin.html#FUNCTIONS-ADVISORY-LOCKS
3. **Lock wait evidence.** `pg_locks.granted=false` означает ожидание, `waitstart` даёт начало;
   документация рекомендует `pg_blocking_pids()` для blocker relation вместо неполного self-join.
   RAG:✅ 2026-09-20 https://www.postgresql.org/docs/16/view-pg-locks.html
4. **Deadlock/wait counters.** `pg_stat_activity.wait_event_type` показывает класс ожидания, а
   `pg_stat_database.deadlocks` — число обнаруженных deadlock в базе. Нужен before/after delta,
   поскольку это накопительные statistics, не per-run metric. RAG:✅ 2026-09-20
   https://www.postgresql.org/docs/16/monitoring-stats.html
5. **Log threshold.** При `log_lock_waits=on` параметр `deadlock_timeout` также определяет задержку
   до сообщения о lock wait; current envelope уже фиксирует 1s. RAG:✅ 2026-09-20
   https://www.postgresql.org/docs/16/runtime-config-locks.html#GUC-DEADLOCK-TIMEOUT
6. **PgBouncer attribution.** Transaction pooling возвращает server connection после транзакции.
   `SHOW POOLS` раскрывает `cl_waiting`, `sv_active`, `maxwait`; `SHOW STATS` — `avg_wait_time`.
   Это отдельная очередь от PostgreSQL lock wait. RAG:✅ 2026-09-20
   https://www.pgbouncer.org/usage.html#show-pools
7. **Pool timeout.** `query_wait_timeout` ограничивает время ожидания назначения server connection;
   при превышении client disconnect, при 0 очередь не ограничена. Timeout control обязан фиксировать
   effective config. RAG:✅ 2026-09-20 https://www.pgbouncer.org/config.html#query_wait_timeout

## Repository facts and design consequences

- RAG:— `UploadSessionStoreRepository::acquireForPatch()` вызывает nonblocking
  `pg_try_advisory_xact_lock(hashtextextended('upload:' || upload_id, 0))`; конфликт — protocol 409,
  поэтому короткое NOWAIT событие может не попасть в polling.
- RAG:— `BlobLock::acquire()` вызывает blocking `pg_advisory_xact_lock(hashtext(scope|sha256))`.
  `hashtext` попадает в `integer`-форму lock API; разные strings могут разделить 32-bit key. P5
  строит реальную collision pair и измеряет false serialization, не заявляя SHA-256 collision.
- RAG:— `load/docker-compose.load.yml` уже задаёт PostgreSQL 16, `log_lock_waits=on`,
  `deadlock_timeout=1s`, PgBouncer `pool_mode=transaction` и tier-specific pool/client limits.
- RAG:— Package concurrency tests имеют максимум два процесса/соединения. Они остаются correctness
  regression; P5 добавляет load evidence, а не заменяет их.
- RAG:— Текущая P8.5 state projection расходится с journal и должна быть reconciled владельцем до
  P5. Отдельный P5 raw collector сохраняет attribution, но не чинит P8 producer.

## Rejected shortcuts

- Один общий HTTP p95 без FPM/pool/DB attribution.
- Считать expected same-session 409 нарушением обычного ≤5 % профиля.
- Выдать direct SQL N=200 за 200 одновременных Vaulter requests внутри PostgreSQL.
- Вывести blocker relation только self-join'ом `pg_locks`, игнорируя wait queues/parallel workers.
- Назвать targeted 1m rows на tier-S host полноценным tier-L SLO прогоном.
- Менять pool size/timeout после просмотра результата и сравнивать с прежним baseline.
