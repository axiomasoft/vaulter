---
title: Справочник REST API
description: REST API v1 Vaulter — базовые правила, стеки middleware, валидаторы и кэширование, выдача файлов и каждый маршрут с методом, путём, параметрами и ответом.
outline: deep
editLink: false
---

# Справочник REST API

Версионированный REST API живёт под `/{routes.prefix}/v1` — по умолчанию
`/api/vaulter/v1`, — и каждый маршрут называется `vaulter.v1.<имя>`. Сначала идут
базовые правила, затем маршруты. Ошибки описаны в [справочнике ошибок](/ru/reference/errors).

::: info Миграция идёт
Маршруты доступа, ссылок, активности, журнала изменений и вебхуков уже на v1. Маршруты
drive, загрузок, документов, комментариев и совместного редактирования ниже пока
обслуживаются без версии под `/{routes.prefix}` и переедут на `/v1` в ближайших
выпусках разработки 1.0; их разделы это отмечают.
:::

## Базовые правила

| Правило | Значение |
|---|---|
| Базовый путь | `/{routes.prefix}/v1` (по умолчанию `/api/vaulter/v1`) |
| Версии | мажор в URL; аддитивные изменения остаются в `v1`; удаление или смена смысла — только в `v2`, и `v1` поддерживается не меньше 12 месяцев после выхода `v2` |
| Заголовок версии | на каждом ответе v1, включая ошибки и `304`: `X-Vaulter-Api-Version: 1` |
| Имена маршрутов | `vaulter.v1.<ресурс>.<действие>` |
| JSON | `snake_case`; все id — строки; время — RFC 3339 в UTC с `Z`; отсутствующее значение — `null`, ключ не пропускается; пустой объект (`properties`, `data`) — `{}` |
| Один ресурс | `{ "data": { … } }` |
| Списки | курсорные страницы: `?cursor=&per_page=` (`per_page` 1–200, по умолчанию 50; вне диапазона — `422 validation_failed`) → `{ "data": [ … ], "meta": { "next_cursor": "…" \| null, "per_page": 50 } }`; `total` нет |
| Корреляция | входящий `X-Request-Id`, подходящий под `^[A-Za-z0-9._:-]{1,64}$`, сохраняется, иначе назначается новый ULID; он возвращается в каждом ответе, передаётся операции и записывается с её активностью и событиями |
| Ошибки | `application/problem+json` со стабильным `code` — см. [справочник ошибок](/ru/reference/errors) |
| Параметры | `{node}`, `{drive}`, `{grant}`, `{share}`, `{endpoint}`, `{delivery}`, `{token}` — простые id (`[A-Za-z0-9_-]+`); привязки моделей к маршрутам нет |

### Стеки middleware

```php
// config/vaulter.php
'routes' => [
    'prefix' => 'api/vaulter',
    'middleware' => [
        'authenticated' => ['api', 'auth:sanctum'],
        'public' => ['api'],
        'hub' => ['api'],
    ],
],
```

Хост перечисляет только свои middleware. Vaulter добавляет к каждому из трёх стеков
свои — `AssignCorrelationId`, `AddApiVersionHeader`, `ApplyResponseCachePolicy` и
`RecordVaulterRequestMetrics` — и ставит первые три по приоритету раньше ограничения
частоты и аутентификации, поэтому `401` и `429` тоже несут id запроса, версию и
политику кэша. На маршрутах хоста эти middleware ничего не делают.

| Стек | Маршруты | Vaulter добавляет |
|---|---|---|
| `authenticated` | все, кроме двух ниже | — |
| `public` | `shares/{token}*` | `throttle:vaulter-public`, `ResolveShareToken` |
| `hub` | `collaboration/hub/*` | `throttle:vaulter-public`, `ResolveCollaborationCall` |

### Резолв и авторизация

Контроллер находит drive по id через `HttpResourceResolver` с фильтром арендатора и
вызывает операции от имени пользователя запроса
(`Vaulter::drive($drive)->actingAs($user)`): HTTP авторизует ровно так же, как вызов той же
операции из PHP.

- Нет аутентифицированного пользователя → `401 actor_required`.
- Узел, который вам не виден, чужого арендатора или несуществующий → одинаковый
  `404 node_not_found` с одинаковым телом, кроме `request_id`. Drive, который вам не виден
  (нет `view` на корне), чужого арендатора, удаляемый или неизвестный → одинаковый
  `404 drive_not_found`.
- Узел виден, но нет нужной способности → `403 permission_denied`; функция, выключенная
  профилем drive → `403 profile_restriction`.
- id гранта или ссылки другого узла считается неизвестным (`404`).

### Валидаторы и предусловия

`ETag` узла — сильный валидатор **того представления, которое получаете вы**:
`"{id}.{sequence}.{digest}"`, где дайджест покрывает всё в вашей копии узла — ваши
способности, вашу звёздочку, состояние проверки и presentation, properties и голову
содержимого. Поэтому грант, звёздочка или завершённая проверка меняют его, хотя
`sequence` узла не изменился. Считайте тег непрозрачным. Содержимое документа использует
`"{node}.{collab_epoch}.{state_version}"`, не зависящий от вызывающего.

- Заголовок `ETag` приходит с чтением одного узла без `include`, с содержимым документа и с
  мутациями, которые возвращают узел или содержимое. Каждый узел в теле, включая элементы
  списков, несёт поле `etag`.
- `If-None-Match` на одном узле или содержимом документа даёт `304` — с `ETag`,
  `Cache-Control`, `Vary`, `X-Vaulter-Api-Version` и `X-Request-Id`, без тела, — только
  после того как узел найден и авторизован. Читатель, у которого отозвали доступ, получает
  `404`, а не `304`.
- Маршруты с пометкой **If-Match** ниже не меняют состояние вслепую:
  - нет `If-Match` → `428 precondition_required` с `meta.header = "If-Match"`;
  - `If-Match: *` → `428` с `meta.reason = "wildcard_not_allowed"` (совпал бы с любой версией);
  - слабый тег (`W/"…"`) никогда не совпадает;
  - тег не текущий → `412 node_sequence_mismatch` (или `document_state_mismatch`) с
    `meta.node_id` — без текущего тега;
  - список `"a", "b"` совпадает, если совпадает любой элемент.

  Проверка идёт после авторизации, а операция пишет, только если узел всё ещё на том
  `sequence`, с которым вы совпали (атомарный compare-and-set), поэтому два клиента с
  одним тегом не могут выиграть оба: второй получит `412`.

### Кэширование

| Ответ | `Cache-Control` |
|---|---|
| Один ресурс с `ETag` (и его `304`) | `private, no-cache`, `Vary: Authorization, Cookie` |
| Списки, мутации, ответы с токеном или секретом, ошибки, `428` | `no-store` (`private, no-store` для `GET`) |
| Байты файлов и редиректы на подписанный URL | `private, no-store` |
| Публичные маршруты ссылок | `private, no-store` и `Referrer-Policy: no-referrer` |

В v1 нет ни `public`, ни `max-age`: общий кэш не должен отдать представление одного
вызывающего другому или отозванному вызывающему.

### Выдача файлов

Байты и подписанные URL выходят только через один компонент, который на каждый запрос
заново проверяет доступ, арендатора, профиль и состояние проверки и фиксирует точную
ревизию. Режим задаёт `vaulter.downloads.delivery_mode`:

- **`strict`** (по умолчанию) — приложение отдаёт байты потоком с
  `Content-Disposition: attachment; filename="…"; filename*=UTF-8''…` (RFC 6266; управляющие
  символы и разделители путей из имени удаляются), `X-Content-Type-Options: nosniff`,
  `Content-Security-Policy: sandbox; default-src 'none'`, `Cache-Control: private, no-store`,
  `Accept-Ranges: none` и `Content-Length`. Заявленный тип сохраняется только для списка
  безопасных inline-типов, иначе `application/octet-stream`.
- **`direct_signed`** (по выбору) — после тех же проверок `302` на URL, подписанный вашим
  `DirectContentUrlSigner` для этой ревизии, действующий `vaulter.downloads.direct_ttl_seconds`
  (1–300), с `Cache-Control: private, no-store` и `Referrer-Policy: no-referrer`.

Ревизия не чистая (ожидает обязательной проверки, заражена) → `423 content_unavailable` до
любого байта или URL. Кто видит файл, но не может его скачать (previewer), получает
`403 permission_denied`. Диапазоны байтов в 1.0 не поддерживаются.

Отзыв ограничен и задокументирован, а не мгновенен: в `strict` проверяется каждый новый
запрос, но уже начатый поток не обрывается; в `direct_signed` уже выданный URL действует
до истечения — отзыв доступа его не отменяет. Скачивания по публичным ссылкам всегда
идут потоком.

---

## Маршруты без версии

Маршруты [Drive](#drive), [документных узлов](#документные-узлы-documents),
[резюмируемых загрузок](#резюмируемые-загрузки),
[совместного редактирования](#совместное-редактирование-в-реальном-времени) и
[легаси-API документов](#легаси-api-документов) пока обслуживаются под `/{routes.prefix}`
без `/v1` и сохраняют прежние соглашения: `{storage}` и `{node}` превращаются в модели до
контроллера, ошибки — `{ "message", "code" }`, узлы — в прежней форме (`storage_id`,
`handler`, …). Они переедут на `/v1` — с базовыми правилами выше — в ближайших выпусках
разработки 1.0.

## Drive

Единое дерево файлов/папок `v_nodes` (`ShowDriveController`, `NodeController`,
`DownloadController`). Эндпоинты уровня коллекции хранилища несут `{storage}` в пути;
эндпоинты уровня узла выводят хранилище из привязанного `{node}`.

### Хранилища

#### `GET storages/{storage}`

Прочитать метаданные контейнера хранилища, корневой узел и использование. → `ShowDriveController::__invoke`

**Ответ** (`DriveResource`):

```json
{
  "data": {
    "id": "01J…",
    "panel": "default",
    "owner": { "type": "App\\Models\\Project", "id": 1 },
    "name": "Project storage",
    "slug": null,
    "root_node_id": "01J…",
    "disk": "local",
    "storage_type": "default",
    "used_bytes": 10485760,
    "quota_bytes": null,
    "status": "active",
    "created_at": "…",
    "updated_at": "…",
    "abilities": { "view": true, "create_folder": true, "upload": true, "manage": true }
  }
}
```

### Список и навигация

#### `GET storages/{storage}/nodes`

Получить прямых потомков папки (или корня хранилища). → `NodeController::index`

| Параметр | Где | Примечания |
|---|---|---|
| `parent_id` | query | опционально; опустите для корня |
| `per_page`, `cursor` | query | пагинация |

**Гейт:** `Ability::View` на родителе. **Ответ:** пагинированная коллекция
`NodeResource`.

#### `GET nodes/{node}`

Один узел плюс его `breadcrumb`; папка также встраивает своих прямых `children`.
→ `NodeController::show`

**Гейт:** `Ability::View`. **Ответ** (`NodeResource`):

```json
{
  "data": {
    "id": "01J…",
    "kind": "file",
    "name": "report.pdf",
    "mime": "application/pdf",
    "extension": "pdf",
    "size_bytes": 524288,
    "storage_id": "01J…",
    "parent_id": "01J…",
    "file_id": "01J…",
    "current_version_id": "01J…",
    "trashed_at": null,
    "created_at": "…",
    "updated_at": "…",
    "content_modified_at": "…",
    "viewer": { "starred": false },
    "children": null,
    "breadcrumb": [ /* корень → этот узел */ ],
    "abilities": { "view": true, "edit": true, "trash": true, "…": true },
    "handler": { "id": "…", "capabilities": { "…": true }, "ui": { "…": "…" } }
  }
}
```

Карта `abilities` заполняется `AccessResolver` для актора запроса (неаутентифицированный
запрос получает read-only оболочку со всеми `false`). `handler` — это резолвнутый
дескриптор [обработчика формата](/ru/guide/document-handlers) для листового узла
(null для папки).

### Операции записи

#### `POST storages/{storage}/folders`

Создать папку. → `NodeController::storeFolder`

| Параметр | Правило |
|---|---|
| `name` | required, string, max 255, без разделителей пути (`/` `\`) |
| `parent_id` | nullable (опустить = корень) |

**Гейт:** `canCreateInWorkspace` + `Ability::Create` на цели назначения.
**Ответ:** `201` `NodeResource`.

#### `POST storages/{storage}/uploads`

Закоммитить бинарную загрузку (multipart). Загрузка новой версии нацеливается на
существующий файловый узел через `node_id`; иначе под родителем создаётся совершенно
новый файл. → `NodeController::upload`

| Параметр | Правило |
|---|---|
| `file` | required, file, `max:` `config('vaulter-drive.uploads.max_file_size')`; если `config('vaulter-drive.uploads.allowed_mime_types')` непуст, ограничен этим набором `mimetypes` |
| `parent_id` | nullable (опустить = корень) |
| `node_id` | nullable — существующий файловый узел, на который коммитится **новая версия** |
| `name` | nullable, max 255, без разделителей пути — переопределяет сохранённое имя |

**Гейт:** новый файл → `canCreateInWorkspace` + `Create` на цели назначения;
новая версия → `Ability::Edit` на целевом узле. **Ответ:** `201` `NodeResource`.

::: tip Большие файлы
Этот эндпоинт читает multipart-тело за один запрос. Для резюмируемых, порезанных на чанки
загрузок используйте [маршруты резюмируемых загрузок](#резюмируемые-загрузки).
:::

#### `PATCH nodes/{node}`

Переименовать узел. → `NodeController::rename`

| Параметр | Правило |
|---|---|
| `name` | required, string, max 255, без разделителей пути |

**Гейт:** `Ability::Edit`. **Ответ:** `NodeResource`.

#### `POST nodes/{node}/move`

Переместить узел под нового родителя. → `NodeController::move`

| Параметр | Правило |
|---|---|
| `parent_id` | nullable (опустить = корень) |

**Гейт:** `Ability::Move` на узле **и** `Create` на цели назначения.
**Ответ:** `NodeResource`.

#### `POST nodes/{node}/copy`

Глубоко скопировать узел (и поддерево папки). → `NodeController::copy`

| Параметр | Правило |
|---|---|
| `parent_id` | nullable (опустить = корень) |
| `name` | nullable, max 255, без разделителей пути — переопределяет имя копии |

**Гейт:** `Ability::View` на источнике **и** `Create` на цели назначения.
**Ответ:** `201` `NodeResource`.

#### `DELETE nodes/{node}`

Отправить в корзину (мягкое удаление) узел и его поддерево. → `NodeController::trash`

**Гейт:** `Ability::Trash`. **Ответ:** `NodeResource` (с установленным `trashed_at`).

#### `POST nodes/{node}/restore`

Восстановить корень корзины (и удалённые вместе с ним узлы). → `NodeController::restore`

| Имя | Где | Примечания |
|---|---|---|
| `destination_id` | body | Необязательно. Папка назначения; нужен `Create` на ней. Без него узел возвращается в папку, из которой был удалён. |

**Гейт:** `Ability::Restore`. **Ответ:** `NodeResource`. Узел, удалённый вместе с папкой, даёт
`409 node_trashed_by_ancestor` (`context.trash_root_id`); исчезнувшая или удалённая исходная
папка без `destination_id` даёт `409 restore_destination_required`; архивный диск —
`409 drive_archived`.

#### `DELETE nodes/{node}/force`

Навсегда удалить узел и его поддерево (отвязывает блобы, освобождает квоту).
→ `NodeController::forceDelete`

**Гейт:** `Ability::Delete`. **Ответ:** `204 No Content`.

### Поиск и корзина

#### `GET storages/{storage}/search`

Поиск по имени внутри дерева хранилища (только живые узлы). → `NodeController::search`

| Параметр | Где | Примечания |
|---|---|---|
| `q` | query | поисковый запрос |
| `per_page`, `cursor` | query | пагинация |

**Гейт:** построчный `Ability::View` (совпадения, которые актор не может видеть,
выбрасываются со страницы). **Ответ:** пагинированная коллекция `NodeResource`.

#### `GET storages/{storage}/trash`

Вид корзины: узлы хранилища в корзине (ещё не вычищенные). → `NodeController::trashed`

**Гейт:** построчный `Ability::Restore`. **Ответ:** пагинированная коллекция
`NodeResource`. См. [Корзина и восстановление](/ru/guide/trash).

### Скачивание

#### `GET nodes/{node}/download`

Стримить текущую head-версию узла. → `DownloadController::current`

#### `GET nodes/{node}/versions/{version}/download`

Стримить конкретную историческую версию файла. → `DownloadController::version`

**Гейт:** `Ability::Download`. **Ответ:** на диске, который умеет выпускать подписанный URL
(S3-стиль), — `302`-редирект на короткоживущий (`5 min`) временный URL; иначе байты
стримятся прямо с драйвера хранилища (никогда не буферизуются в память) с заголовками
`Content-Type` и `Content-Length`.

---

## Документные узлы (Documents)

Документные узлы rich-text/Yjs (`DocumentNodeController`). **Доступно при установленном Documents.** См. [Редактирование](/ru/guide/documents) и
[Версии](/ru/guide/documents).

#### `POST storages/{storage}/documents`

Создать узел `kind=document` с его версией v1. → `store`

| Параметр | Правило |
|---|---|
| `name` | required, string, max 255, без разделителей пути |
| `parent_id` | nullable (опустить = корень) |
| `format` | nullable, одно из зарегистрированных значений `DocumentType` |
| `content` | nullable, array (TipTap doc) |

**Гейт:** `canCreateInWorkspace` + `Create` на цели назначения. **Ответ:**
`201` `NodeResource`.

#### `GET nodes/{node}/content`

Документный узел плюс его текущее head-содержимое. → `content`

**Гейт:** `Ability::View` (и узел должен быть `kind=document`, иначе —
`NotADocumentException`). **Ответ:** `DocumentRevisionResource` со встроенным
содержимым.

#### `PATCH nodes/{node}/content`

Автосохранение содержимого документа (путь дебаунса). → `update`

| Параметр | Правило |
|---|---|
| `content` | required, array (TipTap doc) |
| `yjs_state` | nullable, string (base64 Yjs-бинарь) |
| `state_version` | nullable, integer ≥ 0 — последний прочитанный клиентом `state_version`; проигравшее гонку сохранение отвечает `412 document_state_mismatch` |

**Гейт:** `Ability::Edit`. **Ответ:** `200` `DocumentRevisionResource`
(автосохранение семантически является обновлением, даже когда была дописана новая строка версии).
Ресурс содержит `state_version`; содержимое, которое нельзя сохранить обратно, отвечает
`409 document_content_degraded`.

#### `GET nodes/{node}/document-versions`

История версий документного узла, новейшие сначала. → `versions`

**Гейт:** `Ability::View`. **Ответ:** пагинированный `DocumentRevisionResource`.

#### `POST nodes/{node}/document-versions`

Сделать снапшот текущего head как **именованной** версии. → `storeVersion`

| Параметр | Правило |
|---|---|
| `label` | required, string, max 255 |
| `keep_forever` | nullable, boolean |

**Гейт:** `Ability::Edit`. **Ответ:** `201` `DocumentRevisionResource`.

#### `POST nodes/{node}/document-versions/{version}/restore`

Восстановить предыдущую версию (переводит head на её копию). → `restore`

**Гейт:** `Ability::Edit`. **Ответ:** `201` `DocumentRevisionResource`.

#### `GET nodes/{node}/handler`

Резолвнутый дескриптор обработчика (id + capabilities + ui), чтобы клиент знал, какую
поверхность редактора/превью монтировать. → `handler`

**Гейт:** `Ability::View`. **Ответ:** `{ "data": { … } }`.

#### `GET nodes/{node}/export`

Экспортировать документный узел файлом в запрошенном формате. → `export`

| Параметр | Где | Примечания |
|---|---|---|
| `format` | query | `html` (по умолчанию), `markdown` или любой зарегистрированный формат экспортёра |

**Гейт:** `Ability::Download` + выдача содержимого. **Ответ:** байты экспортёра с `Content-Type`, `Content-Disposition: attachment; filename*`, `Cache-Control: private, no-store` и `X-Content-Type-Options: nosniff` (не JSON); `X-Vaulter-Export-Lossy: 1` помечает содержимое, которое тип смог прочитать лишь частично. Формат, которого нет у типа документа, даёт `422 unknown_export_format` со списком `allowed`. См. [Экспорт](/ru/guide/export).

---

## Резюмируемые загрузки

Порезанные на чанки, резюмируемые загрузки, которые собираются через *тот же* безгоночный
CAS-коммит (`TusProtocolController`, `S3MultipartProtocolController`). **Гейтится фичей `uploads`.**
См. [Загрузки](/ru/guide/uploads).

`{upload_id}` — это УНИКАЛЬНый хэндл сессии на стороне клиента: обычный скаляр, который
резолвит контроллер (и убеждается, что он принадлежит авторизованной цели).

### tus.io

#### `POST storages/{storage}/resumable-uploads`

Создать резюмируемую сессию (tus Creation). → `TusProtocolController::create`

| Параметр | Правило |
|---|---|
| `protocol` | nullable, одно из значений `UploadProtocol` (по умолчанию `tus`) |
| `parent_id` | nullable (опустить = корень) — цель нового файла |
| `node_id` | nullable — существующий файловый узел для **новой версии** |
| `name` | nullable, max 255, без разделителей пути |
| `total_size_bytes` | nullable, integer ≥ 0 |
| `expected_sha256` | nullable, ровно 64 символа (заявляется, но никогда не доверяется как адрес) |
| `mime_type` | nullable, max 191 |
| `metadata` | nullable, array (мержится поверх декодированного заголовка tus `Upload-Metadata`) |

**Гейт:** новый файл → `canCreateInWorkspace` + `Create` под папкой; новая версия →
`Ability::Edit` на узле. **Ответ:** `201` `UploadSessionResource` с заголовками
`Tus-Resumable`, `Location` (URL с `upload_id`) и `Upload-Offset: 0`.

#### `HEAD resumable-uploads/{upload_id}`

Авторитетная точка возобновления на стороне сервера. → `head`

**Ответ:** `204` с `Tus-Resumable`, `Upload-Offset` (точка возобновления) и
`Upload-Length` (когда общий размер известен). Клиент возобновляет от **этого**
смещения, никогда — от собственной догадки.

#### `PATCH resumable-uploads/{upload_id}`

Дописать чанк `application/offset+octet-stream` по заявленному смещению. → `patch`

| Заголовок | Примечания |
|---|---|
| `Upload-Offset` | где начинается этот чанк |

**Ответ:** пока не завершено — `204` с продвинутым `Upload-Offset`; на финальном чанке
байты собираются и коммитятся, а ответ — `201` `NodeResource` (созданный/обновлённый узел).

#### `DELETE resumable-uploads/{upload_id}`

Прервать сессию (staged temp + любой multipart высвобождаются). → `abort`

**Ответ:** `204` с `Tus-Resumable`.

#### `GET resumable-uploads/{upload_id}`

Опросить статус сессии. → `status`

**Ответ** (`UploadSessionResource`):

```json
{
  "data": {
    "upload_id": "…",
    "protocol": "tus",
    "status": "uploading",
    "received_bytes": 8388608,
    "total_size_bytes": 52428800,
    "chunk_size": 8388608,
    "mime_type": "application/pdf",
    "node_id": null,
    "target_parent_id": "01J…",
    "target_name": "report.pdf",
    "assembled_blob_id": null,
    "expires_at": "…"
  }
}
```

::: warning tus можно отключить отдельно
Если `config('vaulter-drive.uploads.resumable.enabled')` равно `false`, tus-маршруты всё равно
монтируются, но каждое tus-действие возвращает **403** — фиче-флаг `uploads` (схема) и
переключатель tus разделены.
:::

### S3-style multipart

#### `POST resumable-uploads/{upload_id}/parts`

Записать (подписать/проксировать) пронумерованную часть; тело — это байты части. Повторная
загрузка того же `part_no` заменяет её. → `S3MultipartProtocolController::parts`

| Параметр | Где | Примечания |
|---|---|---|
| `part_no` | body / заголовок `X-Part-Number` | по умолчанию `1` |
| `byte_offset` | body | где начинается часть |

**Гейт:** новая версия → `Ability::Edit`; новый файл → `canCreateInWorkspace` +
`Create`. **Ответ:** `201` `{ "part_no": …, "size": …, "etag": "…" }`.

#### `POST resumable-uploads/{upload_id}/complete`

Complete-multipart → сборка → коммит (сервер пересчитывает sha256 — ETag никогда не
является контент-адресом). → `complete`

**Ответ:** `201` `NodeResource`.

---

## Доступ: гранты и наследование

Роли, выданные на узел (`vaulter.features.grants`). Каждый маршрут требует `manage` на
узле. См. [Гранты](/ru/guide/grants).

#### `GET v1/nodes/{node}/grants` — `grants.index`

Гранты, выданные прямо на узел. **Ответ:** `200 { "data": [Grant, …] }` (грантов одного
узла немного, список без страниц).

#### `POST v1/nodes/{node}/grants` — `grants.store`

| Поле | Правило |
|---|---|
| `subject.type` | обязательно, тип субъекта, который драйвер прав умеет перечислять |
| `subject.id` | обязательно |
| `role` | обязательно: `previewer`, `reader`, `commenter`, `writer`, `organizer`, `owner` |
| `scope` | `subtree` (по умолчанию) или `item` |
| `expires_at` | необязательно, дата в будущем |

**Ответ:** `201` Grant:

```json
{
  "data": {
    "id": "01J…",
    "node_id": "01J…",
    "subject": { "type": "user", "id": "5" },
    "role": "writer",
    "role_version": 1,
    "scope": "subtree",
    "expires_at": null,
    "created_by": "1",
    "created_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

#### `DELETE v1/nodes/{node}/grants/{grant}` — `grants.destroy`

Отозвать один грант. **Ответ:** `204`.

#### `POST v1/nodes/{node}/inheritance/restrict` — `access.inheritance.restrict`

Перестать применять гранты сверху к узлу и его поддереву. `mode`: `empty` (по умолчанию —
у узла остаются только свои гранты) или `copy_current` (узел сохраняет копии
унаследованных грантов). **Ответ:** `200` Node с новым `ETag`. Если изменение сняло ваш
собственный доступ, узел возвращается со всеми способностями `false`.

#### `POST v1/nodes/{node}/inheritance/restore` — `access.inheritance.restore`

Снова применять гранты сверху; собственные гранты узла остаются. **Ответ:** `200` Node.

---

## Ссылки (shares)

Bearer-ссылки на узел (`vaulter.features.share_links`). Ссылка даёт ровно способности
своей роли на своём наборе узлов; на публичных маршрутах гранты пользователей не
участвуют. См. [Публичные ссылки](/ru/guide/sharing).

### Маршруты владельца

Каждый маршрут владельца требует `share` на узле.

#### `GET v1/nodes/{node}/shares` — `shares.index`

Действующие ссылки узла. **Ответ:** `200 { "data": [Share, …] }`. Токен в списке не
показывается.

#### `POST v1/nodes/{node}/shares` — `shares.store`

| Поле | Правило |
|---|---|
| `role` | `previewer`, `reader` (по умолчанию), `commenter`, `writer`; `organizer`/`owner` → `422 share_role_not_allowed` |
| `audience` | `anyone` (по умолчанию), `authenticated`, `tenant`; для `writer` — только `authenticated` или `tenant` |
| `scope` | `item` (по умолчанию) или `subtree` |
| `password` | необязательно, 1–255 символов |
| `expires_at` | необязательно, в будущем, в пределах `vaulter.sharing` и профиля (`422 share_link_ttl_exceeded`) |
| `max_uses` | необязательно, ≥ 1 успешного визита |

**Ответ:** `201` — ссылка плюс `token` и `url`, **один раз**:

```json
{
  "data": {
    "id": "01J…",
    "node_id": "01J…",
    "role": "reader",
    "role_version": 1,
    "audience": "anyone",
    "scope": "item",
    "expires_at": "2026-10-13T12:00:00.000000Z",
    "max_uses": null,
    "use_count": 0,
    "has_password": false,
    "token_generation": 1,
    "created_by": "1",
    "created_at": "2026-10-10T12:00:00.000000Z",
    "token": "q3V…",
    "url": "https://app.example/share/q3V…"
  }
}
```

#### `PATCH v1/nodes/{node}/shares/{share}` — `shares.update`

Изменить срок, лимит визитов, пароль или аудиторию; токен и роль не меняются.
Отсутствующее поле остаётся как есть; `password: null` / `max_uses: null` их снимают.
**Ответ:** `200` Share (без токена).

#### `POST v1/nodes/{node}/shares/{share}/rotate` — `shares.rotate`

Заменить токен: старый токен и его открытые визиты перестают работать сразу,
`token_generation` растёт. **Ответ:** `200` Share с новыми `token` и `url`, один раз.

#### `DELETE v1/nodes/{node}/shares/{share}` — `shares.destroy`

Отозвать ссылку. **Ответ:** `204`.

### Публичные маршруты

Стек `public`: без собственной аутентификации, `throttle:vaulter-public`, затем
`ResolveShareToken`, который проверяет токен, аудиторию и пароль и засчитывает визит.
Первый ответ визита возвращает заголовок `X-Share-Session`; если отправлять его обратно,
остальные запросы визита не тратят новых использований. Пароль передаётся в
`X-Share-Password`. Каждый ответ — `private, no-store` с `Referrer-Policy: no-referrer`.

Отказы: неизвестный, отозванный, заменённый, истёкший или исчерпанный токен, посетитель
вне арендатора ссылки и узел вне ссылки — все дают `404 share_not_found`; нет пароля или
он неверен — `401 share_password_required`; ссылка `authenticated` без вошедшего
посетителя — `401 actor_required`.

Узлы называются непрозрачным id (никогда настоящим id узла); `?node=` принимает только сам
узел ссылки или одного из детей, которых показывает `nodes`.

#### `GET v1/shares/{token}` — `public-shares.show`

Узел ссылки: `id` (непрозрачный), `kind`, `name`, `mime`, `extension`, `size_bytes`,
`parent_id` (непрозрачный), `created_at`, `updated_at`.

#### `GET v1/shares/{token}/nodes` — `public-shares.nodes`

Дети папки ссылки по имени, страницей. Ссылка `item` ничего не перечисляет
(`404 share_not_found`).

#### `GET v1/shares/{token}/download?node=` — `public-shares.download`

Байты файла ссылки или перечисленного ребёнка через `?node=`. Нужна роль с `download` —
ссылка previewer получает `403 permission_denied`. Всегда потоком, с заголовками
[выдачи файлов](#выдача-файлов); `423 content_unavailable` до любого байта, если файл не
чистый.

---

## Активность

Что произошло в drive или на узле, сначала новое, с фильтром для читателя
(`vaulter.features.activity`).

#### `GET v1/drives/{drive}/activity` — `activity.drive`

Нужен `view` на корне drive. **Ответ:** страница Activity.

#### `GET v1/nodes/{node}/activity` — `activity.node`

Нужен `view` на узле. **Ответ:** страница Activity:

```json
{
  "data": [
    {
      "id": "01J…",
      "type": "node.renamed",
      "node_id": "01J…",
      "actor": { "type": "user", "id": "1" },
      "occurred_at": "2026-10-10T12:00:00.000000Z",
      "data": { "correlation_id": "01J…" }
    }
  ],
  "meta": { "next_cursor": null, "per_page": 50 }
}
```

`node_id` заполнен, если запись про узел; `actor` — `null`, когда проекция его скрывает.

---

## Журнал изменений

Следить за drive инкрементально вместо повторного обхода (`vaulter.features.changes`;
выключен → `403 profile_restriction`). См. [Изменения](/ru/guide/changes).

#### `GET v1/drives/{drive}/changes/start` — `changes.start`

**Ответ:** `200 { "data": { "cursor": "…" } }` — берите его сразу после полного обхода.

#### `GET v1/drives/{drive}/changes?cursor=&limit=` — `changes.index`

`cursor` обязателен; `limit` — 1–`vaulter.changes.max_page_size`, по умолчанию 100.
**Ответ:**

```json
{
  "data": [
    {
      "node_id": "01J…",
      "operation": "upsert",
      "reason": null,
      "subtree": false,
      "node_sequence": 4,
      "node": { "id": "01J…", "etag": "\"01J….4.9f86d081884c7d65\"", "…": "…" }
    }
  ],
  "meta": { "next_cursor": "…", "has_more": false }
}
```

Применяйте изменения по порядку (для узла побеждает последнее); `remove` (с `reason`:
`deleted`, `moved_out`, `permission_lost`) приходит с `node: null`, а с `subtree: true`
удаляет и всё ниже. Сохраняйте `next_cursor` — в отличие от списков он есть всегда — и,
пока `has_more`, сразу запрашивайте снова. `400 invalid_cursor` — курсор другого drive
или повреждённый; `410 cursor_expired` — записи после него удалены: обойдите drive заново.

---

## Webhook-эндпоинты

Глобальная админ-поверхность подписчиков вебхуков, подключается при
`vaulter.features.webhooks`. Каждый маршрут, включая чтение, проходит
`WebhookEndpointPolicy` (`vaulter.webhooks.manage_ability`) до поиска по id. См.
[Вебхуки](/ru/guide/webhooks).

| Метод и путь | Имя | Ответ |
|---|---|---|
| `GET v1/webhook-endpoints` | `webhook-endpoints.index` | страница Endpoint |
| `POST v1/webhook-endpoints` | `webhook-endpoints.store` | `201` Endpoint + `secret` (один раз) |
| `GET v1/webhook-endpoints/{endpoint}` | `webhook-endpoints.show` | Endpoint |
| `PATCH v1/webhook-endpoints/{endpoint}` | `webhook-endpoints.update` | Endpoint |
| `POST v1/webhook-endpoints/{endpoint}/deactivate` | `webhook-endpoints.deactivate` | Endpoint |
| `POST v1/webhook-endpoints/{endpoint}/rotate-secret` | `webhook-endpoints.rotate-secret` | Endpoint + новый `secret` (один раз) |
| `DELETE v1/webhook-endpoints/{endpoint}` | `webhook-endpoints.destroy` | `204` |
| `GET v1/webhook-endpoints/{endpoint}/deliveries` | `webhook-deliveries.index` | страница Delivery |
| `POST v1/webhook-endpoints/{endpoint}/deliveries/{delivery}/redeliver` | `webhook-deliveries.redeliver` | `202` Delivery |

- **Регистрация / изменение** принимают `url` (http(s); адрес, ведущий в частную сеть,
  отклоняется), `events` (типы событий), `is_active`, `drive_id` (события только этого drive).
- **Ротация секрета** принимает `overlap_seconds` (≥ 0, с ограничением): прежний секрет
  ещё столько же проверяет подписи.
- **Endpoint:** `id`, `url`, `drive_id`, `events`, `status` (`active` или `disabled`),
  `disabled_at`, `created_at`.
- **Delivery:** `id`, `event_id`, `event_type`, `status` (`pending`, `failed`, `delivered`,
  `dead`), `attempts`, `next_retry_at`, `last_status_code` (последний HTTP-статус эндпоинта
  или `null`), `created_at`.
- **Повторная доставка** — для `delivered` или `dead` при активном эндпоинте; иначе
  `409 webhook_delivery_not_redeliverable`. Неизвестные id дают
  `404 webhook_endpoint_not_found` / `404 webhook_delivery_not_found`.

---

## Совместное редактирование в реальном времени

Совместное редактирование TipTap/Yjs поверх документных узлов
(`Drive\NodeCollaborationController`). **Включается через `vaulter-documents.collaboration.enabled`.**
См. [Совместное редактирование](/ru/guide/collaboration).

### Клиентский handshake

Монтируется в группе Drive `SubstituteBindings` (`{node}` привязан через route-model binding).

#### `POST nodes/{node}/collab/token`

Выпустить транспорт-агностичную сессию совместного редактирования для документного узла.
→ `token`

**Гейт:** актору нужно как минимум `View`/`Comment`, чтобы подключиться; сессия
read-only, если у него нет `Edit`. **Ответ:** `{ "data": { /* CollaborationSession */ } }`.

### Server-to-server вебхуки

Поверхность hub-callback — **без auth-middleware**, только `ResolveCollaborationCall`,
который аутентифицирует вызывающего через общий webhook-секрет **или** валидный подписанный
room-JWT и привязывает вызов к его узлу (JWT для узла A не может писать в узел B).
Узел резолвится из **тела** (`room` / `node_id`).

#### `POST collab/authenticate`

Хук хаба `onAuthenticate`. → `authenticate`

**Ответ:** `{ "allowed": bool, "readOnly": bool, "user": { "id": … } | null }`.
`allowed=false` (не 403), когда у пользователя нет view-доступа, чтобы хаб мог чисто
отказать сокету. `readOnly` равно `false` только при праве `Edit` на содержимое, которое можно
сохранить обратно: неустановленный тип, более новая схема или частичное чтение — только чтение.

#### `POST collab/store`

Хук хаба `onStoreDocument` — сохранить дебаунснутый Yjs-снапшот. → `store`

| Параметр | Где | Примечания |
|---|---|---|
| `node_id` *или* `room` | body | целевой узел (room нормализуется обратно через префикс канала) |
| `yjs_state` | body | required, base64 Yjs-бинарь (источник истины) |
| `content` / `tiptap` | body | опциональная JSON-проекция, декодируется типом документа (`422 invalid_document_content`, если тип её отвергает) |
| `user_id` | body | атрибуция (для вызова по общему секрету; вызов по JWT использует свой `sub`) |

Вызову должно быть разрешено писать (`mayStore()`), иначе — `403`. На заблокированном документе
неустановленный тип отвечает `409 document_type_unavailable`, а содержимое, которое нельзя
сохранить обратно, — `409 document_content_degraded`; ни Yjs-состояние, ни проекция не меняются.
Без проекции JSON сохраняет свою версию схемы. **Ответ:**
`{ "data": { "node_id": …, "version_no": …, "yjs_state_size": … } }`.

---

## Легаси-API документов

До-Drive поверхность документов (`DocumentController` и компания) остаётся смонтированной
для обратной совместимости. Новым интеграциям стоит предпочесть маршруты
[Drive](#drive) и [документных узлов](#документные-узлы-documents) выше.

### Документы

| Метод и путь | Действие | Параметры |
|---|---|---|
| `GET documents` | `index` | `?q=` поиск, `workspace_type`, `workspace_id` |
| `GET documents/trash` | `trash` | список корзины |
| `POST documents/bulk` | (`BulkDocumentController`) | `action` (`delete`/`restore`/`move`/`force-delete`), `ids[]`, `folder_id` |
| `POST documents` | `store` | `workspace_type`, `workspace_id`, `type`, `title`, `content`, `folder_id?`, `meta?` |
| `GET documents/{id}` | `show` | |
| `PATCH documents/{id}` | `update` | |
| `DELETE documents/{id}` | `destroy` | мягкое удаление |
| `DELETE documents/{id}/force` | `forceDestroy` | навсегда |
| `POST documents/{id}/restore` | `restore` | |
| `PATCH documents/{id}/move` | `move` | `folder_id` (null = корень) |
| `POST documents/{id}/duplicate` | `duplicate` | |
| `GET documents/{documentId}/export` | (`ExportController::show`) | `?format=` |

### Версии

| Метод и путь | Действие | Параметры |
|---|---|---|
| `GET documents/{documentId}/versions` | `index` | |
| `POST documents/{documentId}/versions` | `store` | `snapshot_label?` |
| `POST documents/{documentId}/versions/{versionId}/restore` | `restore` | |

### Разрешения

| Метод и путь | Действие | Параметры |
|---|---|---|
| `GET documents/{documentId}/permissions` | `index` | |
| `POST documents/{documentId}/permissions` | `store` | `subject_type`, `subject_id`, `permission` (числовое разрешение legacy-документа) |
| `DELETE documents/{documentId}/permissions/{permissionId}` | `destroy` | |

### Комментарии

| Метод и путь | Действие | Параметры |
|---|---|---|
| `GET documents/{documentId}/comments` | `index` | |
| `POST documents/{documentId}/comments` | `store` | `body`, `parent_id?`, `anchor?` |
| `POST documents/{documentId}/comments/{commentId}/resolve` | `resolve` | |
| `DELETE documents/{documentId}/comments/{commentId}` | `destroy` | |

См. [Комментарии](/ru/guide/comments).

### Легаси-публичные-ссылки на документы

| Метод и путь | Действие | Параметры |
|---|---|---|
| `GET documents/{documentId}/shares` | `index` | |
| `POST documents/{documentId}/shares` | `store` | `permission` (`view`/`comment`/`edit`), `expires_at?` |
| `DELETE documents/{documentId}/shares/{shareId}` | `destroy` | |
| `GET shares/{token}` | `resolve` | публичный резолв токена |

### Легаси-совместное-редактирование

| Метод и путь | Действие |
|---|---|
| `POST documents/{documentId}/collab/token` | `CollaborationController::token` |
| `POST documents/{documentId}/collab/sync` | `CollaborationController::sync` |

### Легаси-папки

| Метод и путь | Действие | Параметры |
|---|---|---|
| `GET folders` | `index` | `workspace_type`, `workspace_id` |
| `POST folders` | `store` | `workspace_type`, `workspace_id`, `name`, `parent_id?`, `order?` |
| `PATCH folders/{id}` | `update` | переименование / перемещение |
| `DELETE folders/{id}` | `destroy` | |

## Смотрите также

- [Drive](/ru/guide/drive) — хэндл `DriveHandle`, который оборачивают маршруты Drive
- [Корзина и восстановление](/ru/guide/trash) — жизненный цикл маршрутов корзины
- [Загрузки](/ru/guide/uploads) · [Публичные ссылки](/ru/guide/sharing) · [Совместное редактирование](/ru/guide/collaboration)
- [Справочник событий](/ru/reference/events) · [Справочник enum'ов](/ru/reference/contracts)
