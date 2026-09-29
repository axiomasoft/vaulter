# Проверка исходного аудита по репозиторию

Снимок: `67fe5244206297eea9d1c921820e2883874d7179`. Пути ниже относительны корню репозитория; ссылки открывают локальный код. «Подтверждено» означает прочитанный код и статическую цепочку, **не выполненный exploit или тест**. Приоритет разработки должен учитывать выбранный публичный контракт, а не автоматически наследовать все P0 исходного документа.

## Подтверждённые проблемы и уточнения

### E1. CAS теряет строковый scope — высокая значимость

[`UploadFileAction::tenantId/storageIdForScope`](../../packages/drive/src/Actions/UploadFileAction.php) приводят ID к `int` либо возвращают `null`. [`BlobStore` contract](../../packages/core/src/Contracts/Cas/BlobStore.php) и [реализация](../../packages/media/src/Cas/BlobStore.php) также принимают `?int`. При `scope=storage` разные ULID попадают в `snull`; при строковых tenant ID — в `tnull`. Это подтверждает потерю границы дедупликации, но само по себе ещё не доказывает доступ к чужому download endpoint.

Исправление одной сигнатуры недостаточно: нужны схема, lookup/unique key, lock key, модель, GC и reconciler. У [`BlobReconciler::KEY_PATTERN`](../../packages/media/src/Cas/BlobReconciler.php) уже допустим ULID, но нет UUID с дефисами: простое расширение входного типа оставит UUID-объекты вне распознавания orphan sweep.

Читать вместе: [`BlobStoreRepository`](../../packages/core/src/Repositories/Cas/BlobStoreRepository.php), [`PanelTablesBuilder::defineBlobs`](../../packages/core/src/Schema/PanelTablesBuilder.php), [ADR-0012](../../docs/adr/0012-dedup-scope.md). Последний закрепляет отдельный UNIQUE по `storage_key` и disk-agnostic lookup. Добавить `disk` в индекс без пересмотра всего контракта нельзя. Вопрос «должны ли одинаковые байты на разных дисках иметь отдельные blobs» пока **решение о размещении**, а не установленный дефект.

Существующие [scope-тесты](../../packages/media/tests/Cas/BlobDedupScopeBehaviourTest.php) используют целочисленные ID. Нужна сквозная проверка через Drive для bigint/ULID/UUID7, а не только прямой вызов CAS с числами.

### E2. Квота: атомарный счётчик уже есть, атомарного ограничения не видно

[`UploadFileAction`](../../packages/drive/src/Actions/UploadFileAction.php) проверяет quota по модели до транзакции. [`StorageStoreRepository::adjustUsedBytes`](../../packages/core/src/Repositories/Drive/StorageStoreRepository.php) атомарно прибавляет delta к текущему значению, но не проверяет верхний предел. Два upload разных байтов по 8 при свободных 10 могут пройти precheck; разные hashes не сериализуются одним CAS lock. Сценарий выводится из кода, PostgreSQL-воспроизведение не выполнено.

Второй сценарий — ресурс с неизвестным размером: `probeSize()` допускает 0, а итоговый размер используется для учёта без повторного quota-check. Проверить фактический поток и драйвер.

[`UploadFileTest`](../../packages/drive/tests/Drive/UploadFileTest.php) закрепляет **размер текущих головных версий**, а не сумму всей истории. Это допустимый продуктовый выбор. Отдельно нужны лимиты истории и staging, если они должны ограничивать физические расходы. Нельзя молча сменить смысл `used_bytes`.

### E3. PHP API действительно доверяет вызывающему коду

[`DriveContext`](../../packages/drive/src/DriveContext.php) прямо описывает отсутствие access checks; методы делегируют Actions. [HTTP-тесты](../../packages/drive/tests/Feature/DriveAuthorizationTest.php) проверяют ACL другого входа. Поэтому пользовательский endpoint на facade может пропустить ACL, если host ожидает одинаковых гарантий.

Это подтверждённая асимметрия trust boundary, **не доказательство**, что PHP-библиотека обязана запрещать своему доверенному host прямой доступ к моделям. Рекомендуем выбрать и явно назвать безопасную прикладную поверхность, общую для HTTP/PHP/jobs; оставить ограниченный системный путь. `actorId` сам по себе только audit metadata. Отдельно проверить принадлежность переданного `Node` хранилищу bound handle.

### E4. Upload policy различается по входам

[`UploadFileRequest::rules`](../../packages/drive/src/Http/Requests/UploadFileRequest.php) содержит `max` и MIME allowlist. В просмотренной цепочке `DriveContext::upload → UploadFileAction` эти HTTP-ограничения повторно не применяются; quota — другое ограничение. Перенос общей policy в application layer обоснован. Уже существующие [staged upload boundary tests](../../packages/drive/tests/Uploads/StagedUploadBoundaryTest.php) и [upload config tests](../../packages/drive/tests/Drive/UploadConfigTest.php) нужно расширять через матрицу входов, а не заменять новым параллельным pipeline без сверки.

Поправка: строка в `DriveContext::toStream` **определённо трактуется как bytes**, а не попеременно как путь и bytes. Проблема в удобстве/очевидности контракта. PSR stream действительно приводится к строке; семантика владения ресурсом и предел памяти требуют отдельного решения.

### E5. `registerDocumentType()` не соединён с созданием документа

[`VaulterManager`](../../packages/core/src/VaulterManager.php) сохраняет definition в массив; repository-wide поиск не нашёл потребителя `documentTypes()` в рабочей цепочке Documents. [`CreateDocumentNodeRequest`](../../packages/documents/src/Http/Requests/CreateDocumentNodeRequest.php) ограничивает `format` enum `DocumentType::values()`, а MIME отдельно разрешает через handler registry. `versionFormat()` для неричтекстового содержимого записывает handler ID. Значит, одной регистрации metadata недостаточно для обещанного нового типа, а `format` имеет несколько смыслов.

При этом расширение через MIME handlers **уже работает как самостоятельный механизм**. Читать [handler tests](../../packages/documents/tests/Document/DocumentHandlerRegistryTest.php), [descriptor tests](../../packages/documents/tests/Document/NodeHandlerDescriptorTest.php), [`DocumentContent`](../../packages/core/src/Contracts/Handlers/DocumentContent.php). Новый registry должен использовать существующие seams, а не перечёркивать их.

### E6. README и metadata требуют точечной правки

[`README`](../../README.md) вызывает отсутствующий `resolvePermissionsWith`; текущий [manager](../../packages/core/src/VaulterManager.php) его не предоставляет. Это воспроизводимое несоответствие инструкции API, исправляемое без полного редизайна permissions.

[`packages/drive/composer.json`](../../packages/drive/composer.json) и другие manifests содержат GitHub URL с `axioma-studio`; исходный аудит указывает `axiomasoft`. Следует проверить канонические URL/redirects перед правкой. **Composer vendor `axioma-studio/*` не обязан совпадать с GitHub owner**: глобальное переименование имён пакетов из этого не следует.

## Существенные поправки и новые наблюдения

### E7. After-commit уже реализован; оставшаяся проблема — сохранность outbox

[`FileUploaded`](../../packages/drive/src/Events/FileUploaded.php) и [`NodeVersionCommitted`](../../packages/drive/src/Events/NodeVersionCommitted.php) реализуют `ShouldDispatchAfterCommit`. Утверждение исходного аудита о dispatch listeners до commit для этих событий неверно.

Но [`RecordsDriveOutbox`](../../packages/drive/src/Webhooks/RecordsDriveOutbox.php) вставляет outbox после commit и подавляет ошибки. Процесс может завершиться между commit и вставкой — внешняя синхронизация потеряет событие. Для гарантированной доставки запись outbox должна быть частью той же транзакции **и connection**. Это не равнозначно переносу всех внешних listeners внутрь SQL-транзакции.

[`OutboxTransactionTest`](../../packages/core/tests/Webhooks/OutboxTransactionTest.php) проверяет другой путь — Access/Sharing внутри транзакции, причём отдельно закрепляет сохранение mutation при ошибке staging. Поэтому нельзя объявлять весь существующий outbox строго атомарным: нужно решить, где требуется гарантия, а где явно допустим best-effort.

### E8. Повторная легитимная операция может схлопнуться как duplicate

В [`RecordsDriveOutbox::handleNodeTrashed/handleNodeRenamed/handleNodeMoved`](../../packages/drive/src/Webhooks/RecordsDriveOutbox.php) ключ строится из узла и целевого состояния. [`OutboxRecorder::stage`](../../packages/core/src/Webhooks/OutboxRecorder.php) возвращает `null`, если `event_id` уже есть.

При сохранённой первой outbox row последовательность `trash → restore → trash` не создаст вторую trash row. `A → B → A → B` содержит **три rename**, но первая и третья получают одинаковый ключ. Это прямая статическая цепочка потери уведомления. Разделить ID возникновения события, ключ повтора команды и номер изменения агрегата. Точный будущий API пока не выбран.

### E9. Изоляция tenant — принятый контракт с guard, а не отсутствующая работа

[ADR-0003 и amendment](../../docs/adr/0003-embeddability-data-model.md), [`TenantDelegationContractTest`](../../packages/core/tests/Access/TenantDelegationContractTest.php), [`TenantFootgunGuard`](../../packages/core/src/Support/TenantFootgunGuard.php) уже фиксируют host-owned tenancy. Guard бросает исключение вне production, в production пишет warning; subclass считается достаточным признаком интеграции.

Этого мало для доказательства изоляции, но добавление обязательного глобального scope меняет выбранный контракт и затронет maintenance. Opus нужно отдельно оценить строгий режим public operations, явный all-tenant maintenance и адаптеры host tenancy. Отсутствие `auth:sanctum` в общем middleware не доказывает открытость endpoint: controllers/guards нужно проверять отдельно, а public shares и tus OPTIONS имеют иную семантику.

### E10. Cursor и уникальные индексы частично уже существуют

[`NodeController::index`](../../packages/drive/src/Http/Controllers/NodeController.php) использует `cursorPaginate`; [`NodeReadRepository`](../../packages/core/src/Repositories/Drive/NodeReadRepository.php) содержит сортировку для cursor. Непагинированный `DriveContext::list()` — другой контракт. Не ставить задачу «добавить pagination везде с нуля».

[`PanelTablesBuilder`](../../packages/core/src/Schema/PanelTablesBuilder.php) уже создаёт unique `(file_id, version_no)` и active-sibling guards. Однако [`nextVersionNo`](../../packages/core/src/Repositories/Drive/FileVersionStoreRepository.php) — `max + 1`; уникальность предотвращает дубли строк, но не гарантирует успешное завершение двух конкурентных команд. Проверять блокировку головы, retry/conflict и согласованный quota delta.

Дополнительный вопрос безопасности: комментарии `NodeController::index` признают candidate-superset listing, в котором точное подавление deny отложено. Это не динамически подтверждённая утечка, но имена/размеры узлов сами являются данными. Нужен сценарий «ancestor allow + child deny» для listing/search, отдельно от успешного запрета download/show.

## Что не установлено этим проходом

Полная безопасность shares/SSRF/collaboration, фактический статус удалённого CI, fresh-install, upgrade migrations, PostgreSQL race outcomes, нагрузочные пределы. Наличие модулей GC, reconciliation, observability и тестов подтверждено; их полная корректность не заявляется. Эстетические переименования, отказ от Eloquent и новое разбиение пакетов остаются предложениями, а не блокерами.
