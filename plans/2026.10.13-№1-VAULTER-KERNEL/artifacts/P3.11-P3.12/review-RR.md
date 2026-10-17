Вердикт: **changes**. Две дыры высокой тяжести: purge по-прежнему отвечает 404 до replay, а рендер неизвестного кода problem падает.

## Находки

**high** — `packages/drive/src/Http/Controllers/Nodes/UpdateNodeController.php:24`  
Повтор rename после purge не доходит до квитанции. `driveOfNode()` вызывается до `try` и для удалённой строки узла бросает `NodeNotFoundException` (`HttpResourceResolver.php:40`). Новый `catch` на строке 27 это исключение не видит. Trash закрыт: репозиторий находит строку с `trashed_at`, `expectedSequence` даёт `NodeNotFound`, при ключе вызов уходит в `rename()` и replay.  
Репро (не запускать): `PATCH /nodes/{id}` с `Idempotency-Key` и верным `If-Match` → 200. Purge узла. Тот же `PATCH`. Ожидание при `edit` на родителе (иначе на корне) — сохранённый снимок и `Idempotent-Replayed: true`. Фактически 404, заголовка нет.

**high** — `packages/core/src/Http/Problems/ProblemRenderer.php:98`  
Снят `?->` у `spec()`, который объявлен как `?ProblemSpec`. Для кода `http_<status>` (`classify()`, ветка `HttpExceptionInterface`) `spec()` возвращает `null`, чтение `retryable` — `Error`. `?? Retryable::Never` не выполняется. Комментарий на этой строке описывает именно этот путь.  
Репро (не запускать): versioned-запрос под maintenance (`HttpException` 503) или `abort(400)` на префиксе v1. Ожидание — problem+json с `retryable: never`. Фактически необработанный `Error` вместо тела проблемы.

**medium** — `packages/drive/src/Http/Controllers/Nodes/UpdateNodeController.php:32`  
Сторож `sequence === PHP_INT_MAX` закрывает исходный ложный успех (чужой `If-Match`, новый ключ → 412, в операцию не входит). Тот же сторож обрывает replay успешного rename, который поднял sequence с `PHP_INT_MAX - 1` до `PHP_INT_MAX` (`Int64Counter::increment` это значение ещё возвращает, следующее уже `SequenceOverflowException`). Повтор со старым `If-Match` — `StaleNodeException`, sequence уже максимум, 412 до claim.  
Репро (не запускать): sequence = `PHP_INT_MAX - 1`, `PATCH` с ключом и верным `If-Match`, новое имя → 200, sequence = `PHP_INT_MAX`. Тот же `PATCH`. Ожидание — 200 и `Idempotent-Replayed: true`. Фактически 412.

## Закрыто, без нового дефекта

- R1.1 `restore_destination_changed`: `ReceiptPolicy` снят, остался `AfterRefetch` (дефолт receipt — never).
- R1.2 `collaboration_unavailable`: дефолт never/never.
- R1.3 `ModuleCompiler.php:524`: повтор кода в модуле даёт reason и не попадает в список; `array_unique` его больше не прячет.
- R1.4: комментарий есть, каталог не делится — как в решении.
- R2.1: `expires_at <= $now` на обоих `DELETE` (`IdempotencyStore.php:86` и `:148`). Свежая квитанция с `expires_at = now + TTL` под предикат не попадает.
- R2.2: после 1205 `$restore` обнуляется только для `pgsql` (`:211`). MySQL/MariaDB восстанавливают `innodb_lock_wait_timeout` в `finally`.
- R2.3: колонка `pragma_busy_timeout` — `timeout` (SQLite 3.45.1, `select timeout as v …` возвращает значение). Таймаут 2000 мс, прежнее значение пишется обратно в `finally`, в том числе при `SQLITE_BUSY` (обнуление restore только у PG). Pragma на соединении, не в транзакции; откат claim её не затирает.
- R2.4: `idempotency_keys` в `ENTITIES`, doctor идёт по этому списку.
- R3.1 для чужого `If-Match` при уже максимальной sequence: 412 сразу. Дыра выше — про replay перехода на этот максимум.
- R3.5: `root_node_id` типа `int|string` и явный id корня дают одну строку в fingerprint. `null` кастуется в `""` (`FolderOperations.php:36`), но `node_not_found` не cacheable, ключ квитанцией не закрепляется.
- `StoredReceipt`: NULL в tuple остаётся `null`, скаляр — строка; сравнение с `IdempotencyDigest::tuple` на штатной строке сходится. Пустые `resource_*` / `auth_scope_*` дают `null`, не `ResourceRef` и не исключение — как старый `ref()`.
- `IdempotencyClaim`: штатный refusal пишет `code` и int `status`; `keyed()` оставляет строковые ключи текущих `problemDetails()`; `array_map(self::text)` строковые заголовки сохраняет.
- `NodeSnapshotCodec` v1: `encode()` кладёт bool/int/string, `json_decode` их сохраняет. `is_bool` / `is_numeric` / `is_scalar` этот вид квитанции принимают.

Тесты не запускались.