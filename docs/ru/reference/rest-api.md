---
title: Справочник REST API
description: Все REST-маршруты Vaulter — Drive, публичные ссылки, резюмируемые загрузки, документные узлы, совместное редактирование и легаси-API документов — с методом, путём, параметрами, гейтами и ответом.
outline: deep
editLink: false
---

# Справочник REST API

Это исчерпывающий, маршрут-за-маршрутом справочник по HTTP-поверхности Vaulter,
сгенерированный по `packages/core/routes/api.php`. Каждый маршрут монтируется под
настроенным префиксом и middleware:

```php
// config/vaulter.php
'route_prefix' => 'api/vaulter',   // Config::routePrefix()
'middleware'   => ['api'],         // Config::middleware()
```

Так что путь, показанный как `storages/{storage}`, разворачивается в
`/api/vaulter/storages/{storage}`.

## Соглашения

- **`{storage}` / `{node}`** привязаны через **route-model binding** к сменяемым
  `config('vaulter.models.storage')` / `config('vaulter.models.node')`. Каждый маршрут Drive
  вдобавок явно применяет `SubstituteBindings`, так что привязка работает, даже если хост
  подрезает свою группу `api`.
- **`{upload_id}`, `{share}`, `{endpoint}`, `{token}`, `{version}`** — обычные
  скаляры (не привязаны к моделям): контроллер сам их резолвит и убеждается, что они
  принадлежат авторизованной цели.
- **Авторизация** на поверхности Drive — это [`AccessResolver`](/ru/concepts/authorization):
  каждый эндпоинт проверяет [`Ability`](/ru/reference/contracts) на узле (или `canCreateInWorkspace`
  на цели назначения). Отказ — или неаутентифицированный запрос — возвращает **403**
  (`PermissionDeniedException`). Списки фильтруются **по каждому узлу**, поэтому более
  близкий запрет убирает строку.
- **Актор** — это аутентифицированный `request->user()` (его auth-идентификатор становится
  атрибутированным `actorId`).
- **Списки** пагинируются по курсору; передавайте `?per_page=` (по умолчанию 50, зажимается в 1–200)
  и `?cursor=`.
- Ответы — это Laravel API resources, обёрнутые в верхнеуровневый ключ `data`.

### Фиче-гейты

Некоторые группы маршрутов монтируются, только если включён соответствующий фиче-флаг в
`config('vaulter.features.*')` (`Config::featureEnabled()`):

| Фиче-флаг | Что гейтит |
|---|---|
| `document_versions` | маршруты документных узлов + handshake совместного редактирования + collab-вебхуки |
| `uploads` | маршруты резюмируемых загрузок (tus + S3-multipart) |
| `reliability` | маршруты управления webhook-эндпоинтами |

---

## Drive

Единое дерево файлов/папок `v_nodes` (`StorageController`, `NodeController`,
`DownloadController`). Эндпоинты уровня коллекции хранилища несут `{storage}` в пути;
эндпоинты уровня узла выводят хранилище из привязанного `{node}`.

### Хранилища

#### `GET storages/{storage}`

Прочитать метаданные контейнера хранилища, корневой узел и использование. → `StorageController::show`

**Ответ** (`StorageResource`):

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
    "is_pinned": false,
    "position": 0,
    "trashed_at": null,
    "created_at": "…",
    "updated_at": "…",
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
| `file` | required, file, `max:` `config('vaulter.drive.max_upload_kb')`; если `config('vaulter.drive.allowed_mime')` непуст, ограничен этим набором `mimetypes` |
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

Восстановить узел из корзины. → `NodeController::restore`

**Гейт:** `Ability::Restore`. **Ответ:** `NodeResource`.

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

Документные узлы rich-text/Yjs (`DocumentNodeController`). **Гейтится фичей
`document_versions`.** См. [Редактирование](/ru/guide/documents) и
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
`NotADocumentNodeException`). **Ответ:** `NodeDocumentVersionResource` со встроенным
содержимым.

#### `PATCH nodes/{node}/content`

Автосохранение содержимого документа (путь дебаунса). → `update`

| Параметр | Правило |
|---|---|
| `content` | required, array (TipTap doc) |
| `yjs_state` | nullable, string (base64 Yjs-бинарь) |

**Гейт:** `Ability::Edit`. **Ответ:** `200` `NodeDocumentVersionResource`
(автосохранение семантически является обновлением, даже когда была дописана новая строка версии).

#### `GET nodes/{node}/document-versions`

История версий документного узла, новейшие сначала. → `versions`

**Гейт:** `Ability::View`. **Ответ:** пагинированный `NodeDocumentVersionResource`.

#### `POST nodes/{node}/document-versions`

Сделать снапшот текущего head как **именованной** версии. → `storeVersion`

| Параметр | Правило |
|---|---|
| `label` | required, string, max 255 |
| `keep_forever` | nullable, boolean |

**Гейт:** `Ability::Edit`. **Ответ:** `201` `NodeDocumentVersionResource`.

#### `POST nodes/{node}/document-versions/{version}/restore`

Восстановить предыдущую версию (переводит head на её копию). → `restore`

**Гейт:** `Ability::Edit`. **Ответ:** `201` `NodeDocumentVersionResource`.

#### `GET nodes/{node}/handler`

Резолвнутый дескриптор обработчика (id + capabilities + ui), чтобы клиент знал, какую
поверхность редактора/превью монтировать. → `handler`

**Гейт:** `Ability::View`. **Ответ:** `{ "data": { … } }`.

#### `GET nodes/{node}/export`

Экспортировать head-содержимое документного узла в запрошенном формате. → `export`

| Параметр | Где | Примечания |
|---|---|---|
| `format` | query | `html` (по умолчанию), `markdown` или любой зарегистрированный формат экспортёра |

**Гейт:** `Ability::View`. **Ответ:** `{ "data": { … } }`. См. [Экспорт](/ru/guide/export).

---

## Резюмируемые загрузки

Порезанные на чанки, резюмируемые загрузки, которые собираются через *тот же* безгоночный
CAS-коммит (`TusUploadController`, `S3MultipartController`). **Гейтится фичей `uploads`.**
См. [Загрузки](/ru/guide/uploads).

`{upload_id}` — это УНИКАЛЬНый хэндл сессии на стороне клиента: обычный скаляр, который
резолвит контроллер (и убеждается, что он принадлежит авторизованной цели).

### tus.io

#### `POST storages/{storage}/resumable-uploads`

Создать резюмируемую сессию (tus Creation). → `TusUploadController::create`

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
Если `config('vaulter.uploads.tus.enabled')` равно `false`, tus-маршруты всё равно
монтируются, но каждое tus-действие возвращает **403** — фиче-флаг `uploads` (схема) и
переключатель tus разделены.
:::

### S3-style multipart

#### `POST resumable-uploads/{upload_id}/parts`

Записать (подписать/проксировать) пронумерованную часть; тело — это байты части. Повторная
загрузка того же `part_no` заменяет её. → `S3MultipartController::parts`

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

## Публичные ссылки (bearer-ссылки на узлы)

Канал bearer-ссылок (`Sharing\ShareController` для владельцев,
`Sharing\PublicShareController` для публики). Публичная ссылка даёт **ровно свой битмаск**
на своём наборе узлов — на публичном пути identity-ACL никогда не запрашивается.
См. [Публичные ссылки](/ru/guide/sharing).

### Поверхность владельца (аутентифицированная)

Монтируется в группе Drive `SubstituteBindings`, так что `{node}` привязан через
route-model binding. Каждое действие требует `Ability::Share` на узле.

#### `GET nodes/{node}/shares`

Список публичных ссылок на узле (сырой токен никогда не раскрывается). → `index`

**Ответ:** пагинированный `NodeShareResource`.

#### `POST nodes/{node}/shares`

Выпустить публичную ссылку. → `store`

| Параметр | Правило |
|---|---|
| `permissions` | nullable, integer `0..31` (битмаск CRUDS) |
| `level` | nullable, значение `GrantLevel` |
| `scope` | nullable, значение `GrantScope` (`item` по умолчанию, или `subtree`) |
| `password` | nullable, string 1–255 |
| `require_login` | nullable, boolean |
| `allow_discovery` | nullable, boolean |
| `expires_at` | nullable, date, позже текущего момента |
| `max_uses` | nullable, integer ≥ 1 |

Если `permissions` опущен, он по умолчанию равен битам выбранного `level`, иначе — просто
`READ`. **Ответ:** `201` `NodeShareResource` — **сырой `token` и полный `url`
возвращаются здесь ровно один раз** (любой list/show их опускает):

```json
{
  "data": {
    "id": "01J…",
    "node_id": "01J…",
    "token_prefix": "abc12345",
    "permissions": 1,
    "level": "reader",
    "scope": "item",
    "has_password": false,
    "require_login": false,
    "allow_discovery": false,
    "expires_at": null,
    "max_uses": null,
    "use_count": 0,
    "revoked_at": null,
    "created_at": "…",
    "token": "abc12345…rawtokenonce",
    "url": "/share/abc12345…rawtokenonce"
  }
}
```

#### `DELETE nodes/{node}/shares/{share}`

Отозвать публичную ссылку. Id `{share}` должен принадлежать авторизованному узлу (чужой id
даёт `404`). → `destroy`

**Ответ:** `204 No Content`.

### Публичная поверхность (резолвится по токену)

Эти маршруты **не несут auth-middleware** — только `ResolveShareToken`, который хеширует
`{token}`, резолвит живую публичную ссылку, прогоняет timing-safe сравнение + гейты
пароля / require-login и складывает `ShareContext`. Авторизация — это **только** собственный
битмаск ссылки.

#### `GET share/{token}`

Метаданные целевого узла в пределах scope. Папка с `scope=subtree` встраивает своих
непосредственных потомков. → `PublicShareController::show`

**Требует:** бит `View`. **Ответ:** `SharedNodeResource`.

#### `GET share/{token}/nodes`

Список in-scope потомков — **только** для `scope=subtree` (у ссылки со scope=item нет
списка → `404`). → `nodes`

**Требует:** бит `View`. **Ответ:** коллекция `SharedNodeResource`.

#### `GET share/{token}/download`

Стримить текущий head расшаренного файла в пределах бита `Download`, пересечённого с
item-safety (блоб должен быть `Committed`). Атомарно потребляет одно использование
(ограничено `max_uses`) перед стримингом. → `download`

**Требует:** бит `Download`. **Ответ:** `302` на подписанный URL или стримингованное
скачивание (как для [аутентифицированного скачивания](#скачивание)).

---

## Гранты ACL на узел

Управление грантами уровня узла (`Drive\GrantController`). `{node}` привязан через
route-model binding; каждое действие требует `Ability::Share` на узле. См.
[ACL панели](/ru/guide/grants).

#### `GET nodes/{node}/grants`

Список грантов на узле. → `index`

**Ответ:** пагинированный `NodeGrantResource`.

#### `POST nodes/{node}/grants`

Выдать доступ субъекту. → `store`

| Параметр | Правило |
|---|---|
| `subject_type` | required, string, зарегистрированный тип субъекта |
| `subject_id` | required |
| `level` | required, значение `GrantLevel` |
| `effect` | nullable, значение `Effect` (`allow` по умолчанию) |
| `scope` | nullable, значение `GrantScope` (`subtree` по умолчанию) |
| `permissions` | nullable, integer `0..31` |
| `expires_at` | nullable, date |

**Ответ:** `201` `NodeGrantResource`:

```json
{
  "data": {
    "id": "01J…",
    "node_id": "01J…",
    "subject_type": "App\\Models\\User",
    "subject_id": 5,
    "level": "writer",
    "permissions": 15,
    "effect": "allow",
    "scope": "subtree",
    "expires_at": null,
    "created_at": "…"
  }
}
```

#### `DELETE nodes/{node}/grants`

Отозвать грант субъекта. → `destroy`

| Параметр | Правило |
|---|---|
| `subject_type` | required |
| `subject_id` | required |

**Ответ:** `204 No Content`.

---

## Лента активности

Append-only лента активности (`Activity\ActivityController`). Получатели резолвятся на
ЧТЕНИИ через привязку к хранилищу/узлу — строк-уведомлений нет.

#### `GET storages/{storage}/activity`

Лента активности по всему хранилищу. → `forStorage`. **Гейт:** `Ability::View` на
корне хранилища.

#### `GET nodes/{node}/activity`

Лента активности одного узла. → `forNode`. **Гейт:** `Ability::View` на узле.

Обе возвращают пагинированную коллекцию `ActivityResource`.

---

## Совместное редактирование в реальном времени

Совместное редактирование TipTap/Yjs поверх документных узлов
(`Drive\NodeCollaborationController`). **Гейтится фичей `document_versions`.**
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
отказать сокету. `readOnly` равно `false` только при праве `Edit`.

#### `POST collab/store`

Хук хаба `onStoreDocument` — сохранить дебаунснутый Yjs-снапшот. → `store`

| Параметр | Где | Примечания |
|---|---|---|
| `node_id` *или* `room` | body | целевой узел (room нормализуется обратно через префикс канала) |
| `yjs_state` | body | required, base64 Yjs-бинарь (источник истины) |
| `content` / `tiptap` | body | опциональная TipTap-проекция |
| `user_id` | body | атрибуция (для вызова по общему секрету; вызов по JWT использует свой `sub`) |

Вызову должно быть разрешено писать (`mayStore()`), иначе — `403`. **Ответ:**
`{ "data": { "node_id": …, "version_no": …, "yjs_state_size": … } }`.

---

## Управление webhook-эндпоинтами

Глобальная админ-поверхность для эндпоинтов-подписчиков
(`Webhooks\WebhookEndpointsController`). **Гейтится фичей `reliability`.**
Каждое действие гейтится manage-правом `WebhookEndpointPolicy`. См.
[Надёжность и вебхуки](/ru/guide/recipes).

| Метод и путь | Действие | Примечания |
|---|---|---|
| `GET webhook-endpoints` | `index` | список эндпоинтов |
| `POST webhook-endpoints` | `store` | зарегистрировать — возвращает `signing_secret` **один раз** |
| `PATCH webhook-endpoints/{endpoint}` | `update` | обновить |
| `POST webhook-endpoints/{endpoint}/deactivate` | `deactivate` | выключить |
| `DELETE webhook-endpoints/{endpoint}` | `destroy` | удалить |

`{endpoint}` — обычный скаляр, резолвится через read-репозиторий.

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
| `POST documents/{documentId}/permissions` | `store` | `subject_type`, `subject_id`, `permission` (значение `PermissionLevel`) |
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

- [Drive](/ru/guide/drive) — хэндл `DriveContext`, который оборачивают маршруты Drive
- [Корзина и восстановление](/ru/guide/trash) — жизненный цикл маршрутов корзины
- [Загрузки](/ru/guide/uploads) · [Публичные ссылки](/ru/guide/sharing) · [Совместное редактирование](/ru/guide/collaboration)
- [Справочник событий](/ru/reference/events) · [Справочник enum'ов](/ru/reference/contracts)
