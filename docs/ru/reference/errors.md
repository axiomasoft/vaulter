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

Страница генерируется командой `php artisan vaulter:errors:reference`; `--check` завершается с
кодом 1, если она устарела. Обработка ошибок в PHP — в [руководстве по ошибкам](/ru/guide/errors).

<!-- vaulter:errors:start -->
| Code | HTTP | Package | Exception |
|---|---|---|---|
| [`actor_required`](#actor_required) | 401 | core | `Vaulter\Exceptions\Access\MissingActorException` |
| [`cannot_delete_head_revision`](#cannot_delete_head_revision) | 409 | core | `Vaulter\Exceptions\Drive\CannotDeleteHeadRevisionException` |
| [`collaboration_epoch_mismatch`](#collaboration_epoch_mismatch) | 409 | documents | `Vaulter\Documents\Exceptions\CollaborationEpochMismatchException` |
| [`collaboration_seed_conflict`](#collaboration_seed_conflict) | 409 | documents | `Vaulter\Documents\Exceptions\CollaborationSeedConflictException` |
| [`collaboration_unavailable`](#collaboration_unavailable) | 409 | documents | `Vaulter\Documents\Exceptions\CollaborationUnavailableException` |
| [`comment_not_found`](#comment_not_found) | 404 | documents | `Vaulter\Documents\Exceptions\CommentNotFoundException` |
| [`concurrent_update`](#concurrent_update) | 409 | core | `Vaulter\Exceptions\Database\ConcurrentUpdateException` |
| [`content_unavailable`](#content_unavailable) | 423 | core | `Vaulter\Exceptions\Files\ContentUnavailableException` |
| [`cross_drive_operation`](#cross_drive_operation) | 409 | drive | `Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException` |
| [`cursor_expired`](#cursor_expired) | 410 | core | `Vaulter\Exceptions\Changes\CursorExpiredException` |
| [`document_content_degraded`](#document_content_degraded) | 409 | documents | `Vaulter\Documents\Exceptions\DocumentContentDegradedException` |
| [`document_revision_not_found`](#document_revision_not_found) | 404 | documents | `Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException` |
| [`document_state_mismatch`](#document_state_mismatch) | 412 | documents | `Vaulter\Documents\Exceptions\StaleDocumentStateException` |
| [`document_state_too_large`](#document_state_too_large) | 413 | documents | `Vaulter\Documents\Exceptions\DocumentStateTooLargeException` |
| [`document_type_unavailable`](#document_type_unavailable) | 409 | documents | `Vaulter\Documents\Exceptions\DocumentTypeUnavailableException` |
| [`drive_archived`](#drive_archived) | 409 | core | `Vaulter\Exceptions\Drive\DriveArchivedException` |
| [`drive_not_found`](#drive_not_found) | 404 | core | `Vaulter\Exceptions\Nodes\StorageNotFoundException` |
| [`duplicate_registration`](#duplicate_registration) | 500 | core | `Vaulter\Exceptions\Registry\DuplicateNodeContentLifecycleException` |
| [`file_size_limit_exceeded`](#file_size_limit_exceeded) | 413 | core | `Vaulter\Exceptions\Policy\FileSizeLimitExceededException` |
| [`folder_children_limit_exceeded`](#folder_children_limit_exceeded) | 409 | core | `Vaulter\Exceptions\Policy\FolderChildrenLimitExceededException` |
| [`grant_not_found`](#grant_not_found) | 404 | core | — |
| [`internal_error`](#internal_error) | 500 | core | — |
| [`invalid_cursor`](#invalid_cursor) | 400 | core | `Vaulter\Exceptions\Changes\InvalidCursorException` |
| [`invalid_destination`](#invalid_destination) | 409 | core | `Vaulter\Exceptions\Nodes\InvalidDestinationException` |
| [`invalid_document_content`](#invalid_document_content) | 422 | documents | `Vaulter\Documents\Exceptions\InvalidDocumentContentException` |
| [`invalid_drive_state`](#invalid_drive_state) | 409 | core | `Vaulter\Exceptions\Drive\InvalidDriveStateException` |
| [`invalid_node_name`](#invalid_node_name) | 422 | core | `Vaulter\Exceptions\Nodes\InvalidNodeNameException` |
| [`invalid_properties`](#invalid_properties) | 422 | core | `Vaulter\Exceptions\Nodes\InvalidPropertiesException` |
| [`invalid_webhook_signature`](#invalid_webhook_signature) | 400 | core | `Vaulter\Exceptions\Webhooks\InvalidWebhookSignatureException` |
| [`method_not_allowed`](#method_not_allowed) | 405 | core | `Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException` |
| [`node_move_cycle`](#node_move_cycle) | 409 | drive | `Vaulter\Drive\Exceptions\Nodes\NodeCycleException` |
| [`node_name_conflict`](#node_name_conflict) | 409 | core | `Vaulter\Exceptions\Nodes\NodeNameConflictException` |
| [`node_not_found`](#node_not_found) | 404 | core | `Vaulter\Exceptions\Nodes\NodeNotFoundException` |
| [`node_not_trashed`](#node_not_trashed) | 409 | core | `Vaulter\Exceptions\Nodes\NodeNotTrashedException` |
| [`node_sequence_exhausted`](#node_sequence_exhausted) | 409 | core | `Vaulter\Exceptions\Nodes\SequenceOverflowException` |
| [`node_sequence_mismatch`](#node_sequence_mismatch) | 412 | core | `Vaulter\Exceptions\Nodes\StaleNodeException` |
| [`node_trashed_by_ancestor`](#node_trashed_by_ancestor) | 409 | core | `Vaulter\Exceptions\Nodes\NodeTrashedByAncestorException` |
| [`not_a_document`](#not_a_document) | 409 | documents | `Vaulter\Documents\Exceptions\NotADocumentException` |
| [`not_a_file`](#not_a_file) | 422 | core | `Vaulter\Exceptions\Nodes\NotAFileException` |
| [`payload_too_large`](#payload_too_large) | 413 | core | `Illuminate\Http\Exceptions\PostTooLargeException` |
| [`permission_denied`](#permission_denied) | 403 | core | `Vaulter\Exceptions\Access\PermissionDeniedException` |
| [`precondition_required`](#precondition_required) | 428 | core | `Vaulter\Exceptions\Http\PreconditionRequiredException` |
| [`profile_restriction`](#profile_restriction) | 403 | core | `Vaulter\Exceptions\Profiles\ProfileRestrictionException` |
| [`quota_exceeded`](#quota_exceeded) | 413 | core | `Vaulter\Exceptions\Drive\QuotaExceededException` |
| [`rate_limited`](#rate_limited) | 429 | core | `Illuminate\Http\Exceptions\ThrottleRequestsException` |
| [`registry_frozen`](#registry_frozen) | 500 | core | `Vaulter\Exceptions\Registry\RegistryFrozenException` |
| [`restore_destination_changed`](#restore_destination_changed) | 409 | core | `Vaulter\Exceptions\Nodes\RestoreDestinationChangedException` |
| [`restore_destination_required`](#restore_destination_required) | 409 | core | `Vaulter\Exceptions\Nodes\RestoreDestinationRequiredException` |
| [`revision_not_found`](#revision_not_found) | 404 | core | — |
| [`revision_pinned`](#revision_pinned) | 409 | core | `Vaulter\Exceptions\Drive\RevisionPinnedException` |
| [`route_not_found`](#route_not_found) | 404 | core | `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` |
| [`share_link_ttl_exceeded`](#share_link_ttl_exceeded) | 422 | core | `Vaulter\Exceptions\Sharing\ShareLinkTtlExceededException` |
| [`share_not_found`](#share_not_found) | 404 | core | — |
| [`share_role_not_allowed`](#share_role_not_allowed) | 422 | core | `Vaulter\Exceptions\Sharing\ShareRoleNotAllowedException` |
| [`tree_depth_limit_exceeded`](#tree_depth_limit_exceeded) | 409 | core | `Vaulter\Exceptions\Policy\TreeDepthLimitExceededException` |
| [`tree_traversal_incomplete`](#tree_traversal_incomplete) | 409 | core | `Vaulter\Exceptions\Nodes\TreeTraversalIncompleteException` |
| [`unknown_document_type`](#unknown_document_type) | 422 | documents | `Vaulter\Documents\Exceptions\UnknownDocumentTypeException` |
| [`unknown_export_format`](#unknown_export_format) | 422 | documents | `Vaulter\Documents\Exceptions\UnknownExportFormatException` |
| [`unknown_profile`](#unknown_profile) | 422 | core | `Vaulter\Exceptions\Profiles\UnknownProfileException` |
| [`unknown_role`](#unknown_role) | 422 | core | `Vaulter\Exceptions\Access\UnknownRoleException` |
| [`unsupported_node_kind`](#unsupported_node_kind) | 409 | core | `Vaulter\Exceptions\Nodes\UnsupportedNodeKindException` |
| [`upgrade_preflight_failed`](#upgrade_preflight_failed) | 409 | core | `Vaulter\Exceptions\Schema\UpgradePreflightException` |
| [`upload_conflict`](#upload_conflict) | 409 | drive | `Vaulter\Drive\Exceptions\Uploads\UploadConflictException` |
| [`upload_not_found`](#upload_not_found) | 404 | drive | — |
| [`upload_offset_mismatch`](#upload_offset_mismatch) | 409 | drive | `Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException` |
| [`upload_rejected`](#upload_rejected) | 422 | drive | `Vaulter\Drive\Exceptions\Files\UploadRejectedException` |
| [`validation_failed`](#validation_failed) | 422 | core | `Illuminate\Validation\ValidationException` |
| [`webhook_delivery_not_redeliverable`](#webhook_delivery_not_redeliverable) | 409 | core | `Vaulter\Exceptions\Webhooks\WebhookDeliveryNotRedeliverableException` |
| [`write_outside_operation`](#write_outside_operation) | 500 | core | `Vaulter\Exceptions\Database\WriteOutsideOperationException` |

### `actor_required`

**401** · core

**Требуется аутентификация** — Запрос должен выполняться от аутентифицированного субъекта.

### `cannot_delete_head_revision`

**409** · core

**Нельзя удалить текущую ревизию** — Текущую ревизию удалить нельзя.

### `collaboration_epoch_mismatch`

**409** · documents

**Эпоха совместной работы устарела** — Сессия совместной работы устарела.

### `collaboration_seed_conflict`

**409** · documents

**Конфликт начального состояния** — Начальное состояние документа задано другой сессией.

### `collaboration_unavailable`

**409** · documents

**Совместная работа недоступна** — Совместная работа для этого документа недоступна.

### `comment_not_found`

**404** · documents

**Комментарий не найден** — Комментарий не существует или относится к другому узлу.

### `concurrent_update`

**409** · core

**Конкурентное изменение** — Запрос конфликтует с параллельным изменением; повторите его.

### `content_unavailable`

**423** · core

**Содержимое недоступно** — Содержимое недоступно для скачивания.

### `cross_drive_operation`

**409** · drive

**Операция между дисками** — Операция не может пересекать диски.

### `cursor_expired`

**410** · core

**Курсор устарел** — Курсор старше сохранённой ленты изменений; начните с нового списка.

### `document_content_degraded`

**409** · documents

**Содержимое документа деградировало** — Сохранение удалило бы части, которые тип документа больше не принимает.

### `document_revision_not_found`

**404** · documents

**Ревизия документа не найдена** — Ревизия не существует или относится к другому документу.

### `document_state_mismatch`

**412** · documents

**Содержимое документа изменилось** — Содержимое изменилось с момента чтения; перечитайте его и повторите.

### `document_state_too_large`

**413** · documents

**Состояние документа слишком велико** — Состояние совместной работы превышает допустимый размер.

### `document_type_unavailable`

**409** · documents

**Тип документа недоступен** — Тип документа не установлен; документ доступен только для чтения.

### `drive_archived`

**409** · core

**Диск в архиве** — Диск в архиве доступен только для чтения.

### `drive_not_found`

**404** · core

**Диск не найден** — Диск не существует или недоступен вам.

### `duplicate_registration`

**500** · core

**Повторная регистрация** — Один и тот же ключ зарегистрирован дважды.

### `file_size_limit_exceeded`

**413** · core

**Файл слишком большой** — Файл превышает лимит в :limit байт.

### `folder_children_limit_exceeded`

**409** · core

**Папка заполнена** — В папке уже максимально допустимые :limit узлов.

### `grant_not_found`

**404** · core

**Доступ не найден** — Выдача доступа не существует или недоступна вам.

### `internal_error`

**500** · core

**Внутренняя ошибка сервера**

### `invalid_cursor`

**400** · core

**Недопустимый курсор** — Курсор повреждён или не относится к этой ленте.

### `invalid_destination`

**409** · core

**Недопустимое назначение** — Назначение не является доступной папкой этого диска.

### `invalid_document_content`

**422** · documents

**Недопустимое содержимое документа** — Содержимое не соответствует типу документа.

### `invalid_drive_state`

**409** · core

**Недопустимое состояние диска** — Состояние диска не допускает этого перехода.

### `invalid_node_name`

**422** · core

**Недопустимое имя узла** — Имя нарушает правила именования.

### `invalid_properties`

**422** · core

**Недопустимые свойства** — Изменение свойств нарушает контракт.

### `invalid_webhook_signature`

**400** · core

**Неверная подпись вебхука** — Подпись вебхука не прошла проверку.

### `method_not_allowed`

**405** · core

**Метод не разрешён** — Точка API не поддерживает этот HTTP-метод; см. заголовок Allow.

### `node_move_cycle`

**409** · drive

**Перемещение создаст цикл** — Папку нельзя переместить в себя или в потомка.

### `node_name_conflict`

**409** · core

**Конфликт имени узла** — Узел с таким именем уже есть в папке.

### `node_not_found`

**404** · core

**Узел не найден** — Узел не существует или недоступен вам.

### `node_not_trashed`

**409** · core

**Узел не в корзине** — Восстановить или удалить навсегда можно только узел из корзины.

### `node_sequence_exhausted`

**409** · core

**Счётчик изменений исчерпан** — Счётчик изменений узла исчерпан.

### `node_sequence_mismatch`

**412** · core

**Узел изменился** — Узел изменился с момента чтения; перечитайте его и повторите.

### `node_trashed_by_ancestor`

**409** · core

**Узел удалён вместе с папкой** — Восстановите папку, удалённую вместе с этим узлом.

### `not_a_document`

**409** · documents

**Это не документ** — Операции нужен узел-документ.

### `not_a_file`

**422** · core

**Это не файл** — Операции нужен узел-файл.

### `payload_too_large`

**413** · core

**Слишком большое тело запроса** — Тело запроса больше допустимого размера.

### `permission_denied`

**403** · core

**Доступ запрещён** — У вас нет прав на это действие.

### `precondition_required`

**428** · core

**Требуется предусловие** — Для этого запроса нужен заголовок :header.

### `profile_restriction`

**403** · core

**Ограничено профилем диска** — Профиль диска запрещает эту операцию.

### `quota_exceeded`

**413** · core

**Квота превышена** — Загрузка превысит квоту диска.

### `rate_limited`

**429** · core

**Слишком много запросов** — Слишком много запросов; повторите через интервал из заголовка Retry-After.

### `registry_frozen`

**500** · core

**Реестр заморожен** — Регистрация выполнена после загрузки приложения.

### `restore_destination_changed`

**409** · core

**Папка восстановления изменилась** — Папка назначения изменилась во время восстановления; повторите.

### `restore_destination_required`

**409** · core

**Нужна папка для восстановления** — Исходная папка недоступна; выберите папку назначения.

### `revision_not_found`

**404** · core

**Ревизия не найдена** — Ревизия не существует или недоступна вам.

### `revision_pinned`

**409** · core

**Ревизия закреплена** — Открепите ревизию перед удалением.

### `route_not_found`

**404** · core

**Маршрут не найден** — Такой точки API нет.

### `share_link_ttl_exceeded`

**422** · core

**Срок ссылки превышен** — Ссылка может действовать не более :limit_days дн.

### `share_not_found`

**404** · core

**Ссылка не найдена** — Ссылка не существует, истекла или недоступна вам.

### `share_role_not_allowed`

**422** · core

**Роль недопустима для ссылки** — Ссылка не может нести эту роль для этой аудитории.

### `tree_depth_limit_exceeded`

**409** · core

**Превышена глубина дерева** — Дерево папок не может быть глубже :limit уровней.

### `tree_traversal_incomplete`

**409** · core

**Дерево слишком глубокое** — Дерево папок глубже, чем допускает ограниченный обход; выберите папку выше по дереву.

### `unknown_document_type`

**422** · documents

**Неизвестный тип документа** — Такой тип документа не зарегистрирован.

### `unknown_export_format`

**422** · documents

**Неизвестный формат экспорта** — Нет экспортера для запрошенного формата; допустимые — в meta.allowed.

### `unknown_profile`

**422** · core

**Неизвестный профиль** — Такой профиль диска не зарегистрирован.

### `unknown_role`

**422** · core

**Неизвестная роль** — Такая роль не зарегистрирована.

### `unsupported_node_kind`

**409** · core

**Неподдерживаемый вид узла** — Для этого вида узла операция не поддерживается.

### `upgrade_preflight_failed`

**409** · core

**Предпроверка обновления не пройдена** — Данные не соответствуют условиям обновления.

### `upload_conflict`

**409** · drive

**Конфликт загрузки** — Сессию загрузки изменяет другой запрос.

### `upload_not_found`

**404** · drive

**Загрузка не найдена** — Сессия загрузки не существует или недоступна вам.

### `upload_offset_mismatch`

**409** · drive

**Смещение загрузки не совпало** — Upload-Offset отличается от смещения на сервере; продолжите с него.

### `upload_rejected`

**422** · drive

**Загрузка отклонена** — Загруженное содержимое отклонено.

### `validation_failed`

**422** · core

**Ошибка валидации** — Данные запроса некорректны; подробности в поле errors.

### `webhook_delivery_not_redeliverable`

**409** · core

**Доставку нельзя повторить** — Доставка ещё повторяется или её endpoint отключён.

### `write_outside_operation`

**500** · core

**Запись вне операции** — Запись выполнена в обход операций Vaulter.
<!-- vaulter:errors:end -->
