Да. Ниже — отдельный детальный раздел к архитектурному аудиту **Vaulter**, основанный на ранее зафиксированном snapshot репозитория `axiomasoft/vaulter` и сопоставлении его решений с практиками Amazon S3/AWS, Dropbox и Google Drive/Google API Design Guide.

> Важно: рекомендации ниже не предлагают копировать инфраструктурную сложность AWS или Google. Цель — перенести в библиотеку их наиболее полезные **API-инварианты**: стабильные идентификаторы, безопасные повторы, optimistic concurrency, change feed, resumable uploads, policy-based authorization и явные асинхронные операции.

# Vaulter: практики AWS, Dropbox и Google

## Основной вывод

Vaulter уже использует часть правильных подходов: стабильные node/document identifiers, CAS, версии, operation catalog, idempotency, short-lived direct URLs, webhooks, document type registry и fail-closed security controls. Но некоторые решения пока реализованы как отдельные функции, а не как единая системная модель.

Главное улучшение — превратить Vaulter из набора CRUD-сервисов для файлов и документов в **ресурсно-операционную платформу**:

- Любой объект имеет стабильное имя и версию состояния.
- Любая мутация поддерживает preconditions.
- Повтор операции безопасен через idempotency key.
- Длительная работа представлена отдельным ресурсом `Operation`.
- История изменений доступна через cursor-based change feed.
- Webhook сообщает только о появлении изменений, но не является источником истины.
- Путь является пользовательским представлением, а не идентичностью объекта.
- Права вычисляются как `principal + action + resource + conditions`.
- Upload проходит через формальную state machine.
- Extension packages подключаются через небольшие SPI, не расширяя ядро.

Оценка текущего соответствия этим принципам — около **7,7/10**. Наиболее сильные области — CAS, document extension model, версии и общий security mindset. Наиболее слабые — change feed, формальная resource model, update masks, long-running operations, policy composition и единая модель concurrency.

## Что брать у AWS

Amazon S3 полезен Vaulter не внутренней архитектурой хранения, а строгими контрактами вокруг объектов, загрузок, событий и доступа.

### Политики доступа

AWS строит IAM-политику вокруг четырёх элементов:

- Principal.
- Action.
- Resource.
- Condition.

Идентичности по умолчанию не имеют разрешений; доступ выдаётся явно, а ресурсы и условия позволяют дополнительно сузить его. В модели S3 отсутствие явного `Allow` приводит к отказу, а явный `Deny` имеет приоритет над `Allow`. [docs.aws.amazon](https://docs.aws.amazon.com/wellarchitected/latest/security-pillar/sec_permissions_least_privileges.html)

Vaulter следует перейти от проверки условного уровня доступа к policy request:

```php
final readonly class AuthorizationRequest
{
    public function __construct(
        public Principal $principal,
        public Action $action,
        public ResourceReference $resource,
        public AuthorizationContext $context,
    ) {}
}
```

```php
enum PolicyDecision: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Abstain = 'abstain';
}
```

```php
interface PolicyEvaluator
{
    public function evaluate(
        AuthorizationRequest $request,
    ): PolicyDecision;
}
```

Итоговый алгоритм:

```text
if any evaluator returns Deny:
    deny

if one or more evaluators return Allow:
    allow

deny
```

Это лучше, чем `bool can(...)`, потому что позволяет независимо подключать:

- Tenant isolation policy.
- Drive membership policy.
- Node ACL policy.
- Shared-link policy.
- Retention policy.
- Quarantine policy.
- Document-type policy.
- Commercial collaboration policy.
- Host-application policy.

### Конкретное изменение

Вместо интерфейса общего вида:

```php
$permissions->can($actor, 'write', $node);
```

следует использовать namespaced actions:

```php
$authorizer->authorize(new AuthorizationRequest(
    principal: $actor,
    action: Action::from('drive.nodes.update'),
    resource: ResourceReference::fromNode($node),
    context: new AuthorizationContext(
        tenantId: $tenantId,
        ipAddress: $request->ip(),
        sharedLinkId: $sharedLinkId,
        requestedFields: $updateMask,
    ),
));
```

Action keys должны принадлежать пакетам:

```text
core.operations.read
drive.nodes.read
drive.nodes.create
drive.nodes.move
drive.nodes.delete
drive.nodes.restore
drive.shares.create
media.blobs.read
documents.revisions.create
documents.exports.create
collaboration.sessions.create
```

Core при этом знает только `Action`, `ResourceReference`, `Principal` и механизм композиции. Конкретные действия регистрируют пакеты.

### Idempotency

AWS рекомендует делать мутирующие операции идемпотентными с использованием клиентского токена. Повтор с тем же токеном должен возвращать результат первой успешной операции, а состояние запроса необходимо хранить как минимум в фазах `pending`, `completed` и `failed`. [docs.aws.amazon](https://docs.aws.amazon.com/wellarchitected/latest/framework/rel_prevent_interaction_failure_idempotent.html)

В Vaulter уже есть idempotency infrastructure, но для уровня 1.0 контракт нужно зафиксировать строже:

```php
interface IdempotentOperationStore
{
    public function claim(
        IdempotencyScope $scope,
        IdempotencyKey $key,
        RequestFingerprint $fingerprint,
    ): ClaimResult;

    public function complete(
        ClaimedOperation $operation,
        StoredOperationResponse $response,
    ): void;

    public function fail(
        ClaimedOperation $operation,
        StoredOperationFailure $failure,
    ): void;
}
```

`IdempotencyScope` должен включать:

```text
tenant_id
principal_id
operation_key
API major version
```

`RequestFingerprint` должен вычисляться по нормализованному payload:

```text
HTTP method
route operation ID
resource ID
body
relevant headers
content checksum
```

Поведение:

| Ситуация | Результат |
|---|---|
| Новый ключ | Операция выполняется |
| Тот же ключ и тот же fingerprint | Возвращается сохранённый результат |
| Тот же ключ и другой fingerprint | `409 idempotency_key_reused` |
| Операция ещё выполняется | `409 operation_in_progress` или сохранённый `Operation` |
| Предыдущий transient failure | Разрешён повтор по документированным правилам |
| Предыдущий permanent failure | Возвращается тот же problem response либо требуется новый ключ |

Сейчас важно не просто предотвращать duplicate insert, а сохранять **семантически тот же ответ**: status, resource ID, revision, operation ID и безопасный набор headers.

### Retriable errors

S3 SDK применяет exponential backoff при временных ошибках, включая `503 Slow Down`; AWS отдельно рекомендует backoff для дополнительных повторов. [docs.aws.amazon](https://docs.aws.amazon.com/AmazonS3/latest/userguide/optimizing-performance-design-patterns.html)

Vaulter должен формализовать retry classification:

```php
interface RetryClassifier
{
    public function classify(Throwable $error): RetryDisposition;
}
```

```php
enum RetryDisposition
{
    case Never;
    case Immediately;
    case WithBackoff;
    case AfterDelay;
}
```

В problem details полезно добавить:

```json
{
  "type": "https://vaulter.dev/problems/storage-throttled",
  "status": 503,
  "code": "storage_throttled",
  "request_id": "req_...",
  "retryable": true,
  "retry_after": 5
}
```

Автоматически повторять можно только операции, которые:

- Идемпотентны по своей природе.
- Имеют idempotency key.
- Не начали необратимый side effect.
- Либо используют transactional outbox/operation journal.

### Multipart upload

S3 разделяет создание multipart upload, передачу частей и атомарное завершение. Для целостности поддерживаются checksums частей и всего объекта; объект принимается только после проверки переданной контрольной суммы. [docs.aws.amazon](https://docs.aws.amazon.com/us_en/AmazonS3/latest/userguide/checking-object-integrity-upload.md)

Vaulter upload session должна быть самостоятельным агрегатом:

```php
enum UploadState: string
{
    case Created = 'created';
    case Receiving = 'receiving';
    case Verifying = 'verifying';
    case Quarantined = 'quarantined';
    case Scanning = 'scanning';
    case Ready = 'ready';
    case Committed = 'committed';
    case Aborted = 'aborted';
    case Expired = 'expired';
    case Failed = 'failed';
}
```

```php
final readonly class CreateUploadSession
{
    public function __construct(
        public TenantId $tenantId,
        public PrincipalId $principalId,
        public ?NodeId $parentId,
        public string $filename,
        public MediaType $mediaType,
        public int $expectedSize,
        public ChecksumExpectation $checksum,
        public UploadPurpose $purpose,
        public Instant $expiresAt,
    ) {}
}
```

Обязательные инварианты:

- `expectedSize` задаётся до начала upload.
- Максимальный размер проверяется до выдачи direct-upload URL.
- Каждая часть имеет номер, размер и checksum.
- Повторная передача той же части с тем же checksum безопасна.
- Та же часть с другим checksum отклоняется.
- `complete` проверяет наличие всех частей.
- Финальный content hash вычисляется независимо.
- Blob не становится доступным до положительного scanner verdict.
- Создание node/document и привязка blob происходят атомарно.
- Незавершённые sessions автоматически очищаются.
- `abort` идемпотентен.
- Завершённая session не может быть переиспользована.

### Integrity metadata

Dropbox возвращает `content_hash`, пригодный для проверки целостности файла. S3 хранит checksum вместе с metadata объекта и позволяет использовать его для проверки download. [docs.dropboxapi](https://docs.dropboxapi.com/dropbox-api/api-reference/user-endpoints/files/upload)

В Vaulter нужно разделить как минимум четыре понятия:

| Поле | Назначение |
|---|---|
| `content_hash` | Hash логического содержимого |
| `storage_checksum` | Проверка конкретного storage object |
| `etag` | Версия metadata/resource |
| `revision_id` | Идентификатор immutable revision |

Нельзя использовать одно поле одновременно как hash содержимого, optimistic concurrency token и storage ETag.

Рекомендуемая модель:

```php
final readonly class ContentDigest
{
    public function __construct(
        public string $algorithm,
        public int $algorithmVersion,
        public string $value,
    ) {}
}
```

Algorithm version необходим, чтобы в будущем можно было изменить chunking или правила canonicalization без поломки существующего CAS.

### Версионирование

S3 Versioning сохраняет версии объектов и позволяет восстановиться после случайных действий; Object Lock добавляет WORM retention и legal holds. [aws.amazon](https://aws.amazon.com/s3/features/)

Для Vaulter следует разделить:

- Soft deletion node.
- Immutable content revisions.
- Metadata revisions.
- Delete marker.
- Retention policy.
- Legal hold.

Не следует физически удалять blob сразу после удаления последней видимой ссылки. Сначала должна создаваться GC-кандидатура:

```text
referenced
unreferenced
retention_pending
gc_eligible
deleting
deleted
```

GC обязан повторно проверить references внутри транзакции или под distributed lock.

### События

S3 Event Notifications доставляются как минимум один раз, могут приходить не по порядку и иногда дублироваться. Это правильная модель ожиданий и для Vaulter webhooks. [docs.aws.amazon](https://docs.aws.amazon.com/ja_jp/ja_jp/AmazonS3/latest/userguide/notification-how-to-event-types-and-destinations.html)

Каждое доменное событие должно содержать:

```json
{
  "id": "evt_01...",
  "type": "drive.node.moved.v1",
  "occurred_at": "2026-10-19T07:00:00Z",
  "tenant_id": "ten_...",
  "aggregate": {
    "type": "drive.node",
    "id": "nod_...",
    "version": 42
  },
  "operation_id": "op_...",
  "request_id": "req_...",
  "actor_id": "usr_...",
  "data": {}
}
```

Consumer использует `(event_id)` для deduplication и `(aggregate_id, aggregate_version)` для обнаружения пропусков или перестановки событий. Глобальную упорядоченность обещать нельзя.

### Request IDs

S3 возвращает request identifiers даже для ошибочных запросов, чтобы запрос можно было сопоставить с журналами и обращением в поддержку. [docs.aws.amazon](https://docs.aws.amazon.com/de_de/AmazonS3/latest/API/get-request-ids.html)

Vaulter следует генерировать:

- `request_id` — одна HTTP-попытка.
- `operation_id` — логическая операция, переживающая retries.
- `trace_id` — distributed trace.
- `event_id` — конкретное опубликованное событие.
- `delivery_id` — попытка webhook delivery.

Эти идентификаторы нельзя смешивать.

## Что брать у Dropbox

Dropbox особенно полезен для моделирования file tree, stable identity, namespaces, синхронизации и webhooks.

### ID важнее пути

Dropbox присваивает файлам и папкам стабильные IDs, сохраняющиеся при перемещении или переименовании; path остаётся удобным человекочитаемым адресом. [dropbox](https://www.dropbox.com/developers/paper-api-alpha)

Vaulter должен закрепить правило:

> `NodeId` — идентичность объекта. Путь — производная проекция текущего дерева.

Это означает:

- Foreign keys используют `node_id`.
- Audit events используют `node_id`.
- Comments и document revisions используют `node_id` или `document_id`.
- Shares используют stable ID.
- Path нельзя хранить как единственную ссылку на объект.
- Move не меняет identity.
- Rename не инвалидирует внутренние ссылки.
- Старый path не обязан разрешаться.

Полезно добавить каноническое resource name:

```text
tenants/{tenant}/drives/{drive}/nodes/{node}
```

И не использовать:

```text
/Marketing/2026/Budget.xlsx
```

в качестве security boundary.

### Namespace model

Dropbox использует namespaces и позволяет выполнять path-based операции относительно явно выбранного root; это особенно важно для team spaces и shared folders. [docs.dropboxapi](https://docs.dropboxapi.com/dropbox-api/docs/team-files)

В Vaulter tenant context и drive context должны быть явными value objects:

```php
final readonly class ResourceScope
{
    public function __construct(
        public TenantId $tenantId,
        public DriveId $driveId,
        public ?NodeId $rootNodeId = null,
    ) {}
}
```

Запросы не должны неявно брать tenant только из глобального container singleton:

```php
$nodes->find($nodeId);
```

Безопаснее:

```php
$nodes->find(
    scope: $scope,
    nodeId: $nodeId,
);
```

На уровне repository query всегда должны присутствовать минимум `tenant_id` и `drive_id`. Даже если ID глобально уникален, явный scope защищает от object-level authorization ошибок и accidental cross-tenant joins.

### Cursor-based change feed

Dropbox использует cursor, который можно передавать в `list_folder/continue` для получения следующих элементов и последующих изменений. Webhook не содержит сами изменения: он лишь сообщает, что для аккаунта появились изменения, после чего клиент получает их через cursor API. [dropbox.github](https://dropbox.github.io/dropbox-sdk-java/api-docs/v4.0.0/com/dropbox/core/v2/files/DbxUserFilesRequests.html)

Это одна из самых ценных практик для Vaulter.

Нужно добавить public SPI:

```php
interface ChangeFeed
{
    public function list(
        ChangeFeedRequest $request,
    ): ChangePage;
}
```

```php
final readonly class ChangeFeedRequest
{
    public function __construct(
        public ResourceScope $scope,
        public ?ChangeCursor $cursor,
        public int $pageSize,
        public ChangeFilter $filter,
    ) {}
}
```

```php
final readonly class ChangePage
{
    /** @param list<ChangeRecord> $changes */
    public function __construct(
        public array $changes,
        public ChangeCursor $nextCursor,
        public bool $hasMore,
    ) {}
}
```

Change record:

```php
final readonly class ChangeRecord
{
    public function __construct(
        public ChangeSequence $sequence,
        public ChangeKind $kind,
        public ResourceReference $resource,
        public ?ResourceVersion $version,
        public bool $removed,
        public Instant $occurredAt,
    ) {}
}
```

Это позволит:

- Синхронизировать desktop/mobile clients.
- Восстанавливаться после потерянного webhook.
- Строить search index.
- Реплицировать metadata.
- Реализовать offline mode.
- Подключать audit export.
- Синхронизировать коммерческий collaboration package.
- Добавить внешние коннекторы.

Webhook должен содержать только hint:

```json
{
  "tenant_id": "ten_...",
  "drive_id": "drv_...",
  "latest_cursor": "opaque..."
}
```

После получения consumer вызывает `/changes?cursor=...`.

### Cursor — не offset

Google также требует opaque page tokens и предупреждает, что пользователь не должен разбирать внутреннее устройство token. Поэтому Vaulter cursor нельзя делать простым base64 от `created_at:id`. [google.aip](https://google.aip.dev/158)

Cursor должен быть:

- Opaque.
- URL-safe.
- Подписан или храниться server-side.
- Привязан к tenant/drive.
- Привязан к filter fingerprint.
- Versioned.
- Иметь документированную expiry policy.

Пример внутреннего payload:

```json
{
  "v": 1,
  "tenant": "ten_...",
  "drive": "drv_...",
  "sequence": 182731,
  "filter_hash": "sha256:...",
  "issued_at": 1792393200
}
```

Публичный клиент не должен видеть эту структуру.

### Webhook как сигнал

Dropbox требует быстро ответить на webhook и выполнять обработку асинхронно; payload сообщает о наличии изменений, но не заменяет change enumeration. [docs.dropboxapi](https://docs.dropboxapi.com/dropbox-api/docs/webhooks)

Для Vaulter это означает:

1. Webhook delivery сообщает о событии или новом cursor.
2. Consumer отвечает `2xx`.
3. Тяжёлая обработка выполняется через очередь.
4. Consumer самостоятельно вызывает change feed.
5. Cursor сохраняется только после успешной обработки страницы.
6. Повтор delivery не создаёт повторного side effect.

Это устойчивее, чем передавать в webhook полный mutable resource snapshot и ожидать, что каждое уведомление обязательно дойдёт.

### Stable revision

Dropbox допускает операции с проверкой ожидаемой revision, например delete при совпадении `parentRev`. В Vaulter preconditions должны быть доступны для всех опасных мутаций: [dropbox.github](https://dropbox.github.io/dropbox-sdk-java/api-docs/v4.0.0/com/dropbox/core/v2/files/DbxUserFilesRequests.html)

```php
final readonly class MutationPreconditions
{
    public function __construct(
        public ?ResourceVersion $ifVersion,
        public ?RevisionId $ifRevision,
        public ?Instant $ifUnmodifiedSince,
        public bool $mustExist = true,
    ) {}
}
```

Применять к:

- Rename.
- Move.
- Metadata update.
- Content replacement.
- Delete.
- Restore.
- Share policy update.
- Document revision commit.
- Collaboration snapshot commit.

## Что брать у Google

Google API Design Guide полезен прежде всего для долгосрочной стабильности API и SDK.

### Стандартные ресурсы

Google рекомендует единообразные resource names и стандартные поля, включая `name`, `etag` и `request_id`. [google.aip](https://google.aip.dev/148)

Для Vaulter стоит стандартизировать DTO:

```json
{
  "name": "tenants/ten_1/drives/drv_1/nodes/nod_1",
  "id": "nod_1",
  "display_name": "Budget.xlsx",
  "kind": "document",
  "etag": "\"rv_42\"",
  "create_time": "2026-10-19T07:00:00Z",
  "update_time": "2026-10-19T07:30:00Z"
}
```

`name` — глобально однозначное resource name внутри Vaulter API. `display_name` — пользовательское имя. `id` — короткий stable identifier.

### Optimistic concurrency

Google использует ETag, чтобы клиент мог доказать, что обновляет известную ему версию объекта. При несовпадении операция отклоняется, предотвращая lost updates. [google.aip](https://google.aip.dev/154)

В Vaulter `etag` следует рассчитывать по resource version, а не обязательно по blob hash:

```text
etag = quote(base64url(HMAC(resource_id + resource_version)))
```

Пример:

```http
PATCH /v1/nodes/nod_123
If-Match: "dHJfNDI"
```

При конфликте:

```json
{
  "type": "https://vaulter.dev/problems/version-conflict",
  "status": 409,
  "code": "version_conflict",
  "expected_etag": "\"dHJfNDI\"",
  "current_etag": "\"dHJfNDM\"",
  "request_id": "req_..."
}
```

Для критических мутаций `If-Match` должен быть обязательным:

- Content replacement.
- Move между родителями.
- Destructive delete.
- Restore с возможным name collision.
- ACL update.
- Document revision commit.

Для additive operations он может быть необязательным:

- Add comment.
- Add reaction.
- Append audit annotation.

### Update masks

Google использует update masks, чтобы клиент явно указал поля, которые требуется изменить; это предотвращает случайное обнуление или перезапись остальных полей. [docs.cloud.google](https://docs.cloud.google.com/java/docs/update-masks)

Вместо отдельных методов:

```text
renameNode
changeDescription
setMetadata
changeColor
setCustomProperties
```

можно предоставить единый стабильный update contract:

```json
{
  "node": {
    "display_name": "New name.xlsx",
    "description": null,
    "metadata": {
      "department": "finance"
    }
  },
  "update_mask": [
    "display_name",
    "description",
    "metadata.department"
  ]
}
```

Правила:

- Поле вне `update_mask` игнорируется.
- Поле в mask со значением `null` очищается.
- Неизвестный path возвращает validation error.
- Immutable fields отклоняются.
- Package extensions регистрируют собственные mask paths.
- `"*"` разрешается только для полного replace и только там, где это безопасно.

Это особенно важно для document type metadata и будущих spreadsheet/document integrations: добавление нового поля не должно приводить к его обнулению старым клиентом.

### Opaque pagination

Google требует добавлять pagination с самого начала, использовать `page_size`, `page_token`, `next_page_token` и непрозрачные tokens. [google.aip](https://google.aip.dev/132)

В Vaulter все list/search endpoints должны использовать единый контракт:

```json
{
  "nodes": [],
  "next_page_token": "opaque",
  "snapshot_time": "2026-10-19T07:00:00Z"
}
```

Параметры:

```text
page_size
page_token
filter
order_by
fields
```

Token должен быть привязан к:

- Tenant.
- Drive.
- Query/filter.
- Sort order.
- Snapshot или cursor position.
- API version.

Offset pagination следует оставить только для административных интерфейсов с маленькими наборами данных.

### Request IDs

Google рекомендует optional `request_id` для deduplication, безопасных повторов и аудита; при повторе сервер должен вернуть результат ранее выполненной операции. [google.aip](https://google.aip.dev/155)

Vaulter следует унифицировать `request_id` и idempotency:

```http
Vaulter-Request-Id: 5b3f0a9d-...
Idempotency-Key: 8364f0db-...
```

Разница:

- `Vaulter-Request-Id` идентифицирует transport attempt.
- `Idempotency-Key` идентифицирует logical mutation.

Клиент может повторить операцию с новым request ID, но тем же idempotency key.

### Long-running operations

Google рекомендует представлять длительное изменение отдельной long-running operation, а не удерживать HTTP request до завершения. [google.aip](https://google.aip.dev/134)

Для Vaulter это нужно применить к:

- Recursive folder copy.
- Recursive delete/restore.
- Large export.
- Office document import.
- Virus scan.
- Thumbnail generation.
- Re-encryption.
- Blob migration.
- Drive transfer.
- Large permission propagation.
- Spreadsheet conversion.
- Document schema migration.
- Integrity scrub/heal.

Контракт:

```json
{
  "name": "operations/op_123",
  "done": false,
  "metadata": {
    "type": "drive.folder.copy",
    "progress": 0.42,
    "processed_items": 420,
    "total_items": 1000
  }
}
```

После завершения:

```json
{
  "name": "operations/op_123",
  "done": true,
  "response": {
    "resource": "tenants/ten_1/drives/drv_1/nodes/nod_new"
  }
}
```

Либо:

```json
{
  "name": "operations/op_123",
  "done": true,
  "error": {
    "code": "destination_conflict",
    "status": 409
  }
}
```

Нужны:

- `GET /operations/{id}`.
- `DELETE /operations/{id}` для cancel request.
- Progress metadata.
- Retry policy.
- Parent/child operations.
- Retention периода завершённых operations.
- Authorization на просмотр operation.
- Idempotency для её создания.

### Partial success

Google отдельно моделирует unreachable resources и partial success, вместо того чтобы скрывать неполные результаты. Для batch update Google различает атомарный режим и partial failures. [google.aip](https://google.aip.dev/217)

В Vaulter batch API должен явно выбирать semantics:

```json
{
  "atomic": true,
  "requests": []
}
```

или:

```json
{
  "atomic": false,
  "requests": []
}
```

Partial response:

```json
{
  "results": [
    {"index": 0, "status": 200},
    {
      "index": 1,
      "status": 409,
      "problem": {
        "code": "version_conflict"
      }
    }
  ]
}
```

Нельзя возвращать `200 OK` с неструктурированным массивом предупреждений.

## Применение к Vaulter

### Core сейчас слишком широк

В существующей структуре `vaulter-core` отвечает не только за runtime kernel, но также затрагивает schema, permissions, sharing и audit, а зависимость от adjacency-list указывает на проникновение модели дерева Drive в ядро.

AWS/Dropbox/Google не требуют помещать file tree, sharing и policies в базовый kernel. Из их практик следует противоположное: kernel задаёт общие механизмы, а домен хранения реализует ресурсы и actions.

Целевое содержимое core:

```text
packages/core
├── Contracts
│   ├── Clock
│   ├── TransactionManager
│   ├── LockManager
│   ├── EventPublisher
│   ├── PolicyEvaluator
│   └── OperationStore
├── Identity
│   ├── TenantId
│   ├── PrincipalId
│   └── ResourceReference
├── Operations
│   ├── Operation
│   ├── OperationHandler
│   ├── Idempotency
│   └── Preconditions
├── Authorization
│   ├── Action
│   ├── PolicyDecision
│   └── AuthorizationRequest
├── Events
│   ├── EventEnvelope
│   ├── Outbox
│   └── ChangeCursor
├── Problems
│   ├── Problem
│   └── ProblemCode
└── Modules
    ├── Module
    └── ModuleRegistry
```

Из core следует вынести:

- Node tree.
- Adjacency list.
- Sharing.
- Drive ACL.
- File paths.
- Blob metadata.
- Document presentation.
- Collaboration details.
- HTTP controllers конкретных доменов.
- Migration ownership верхних пакетов.

### Resource SPI

Core должен позволять пакетам регистрировать типы ресурсов:

```php
interface ResourceType
{
    public function key(): string;

    public function actions(): iterable;

    public function policyEvaluators(): iterable;

    public function eventSchemas(): iterable;

    public function problemCodes(): iterable;
}
```

Примеры:

```text
drive.node
drive.share
media.blob
documents.document
documents.revision
webhooks.subscription
collaboration.session
```

Это позволит коммерческому collaboration package зарегистрировать собственные ресурсы без изменения core.

### Operation SPI

Operation catalog следует сделать основным public extension point:

```php
interface OperationDefinition
{
    public function key(): string;

    public function inputType(): string;

    public function resultType(): string;

    public function authorizationAction(): Action;

    public function idempotency(): IdempotencyRequirement;

    public function executionMode(): ExecutionMode;

    public function retryPolicy(): RetryPolicy;

    public function handler(): string;
}
```

```php
enum ExecutionMode
{
    case Synchronous;
    case Asynchronous;
    case Adaptive;
}
```

`Adaptive` позволяет небольшую операцию выполнить сразу, а большую превратить в `Operation`, сохранив одинаковый semantic result.

Примеры operation keys:

```text
drive.nodes.create
drive.nodes.move
drive.nodes.copy
drive.nodes.delete
media.uploads.create
media.uploads.complete
documents.revisions.commit
documents.exports.create
collaboration.sessions.issue
```

### Change journal

Для Dropbox-подобной синхронизации Vaulter нужен append-only operational journal:

```text
vaulter_change_log
- tenant_id
- drive_id
- sequence
- event_id
- resource_type
- resource_id
- resource_version
- change_kind
- removed
- occurred_at
- payload_version
```

Sequence должен выделяться внутри `(tenant_id, drive_id)` либо другой явно документированной области. Не нужен единый глобальный sequence на всю installation.

Transaction:

```text
1. Проверить authorization.
2. Проверить preconditions.
3. Изменить aggregate.
4. Увеличить resource version.
5. Записать domain event.
6. Записать change record.
7. Записать outbox delivery.
8. Commit.
```

Webhooks, search indexing, audit export и collaboration читают один журнал, но имеют независимые consumer checkpoints.

### CAS и privacy

Tenant-scoped CAS — безопасный default. Глобальный dedup может раскрывать существование одинакового содержимого между tenants через timing, quotas или side channels.

Рекомендуемые режимы:

```php
enum DeduplicationScope
{
    case Tenant;
    case Drive;
    case Installation;
    case Disabled;
}
```

Default:

```text
Tenant
```

Для `Installation` должно требоваться явное включение и документированная privacy model.

Blob key нельзя строить только как публично угадываемый hash:

```text
blobs/sha256/{hash}
```

Безопаснее:

```text
tenants/{tenant}/blobs/{keyed_digest}
```

где физическая адресация отделена от публичного content hash.

### Direct URLs

S3 presigned URL передаёт временные полномочия пользователя, который его подписал, и действует ограниченный период. Такой URL фактически является bearer capability. [docs.aws.amazon](https://docs.aws.amazon.com/boto3/latest/guide/s3-presigned-urls.html)

Vaulter direct URL должен включать или логически связывать:

- Tenant.
- Resource ID.
- Revision/blob ID.
- Разрешённое действие.
- Expiration.
- Content disposition.
- Expected filename.
- Optional IP/audience restriction.
- Share revocation epoch.
- Signing key ID.

API:

```php
interface DownloadGrantIssuer
{
    public function issue(
        DownloadGrantRequest $request,
    ): DownloadGrant;
}
```

Не следует возвращать raw storage URL, если для revoke требуется немедленное прекращение доступа. В строгом режиме лучше выдавать Vaulter broker URL, который повторно проверяет grant и затем redirect/streams content.

### Document type SPI

Текущий `DocumentType` связывает content semantics с `ContentCapabilities` и `ViewerDescriptor` из Drive. Это осложняет backend-only document type и добавление других UI ecosystems.

Правильное разделение:

```php
interface DocumentType
{
    public function key(): DocumentTypeKey;

    public function schema(): DocumentSchema;

    public function codec(): DocumentCodec;

    public function defaultContent(): DocumentContent;
}
```

```php
interface DocumentCapabilityProvider
{
    public function capabilities(
        DocumentTypeKey $type,
    ): DocumentCapabilities;
}
```

```php
interface EditorProvider
{
    public function supports(
        DocumentTypeKey $type,
        ClientPlatform $platform,
    ): bool;

    public function descriptor(
        DocumentTypeKey $type,
        ClientPlatform $platform,
    ): EditorDescriptor;
}
```

```php
interface DocumentImporter
{
    public function supports(
        MediaType $source,
        DocumentTypeKey $target,
    ): bool;

    public function import(
        ImportRequest $request,
    ): OperationReference;
}
```

```php
interface DocumentExporter
{
    public function supports(
        DocumentTypeKey $source,
        MediaType $target,
    ): bool;

    public function export(
        ExportRequest $request,
    ): OperationReference;
}
```

Так XLSX importer, Univer editor и PDF exporter можно распространять тремя независимыми пакетами.

### Collaboration protocols

Collaboration не должен быть привязан в public SPI к Hocuspocus/Yjs. Требуется protocol registry:

```php
interface CollaborationProtocol
{
    public function key(): string;

    public function supports(
        DocumentTypeKey $type,
    ): bool;

    public function issueSession(
        CollaborationSessionRequest $request,
    ): CollaborationSession;
}
```

Протоколы:

```text
yjs
univer-ot
wopi
onlyoffice
none
```

Public Documents определяет session contract, а private collaboration repository реализует протоколы.

## Антипаттерны Vaulter

| Текущее направление | Почему недостаточно | Целевая модель |
|---|---|---|
| Проверка доступа через общий driver | Не выражает явный deny, conditions и composition | AWS-подобный policy evaluation |
| Webhook как основная доставка события | Потери, дубли и перестановка требуют recovery | Dropbox-подобный change cursor |
| Path участвует в идентификации | Rename/move ломают ссылки | Stable node ID и namespace scope |
| Boolean capabilities | Не выражают лимиты и варианты | Typed capability descriptors |
| Один collaboration driver | Привязка к одному realtime stack | Protocol registry |
| Document type знает viewer | Content domain связан с UI | Раздельные content/editor SPI |
| Синхронные большие операции | Timeout и неясный recovery | Google-style operation resource |
| Update DTO без mask | Старые клиенты могут затереть новые поля | Update mask |
| Offset pagination | Нестабильна при изменении дерева | Opaque cursor/page token |
| Blob hash как универсальная версия | Смешивает integrity и concurrency | Digest, ETag и Revision отдельно |
| Простое событие без aggregate version | Нельзя обработать перестановку | Versioned event envelope |
| CAS без configurable scope | Privacy и side-channel риски | Tenant-scoped default |
| Direct URL без grant abstraction | Трудно отозвать и аудитировать | Typed download grant |
| Idempotency только как dedup | Не гарантирует тот же response | Persisted operation result |

## Конкретные сценарии

### Перемещение файла

Плохой контракт:

```http
POST /nodes/123/move
{
  "parent_id": "456"
}
```

Улучшенный:

```http
POST /v1/tenants/ten_1/drives/drv_1/nodes/nod_123:move
Idempotency-Key: 4bfc...
If-Match: "rv_42"

{
  "destination_parent": "tenants/ten_1/drives/drv_1/nodes/nod_456",
  "conflict_strategy": "fail"
}
```

Сервер:

1. Проверяет scope.
2. Проверяет `drive.nodes.move`.
3. Проверяет ETag.
4. Проверяет cycle.
5. Проверяет имя в destination.
6. Выполняет mutation.
7. Записывает resource version 43.
8. Записывает change record.
9. Записывает outbox event.
10. Сохраняет idempotent response.

### Загрузка XLSX

```http
POST /v1/uploads
Idempotency-Key: ...

{
  "parent": "tenants/ten_1/drives/drv_1/nodes/nod_parent",
  "filename": "budget.xlsx",
  "media_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "size": 12839201,
  "checksum": {
    "algorithm": "sha256",
    "value": "..."
  },
  "purpose": "document_import"
}
```

После upload:

```http
POST /v1/uploads/upl_1:complete
```

Сервер возвращает:

```json
{
  "operation": "operations/op_scan_import_1"
}
```

Operation выполняет:

1. Проверку размера.
2. Проверку checksum.
3. Malware scan.
4. OOXML structural validation.
5. Zip bomb limits.
6. External-link policy.
7. Formula/macro policy.
8. Создание immutable source blob.
9. Запуск importer.
10. Создание spreadsheet document.
11. Публикацию change/event.
12. Атомарную фиксацию результата.

### Синхронизация клиента

Первичный запрос:

```http
GET /v1/drives/drv_1/changes?page_size=500
```

Ответ:

```json
{
  "changes": [],
  "next_cursor": "cur_opaque",
  "has_more": false
}
```

Webhook:

```json
{
  "drive": "drives/drv_1",
  "latest_cursor": "cur_hint"
}
```

Клиент вызывает:

```http
GET /v1/drives/drv_1/changes?cursor=cur_saved
```

Cursor обновляется только после успешного применения страницы.

### Конфликт документа

Клиент открыл revision 17 и пытается сохранить новую версию:

```http
POST /v1/documents/doc_1/revisions
Idempotency-Key: ...
If-Match: "revision-17"
```

Если уже существует revision 18:

```json
{
  "type": "https://vaulter.dev/problems/revision-conflict",
  "status": 409,
  "code": "revision_conflict",
  "base_revision": "rev_17",
  "current_revision": "rev_18",
  "merge_capability": "yjs"
}
```

Core не выполняет merge. Merge strategy предоставляет document type или collaboration protocol.

## Приоритеты внедрения

### P0 — до 1.0

- Зафиксировать resource naming и stable identity.
- Ввести единый `MutationPreconditions`.
- Добавить ETag/resource version ко всем mutable resources.
- Сделать idempotency contract строгим: fingerprint, persisted response, key-reuse conflict.
- Утвердить versioned event envelope.
- Формализовать at-least-once delivery.
- Разделить content hash, storage checksum, ETag и revision.
- Сделать tenant-scoped CAS default.
- Разделить `DocumentType`, capabilities и editor descriptors.
- Заменить Hocuspocus-oriented collaboration SPI на protocol registry.
- Вынести Drive-specific tree dependencies из core.
- Ввести policy composition с default deny и explicit deny.
- Зафиксировать upload session state machine.
- Добавить opaque pagination tokens ко всем collection endpoints.

### P1 — сразу после RC

- Реализовать change feed с durable cursors.
- Перевести webhooks в hint + cursor модель.
- Добавить long-running `Operation` resource.
- Перевести recursive copy/delete, import/export и scrub/heal на operations.
- Добавить update masks.
- Добавить atomic/partial batch semantics.
- Реализовать download grant abstraction.
- Добавить key rotation и grant revocation epoch.
- Добавить resumable multipart uploads и per-part checksums.
- Добавить consumer checkpoints для search, webhooks и collaboration.
- Ввести capability descriptors с числовыми лимитами.

### P2 — развитие платформы

- Spreadsheet importer/exporter packages.
- WOPI/OnlyOffice collaboration protocols.
- Offline synchronization SDK.
- Change-stream replication.
- Retention и legal-hold package.
- Storage migration operations.
- Cross-region replication adapter.
- Tiered storage/lifecycle policies.
- Delta/content-aware upload optimizations.
- Selective field responses.
- SDK automatic pagination.
- Declarative client support.

## Обновлённая оценка

| Критерий | Сейчас | После P0/P1 |
|---|---:|---:|
| Компактность core | 6,5/10 | 9/10 |
| Stable identity | 8/10 | 9,5/10 |
| Authorization model | 7/10 | 9/10 |
| Idempotency | 7,5/10 | 9,5/10 |
| Concurrency control | 7/10 | 9,5/10 |
| Upload architecture | 7/10 | 9/10 |
| Integrity/CAS | 8/10 | 9,5/10 |
| Change synchronization | 5/10 | 9/10 |
| Webhooks | 7/10 | 9/10 |
| Document extensibility | 8,5/10 | 9,5/10 |
| Collaboration abstraction | 6/10 | 9/10 |
| API longevity | 7/10 | 9,5/10 |
| Operational recovery | 7/10 | 9/10 |
| Готовность к 1.0 | 7/10 | 9+/10 |

Самые ценные заимствования для Vaulter:

1. **Dropbox:** stable IDs, namespaces и cursor-based change feed.
2. **Google:** ETags, update masks, opaque pagination и long-running operations.
3. **AWS:** policy evaluation, строгая idempotency, checksummed multipart uploads и честная at-least-once event model.

Если реализовать именно эти принципы, Vaulter станет не просто Laravel-аналогом файлового менеджера, а компактным расширяемым storage/document kernel, на котором можно независимо строить Drive, документы, Excel-редакторы, collaboration, импортёры, enterprise retention и внешние интеграции.