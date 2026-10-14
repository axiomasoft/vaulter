Вердикт: **changes**. Две ошибки высокой серьёзности: prune может стереть уже новую квитанцию и повтор выполнит мутацию ещё раз, а на MySQL после таймаута ожидания блокировки сессионный `innodb_lock_wait_timeout` остаётся равным 2.

## Высокая

**1. `packages/core/src/Idempotency/IdempotencyStore.php:146`** — гонка prune и повторного claim.

`prune()` сначала читает истёкшие `scope_digest` (строки 135–140), затем в другом autocommit удаляет `WHERE scope_digest IN (…) AND status = completed` без `expires_at`. Между этими операторами повтор с тем же ключом считает квитанцию истёкшей, удаляет её, выполняет мутацию и коммитит новую квитанцию с тем же digest и `expires_at = now + TTL`. Отложенный `DELETE` совпадает с новой строкой и удаляет её. Следующий повтор строки не находит и выполняет мутацию второй раз (K2).

Тот же предикат без `expires_at` есть на строке 85. Там claim против claim удерживает блокировку строки (на PG это deadlock `40P01` и повтор всей попытки, не второй эффект). Дыра становится живой именно в prune: чтение и удаление — разные транзакции.

Репро (не запускать): два соединения. В A вставить `completed` с `expires_at < now`. Остановить `prune()` после `pluck`. В B выполнить тот же keyed write до commit. Доиграть `DELETE` в A. Третий вызов с тем же ключом создаёт второй эффект вместо replay.

**2. `packages/core/src/Idempotency/IdempotencyStore.php:203`** — MySQL не восстанавливает `innodb_lock_wait_timeout`.

При `1205` код обнуляет `$restore` и бросает `OperationInProgressException` без `previous` (строка 206). Для PG это верно: транзакция уже aborted, лишний `set_config` не нужен. Для MySQL/MariaDB транзакция жива, `SET SESSION` откатом не снимается. `finally` restore не вызывает. Соединение воркера остаётся с таймаутом 2 с навсегда: следующий claim читает `previous = 2` и «восстанавливает» 2. Чужие `lockForUpdate` на этом соединении начинают падать через 2 с вместо 50 с.

Репро (не запускать): соединение A держит незакоммиченный claim. Соединение B ждёт тот же digest дольше 2 с, ловит `operation_in_progress`, затем `SELECT @@session.innodb_lock_wait_timeout` — ожидается исходное значение, фактически 2, в том числе в следующем job того же воркера.

## Средняя

**3. `packages/core/src/Idempotency/IdempotencyStore.php:184`** — SQLite не ограничен 2 с и не становится `operation_in_progress`.

`withBoundedWait()` выставляет таймаут только для `pgsql` и `mysql`/`mariadb`. `isLockTimeout()` знает лишь `55P03` и `1205`. `SQLITE_BUSY` (`database is locked`) проходит в `retryingOutermost`: это concurrency-ошибка, до 3 повторов, затем `concurrent_update`, а не `operation_in_progress`. Ждать будет столько, сколько задано в драйвере. Пока первый держит writer lock, второй не получает отказ D25 «2 с, затем 409 + Retry-After: 1».

Репро (не запускать): два соединения SQLite, один ключ. Первое останавливается внутри попытки после claim. Второе не должно висеть дольше 2 с и должно получить `operation_in_progress`.

**4. `packages/core/src/Doctor/Checks/SchemaTablesCheck.php:19`** — doctor не видит таблицу, readiness видит.

`ENTITIES` (проверка `vaulter:doctor`) не содержит `idempotency_keys`. `OWNED_ENTITIES['core']` (строка 34) содержит, и `ModuleReadiness` на этом останавливает любую запись с `module_not_ready`. Если миграция помечена выполненной, а таблица удалена, doctor даёт `core.schema.tables_present`, записи при этом падают.

Репро (не запускать): `DROP TABLE v_idempotency_keys` при уже записанной миграции `2026_10_13_000028`. Doctor — ok по таблицам. Любой `write()` — `module_not_ready`.

## Не находки

Атомарность claim, мутации и receipt в одной внешней транзакции соблюдена. Savepoint Laravel откатывает строки sink и `afterCommit` вложенного уровня. `CachedRefusal` бросается после commit. Незакоммиченный claim не читается: `ON CONFLICT DO NOTHING` / `ON DUPLICATE KEY UPDATE` ждут на уникальном индексе. PG: `set_config(…, true)` и при `55P03` следующий запрос не выполняется. `OperationInProgressException(1)` без `previous` не попадает в `isConcurrencyFailure`. Replay: сравнение tuple, затем `authorize`, затем `decode`. Вложенный `write()` claim не берёт. `char(64)` под hex SHA-256 и `varchar(344)` под base64 255 байт вмещают значение. Канонический tuple с префиксом длины инъективен.