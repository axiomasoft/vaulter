**Вердикт: approve.** Находок нет.

Проверен `d0d757cd` (предок HEAD, по путям lane diff с HEAD пуст). Репро не запускались.

- **O1.** `EventRecorder::record` (`EventRecorder.php:46`) и `SinkWriter::connection` (`SinkWriter.php:29`) требуют открытую транзакцию `VaulterDatabase`. Событие и delivery пишутся на этом же соединении (`OutboxWriter.php:65`, `:74`). Исключение выходит из записи и откатывает операцию. Повтор при живой строке: `insertOnce` по `event_id` возвращает false → `Replayed` (`OutboxWriter.php:65`) до вставки delivery; если более ранний sink уже вернул `Replayed`, outbox не вызывается (`EventRecorder.php:74`).
- **`outbox_seq`.** `bigIncrements` без `IdStrategy` (`SchemaBuilder.php:871`). MySQL: `bigint unsigned AUTO_INCREMENT`; PostgreSQL (`ulid` preset): `nextval`; SQLite (`ulid.ulid`): `INTEGER` PK, тогда как id сущностей — `varchar`.
- **DDL.** PK `outbox_seq`, UNIQUE `event_id`, индекс `created_at`; consumers PK `consumer_id`; deliveries PK `(event_id, consumer_id)`, индексы due/lease/seq. В снимке MySQL `payload` и `gaps` — `json NOT NULL` без DEFAULT; CREATE в снимке валиден, писатели всегда передают payload и `gaps`.
- **Тело.** `OutboxEnvelope::body` (`OutboxEnvelope.php:60`) совпадает со старым `EventEnvelope::fromEvent`, включая `data` как объект при пустом массиве.
- **`isExternal`.** `DocumentContentUpdated::isExternal` возвращает `$this->created` (`DocumentContentUpdated.php:81`); колонка `external` берётся из occurrence (`OutboxWriter.php:61`). Подписка consumer — по типу.
- **Конфиг.** `hold < settle` отклоняется, равенство проходит (`OutboxConfig.php:89`). `vaulter.webhooks.relay.claim_seconds` в `REMOVED_KEYS` (`WebhooksConfig.php:49`).

Края не засчитаны: забытый `vaulter.schedule.webhooks_relay` не читается и не подменяет `outbox_relay` (установок нет, D21); повтор после prune, когда строка события уже удалена, а PK delivery ещё жив, падает и откатывает операцию, не оставляя новой строки.
