# Справочник ошибок

Каждое исключение Vaulter наследуется от `Vaulter\Exceptions\VaulterException` и несёт
сообщение в формате `[Vaulter] {what}. Why: {…}. Fix: {…}. Docs: {url}`, где URL
указывает на соответствующий якорь ниже.

## problem+json

Любая ошибка версионированного маршрута `/v1` отвечает `application/problem+json` (RFC 9457) с
полями `type`, `title`, `status`, `code`, `detail`, `request_id`, `errors` и `meta`. Ветвитесь по
`code` — стабильному контракту SemVer; `title` и `detail` — локализованный текст из `lang/` и
могут меняться, а сообщение исключения из лога в тело не попадает. Полный список кодов,
сгенерированный из каталога, — [справочник кодов ошибок](/ru/reference/errors). Разделы ниже
описывают исключения PHP; неверсионированные маршруты по-прежнему отвечают `{message, code}`.

## document-not-found

`DocumentNotFoundException` — запрошенный id документа не существует, был мягко удалён
или лежит вне текущего workspace. Проверьте id и фильтр по workspace. API отображает
это на `404`.

## precondition-required

`PreconditionRequiredException` (428 `precondition_required`, `meta.header`, `meta.reason`) —
запрос, меняющий состояние относительно представления, не сказал, какое именно он видел:
заголовок `If-Match` отсутствует (`reason: missing`) или равен `*`, который совпадает с любым
представлением и потому не ловит потерянное обновление (`reason: wildcard`). Прочитайте ресурс и
верните его `ETag` в `If-Match`.

## permission-denied

`PermissionDeniedException` — ваш `PermissionResolver` вернул `false` для запрошенного
действия. Выдайте роль с нужной способностью на узле или предке выше границы наследования
либо поправьте resolver. API отображает это на `403`.

## unknown-role

`UnknownRoleException` (422 `unknown_role`) — грант, ссылка-шар или карта ролей драйвера
указали незарегистрированную роль (или версию роли). Роли 1.0: `previewer`, `reader`,
`commenter`, `writer`, `organizer`, `owner`, все версии 1.

## unknown-document-type

`UnknownDocumentTypeException` (422 `unknown_document_type`) — ни один зарегистрированный
[тип документа](/ru/extending/document-types) не имеет переданного ключа. Добавьте его класс в
`config('vaulter-documents.types')`, пометьте тегом `vaulter.document_types` или
зарегистрируйте из сервис-провайдера через `Documents::types()->register()`.

## document-type-unavailable

`document_type_unavailable` (409) — тип документа не установлен (его расширение удалено).
Документ по-прежнему читается как JSON, копируется, удаляется в корзину и навсегда;
редактирование, контрольные точки, восстановление, экспорт и совместная работа требуют снова
зарегистрировать тип. `context.type` называет отсутствующий ключ.

## document-content-degraded

`document_content_degraded` (409) — сохранённое содержимое содержит части, которые тип больше не
принимает (узлы выключенного расширения редактора), или записано в более новой версии схемы, чем
установленный тип. Оно прочитано без этих частей, и сохранение поверх удалило бы их; документ
доступен только для чтения, пока расширение не включат снова.

## registry-frozen

`RegistryFrozenException` (`registry_frozen`) — тип документа зарегистрировали после загрузки
приложения. Регистрируйте типы из сервис-провайдера (конфиг, тег контейнера или `register()` до
окончания загрузки).

## blob-unavailable

`BlobUnavailableException` (409 `blob_unavailable`) — сохранённые байты файла больше недоступны, либо владелец
попытался запинить блоб в состоянии deleting или deleted (`BlobPins::pin()`,
[Хранилище и блобы](/ru/concepts/storage-and-blobs#пины-и-чтение)). Ничего не записано. Загрузите содержимое
заново: коммит байтов воскрешает затомбстоненный блоб, пока они ещё существуют, и возвращённый блоб можно пинить.

## module-graph-invalid

`ModuleGraphException` (503 `module_graph_invalid`) — манифесты установленных модулей не
компилируются: повторяющийся id, неизвестный или небулев ключ `vaulter.modules`, активный модуль с
неактивной зависимостью, отсутствующий или лишний поставщик capability, вклад в неизвестный слот.
Ничего не активируется; `vaulter:modules` и `vaulter:doctor` печатают все причины. Исправьте
манифесты или `vaulter.modules`, затем пересоберите кэши и перезапустите воркеры
([ADR-0027](/adr/0027-kernel-boundaries-and-modules)).

## module-not-ready

`ModuleNotReadyException` (503 `module_not_ready`) — запись пришла в модуль, который неактивен или
чьих таблиц ещё нет (миграции не выполнены). Операция отклоняется до открытия транзакции; выполните
`php artisan migrate` (или включите модуль и его зависимости в `vaulter.modules`) и повторите.
Готовность модуля запоминается на время жизни процесса воркера
([ADR-0028](/adr/0028-extension-slots-and-operation-pipeline)).

## extension-failed

`InvalidExtensionResult` (500 `extension_failed`) — вклад модуля в слот нарушил контракт слота:
middleware операции вызвал `$next` не ровно один раз, передал другую операцию или вернул не результат
`$next`; контрибьютор снимка вернул зарезервированный или повторяющийся ключ фрагмента. Операция не
выполнена или откачена. Это ошибка интеграции: она не повторяется и не кэшируется. Исправьте или
отключите модуль, внёсший вклад ([ADR-0028](/adr/0028-extension-slots-and-operation-pipeline)).

## idempotency-key-reused

`IdempotencyKeyReusedException` (422 `idempotency_key_reused`) — тот же актор уже использовал
`Idempotency-Key` для этой операции с другим запросом (другие идентификаторы, поля или предусловия).
Ничего не выполнено; сохранённый результат принадлежит первому запросу. Для нового запроса отправьте
новый ключ ([идемпотентность](/ru/concepts/idempotency), [ADR-0029](/adr/0029-idempotency)).

## operation-in-progress

`OperationInProgressException` (409 `operation_in_progress`, `Retry-After`) — запрос с тем же
`Idempotency-Key` ещё выполняется и не завершился за ограниченное ожидание (2 секунды). Ничего не
выполнено; повторите запрос через `Retry-After`, чтобы получить результат первого
([идемпотентность](/ru/concepts/idempotency)).

## duplicate-registration

`DuplicateDocumentTypeException` (`duplicate_registration`) — один ключ типа документа
зарегистрирован дважды. Регистрируйте его один раз или подменяйте через
`Documents::types()->replace()`.

## invalid-tiptap-content

`InvalidDocumentContentException::missingDocNode()` — корень контента должен быть
`{"type":"doc", …}`. Передайте валидный TipTap-документ в JSON или используйте `TipTapContent::empty()`.

## invalid-document-content

`InvalidDocumentContentException::notAnObject()` (422 `invalid_document_content`, reason
`invalid_json`) — тип документа хранит JSON-объект (spreadsheet, собственный тип хоста), а пришёл
список или скаляр. Отправьте объект. Содержимое больше `maxSizeBytes()` типа или
`vaulter-documents.content.max_size_kb` даёт тот же код с reason `too_large`.

## content-too-large

`InvalidDocumentContentException::tooLarge()` — контент превышает
`config('vaulter-documents.content.max_size_kb')`. Разбейте документ или поднимите лимит.

## unknown-collaboration-driver

`VaulterException` из `CollaborationDriverManager`: выберите `hocuspocus` в
`vaulter-collaboration.driver`. Удалённый драйвер `reverb` даёт
`InvalidConfigurationException` с явным сообщением.

## missing-jwt

Драйверу Hocuspocus нужен `firebase/php-jwt`, чтобы выпускать токены комнат. Выполните
`composer require firebase/php-jwt`.

## missing-collab-secret

Драйверу Hocuspocus нужен `VAULTER_COLLAB_SECRET`. Задайте общий секрет в своём
окружении.

## comment-not-found

`comment_not_found` (404) — комментария нет или он принадлежит другому узлу (в том числе
`parent_id` ответа). Возьмите id из списка комментариев этого узла.

## document-state-mismatch

`document_state_mismatch` (412) — сохранение несло `expectedStateVersion`, но раньше успело
другое сохранение контента документа (`context.actual` — текущий `state_version`). Ничего не
записано. Перезагрузите документ и повторите правку. Переименование и перемещение эту ошибку не
вызывают: у узла и его контента разные валидаторы.

## collaboration-epoch-mismatch

`collaboration_epoch_mismatch` (409) — запись коллаборации пришла из комнаты старого поколения:
документ восстановили или заменили его JSON после открытия комнаты. Запись отвергнута, чтобы не
влить брошенную историю обратно; откройте документ в новой комнате. Хаб закрывает такую комнату
кодом 4409 `epoch_changed` и не повторяет запись.

## collaboration-seed-conflict

`collaboration_seed_conflict` (409) — хаб попытался сидировать состояние документа, у которого
оно уже есть (другой инстанс хаба успел первым). Второй сид отвергнут: оба слились бы в
задвоенный контент. Хаб загружает сохранённое состояние.

## collaboration-unavailable

`collaboration_unavailable` (409) — `collaborationSession()` или вызов хаба для документа, который
нельзя редактировать совместно: модуль выключен (`vaulter.modules.collaboration`, `context.reason = disabled`;
маршрут неактивного модуля — обычный `404`) или тип документа не реализует `SupportsCollaboration`
(`unsupported_type`). Редактируйте через `DocumentOperations::update()`.

## document-state-too-large

`document_state_too_large` (413) — чекпоинт или seed несли бинарный Yjs-state больше
`vaulter-collaboration.max_state_bytes` (по умолчанию 16 МиБ). Ничего не записано.
Поднимите лимит или разделите документ.

## document-state-pending

`document_state_pending` (409, retryable `with_backoff`) — у документа есть сохранённые правки коллаборации, которых ещё
нет в его контенте. Чтение, экспорт, замена, восстановление или purge контента сначала просят realtime-транспорт внести
их; если он не активен или не успевает к сроку, запрос отвергается без изменений. Повторите позже; убедитесь, что модуль
коллаборации и его хаб работают. `drain.restore_deadline` (`vaulter-collaboration`, по умолчанию 10 с) — сколько запрос ждёт
хаб; если хаб недоступен, почините его или выполните `compact --pending` образа хаба (см.
[Совместное редактирование](/ru/guides/collaboration#компактация-и-состояние-pending)). Retention и шаг purge при
удалении диска отвечают тем же кодом для такого документа, пока маркер не снят.

## collaboration-protocol-mismatch

`collaboration_protocol_mismatch` (409) — вызов хаба не нёс `X-Vaulter-Collab-Protocol: 2` или нёс другую версию. Ничего
не прочитано. Хаб и бэкенд должны говорить на одном протоколе: обновите отстающий (образ `vaulter-collaboration-hub` и
`vaulter-collaboration` выпускаются вместе). Хаб, увидевший этот ответ, останавливает durable-записи и показывает
`protocol` в `/readyz`, пока бэкенд снова не ответит `2xx`.

## collaboration-checkpoint-conflict

`collaboration_checkpoint_conflict` (409, retryable `after_refetch`) — компактация хаба проиграла compare-and-set:
`context.reason` — `lease` (поколение ему больше не принадлежит), `coverage` (покрываемого диапазона нет в журнале) или
`checkpoint_version` (раньше опубликован другой чекпоинт). Ничего не записано и не удалено. Хаб берёт lease заново.

## collaboration-update-mismatch

`collaboration_update_mismatch` (422) — обновление с этим `update_id` уже зафиксировано, но с другими байтами.
`update_id` называет ровно одно обновление; повтор обязан нести тот же payload. Клиент, переиспользующий id, ошибочен.

## collaboration-update-too-large

`collaboration_update_too_large` (413) — тело append, checkpoint или recheck (или его payload) больше лимита,
`vaulter-collaboration.limits.update_bytes` / `limits.request_bytes` / `max_state_bytes`; решается по длине до любого
декодирования. Ничего не записано. Держите обновления ниже лимитов или поднимите их вместе с
`VAULTER_COLLAB_MAX_UPDATE_BYTES`, `VAULTER_COLLAB_MAX_REQUEST_BYTES` и `VAULTER_COLLAB_MAX_DOCUMENT_BYTES` хаба.

## collaboration-draining

`collaboration_draining` (503, retryable `with_backoff`) — `vaulter:collaboration:drain` выставил флаг draining, поэтому
журнал отвергает новые обновления, пока сохранённые компактируются. Хаб хранит обновление и повторяет его. После
повторного включения модуля выполните `php artisan vaulter:collaboration:drain --release`; `vaulter:doctor` показывает
флаг как `collaboration.draining`.

## invalid-node-name

`InvalidNodeNameException` (422 `invalid_node_name`, с `reason` и `errors.name`) — новое имя
нарушило базовые правила или политику имён профиля. Ничего не обрезается. `reason`: `empty`,
`path_alias` (`.`/`..`), `separator` (`/`, `\`), `control_char`, `reserved_char`
(`< > : " | ? *` в `portable-v1` или невалидный UTF-8), `trailing_dot_or_space`,
`leading_or_trailing_space`, `reserved_name` (имена устройств Windows, `desktop.ini`,
`.DS_Store`) или `too_long` (больше 255 байт UTF-8 или единиц UTF-16). Имена хранятся в Unicode
NFC, если установлен ext-intl.

## folder-children-limit-exceeded

`FolderChildrenLimitExceededException` (409 `folder_children_limit_exceeded`, `limit`) — в папке
уже `maxFolderChildren` узлов политики диска; дети в корзине считаются до очистки. Используйте
другую папку или очистите её корзину.

## tree-depth-limit-exceeded

`TreeDepthLimitExceededException` (409 `tree_depth_limit_exceeded`, `limit`) — создание,
перемещение, копирование или восстановление поместило бы узел глубже `maxFolderDepth` политики
диска. Выберите назначение повыше.

## file-size-limit-exceeded

`FileSizeLimitExceededException` (413 `file_size_limit_exceeded`, `limit`) — заявленный,
потоковый или итоговый размер больше `maxFileBytes` политики диска или
`vaulter-drive.uploads.max_file_size` установки.

## cannot-delete-head-revision

`CannotDeleteHeadRevisionException` (409 `cannot_delete_head_revision`) — `deleteRevision()`
вызван для текущей (head) ревизии файла. Голову удалить нельзя: удалите файл или сначала
создайте новую ревизию.

## revision-pinned

`RevisionPinnedException` (409 `revision_pinned`) — ревизия закреплена (`keep_forever`) и не
удаляется ни вручную, ни прунингом. Сначала снимите закрепление через `setRevisionRetention()`.

## invalid-properties

`InvalidPropertiesException` (422 `invalid_properties`, `reason`) — патч свойств нарушил
контракт: `namespace` (`^[a-z0-9][a-z0-9.-]{0,127}$`), `key` (`^[A-Za-z0-9_.-]{1,64}$`),
`value_too_long` (значение — строка UTF-8 до 1024 байт), `too_many` / `too_large`
(`maxPropertyCount` / `maxPropertiesBytes` политики диска) или `reserved_namespace` (`vaulter` и
`vaulter.*` пишет только система).

## node-trashed-by-ancestor

`NodeTrashedByAncestorException` — узел, удалённый в корзину вместе с папкой, восстанавливается
через эту папку, а не сам по себе. В ответе `context.trash_root_id` — корень, который нужно
восстановить (или удалить безвозвратно). Восстановить можно только корень корзины
(`trash_root_id = id`).

## restore-destination-required

`RestoreDestinationRequiredException` — папка, из которой узел попал в корзину, исчезла или сама
в корзине, а восстановление больше не переносит узел молча (раньше оно откатывалось в корень
диска). Передайте явное назначение: `RestoreOptions(destination: $folder)` или `destination_id` в
`POST nodes/{node}/restore`. На этой папке нужен `Create`.

## tree-traversal-incomplete

`TreeTraversalIncompleteException` — дерево папок под узлом глубже, чем может прочитать
ограниченный обход (наибольший `maxFolderDepth` среди профилей, но не меньше 64 уровней), поэтому
копирование, удаление в корзину или чистка не могут доказать, что видят всех потомков. Операция
останавливается до любой записи, а не действует на часть дерева. Сначала работайте с более
мелкой папкой.

## drive-archived

`DriveArchivedException` — архивный диск доступен только для чтения: любое изменение, чистка и
создание от не-system актора отклоняются, чтение и скачивание работают. Сначала разархивируйте
диск (`$drive->unarchive()`, нужен `Manage` на корне).

## invalid-drive-state

`InvalidDriveStateException` — жизненный цикл диска допускает только `active → archived → active`
и `active|archived → deleting`. `archive()` требует активный диск, `unarchive()` — архивный.

## write-outside-operation

`write_outside_operation` (500, ошибка интеграции) — код вне операций Vaulter сохранил, обновил или
удалил модель Vaulter (`Node::query()->update(...)`, `$node->save()`). Строки меняются только через
операции `DriveHandle`: они держат инварианты, авторизацию и журнал; защита моделей
(`vaulter.database.write_guard`, по умолчанию `true`) отклоняет запись до обращения к базе.
Перенесите запись в операцию. `DB::table()` и сырой SQL не перехватываются: защита — это
эшелонированная оборона, а не песочница.

## share-not-found

`ShareNotFoundException` (`share_not_found`, 404) — публичный запрос по ссылке ничего не открывает:
токен неизвестен, отозван, заменён ротацией, истёк или исчерпан, посетитель вне арендатора ссылки
либо `?node=` указывает на узел вне ссылки. Ответ во всех случаях одинаков, чтобы токен нельзя
было подобрать. Запросите у владельца актуальную ссылку.

## share-password-required

`SharePasswordRequiredException` (`share_password_required`, 401) — ссылка защищена паролем, а
запрос пришёл без пароля или с неверным. Передайте пароль в заголовке `X-Share-Password` (не в
URL — он попадает в логи).

## webhook-endpoint-not-found

`WebhookEndpointNotFoundException` (`webhook_endpoint_not_found`, 404) — webhook endpoint с id из
URL не существует (возможно, удалён). Получите список эндпоинтов и используйте актуальный id.

## webhook-delivery-not-found

`WebhookDeliveryNotFoundException` (`webhook_delivery_not_found`, 404) — доставки нет, она
принадлежит другому эндпоинту или удалена по истечении срока хранения.

## webhook-delivery-not-redeliverable

`WebhookDeliveryNotRedeliverableException` (`webhook_delivery_not_redeliverable`, 409) — ручная
повторная доставка запрошена для доставки, которая ещё `pending` или `failed` (повторы не
закончились), либо чей эндпоинт неактивен. Дождитесь конца повторов или смерти доставки и
сначала включите эндпоинт.

## invalid-change-cursor

`Vaulter\Drive\Exceptions\Changes\InvalidCursorException` (`invalid_cursor`, 400) — [курсор журнала
изменений](/concepts/events), который это приложение не подписывало, который изменён или принадлежит
другому drive либо тенанту. Курсоры непрозрачны: храните их как есть. Начните заново со
`startCursor()` после полного листинга.

## cursor-expired

`Vaulter\Drive\Exceptions\Changes\CursorExpiredException` (`cursor_expired`, 410) — записи после курсора
удалены `vaulter:changes:prune` (старше `vaulter-drive.changes.retention_days`) либо журнал drive сброшен
`vaulter:changes:reset`. Пересинхронизируйтесь: возьмите новый `startCursor()`, заново получите
листинг drive и замените локальную копию.

## webhook-signature

`InvalidWebhookSignatureException` (`invalid_webhook_signature`, 400) — `StandardWebhookVerifier`
отклонил полученный вебхук: нет заголовка, `webhook-timestamp` вне допуска или ни одна подпись не
совпала с сырым телом и секретами. Проверяйте неизменённое тело секретом эндпоинта `whsec_` (при
ротации — обоими) и сверьте часы получателя.
