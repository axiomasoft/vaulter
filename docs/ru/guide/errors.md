# Справочник ошибок

Каждое исключение Vaulter наследуется от `Vaulter\Exceptions\VaulterException` и несёт
сообщение в формате `[Vaulter] {what}. Why: {…}. Fix: {…}. Docs: {url}`, где URL
указывает на соответствующий якорь ниже.

## document-not-found

`DocumentNotFoundException` — запрошенный id документа не существует, был мягко удалён
или лежит вне текущего workspace. Проверьте id и фильтр по workspace. API отображает
это на `404`.

## permission-denied

`PermissionDeniedException` — ваш `PermissionResolver` вернул `false` для запрошенного
действия. Выдайте нужный уровень доступа или поправьте resolver. API отображает это на `403`.

## unknown-document-type

`UnknownDocumentTypeException` — переданный `type` отсутствует в
`config('vaulter.enabled_document_types')`. Включите его или зарегистрируйте свой тип через
`Vaulter::registerDocumentType()`.

## invalid-tiptap-content

`InvalidDocumentContentException::missingDocNode()` — корень контента должен быть
`{"type":"doc", …}`. Передайте валидный TipTap-документ в JSON или используйте `TipTapContent::empty()`.

## content-too-large

`InvalidDocumentContentException::tooLarge()` — контент превышает
`config('vaulter-documents.content.max_size_kb')`. Разбейте документ или поднимите лимит.

## unknown-collaboration-driver

`VaulterException` из `CollaborationDriverManager`: выберите `hocuspocus` в
`vaulter-documents.collaboration.driver`.

## missing-jwt

Драйверу Hocuspocus нужен `firebase/php-jwt`, чтобы выпускать токены комнат. Выполните
`composer require firebase/php-jwt`.

## missing-collab-secret

Драйверу Hocuspocus нужен `VAULTER_COLLAB_SECRET`. Задайте общий секрет в своём
окружении.

## document-state-mismatch

`document_state_mismatch` (412) — сохранение несло `expectedStateVersion`, но раньше успело
другое сохранение контента документа (`context.actual` — текущий `state_version`). Ничего не
записано. Перезагрузите документ и повторите правку. Переименование и перемещение эту ошибку не
вызывают: у узла и его контента разные валидаторы.

## collaboration-epoch-mismatch

`collaboration_epoch_mismatch` (409) — запись коллаборации пришла из комнаты старого поколения:
документ восстановили или заменили его JSON после открытия комнаты. Запись отвергнута, чтобы не
влить брошенную историю обратно; откройте документ в новой комнате.

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

## invalid-properties

`InvalidPropertiesException` (422 `invalid_properties`, `reason`) — патч свойств нарушил
контракт: `namespace` (`^[a-z0-9][a-z0-9.-]{0,127}$`), `key` (`^[A-Za-z0-9_.-]{1,64}$`),
`value_too_long` (значение — строка UTF-8 до 1024 байт), `too_many` / `too_large`
(`maxPropertyCount` / `maxPropertiesBytes` политики диска) или `reserved_namespace` (`vaulter` и
`vaulter.*` пишет только система).
