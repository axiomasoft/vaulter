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

`InvalidTipTapContentException::missingDocNode()` — корень контента должен быть
`{"type":"doc", …}`. Передайте валидный TipTap-документ в JSON или используйте `TipTapContent::empty()`.

## content-too-large

`InvalidTipTapContentException::tooLarge()` — контент превышает
`config('vaulter.max_document_size_kb')`. Разбейте документ или поднимите лимит.

## unknown-collaboration-driver

`VaulterException` из `Vaulter::driver()` — указанный драйвер не зарегистрирован в
`config('vaulter.collaboration.drivers')`. Добавьте запись, чей `class` реализует
`CollaborationDriver`.

## missing-jwt

Драйверу Hocuspocus нужен `firebase/php-jwt`, чтобы выпускать токены комнат. Выполните
`composer require firebase/php-jwt`.

## missing-collab-secret

Драйверу Hocuspocus нужен `VAULTER_COLLAB_SECRET`. Задайте общий секрет в своём
окружении.
