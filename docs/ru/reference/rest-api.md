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
| Параметры | `{node}`, `{drive}`, `{revision}`, `{upload}`, `{grant}`, `{share}`, `{endpoint}`, `{delivery}`, `{token}` — простые id (`[A-Za-z0-9_-]+`); привязки моделей к маршрутам нет |

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
- Маршруты уровня drive (поиск, звёздочки, корзина, её очистка, журнал изменений) не
  отклоняются, а фильтруются до видимого вам: тот, кому дали доступ только к папке глубоко
  внутри, находит то, что в ней. Drive, где вы не видите ни одного узла, отвечает
  `404 drive_not_found`, как неизвестный.

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
- Маршруты с пометкой **Idempotency-Key** ниже принимают этот заголовок
  ([идемпотентность](/ru/concepts/idempotency), [ADR-0029](/adr/0029-idempotency)): одна Structured
  Field String (`"…"`) или один токен без пробелов, управляющих символов и запятых, 1–255 байт,
  сравнивается точно. Тот же ключ с тем же запросом (метод, маршрут, параметры, query, тело и
  `If-Match`) в течение 24 часов возвращает сохранённый ответ с `Idempotent-Replayed: true` — после
  новой проверки прав, поэтому вызывающий без доступа получает текущий `404`/`403`. Другой запрос
  под тем же ключом → `422 idempotency_key_reused`; первый запрос ещё выполняется → `409
  operation_in_progress` с `Retry-After`; некорректный заголовок → `422 validation_failed` с
  `errors["header:Idempotency-Key"]`. Остальные маршруты заголовок игнорируют.

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
ревизию. Режим задаёт `vaulter-drive.downloads.delivery_mode`:

- **`strict`** (по умолчанию) — приложение отдаёт байты потоком с
  `Content-Disposition: attachment; filename="…"; filename*=UTF-8''…` (RFC 6266; управляющие
  символы и разделители путей из имени удаляются), `X-Content-Type-Options: nosniff`,
  `Content-Security-Policy: sandbox; default-src 'none'`, `Cache-Control: private, no-store`,
  `Accept-Ranges: none` и `Content-Length`. Заявленный тип сохраняется только для списка
  безопасных inline-типов, иначе `application/octet-stream`.
- **`direct_signed`** (по выбору) — после тех же проверок `302` на URL, подписанный вашим
  `DirectContentUrlSigner` для этой ревизии, действующий `vaulter-drive.downloads.direct_ttl_seconds`
  (1–300), с `Cache-Control: private, no-store` и `Referrer-Policy: no-referrer`.

Ревизия не чистая (ожидает обязательной проверки, заражена) → `423 content_unavailable` до
любого байта или URL. Кто видит файл, но не может его скачать (previewer), получает
`403 permission_denied`. Диапазоны байтов в 1.0 не поддерживаются.

Отзыв ограничен и задокументирован, а не мгновенен: в `strict` проверяется каждый новый
запрос, но уже начатый поток не обрывается; в `direct_signed` уже выданный URL действует
до истечения — отзыв доступа его не отменяет. Скачивания по публичным ссылкам всегда
идут потоком.

---

## Пути без версии

Под `/{routes.prefix}` без `/v1` больше ничего не обслуживается. Прежних путей
(`storages/*`, `nodes/{node}` без `/v1`, `resumable-uploads/*`, `nodes/{node}/content`,
`nodes/{node}/document-versions`, `collab/*`, …) нет: под префиксом v1 неизвестный путь
отвечает `404 route_not_found` в виде problem+json, вне его — обычным 404 хоста. Какие маршруты
куда переехали — в [таблице обновления](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md).

## Drive

Drive, дерево узлов, содержимое файлов и корзина (пакет `vaulter-drive`). Маршруты
регистрируются всегда, пока пакет установлен; функцию, выключенную глобально или профилем
drive, отклоняет операция — `403 profile_restriction` на том, что вам видно, — поэтому
`route:cache` никогда не замораживает флаг.

Операции над любым видом узла живут под `nodes/{node}`, операции над содержимым файла — под
`files/{node}` (над содержимым документа — под `documents/{node}`). Узел другого вида на
маршруте содержимого отвечает `422 not_a_file`.

### Ресурс Drive

```json
{
  "data": {
    "id": "01J…",
    "owner": { "type": "App\\Models\\Project", "id": "42" },
    "profile": "default",
    "name": "Project files",
    "root_node_id": "01J…",
    "status": "active",
    "quota_bytes": null,
    "used_bytes": 10485760,
    "created_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

`status` — `active`, `archived` или `deleting`. Что вам можно делать в drive — это
`abilities` его корневого узла (`GET v1/nodes/{root_node_id}`).

#### `GET v1/drives/{drive}` — `drives.show`

Нужен `view` на корне. Drive, который вам не виден, чужого арендатора, удаляемый или
неизвестный → `404 drive_not_found`.

#### `POST v1/drives/{drive}/archive` — `drives.archive`

#### `POST v1/drives/{drive}/unarchive` — `drives.unarchive`

#### `DELETE v1/drives/{drive}` — `drives.destroy`

Жизненный цикл, `manage` на корне. Архивный drive только для чтения: каждая мутация
отвечает `409 drive_archived`, пока его не вернут из архива. Удаление запускает
асинхронную очистку и отвечает drive со `status: "deleting"`; с этого момента он `404` для
всех. **Ответ:** `200` Drive.

### Ресурс узла

```json
{
  "data": {
    "id": "01J…",
    "drive_id": "01J…",
    "parent_id": "01J…",
    "kind": "file",
    "name": "contract.pdf",
    "mime": "application/pdf",
    "extension": "pdf",
    "size_bytes": 1024,
    "sequence": 7,
    "etag": "\"01J….7.3f2a9c1d0b4e5a67\"",
    "inherits_access": true,
    "properties": {},
    "created_at": "…", "created_by": "1",
    "updated_at": "…", "updated_by": "1",
    "content_modified_at": "…", "content_modified_by": "1",
    "trashed_at": null, "trashed_by": null, "trash_root": false,
    "presentation": {
      "handler": "vaulter/preview",
      "document_type": null,
      "type_available": true,
      "viewer": { "key": "vaulter/pdf-preview", "props": {} },
      "capabilities": { "editable": false, "previewable": true, "downloadable": true, "thumbnailable": false, "collaborative": false }
    },
    "abilities": { "view": true, "download": true, "comment": true, "edit": true, "create": false, "move": true, "copy": true, "trash": true, "restore": true, "share": false, "manage": false, "delete": false },
    "viewer": { "starred": false },
    "scan_status": "clean",
    "file": { "revision_id": "01J…", "revision_number": 3, "size_bytes": 1024, "sha256": "…", "mime": "application/pdf", "scan_status": "clean" },
    "document": null
  }
}
```

- `presentation` — `null` у папки; документ несёт `presentation.document_type` и
  `handler: null`, а свою голову — в `document`.
- `abilities` — ваши, вычисленные для всей страницы разом; `etag` — тег ровно этого
  представления (см. [Валидаторы](#валидаторы-и-предусловия)).

### Списки и чтение

#### `GET v1/drives/{drive}/nodes?parent=` — `nodes.index`

Видимые дети `parent`, а без него — корня drive. Курсорная страница Node. На этом и других
маршрутах уровня drive, называющих узел внутри него (`folders.store`, `files.store`,
`uploads.store`), промах в drive, чей корень вам не виден, отвечает `404 drive_not_found` — как
неизвестный drive, — и лишь промах в видимом вам drive отвечает `404 node_not_found`.

#### `GET v1/drives/{drive}/search?q=` — `nodes.search`

Живые узлы, чьё имя совпадает с `q` (обязателен), в пределах видимого вам. Курсорная страница.

#### `GET v1/drives/{drive}/starred` — `nodes.starred`

Ваши помеченные звёздочкой узлы drive, которые вам всё ещё видны, новые звёзды первыми.
Курсорная страница.

#### `GET v1/shared` — `shared.index`

Узлы, к которым вам дали доступ другие, по всем drive — курсорная страница, как любой
список. Нужна функция `grants`, иначе
`403 profile_restriction`.

#### `GET v1/nodes/{node}?include=breadcrumb,children` — `nodes.show`

Один узел со своим `ETag`; `If-None-Match` отвечает `304` после авторизации. `include`
добавляет `breadcrumb` — `[{ "id", "name" }]` видимых предков, корень первым, сам узел
последним, — и `children`, **первую страницу** детей папки
(`{ "data": [Node…], "meta": { "next_cursor", "per_page" } }`, `null` у файла); это не полный
обход. Ответ с `include` — другой вариант: без заголовка `ETag` и никогда не `304`.

#### `GET v1/nodes/{node}/abilities?explain=` — `nodes.abilities`

`{ "data": { "abilities": { …12… }, "explain": null } }`. `explain=<способность>` добавляет
`{ "ability", "allowed", "reasons": [ … ] }`; объяснение раскрывает гранты за ответом,
поэтому нужен `manage` (иначе `403 permission_denied`).

### Изменение узлов

Маршруты с пометкой **If-Match** требуют текущий тег узла (`428` без него, `412` при
устаревшем — см. [Валидаторы](#валидаторы-и-предусловия)). Нужная маршруту способность
проверяется первой: тот, кто видит узел, но не имеет её, получает `403 permission_denied`,
а не `428` или `412`. Каждый отвечает изменённым Node с новым `ETag`.

#### `POST v1/drives/{drive}/folders` — `folders.store` · **Idempotency-Key**

| Поле | Правило |
|---|---|
| `name` | обязательно, допустимое имя узла |
| `parent_id` | необязательная строка; без неё — корень drive |

Нужен `create` в родителе. **Ответ:** `201` Node.

#### `PATCH v1/nodes/{node}` — `nodes.update` · **If-Match** · **Idempotency-Key**

`name` (обязательно). Нужен `edit`. Здесь меняется только имя; у свойств свой маршрут.

#### `PATCH v1/nodes/{node}/properties` — `nodes.properties.update` · **If-Match**

| Поле | Правило |
|---|---|
| `namespace` | обязательно, например `acme`; `vaulter` и `vaulter.*` зарезервированы (`422 invalid_properties`) |
| `set` | объект строковых значений для записи |
| `unset` | список ключей для удаления |

Одно пространство имён меняется атомарно. Нужен `edit`.

#### `PUT v1/nodes/{node}/star` — `nodes.star` · `DELETE v1/nodes/{node}/star` — `nodes.unstar`

Ваша личная звёздочка (нужен `view`). Идемпотентно, без If-Match: меняется только ваш
`viewer.starred` — а значит, ваш тег, — но никогда `sequence` узла.

#### `POST v1/nodes/{node}/move` — `nodes.move` · **If-Match**

| Поле | Правило |
|---|---|
| `destination_id` | строка или `null` (корень drive); живая папка того же drive |
| `on_conflict` | `fail` (по умолчанию, `409 node_name_conflict`) или `rename` |

Нужен `move` на узле и `create` в назначении.

#### `POST v1/nodes/{node}/copy` — `nodes.copy`

`destination_id` (без него — корень drive), `name` (необязательно). Источник не меняется,
поэтому If-Match не нужен; занятое имя получает свободное (`name (2)`). Нужен `copy` на
источнике и `create` в назначении. **Ответ:** `201` Node.

### Корзина

#### `POST v1/nodes/{node}/trash` — `nodes.trash` · **If-Match**

Переносит узел с поддеревом в корзину; узел становится корнем корзины. Нужен `trash`.

#### `GET v1/drives/{drive}/trash` — `trash.index`

Видимые вам корни корзины, курсорная страница. `etag` каждого элемента — то, что ждут
восстановление и окончательное удаление: удалённый в корзину узел не читается через
`nodes.show`.

#### `POST v1/nodes/{node}/restore` — `nodes.restore` · **If-Match**

| Поле | Правило |
|---|---|
| `destination_id` | необязательная папка; без неё — папка, из которой узел удалили |
| `on_conflict` | `rename` (по умолчанию) или `fail` |

Нужен `restore` (и `create`, если узел возвращается в другое место). Член, удалённый вместе
с папкой, → `409 node_trashed_by_ancestor` (`meta.context.trash_root_id`); исчезнувшая
исходная папка без `destination_id` → `409 restore_destination_required`.

#### `DELETE v1/nodes/{node}` — `nodes.purge` · **If-Match**

Окончательно удаляет узел с поддеревом. Только из корзины (иначе `409 node_not_trashed`);
нужен `delete`. Тег проверяется до любого удаления, поэтому с устаревшим тегом строки,
квота и сохранённые байты остаются нетронутыми. **Ответ:** `204`.

#### `DELETE v1/drives/{drive}/trash` — `trash.empty`

Окончательно удаляет все корни корзины, на которые у вас есть `delete`; остальные
остаются. **Ответ:** `200 { "data": { "purged_count": 2 } }`.

### Файлы и ревизии

#### `POST v1/drives/{drive}/files` — `files.store`

Multipart, одним запросом: `file` (лимит `vaulter-drive.uploads.max_file_size`,
необязательный список MIME), `parent_id`, `name`. Нужен `create` в родителе. **Ответ:**
`201` Node. Для больших файлов — [резюмируемые загрузки](#резюмируемые-загрузки).

#### `GET v1/files/{node}/revisions` — `files.revisions.index`

Хранимые ревизии, новые первыми, курсорная страница FileRevision:

```json
{
  "id": "01J…", "number": 3, "size_bytes": 1024, "mime": "application/pdf",
  "declared_mime": "application/pdf", "sha256": "…", "scan_status": "clean",
  "keep_forever": false, "is_head": true, "created_by": "1", "created_at": "…"
}
```

#### `POST v1/files/{node}/revisions` — `files.revisions.store` · **If-Match**

Multipart `file`: следующая ревизия. Нужен `edit`. **Ответ:** `201` FileRevision.

#### `POST v1/files/{node}/revisions/{revision}/restore` — `files.revisions.restore` · **If-Match**

Копирует старую ревизию вперёд как новую голову (её байты снова учитываются в квоте). Нужен
`edit`. **Ответ:** `200` FileRevision.

#### `PATCH v1/files/{node}/revisions/{revision}` — `files.revisions.update`

`keep_forever` (обязательный boolean): закрепить ревизию или вернуть её политике хранения.
Нужен `edit`.

#### `DELETE v1/files/{node}/revisions/{revision}` — `files.revisions.destroy`

Удаляет ревизию и освобождает её байты; нужен `manage`. Голова →
`409 cannot_delete_head_revision`; закреплённая → `409 revision_pinned`. **Ответ:** `204`.

#### `GET v1/files/{node}/download` — `files.download`

#### `GET v1/files/{node}/revisions/{revision}/download` — `files.revisions.download`

Байты головы или одной точной ревизии по правилам [выдачи файлов](#выдача-файлов): поток в
режиме `strict`, `302` на подписанный URL в режиме `direct_signed`; `403 permission_denied`
тому, кому можно только смотреть; `423 content_unavailable` для ревизии, не проверенной
чистой, — оба до первого байта.

---

## Резюмируемые загрузки

Загрузки частями (ядро `tus` 1.0.0 или S3-style multipart), завершающиеся тем же
безгоночным коммитом, что и загрузка одним запросом; собранные байты хешируются заново,
контрольная сумма клиента или ETag части никогда не становятся адресом содержимого.
Ограничиваются `throttle:vaulter-uploads`. См. [Загрузки](/ru/guides/uploads).

`vaulter-drive.uploads.resumable.enabled = false` оставляет маршруты зарегистрированными и
отклоняет создание, дозапись, завершение, отмену, `HEAD` и `OPTIONS` с
`403 profile_restriction` (невидимая цель по-прежнему `404`); опрос статуса продолжает
работать.

#### `POST v1/drives/{drive}/uploads` — `uploads.store`

| Поле | Правило |
|---|---|
| `protocol` | `tus` (по умолчанию) или `s3-multipart` |
| `parent_id` | необязательная строка — папка нового файла (без неё — корень drive) |
| `node_id` | необязательная строка — существующий файл, чью новую ревизию загружают; **нужен If-Match** с тегом этого файла |
| `name` | необязательно, до 255, без разделителей пути |
| `total_size_bytes` | необязательное целое ≥ 0, сразу проверяется политикой drive |
| `expected_sha256` | необязательно, 64 hex-символа (заявленное, не доверяется) |
| `mime_type` | необязательно, до 191 |
| `metadata` | необязательный объект; ключи вне `vaulter-drive.uploads.resumable.metadata.allowed_keys` отбрасываются |

Нужен `create` в папке или `edit` на файле. If-Match для `node_id` проверяется при создании
сессии; коммит в конце его повторно не сравнивает (сохранение ожидания в сессии
запланировано после 1.0). **Ответ:** `201` UploadSession; для tus ещё `Tus-Resumable`,
`Location: …/v1/uploads/{upload}` и `Upload-Offset: 0`.

```json
{
  "data": {
    "upload_id": "…", "drive_id": "01J…", "protocol": "tus", "status": "uploading",
    "received_bytes": 8388608, "total_size_bytes": 52428800, "chunk_size": 8388608,
    "mime_type": "application/pdf", "node_id": null, "target_parent_id": "01J…",
    "target_name": "report.pdf", "expires_at": "…"
  }
}
```

Дальше с сессией может работать только её создатель (другому пользователю —
`403 permission_denied`); неизвестный `{upload}` или `{upload}` чужого арендатора — `404`.

#### `GET v1/uploads/{upload}` — `uploads.show`

Статус сессии (не часть ядра tus). **Ответ:** `200` UploadSession.

#### `HEAD v1/uploads/{upload}` — `uploads.tus.head`

`204` с `Tus-Resumable`, `Upload-Offset` (точка возобновления на сервере — продолжайте с
неё, а не со своей догадки) и `Upload-Length`, если известен.

#### `PATCH v1/uploads/{upload}` — `uploads.tus.patch`

Чанк `application/offset+octet-stream` по `Upload-Offset`. Пока загрузка не завершена —
`204` с продвинутым `Upload-Offset`; последний чанк коммитит файл и отвечает `201` Node (с
его `ETag`). Протокольные отказы сохраняют заголовки tus внутри `application/problem+json`:
нет или другой `Tus-Resumable` → `412` с `Tus-Version`; неверный тип содержимого → `415`;
чанк больше `max_chunk_size` → `413`; несовпадающее смещение →
`409 upload_offset_mismatch` с `Upload-Offset`.

#### `DELETE v1/uploads/{upload}` — `uploads.tus.destroy`

Отмена; промежуточные байты освобождаются. `204` с `Tus-Resumable`.

#### `OPTIONS v1/uploads/{upload}` — `uploads.tus.options`

Обнаружение без поиска сессии: `Tus-Version`, `Tus-Extension: creation,termination` и
`Tus-Max-Size`, если лимит задан.

#### `POST v1/uploads/{upload}/parts` — `uploads.parts.store`

Тело — байты части; `part_no` (query/тело или `X-Part-Number`, по умолчанию 1) и
`byte_offset`. Повторная загрузка части заменяет её. **Ответ:** `201
{ "part_no", "size", "etag" }`.

#### `POST v1/uploads/{upload}/complete` — `uploads.complete`

Сшивает части и коммитит. **Ответ:** `201` Node.

---

## Комментарии

Комментарии к любым узлам (`vaulter-documents`). Маршруты зарегистрированы всегда, переключатель
проверяют операции: при выключенных комментариях — глобально (`vaulter-documents.comments.enabled`)
или в профиле диска — видимый узел отвечает `403 profile_restriction`, невидимый — как обычно
`404 node_not_found`. `{comment}` другого узла — `404 comment_not_found`. Чтение — `view`,
создание — `comment`, resolve — `comment` и авторство либо `edit`, удаление — автор комментария
или `manage`. См. [Комментарии](/ru/guides/comments).

#### `GET v1/nodes/{node}/comments` — `comments.index`

**Ответ:** `200` страница Comment, сначала старые.

#### `POST v1/nodes/{node}/comments` — `comments.store`

| Поле | Правило |
|---|---|
| `body` | обязательно, строка, до 5000 |
| `parent_id` | опционально, id комментария того же узла (ответ в ветке) |
| `anchor` | опционально, объект; до 16384 байт JSON со стандартным экранированием PHP и 16 уровней вложенности |

**Ответ:** `201` Comment.

#### `POST v1/nodes/{node}/comments/{comment}/resolve` — `comments.resolve`

**Ответ:** `200` Comment.

#### `DELETE v1/nodes/{node}/comments/{comment}` — `comments.destroy`

**Ответ:** `204`.

**Comment:** `id`, `node_id`, `parent_id`, `author` (`{ type, id }`), `body`, `anchor`,
`resolved_at`, `resolved_by`, `created_at`.

---

## Документы

Документы живут в диске рядом с файлами и папками (`vaulter-documents`). Маршруты работают с
содержимым документа под `documents/{node}`; узел другого вида отвечает `409 not_a_document`. См.
[Документы](/ru/guides/documents).

У **содержимого** документа свой валидатор, независимый от `sequence` узла:
`ETag: "{node}.{collab_epoch}.{state_version}"`. От вызывающего он не зависит: читатель и
редактор получают одно тело и один тег, а переименование или перемещение его не меняют.
Маршруты с пометкой **If-Match** требуют этот тег; устаревший или слабый — это
`412 document_state_mismatch`, в том числе когда хаб коллаборации успел сохранить более новое
состояние.

#### `POST v1/drives/{drive}/documents` — `documents.store`

| Поле | Правило |
|---|---|
| `name` | обязательно, строка, без разделителей пути |
| `parent_id` | опционально (без него — корень диска) |
| `type` | опционально, `vendor/name` (по умолчанию — настроенный); незарегистрированный — `422 unknown_document_type` |
| `content` | опционально, объект — валидирует кодек типа (`422 invalid_document_content`) |

**Гейт:** `Create` в родителе и типы документов профиля. **Ответ:** `201` Node с `ETag`.

#### `GET v1/documents/{node}/content` — `documents.content.show`

**Гейт:** `view`. **Ответ:** `200 { "data": { "type", "schema_version", "state_version",
"collab_epoch", "revision_id", "content" } }` с `ETag` содержимого; `304` на совпавший
`If-None-Match` — только после авторизации.

#### `PUT v1/documents/{node}/content` — `documents.content.update` · **If-Match**

| Поле | Правило |
|---|---|
| `content` | обязательно, объект — всё новое содержимое |

**Гейт:** `edit`. **Ответ:** `200` как выше с новым `ETag`. Содержимое, которое нельзя сохранить
обратно, — `409 document_content_degraded`; неустановленный тип — `409 document_type_unavailable`.

#### `GET v1/documents/{node}/revisions` — `documents.revisions.index`

**Гейт:** `view`. **Ответ:** `200` страница DocumentRevision, сначала свежие.

#### `POST v1/documents/{node}/revisions` — `documents.revisions.store`

Снимает рабочее состояние чекпоинтом, который становится головой.

| Поле | Правило |
|---|---|
| `label` | опционально, строка, до 255 |
| `keep_forever` | опционально, boolean |

**Гейт:** `edit`. **Ответ:** `201` DocumentRevision.

#### `GET v1/documents/{node}/revisions/{revision}` — `documents.revisions.show`

**Гейт:** `view`. **Ответ:** `200` DocumentRevision с `content`. Неизвестный id или id другого
документа — `404 document_revision_not_found`.

#### `POST v1/documents/{node}/revisions/{revision}/restore` — `documents.revisions.restore` · **If-Match**

Копирует ревизию вперёд как рабочее состояние и двигает эпоху коллаборации. **Гейт:** `edit`.
**Ответ:** `200` содержимое с новым `ETag`.

#### `PATCH v1/documents/{node}/revisions/{revision}` — `documents.revisions.update`

| Поле | Правило |
|---|---|
| `keep_forever` | обязательно, boolean — закрепить ревизию вне прунинга или снять закрепление |

Метка ревизии задаётся при создании и не меняется. **Гейт:** `edit`. **Ответ:** `200`
DocumentRevision.

#### `GET v1/documents/{node}/export/{format}` — `documents.export`

`{format}` — `html`, `markdown` или другой зарегистрированный формат экспортёра; `?revision={id}`
рендерит один чекпоинт вместо рабочего состояния.

**Гейт:** `download` и выдача содержимого. **Ответ:** байты экспортёра как вложение
(`Content-Disposition`, `X-Content-Type-Options: nosniff`,
`Content-Security-Policy: sandbox; default-src 'none'`, `Cache-Control: private, no-store`,
`Content-Length`); `X-Vaulter-Export-Lossy: 1` помечает содержимое, которое тип смог прочитать
лишь частично. Формат, которого нет у типа, — `422 unknown_export_format` с `meta.allowed`. См.
[Экспорт](/ru/guides/export).

**DocumentRevision:** `id`, `number`, `schema_version`, `label`, `is_autosave`, `keep_forever`,
`content_hash`, `is_head`, `created_by`, `created_at` (и `content` при чтении одной ревизии).

---

## Доступ: гранты и наследование

Роли, выданные на узел (`vaulter-drive.features.grants`). Каждый маршрут требует `manage` на
узле. См. [Гранты](/ru/guides/grants).

#### `GET v1/nodes/{node}/grants` — `grants.index`

Гранты, выданные прямо на узел, постранично (`cursor`, `per_page` 1–200, по умолчанию 50).
**Ответ:** `200 { "etag": "…", "data": [Grant, …], "meta": { "next_cursor": …, "per_page": … } }`.
`etag` — тег доступа узла, одинаковый на всех страницах одного набора; на последней странице
`next_cursor` равен `null`. Страница один (`next_cursor` задан) и страница два (`null`):

```json
{ "etag": "\"node-access.5.3\"", "data": [{ "id": "g1" }], "meta": { "next_cursor": "eyJ2Ijo…", "per_page": 1 } }
{ "etag": "\"node-access.5.3\"", "data": [], "meta": { "next_cursor": null, "per_page": 1 } }
```

**Пустая страница может нести курсор** — читайте до `next_cursor: null`, а не до пустого
`data`. Курсор зашифрован и привязан к узлу, вызывающему и версии доступа: другой узел, другой
вызывающий или изменившийся набор грантов дают `422 validation_failed` (`errors.cursor`), а
право `manage` проверяется на каждой странице. Набор, изменившийся между страницами, —
не снимок: перечитайте с первой страницы.

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

Bearer-ссылки на узел (`vaulter-drive.features.share_links`). Ссылка даёт ровно способности
своей роли на своём наборе узлов; на публичных маршрутах гранты пользователей не
участвуют. См. [Публичные ссылки](/ru/guides/sharing).

### Маршруты владельца

Каждый маршрут владельца требует `share` на узле.

#### `GET v1/nodes/{node}/shares` — `shares.index`

Действующие ссылки узла, постранично (`cursor`, `per_page` 1–200, по умолчанию 50).
**Ответ:** `200 { "data": [Share, …], "meta": { "next_cursor": …, "per_page": … } }`. Токен в
списке не показывается. Пустая страница может нести непустой `next_cursor`; читайте до `null`.
Курсор зашифрован и привязан к узлу и вызывающему (иначе `422 validation_failed`), право
`share` проверяется на каждой странице; страницы показывают текущее состояние, не снимок.

#### `POST v1/nodes/{node}/shares` — `shares.store`

| Поле | Правило |
|---|---|
| `role` | `previewer`, `reader` (по умолчанию), `commenter`, `writer`; `organizer`/`owner` → `422 share_role_not_allowed` |
| `audience` | `anyone` (по умолчанию), `authenticated`, `tenant`; для `writer` — только `authenticated` или `tenant` |
| `scope` | `item` (по умолчанию) или `subtree` |
| `password` | необязательно, 1–255 символов |
| `expires_at` | необязательно, в будущем, в пределах `vaulter-drive.sharing` и профиля (`422 share_link_ttl_exceeded`) |
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
(`vaulter-drive.features.activity`).

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

Следить за drive инкрементально вместо повторного обхода (`vaulter-drive.features.changes`;
выключен → `403 profile_restriction`). См. [Изменения](/ru/concepts/events).

#### `GET v1/drives/{drive}/changes/start` — `changes.start`

`200 { "data": { "cursor": "…" } }`. Получите C0 **до** рекурсивного обхода,
затем читайте журнал от C0. См. [Журнал изменений](/ru/concepts/events).

#### `GET v1/drives/{drive}/changes?cursor=&limit=` — `changes.index`

`cursor` обязателен; `limit` по умолчанию 100, не выше настроенного максимума.
Envelope: `{ "data": [Change…], "meta": { "next_cursor": "…", "has_more": false } }`.
Upsert содержит `operation`, `sync_key`, `node_id`, `subtree`, `node_sequence`, `node`.
Remove содержит ровно `{ "operation": "remove", "sync_key": "…" }`: удалите локальный
узел и известных потомков по сохранённому ключу читателя. Raw id и причина не выдаются.
Ответ Node требует `sync_key: string|null`; для анонимного читателя это null.
Сохраняйте курсор даже пустой страницы; продолжайте при `has_more: true`.
Чужой, повреждённый или формат 1 даёт `400 invalid_cursor`; deadline, generation,
epoch или prune — `410 cursor_expired`. Оба требуют нового bootstrap и соответствия.
Подписанный feed cursor отделён от зашифрованного продолжения списка.

---

## Webhook-эндпоинты

Глобальная админ-поверхность подписчиков вебхуков, подключается при
`vaulter.modules.webhooks`. Каждый маршрут, включая чтение, проходит
`WebhookEndpointPolicy` (`vaulter-webhooks.manage_ability`) до поиска по id. См.
[Вебхуки](/ru/guides/webhooks).

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
  `404 webhook_endpoint_not_found` / `404 webhook_delivery_not_found`. Передавайте
  `Idempotency-Key`: повтор с тем же ключом отвечает из квитанции (`Idempotent-Replayed`,
  доставка в текущем состоянии) и ничего не отправляет повторно; без ключа повтор после
  завершения доставки — это новая переотправка.

---

## Протоколы коллаборации

Публичный documents обслуживает нейтральную сессию независимо от реализации транспорта. См. [протоколы коллаборации](/ru/extending/collaboration-protocols).

#### `POST v1/documents/{node}/collaboration/session` — `documents.collaboration.session`

Общая policy проверяет доступ и привязку документа к протоколу. Ответ `201` содержит `data`:
`protocol`, `version`, `endpoint`, `token`, `expires_at`, `mode`, `node_id`, `generation`, `schema_version`, `options`.
Не секретные `options` определяются протоколом. При недоступной привязке возвращается `409 collaboration_unavailable`.

### Эндпоинты хаба (сервер-к-серверу)

Эксплуатация коммерческого дополнения описана в его приватном руководстве.

## OpenAPI и совместимость

[`openapi/vaulter-v1.yaml`](https://github.com/axiomasoft/vaulter/blob/main/openapi/vaulter-v1.yaml) (OpenAPI 3.1)
— источник истины этого API: каждый маршрут `vaulter.v1.<имя>` — операция с `operationId: <имя>`, а эта
страница объясняет, что утверждает файл. В нём описаны параметры, правила `If-Match` (`428` / `412`) и
`If-None-Match` (`304`), заголовки кэша и выдачи файлов, ошибки `application/problem+json` со значениями
`code` и схема каждого ресурса. В `openapi/examples/` лежит по одному реальному ответу на ресурс.

Контракт проверяется, а не только записан:

- **Покрытие.** Тест сверяет таблицу маршрутов с документом: маршрут без операции, операция без маршрута или
  другой метод/путь роняют тест.
- **Ответы.** Feature-тесты `core`, `drive` и `documents` идут с `VAULTER_OPENAPI_VALIDATE=1`; каждый ответ
  `vaulter.v1.*` проверяется по статусу, заголовкам, media type и телу (`opis/json-schema`, draft 2020-12).
  Неописанный статус или media type роняет тест.
- **Список маршрутов.** `packages/core/tests/Fixtures/golden/routes-v1.json` фиксирует имена, методы, пути и
  стеки middleware; изменение маршрута видно в диффе pull request.
- **Ломающие изменения.** CI сравнивает документ с контрактом последнего релизного тега (до первого релиза с
  этим файлом — с базовой веткой) через `oasdiff breaking --fail-on ERR`; ломающее изменение `v1` валит сборку.

**Политика совместимости.** Внутри `v1` — только аддитивные изменения: новый маршрут, новый необязательный
параметр, новое поле ответа, новый `code` проблемы. Клиент обязан игнорировать неизвестные поля. Удаление или
переименование поля, смена смысла или типа, новое обязательное поле запроса, новый статус для существующего
исхода — только в `v2`. Когда выйдет `v2`, `v1` отвечает заголовками `Deprecation` и `Sunset` и поддерживается
не меньше 12 месяцев.

**Как менять контракт.** Правьте маршруты и `openapi/vaulter-v1.yaml` одним изменением, затем пересоберите golden
(`UPDATE_GOLDEN=1`) и примеры (`VAULTER_OPENAPI_EXAMPLES_WRITE=1`) — см.
[CONTRIBUTING](https://github.com/axiomasoft/vaulter/blob/main/CONTRIBUTING.md).

## Смотрите также

- [Drive](/ru/concepts/nodes-and-revisions) — хэндл `DriveHandle`, который оборачивают маршруты Drive
- [Корзина и восстановление](/ru/guides/trash) — жизненный цикл маршрутов корзины
- [Загрузки](/ru/guides/uploads) · [Публичные ссылки](/ru/guides/sharing) · [Совместное редактирование](/ru/guides/collaboration)
- [Справочник событий](/ru/reference/events) · [Справочник enum'ов](/ru/reference/php-api)
