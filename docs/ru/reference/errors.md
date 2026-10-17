---
title: Коды ошибок
description: Каждый код проблемы, которым отвечает REST API Vaulter v1, — генерируется из каталога кодов.
outline: deep
---

# Коды ошибок

Каждая ошибка маршрута `/v1` — ответ [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)
`application/problem+json`:

```json
{
  "type": "https://github.com/axiomasoft/vaulter/blob/main/docs/reference/errors.md#node_name_conflict",
  "title": "Конфликт имени узла",
  "status": 409,
  "code": "node_name_conflict",
  "detail": "Узел с таким именем уже есть в папке.",
  "retryable": "never",
  "request_id": "01J…",
  "errors": null,
  "meta": {}
}
```

`code` — стабильный контракт (SemVer): ветвитесь по нему, а не по `title` или `detail`, которые
локализуются (`Content-Language`) и могут меняться. `errors` — карта полей Laravel; она заполнена
для `validation_failed` и для ошибок, привязанных к одному полю. `meta` — машиночитаемые
дополнения вроде `reason`; пути хранилища, tenant id, токены и SQL в неё не попадают. Ответ
всегда повторяет id запроса в `X-Request-Id`. Статус без кода Vaulter отвечает `http_<status>`
(например `http_503`), `title` — стандартная фраза статуса.

`retryable` говорит, можно ли повторить тот же запрос: `never` — как есть нельзя, `after_refetch` —
после повторного чтения ресурса (устаревшее предусловие), `with_backoff` — позже, с учётом
`Retry-After`, если он есть в ответе. Решает клиент; сервер за него не повторяет.
**Receipt** — политика [идемпотентных операций](/ru/concepts/idempotency): отказ `cacheable`
сохраняется под `Idempotency-Key` и воспроизводится повтору, отказ `never` (аутентификация,
видимость, валидация, предусловия, лимиты, ошибки сервера) откатывается вместе с ключом.
**Module** — модуль, объявивший код; при отключении модуля его коды пропадают.

Страница генерируется командой `php artisan vaulter:errors:reference`; `--check` завершается с
кодом 1, если она устарела. Обработка ошибок в PHP — в [руководстве по ошибкам](/ru/guides/errors).

<!-- vaulter:errors:start -->
| Code | HTTP | Module | Retryable | Receipt | Exception |
|---|---|---|---|---|---|
| [`actor_required`](#actor_required) | 401 | core | `never` | `never` | `Vaulter\Exceptions\Access\MissingActorException` |
| [`blob_unavailable`](#blob_unavailable) | 409 | media | `never` | `never` | `Vaulter\Media\Exceptions\Cas\BlobUnavailableException` |
| [`cannot_delete_head_revision`](#cannot_delete_head_revision) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\CannotDeleteHeadRevisionException` |
| [`collaboration_epoch_mismatch`](#collaboration_epoch_mismatch) | 409 | collaboration | `after_refetch` | `cacheable` | `Vaulter\Collaboration\Exceptions\CollaborationEpochMismatchException` |
| [`collaboration_seed_conflict`](#collaboration_seed_conflict) | 409 | collaboration | `after_refetch` | `cacheable` | `Vaulter\Collaboration\Exceptions\CollaborationSeedConflictException` |
| [`collaboration_unavailable`](#collaboration_unavailable) | 409 | collaboration | `never` | `never` | `Vaulter\Collaboration\Exceptions\CollaborationUnavailableException` |
| [`comment_not_found`](#comment_not_found) | 404 | documents | `never` | `never` | `Vaulter\Documents\Exceptions\CommentNotFoundException` |
| [`concurrent_update`](#concurrent_update) | 409 | core | `with_backoff` | `never` | `Vaulter\Exceptions\Database\ConcurrentUpdateException` |
| [`content_unavailable`](#content_unavailable) | 423 | drive | `with_backoff` | `never` | `Vaulter\Drive\Exceptions\Files\ContentUnavailableException` |
| [`cross_drive_operation`](#cross_drive_operation) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException` |
| [`cursor_expired`](#cursor_expired) | 410 | drive | `after_refetch` | `never` | `Vaulter\Drive\Exceptions\Changes\CursorExpiredException` |
| [`dependency_unavailable`](#dependency_unavailable) | 503 | core | `with_backoff` | `never` | — |
| [`document_content_degraded`](#document_content_degraded) | 409 | documents | `never` | `cacheable` | `Vaulter\Documents\Exceptions\DocumentContentDegradedException` |
| [`document_revision_not_found`](#document_revision_not_found) | 404 | documents | `never` | `never` | `Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException` |
| [`document_state_mismatch`](#document_state_mismatch) | 412 | documents | `after_refetch` | `cacheable` | `Vaulter\Documents\Exceptions\StaleDocumentStateException` |
| [`document_state_pending`](#document_state_pending) | 409 | documents | `with_backoff` | `never` | `Vaulter\Documents\Exceptions\DocumentStatePendingException` |
| [`document_state_too_large`](#document_state_too_large) | 413 | documents | `never` | `cacheable` | `Vaulter\Documents\Exceptions\DocumentStateTooLargeException` |
| [`document_type_unavailable`](#document_type_unavailable) | 409 | documents | `never` | `cacheable` | `Vaulter\Documents\Exceptions\DocumentTypeUnavailableException` |
| [`drive_archived`](#drive_archived) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\DriveArchivedException` |
| [`drive_not_found`](#drive_not_found) | 404 | drive | `never` | `never` | `Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException` |
| [`duplicate_registration`](#duplicate_registration) | 500 | core | `never` | `never` | — |
| [`extension_failed`](#extension_failed) | 500 | core | `never` | `never` | `Vaulter\Exceptions\Support\InvalidExtensionResult` |
| [`file_size_limit_exceeded`](#file_size_limit_exceeded) | 413 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Policy\FileSizeLimitExceededException` |
| [`folder_children_limit_exceeded`](#folder_children_limit_exceeded) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Policy\FolderChildrenLimitExceededException` |
| [`grant_not_found`](#grant_not_found) | 404 | drive | `never` | `never` | — |
| [`idempotency_key_reused`](#idempotency_key_reused) | 422 | core | `never` | `never` | `Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException` |
| [`internal_error`](#internal_error) | 500 | core | `never` | `never` | — |
| [`invalid_cursor`](#invalid_cursor) | 400 | drive | `never` | `never` | `Vaulter\Drive\Exceptions\Changes\InvalidCursorException` |
| [`invalid_destination`](#invalid_destination) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\InvalidDestinationException` |
| [`invalid_document_content`](#invalid_document_content) | 422 | documents | `never` | `cacheable` | `Vaulter\Documents\Exceptions\InvalidDocumentContentException` |
| [`invalid_drive_state`](#invalid_drive_state) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\InvalidDriveStateException` |
| [`invalid_node_name`](#invalid_node_name) | 422 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\InvalidNodeNameException` |
| [`invalid_properties`](#invalid_properties) | 422 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\InvalidPropertiesException` |
| [`invalid_webhook_signature`](#invalid_webhook_signature) | 400 | webhooks | `never` | `never` | `Vaulter\Webhooks\Exceptions\InvalidWebhookSignatureException` |
| [`method_not_allowed`](#method_not_allowed) | 405 | core | `never` | `never` | `Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException` |
| [`module_graph_invalid`](#module_graph_invalid) | 503 | core | `never` | `never` | `Vaulter\Modules\ModuleGraphException` |
| [`module_not_ready`](#module_not_ready) | 503 | core | `never` | `never` | `Vaulter\Exceptions\Modules\ModuleNotReadyException` |
| [`node_move_cycle`](#node_move_cycle) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\NodeCycleException` |
| [`node_name_conflict`](#node_name_conflict) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException` |
| [`node_not_found`](#node_not_found) | 404 | drive | `never` | `never` | `Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException` |
| [`node_not_trashed`](#node_not_trashed) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\NodeNotTrashedException` |
| [`node_sequence_exhausted`](#node_sequence_exhausted) | 409 | drive | `never` | `cacheable` | `Vaulter\Exceptions\Nodes\SequenceOverflowException` |
| [`node_sequence_mismatch`](#node_sequence_mismatch) | 412 | drive | `after_refetch` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\StaleNodeException` |
| [`node_trashed_by_ancestor`](#node_trashed_by_ancestor) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\NodeTrashedByAncestorException` |
| [`not_a_document`](#not_a_document) | 409 | documents | `never` | `cacheable` | `Vaulter\Documents\Exceptions\NotADocumentException` |
| [`not_a_file`](#not_a_file) | 422 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\NotAFileException` |
| [`operation_in_progress`](#operation_in_progress) | 409 | core | `with_backoff` | `never` | `Vaulter\Exceptions\Idempotency\OperationInProgressException` |
| [`payload_too_large`](#payload_too_large) | 413 | core | `never` | `cacheable` | `Illuminate\Http\Exceptions\PostTooLargeException` |
| [`permission_denied`](#permission_denied) | 403 | core | `never` | `never` | `Illuminate\Auth\Access\AuthorizationException` |
| [`precondition_required`](#precondition_required) | 428 | core | `after_refetch` | `never` | `Vaulter\Exceptions\Http\PreconditionRequiredException` |
| [`profile_restriction`](#profile_restriction) | 403 | drive | `never` | `never` | `Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException` |
| [`quota_exceeded`](#quota_exceeded) | 413 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\QuotaExceededException` |
| [`rate_limited`](#rate_limited) | 429 | core | `with_backoff` | `never` | `Illuminate\Http\Exceptions\ThrottleRequestsException` |
| [`registry_frozen`](#registry_frozen) | 500 | core | `never` | `never` | `Vaulter\Exceptions\Registry\RegistryFrozenException` |
| [`restore_destination_changed`](#restore_destination_changed) | 409 | drive | `after_refetch` | `never` | `Vaulter\Drive\Exceptions\Nodes\RestoreDestinationChangedException` |
| [`restore_destination_required`](#restore_destination_required) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\RestoreDestinationRequiredException` |
| [`revision_not_found`](#revision_not_found) | 404 | drive | `never` | `never` | — |
| [`revision_pinned`](#revision_pinned) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\RevisionPinnedException` |
| [`route_not_found`](#route_not_found) | 404 | core | `never` | `never` | `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` |
| [`share_link_ttl_exceeded`](#share_link_ttl_exceeded) | 422 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Sharing\ShareLinkTtlExceededException` |
| [`share_not_found`](#share_not_found) | 404 | drive | `never` | `never` | `Vaulter\Drive\Exceptions\Sharing\ShareNotFoundException` |
| [`share_password_required`](#share_password_required) | 401 | drive | `never` | `never` | `Vaulter\Drive\Exceptions\Sharing\SharePasswordRequiredException` |
| [`share_role_not_allowed`](#share_role_not_allowed) | 422 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Sharing\ShareRoleNotAllowedException` |
| [`tree_depth_limit_exceeded`](#tree_depth_limit_exceeded) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Policy\TreeDepthLimitExceededException` |
| [`tree_traversal_incomplete`](#tree_traversal_incomplete) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\TreeTraversalIncompleteException` |
| [`unknown_document_type`](#unknown_document_type) | 422 | documents | `never` | `cacheable` | `Vaulter\Documents\Exceptions\UnknownDocumentTypeException` |
| [`unknown_export_format`](#unknown_export_format) | 422 | documents | `never` | `cacheable` | `Vaulter\Documents\Exceptions\UnknownExportFormatException` |
| [`unknown_profile`](#unknown_profile) | 422 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Profiles\UnknownProfileException` |
| [`unknown_role`](#unknown_role) | 422 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Access\UnknownRoleException` |
| [`unsupported_node_kind`](#unsupported_node_kind) | 409 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Nodes\UnsupportedNodeKindException` |
| [`upload_conflict`](#upload_conflict) | 409 | drive | `after_refetch` | `never` | `Vaulter\Drive\Exceptions\Uploads\UploadConflictException` |
| [`upload_not_found`](#upload_not_found) | 404 | drive | `never` | `never` | — |
| [`upload_offset_mismatch`](#upload_offset_mismatch) | 409 | drive | `after_refetch` | `never` | `Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException` |
| [`upload_rejected`](#upload_rejected) | 422 | drive | `never` | `cacheable` | `Vaulter\Drive\Exceptions\Files\UploadRejectedException` |
| [`validation_failed`](#validation_failed) | 422 | core | `never` | `never` | `Illuminate\Validation\ValidationException` |
| [`webhook_delivery_not_found`](#webhook_delivery_not_found) | 404 | webhooks | `never` | `never` | `Vaulter\Webhooks\Exceptions\WebhookDeliveryNotFoundException` |
| [`webhook_delivery_not_redeliverable`](#webhook_delivery_not_redeliverable) | 409 | webhooks | `never` | `cacheable` | `Vaulter\Webhooks\Exceptions\WebhookDeliveryNotRedeliverableException` |
| [`webhook_endpoint_not_found`](#webhook_endpoint_not_found) | 404 | webhooks | `never` | `never` | `Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException` |
| [`write_outside_operation`](#write_outside_operation) | 500 | core | `never` | `never` | `Vaulter\Exceptions\Database\WriteOutsideOperationException` |

### `actor_required`

**401** · core · retryable `never` · receipt `never`

**Требуется аутентификация** — Запрос должен выполняться от аутентифицированного субъекта.

### `blob_unavailable`

**409** · media · retryable `never` · receipt `never`

**Содержимое недоступно** — Сохранённое содержимое файла больше недоступно.

### `cannot_delete_head_revision`

**409** · drive · retryable `never` · receipt `cacheable`

**Нельзя удалить текущую ревизию** — Текущую ревизию удалить нельзя.

### `collaboration_epoch_mismatch`

**409** · collaboration · retryable `after_refetch` · receipt `cacheable`

**Эпоха совместной работы устарела** — Сессия совместной работы устарела.

### `collaboration_seed_conflict`

**409** · collaboration · retryable `after_refetch` · receipt `cacheable`

**Конфликт начального состояния** — Начальное состояние документа задано другой сессией.

### `collaboration_unavailable`

**409** · collaboration · retryable `never` · receipt `never`

**Совместная работа недоступна** — Совместная работа для этого документа недоступна.

### `comment_not_found`

**404** · documents · retryable `never` · receipt `never`

**Комментарий не найден** — Комментарий не существует или относится к другому узлу.

### `concurrent_update`

**409** · core · retryable `with_backoff` · receipt `never`

**Конкурентное изменение** — Запрос конфликтует с параллельным изменением; повторите его.

### `content_unavailable`

**423** · drive · retryable `with_backoff` · receipt `never`

**Содержимое недоступно** — Содержимое недоступно для скачивания.

### `cross_drive_operation`

**409** · drive · retryable `never` · receipt `cacheable`

**Операция между дисками** — Операция не может пересекать диски.

### `cursor_expired`

**410** · drive · retryable `after_refetch` · receipt `never`

**Курсор устарел** — Курсор старше сохранённой ленты изменений; начните с нового списка.

### `dependency_unavailable`

**503** · core · retryable `with_backoff` · receipt `never`

**Зависимость недоступна** — Сервис, от которого зависит запрос, недоступен; повторите позже.

### `document_content_degraded`

**409** · documents · retryable `never` · receipt `cacheable`

**Содержимое документа деградировало** — Сохранение удалило бы части, которые тип документа больше не принимает.

### `document_revision_not_found`

**404** · documents · retryable `never` · receipt `never`

**Ревизия документа не найдена** — Ревизия не существует или относится к другому документу.

### `document_state_mismatch`

**412** · documents · retryable `after_refetch` · receipt `cacheable`

**Содержимое документа изменилось** — Содержимое изменилось с момента чтения; перечитайте его и повторите.

### `document_state_pending`

**409** · documents · retryable `with_backoff` · receipt `never`

**Содержимое документа ещё не сохранено** — Сохранённые правки совместной работы ещё не перенесены в содержимое; повторите позже.

### `document_state_too_large`

**413** · documents · retryable `never` · receipt `cacheable`

**Состояние документа слишком велико** — Состояние совместной работы превышает допустимый размер.

### `document_type_unavailable`

**409** · documents · retryable `never` · receipt `cacheable`

**Тип документа недоступен** — Тип документа не установлен; документ доступен только для чтения.

### `drive_archived`

**409** · drive · retryable `never` · receipt `cacheable`

**Диск в архиве** — Диск в архиве доступен только для чтения.

### `drive_not_found`

**404** · drive · retryable `never` · receipt `never`

**Диск не найден** — Диск не существует или недоступен вам.

### `duplicate_registration`

**500** · core · retryable `never` · receipt `never`

**Повторная регистрация** — Один и тот же ключ зарегистрирован дважды.

### `extension_failed`

**500** · core · retryable `never` · receipt `never`

**Сбой расширения** — Расширение установленного модуля нарушило свой контракт; операция не выполнена или откачена.

### `file_size_limit_exceeded`

**413** · drive · retryable `never` · receipt `cacheable`

**Файл слишком большой** — Файл превышает лимит в :limit байт.

### `folder_children_limit_exceeded`

**409** · drive · retryable `never` · receipt `cacheable`

**Папка заполнена** — В папке уже максимально допустимые :limit узлов.

### `grant_not_found`

**404** · drive · retryable `never` · receipt `never`

**Доступ не найден** — Выдача доступа не существует или недоступна вам.

### `idempotency_key_reused`

**422** · core · retryable `never` · receipt `never`

**Ключ идемпотентности уже использован** — Ключ идемпотентности уже использован для другого запроса; отправьте новый ключ.

### `internal_error`

**500** · core · retryable `never` · receipt `never`

**Внутренняя ошибка сервера**

### `invalid_cursor`

**400** · drive · retryable `never` · receipt `never`

**Недопустимый курсор** — Курсор повреждён или не относится к этой ленте.

### `invalid_destination`

**409** · drive · retryable `never` · receipt `cacheable`

**Недопустимое назначение** — Назначение не является доступной папкой этого диска.

### `invalid_document_content`

**422** · documents · retryable `never` · receipt `cacheable`

**Недопустимое содержимое документа** — Содержимое не соответствует типу документа.

### `invalid_drive_state`

**409** · drive · retryable `never` · receipt `cacheable`

**Недопустимое состояние диска** — Состояние диска не допускает этого перехода.

### `invalid_node_name`

**422** · drive · retryable `never` · receipt `cacheable`

**Недопустимое имя узла** — Имя нарушает правила именования.

### `invalid_properties`

**422** · drive · retryable `never` · receipt `cacheable`

**Недопустимые свойства** — Изменение свойств нарушает контракт.

### `invalid_webhook_signature`

**400** · webhooks · retryable `never` · receipt `never`

**Неверная подпись вебхука** — Подпись вебхука не прошла проверку.

### `method_not_allowed`

**405** · core · retryable `never` · receipt `never`

**Метод не разрешён** — Точка API не поддерживает этот HTTP-метод; см. заголовок Allow.

### `module_graph_invalid`

**503** · core · retryable `never` · receipt `never`

**Граф модулей некорректен** — Установленные модули не образуют корректный граф; ни один модуль не активен.

### `module_not_ready`

**503** · core · retryable `never` · receipt `never`

**Модуль не готов** — Модуль, выполняющий операцию, неактивен или его схема ещё не установлена.

### `node_move_cycle`

**409** · drive · retryable `never` · receipt `cacheable`

**Перемещение создаст цикл** — Папку нельзя переместить в себя или в потомка.

### `node_name_conflict`

**409** · drive · retryable `never` · receipt `cacheable`

**Конфликт имени узла** — Узел с таким именем уже есть в папке.

### `node_not_found`

**404** · drive · retryable `never` · receipt `never`

**Узел не найден** — Узел не существует или недоступен вам.

### `node_not_trashed`

**409** · drive · retryable `never` · receipt `cacheable`

**Узел не в корзине** — Восстановить или удалить навсегда можно только узел из корзины.

### `node_sequence_exhausted`

**409** · drive · retryable `never` · receipt `cacheable`

**Счётчик изменений исчерпан** — Счётчик изменений узла исчерпан.

### `node_sequence_mismatch`

**412** · drive · retryable `after_refetch` · receipt `cacheable`

**Узел изменился** — Узел изменился с момента чтения; перечитайте его и повторите.

### `node_trashed_by_ancestor`

**409** · drive · retryable `never` · receipt `cacheable`

**Узел удалён вместе с папкой** — Восстановите папку, удалённую вместе с этим узлом.

### `not_a_document`

**409** · documents · retryable `never` · receipt `cacheable`

**Это не документ** — Операции нужен узел-документ.

### `not_a_file`

**422** · drive · retryable `never` · receipt `cacheable`

**Это не файл** — Операции нужен узел-файл.

### `operation_in_progress`

**409** · core · retryable `with_backoff` · receipt `never`

**Операция выполняется** — Запрос с тем же ключом идемпотентности ещё выполняется; повторите позже.

### `payload_too_large`

**413** · core · retryable `never` · receipt `cacheable`

**Слишком большое тело запроса** — Тело запроса больше допустимого размера.

### `permission_denied`

**403** · core · retryable `never` · receipt `never`

**Доступ запрещён** — У вас нет прав на это действие.

### `precondition_required`

**428** · core · retryable `after_refetch` · receipt `never`

**Требуется предусловие** — Для этого запроса нужен заголовок :header.

### `profile_restriction`

**403** · drive · retryable `never` · receipt `never`

**Ограничено профилем диска** — Профиль диска запрещает эту операцию.

### `quota_exceeded`

**413** · drive · retryable `never` · receipt `cacheable`

**Квота превышена** — Загрузка превысит квоту диска.

### `rate_limited`

**429** · core · retryable `with_backoff` · receipt `never`

**Слишком много запросов** — Слишком много запросов; повторите через интервал из заголовка Retry-After.

### `registry_frozen`

**500** · core · retryable `never` · receipt `never`

**Реестр заморожен** — Регистрация выполнена после загрузки приложения.

### `restore_destination_changed`

**409** · drive · retryable `after_refetch` · receipt `never`

**Папка восстановления изменилась** — Папка назначения изменилась во время восстановления; повторите.

### `restore_destination_required`

**409** · drive · retryable `never` · receipt `cacheable`

**Нужна папка для восстановления** — Исходная папка недоступна; выберите папку назначения.

### `revision_not_found`

**404** · drive · retryable `never` · receipt `never`

**Ревизия не найдена** — Ревизия не существует или недоступна вам.

### `revision_pinned`

**409** · drive · retryable `never` · receipt `cacheable`

**Ревизия закреплена** — Открепите ревизию перед удалением.

### `route_not_found`

**404** · core · retryable `never` · receipt `never`

**Маршрут не найден** — Такой точки API нет.

### `share_link_ttl_exceeded`

**422** · drive · retryable `never` · receipt `cacheable`

**Срок ссылки превышен** — Ссылка может действовать не более :limit_days дн.

### `share_not_found`

**404** · drive · retryable `never` · receipt `never`

**Ссылка не найдена** — Ссылка не существует, истекла или недоступна вам.

### `share_password_required`

**401** · drive · retryable `never` · receipt `never`

**Нужен пароль ссылки** — Ссылка защищена паролем. Передайте пароль в заголовке X-Share-Password.

### `share_role_not_allowed`

**422** · drive · retryable `never` · receipt `cacheable`

**Роль недопустима для ссылки** — Ссылка не может нести эту роль для этой аудитории.

### `tree_depth_limit_exceeded`

**409** · drive · retryable `never` · receipt `cacheable`

**Превышена глубина дерева** — Дерево папок не может быть глубже :limit уровней.

### `tree_traversal_incomplete`

**409** · drive · retryable `never` · receipt `cacheable`

**Дерево слишком глубокое** — Дерево папок глубже, чем допускает ограниченный обход; выберите папку выше по дереву.

### `unknown_document_type`

**422** · documents · retryable `never` · receipt `cacheable`

**Неизвестный тип документа** — Такой тип документа не зарегистрирован.

### `unknown_export_format`

**422** · documents · retryable `never` · receipt `cacheable`

**Неизвестный формат экспорта** — Нет экспортера для запрошенного формата; допустимые — в meta.allowed.

### `unknown_profile`

**422** · drive · retryable `never` · receipt `cacheable`

**Неизвестный профиль** — Такой профиль диска не зарегистрирован.

### `unknown_role`

**422** · drive · retryable `never` · receipt `cacheable`

**Неизвестная роль** — Такая роль не зарегистрирована.

### `unsupported_node_kind`

**409** · drive · retryable `never` · receipt `cacheable`

**Неподдерживаемый вид узла** — Для этого вида узла операция не поддерживается.

### `upload_conflict`

**409** · drive · retryable `after_refetch` · receipt `never`

**Конфликт загрузки** — Сессию загрузки изменяет другой запрос.

### `upload_not_found`

**404** · drive · retryable `never` · receipt `never`

**Загрузка не найдена** — Сессия загрузки не существует или недоступна вам.

### `upload_offset_mismatch`

**409** · drive · retryable `after_refetch` · receipt `never`

**Смещение загрузки не совпало** — Upload-Offset отличается от смещения на сервере; продолжите с него.

### `upload_rejected`

**422** · drive · retryable `never` · receipt `cacheable`

**Загрузка отклонена** — Загруженное содержимое отклонено.

### `validation_failed`

**422** · core · retryable `never` · receipt `never`

**Ошибка валидации** — Данные запроса некорректны; подробности в поле errors.

### `webhook_delivery_not_found`

**404** · webhooks · retryable `never` · receipt `never`

**Доставка не найдена** — У этого endpoint нет такой доставки.

### `webhook_delivery_not_redeliverable`

**409** · webhooks · retryable `never` · receipt `cacheable`

**Доставку нельзя повторить** — Доставка ещё повторяется или её endpoint отключён.

### `webhook_endpoint_not_found`

**404** · webhooks · retryable `never` · receipt `never`

**Webhook endpoint не найден** — Такого webhook endpoint не существует.

### `write_outside_operation`

**500** · core · retryable `never` · receipt `never`

**Запись вне операции** — Запись выполнена в обход операций Vaulter.
<!-- vaulter:errors:end -->
