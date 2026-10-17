Вердикт: **changes**. Две находки высокой тяжести: сторож `If-Match` на границе последовательности и HTTP-повтор `rename`, который не доходит до replay.

## Находки

### 1. high — `PHP_INT_MAX` совпадает с реальной sequence

`packages/drive/src/Http/Controllers/Nodes/UpdateNodeController.php:26-33`  
`packages/core/src/Operations/NodeSequence.php:29`  
`packages/drive/src/Actions/Nodes/RenameNodeAction.php:66-68`

Sequence `0..PHP_INT_MAX` — штатное состояние (`Int64Counter`, `NodeSequenceContractTest`). При ключе и протухшем `If-Match` контроллер подставляет `expected = PHP_INT_MAX`. Если у узла уже эта sequence, `assertExpected` считает предусловие верным.

- Имя равно текущему: `200` и квитанция успеха. `412` нет.
- Имя другое: `bump` бросает `SequenceOverflowException` → `409 node_sequence_exhausted` (`receipt: cacheable`). Ключ на TTL закреплён за этим отказом, а не за `412`.

Обычный повтор после успешного rename сюда не попадает: HTTP-fingerprint тот же, квитанция replay'ится раньше CAS. Ломается первый устаревший запрос при sequence на максимуме.

Репро (не запускать): узел с `sequence = PHP_INT_MAX`, имя `Current`. `PATCH /nodes/{id}` с новым `Idempotency-Key`, телом `{"name":"Current"}` и чужим `If-Match`. Ожидание по контракту — `412`. Фактически `200` и `Idempotent-Replayed` на следующем таком же запросе. Второе тело — `{"name":"Other"}`: первый ответ `409 node_sequence_exhausted`, повтор — тот же `409` из квитанции.

### 2. high — повтор rename не доходит до `NodeReplayAuthorizer`

`packages/drive/src/Http/Controllers/Nodes/UpdateNodeController.php:24-31`  
`packages/drive/src/Http/Resources/NodeResponses.php:105-127`

`expectedSequence` ищет узел через `nodes()->find` (без корзины) до `rename()` / claim. `NodeNotFoundException` не перехватывается, в отличие от `StaleNodeException`. После purge или trash тот же `PATCH` с тем же ключом и тем же `If-Match` отвечает `404`. Квитанция успеха не читается, проверка родителя и корня из ADR-0029 §5 и `docs/concepts/idempotency.md:58-61` не выполняется.

PHP `nodes()->rename` с тем же ключом до authorizer доходит. HTTP и PHP расходятся.

Репро: `PATCH` rename с ключом и верным `If-Match` → `200`. Purge узла. Тот же `PATCH`. Ожидание, если на родителе ещё есть `edit` (иначе на корне) — сохранённый снимок и `Idempotent-Replayed: true`. Фактически `404`, заголовка нет.

### 3. medium — trashed считается исчезнувшим

`packages/drive/src/Idempotency/NodeReplayAuthorizer.php:60-90`  
`packages/core/src/Operations/NodeLocator.php:36-38`

`find()` вызывает `NodeLocator::find` без `includeTrashed`. Узел в корзине неотличим от purge: право проверяется на записанном родителе, иначе на корне (`authorizeCreate` для create, `Edit` для rename). Актор с `create`/`edit` на родителе получает исторический снимок узла, который обычный `find` прячет (`404`).

Перенос без корзины сделан верно: locator возвращает узел, guard смотрит новое место (create — `Create` на текущем родителе, rename — `Edit` на узле). Скрытый родитель даёт `404`.

На HTTP rename это маскируется находкой 2. На `folders.create` контроллер в runner не вмешивается.

Репро: `POST .../folders` с ключом → `201`. Trash этой папки. Тот же `POST`, пока на родителе есть `create`. Ожидание для ещё существующего узла — отказ как у нового запроса (`404`). Фактически снова `201` и снимок.

Чинить вместе с п. 2: если просто убрать предпроверку контроллера, HTTP rename попадёт в этот fallback.

### 4. medium — replay удалённого узла меняет ETag

`packages/drive/src/Http/Resources/NodeResponses.php:68-77`

`respond` заново считает abilities. `abilitiesMany` → `findMany` не видит удалённый и trashed узел и возвращает `[]`, дальше `NodeAbilities::denied()`. ETag считается из этого набора, не из первого ответа. Контракт исторического ETag: `docs/concepts/idempotency.md:50-51`, ADR-0029:52-53. Поля снимка в кодеке при этом сохраняются.

Репро: тот же сценарий, что в п. 3. Сравнить `ETag` и `data.abilities` первого и второго `201`. Второй — все флаги `false`, другой тег.

### 5. low — `create(null)` и `create(rootId)` — разные fingerprint

`packages/drive/src/Operations/FolderOperations.php:31-36`

В fingerprint попадает запрошенный `parent`, а действие резолвит `null` в корень. Один ключ на `create($name)` и `create($name, $rootId)` даёт `422 idempotency_key_reused`, replay нет. HTTP это не задевает: транспортный hash заменяет fingerprint операции и хеширует тело как есть.

Репро: PHP, один ключ, `folders()->create('Docs')`, затем `folders()->create('Docs', $root->id)`. Второй вызов — `idempotency_key_reused`, папка не возвращается из квитанции.

## Проверено, без находки

- `NodeSnapshotCodec`: все поля `NodeSnapshot`, `FileHeadSnapshot`, `DocumentHeadSnapshot`, `ViewerStateSnapshot`. Время снимка уже UTC; кодек пишет `Y-m-d\TH:i:s.uP`, HTTP снова форматирует через `SnapshotValue::iso` (`Z`). Сдвига инстанта по коду не видно. Равенство `decode(encode())` есть в `IdempotencyContractTest`, прогон не делался.
- Парсер `ReadIdempotencyKey`: одна SF-String (`\"`, `\\`, без хвоста), plain token без пробелов, контролов и запятой, 1–255 байт, регистр сохраняется, списки и два заголовка отклоняются. `422 validation_failed` — отклонение от knowledge (`400`); с OpenAPI, `docs/concepts/idempotency.md:34` и `ProblemRenderer` совпадает.
- HTTP-fingerprint (метод, имя маршрута, скалярные параметры, query, тело, `If-Match`) подменяет fingerprint операции, поэтому `PHP_INT_MAX` в hash не входит. Повтор с тем же `If-Match` после успешного rename replay'ится, пока sequence не равна `PHP_INT_MAX`.
- `HttpOperationContextFactory` кладёт уже декодированный ключ и transport fingerprint только если middleware записал атрибут. Middleware стоит на `folders.store` и `nodes.update`.
- OpenAPI: `Idempotency-Key` на этих двух операциях; ADR-0029 описывает тот же охват.