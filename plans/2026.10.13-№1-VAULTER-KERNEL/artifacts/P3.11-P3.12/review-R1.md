Вердикт: **changes**. Одна находка высокой важности: недетерминированный `restore_destination_changed` помечен как квитанция.

## Находки

### 1. Высокая — `restore_destination_changed` кэшируется

`packages/core/src/Http/Problems/DriveSliceProblemCodes.php:84`

Код объявлен как `409 / after_refetch / cacheable`. D25 кэширует только детерминированный 4xx. Запись плана P3.10 требует не фиксировать этот 409 квитанцией: папка назначения могла исчезнуть между авторизацией и блокировкой и снова стать живой.

Сценарий. `POST restore` с `Idempotency-Key`, тот же destination id. Первая попытка получает `RestoreDestinationChangedException`. `IdempotencyClaim` видит `isCacheable` и пишет refusal. `TrashOperations::restore` ловит исключение и вызывает `write()` ещё до двух раз с тем же контекстом. Повторный claim сразу отдаёт сохранённый 409 и не вызывает `resolveDestination`. Клиентский повтор тем же ключом в течение TTL тоже получает 409, даже если папка уже живая.

Репро (не запускать). В `ProblemSpecRulesTest` ожидать `receipt: Never`. Сквозной: restore с ключом, destination не active folder на первой попытке и active на второй. Вторая попытка должна снова выполнять операцию, а не replay.

### 2. Средняя — `collaboration_unavailable` помечен как временный

`packages/documents/src/Http/Problems/DocumentsProblemCodes.php:42`

Сейчас `409 / with_backoff / never` (дефолт receipt), без комментария. Исключение ставится, когда коллаборация выключена или тип её не поддерживает. Это устойчивый отказ, не зависимость. Парный `document_type_unavailable` объявлен `never / cacheable`. Заголовка `Retry-After` у исключения нет.

Сценарий. `POST .../collaboration/session` при `vaulter-documents.collaboration.enabled=false` и с ключом идемпотентности. Тело говорит `retryable=with_backoff`. Клиент ждёт и повторяет тот же ключ. Квитанции нет, операция выполняется снова и снова отвечает 409.

Репро. Сравнить spec с `document_type_unavailable`: ожидание `retryable=never`, `receipt=cacheable`.

### 3. Средняя — дубль кода внутри одного модуля не валит граф

`packages/core/src/Modules/ModuleCompiler.php:477`

`unique()` сначала делает `array_unique` по списку кодов модуля. Два источника одного модуля (`DriveProblemCodes` и `DriveSliceProblemCodes`, D39) или дважды `->problems(один класс)` схлопываются. Reason компиляции не появляется, граф считается валидным. `ProblemCatalog::entries()` (`ProblemCatalog.php:141`) затем бросает `InvalidArgumentException`. Рендерер вызывает каталог снова, уже пытаясь отрисовать эту ошибку.

Сценарий. Модуль `drive` объявляет `node_not_found` в обоих источниках. Компиляция проходит. Первый problem-ответ падает внутри каталога, а не отдаёт `503 module_graph_invalid`.

Репро. В `ModuleCompilerTest` один модуль с двумя источниками одного кода. Ожидание: `InvalidModuleGraph` с reason `problem code`. Сейчас вернётся `CompiledModules`.

### 4. Низкая — `upload_conflict` и `upload_offset_mismatch` без receipt и без комментария

`packages/drive/src/Http/Problems/DriveProblemCodes.php:33` и `:35`

Оба кода — предметные 409, receipt остаётся дефолтным `never`, комментария-отступления нет. Для гонки лока (`forSession`) `never` верный: кэш на 24 часа заморозил бы чужой PATCH. Для `forClosedSession` тот же запрос детерминирован (сессия уже закрыта), и D25 как раз требует квитанцию. Один код не различает эти исходы.

Сценарий. Повтор того же PATCH с тем же ключом по закрытой сессии снова входит в критическую секцию вместо replay 409.

Репро. Развести два кода или зафиксировать комментарием, почему общий receipt именно `never`.

## Проверено, замечаний нет

Классификация остальных кодов совпадает с D25 и `knowledge/p3-surface#problems`. 401, 403, 404, `validation_failed`, 428, 429 и любой 5xx имеют `receipt=never`. Оба 412 (`node_sequence_mismatch`, `document_state_mismatch`) — `after_refetch` и `cacheable`. Предметные 409, 413 и 422, кроме пунктов 1, 2 и 4, — `cacheable`. `operation_in_progress`, `rate_limited`, `dependency_unavailable`, `concurrent_update` — `with_backoff` и `never`. `payload_too_large` — `never` и `cacheable`. У 428 `precondition_required` retryable `after_refetch` при receipt `never`: клиенту нужно заново прочитать ресурс и прислать предусловие, квитанцию хранить нельзя.

`ModuleCompiler` вызывает `codes()` и отвергает дубль между разными установленными модулями, битый код и запись не-`ProblemSpec`. При невалидном графе `ProblemSources::active` отдаёт только `CoreProblemCodes`. `ErrorsReference` строится из `ProblemSources::offered`, поэтому opt-in webhooks попадает в справочник. D39: `DriveSliceProblemCodes` объявляет `DriveModule` (в справочнике модуль `drive`, например `node_not_found`), `WebhooksProblemCodes` — `WebhooksModule`.

Генератор `identities-protocol` читает `new ProblemSpec` и позиционно, и по именам. Пропуск `retryable` или `receipt` даёт `never`. Фикстура: 77 кодов, поля совпадают с исходниками. Ledger: 72 `redefined` и 3 `added` с решением D25. `extension_failed` и `module_not_ready` остались одной записью `added` D8.

Ожидания `OwnershipRegistryTest` совпадают с фикстурами: таблицы P4, конфиг-корни `vaulter` и `vaulter-documents`, события только `documents` и `drive` при пустом `compiled()->events` (P3.13), три hub-маршрута не смонтированы, session-маршрут и три collaboration-кода объявляет documents до P5, счётчики azgard и filament — 15 и 6.

OpenAPI: у `Problem` есть `retryable` со значениями `never`, `after_refetch`, `with_backoff`. Перечень `code` совпадает с 77 кодами. Поле не входит в `required`, тело его всё равно всегда пишет. `errors.ts` декодирует эти три значения, иначе `never`, и сам не повторяет запрос. `schema.d.ts` содержит тот же union. Переводы en/ru есть у всех кодов.