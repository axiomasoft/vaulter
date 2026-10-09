---
title: Совместное редактирование
description: Совместное редактирование документов в реальном времени на Yjs и внешнем хабе Hocuspocus — сессия, ограждение по поколению (epoch), загрузка и сидирование на сервере, read-only и обвязка @vaulter/vue.
outline: deep
---

# Совместное редактирование

Vaulter редактирует **узлы-документы** (`kind = document`, контент в агрегате `v_documents`)
совместно в реальном времени. Бесконфликтное слияние обеспечивает **Yjs** — бинарное
CRDT-состояние является **источником истины**, JSON TipTap — его проекция. Транспорт —
внешний хаб **Hocuspocus** (`hocuspocus-server/`) за контрактом `CollaborationDriver`.

Reverb для совместного редактирования Yjs удалён: `vaulter-documents.collaboration.driver =
reverb` теперь даёт `InvalidConfigurationException`. Broadcast-каналы комментариев и их
проверки доступа сохраняются.

## Конфигурация

Все ключи находятся под `config('vaulter-documents.collaboration')`:

```php
// config/vaulter-documents.php
'collaboration' => [
    'enabled' => false,
    'driver' => 'hocuspocus',
    'hub' => [
        'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
        'jwt_secret' => env('VAULTER_COLLAB_SECRET'),
        'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
        'token_ttl' => 3600,
        'timestamp_tolerance' => 300,
    ],
    'max_state_bytes' => 16777216,
    'broadcast' => ['channel_prefix' => 'vaulter.documents'],
],
```

| Ключ | Env | По умолчанию | Назначение |
|---|---|---|---|
| `enabled` | — | `false` | Opt-in. Выключено: маршруты не смонтированы, `collaborationSession()` отвечает `409 collaboration_unavailable`. |
| `driver` | `VAULTER_COLLAB_DRIVER` | `hocuspocus` | Единственный транспорт; `reverb` отвергается. |
| `broadcast.channel_prefix` | — | `vaulter.documents` | Пространство имён комнат: `<prefix>.<driveId>.<nodeId>.<epoch>`. |
| `hub.url` | `VAULTER_COLLAB_URL` | `ws://localhost:1234` | WebSocket-URL хаба для клиента. |
| `hub.jwt_secret` | `VAULTER_COLLAB_SECRET` | *(не задан)* | Ключ HS256 для выпуска и проверки room-JWT. Только на стороне Laravel. |
| `hub.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | *(не задан)* | Ключ HMAC-подписи тела каждого вызова хаба. Общий с хабом; должен отличаться от `jwt_secret`. |
| `hub.token_ttl` | — | `3600` | Срок жизни room-JWT в секундах, от 1 до 3600 (иначе `InvalidConfigurationException`). |
| `hub.timestamp_tolerance` | — | `300` | Допустимый сдвиг часов подписанного вызова и окно защиты от повтора. |
| `max_state_bytes` | — | `16777216` | Максимальный бинарный Yjs-state одной записи (`413 document_state_too_large`). |

::: warning Коллаборация включается отдельно
Включите `vaulter-documents.collaboration.enabled`, установите `firebase/php-jwt` и задайте
оба секрета хаба — разные. Кэш защиты от повтора подписанных вызовов живёт в `Cache`-store
приложения: в продакшне нужен общий для всех инстансов store (Redis, database), а не
`array` или `file`.
:::

## Поколения: epoch

У каждого документа есть **поколение коллаборации** (`v_documents.collab_epoch`). Оно растёт,
когда рабочее состояние заменяется не из комнаты:

- `restore()` ревизии;
- `update()` коллаборативного документа, у которого уже есть Yjs-state (замена JSON через
  API/PHP — Yjs-state сбрасывается, следующая комната сидируется из нового JSON).

Поколение входит в имя комнаты, токен и каждую запись. Это **storage fence**: запись несёт
epoch комнаты и сравнивается с `collab_epoch` на строке документа под `FOR UPDATE`; restore
меняет epoch под той же блокировкой. Поэтому запись комнаты, открытой до restore, либо
коммитится до него (и заменяется им), либо отвергается `409 collaboration_epoch_mismatch` —
как бы поздно ни сработал debounce хаба, она никогда не ляжет поверх восстановленного
документа.

Хаб узнаёт о restore при следующей записи этой комнаты и закрывает её кодом **4409**
`epoch_changed`; клиенты открывают новую сессию и **новый** `Y.Doc`.

## Поток подключения

1. Клиент вызывает `POST nodes/{node}/collab/token`, при желании передавая `schema_version`
   своего редактора.
2. `DocumentOperations::collaborationSession($document, $clientSchemaVersion)` находит узел,
   авторизует `View` (невидимый узел — `404 node_not_found`) и выбирает режим. Сессия
   **read-write**, только если у актора есть `Edit`, тип документа установлен и контент
   читается без потерь в схеме, которую эта установка пишет, версия схемы клиента не задана
   или совпадает с типом, а диск активен (архивный — read-only). Выключенная коллаборация или
   тип без `SupportsCollaboration` — `409 collaboration_unavailable`.
3. Драйвер выпускает сессию: `room`, `node_id`, `drive_id`, `epoch`, `schema_version`, `mode`
   (`rw`|`ro`), `token`, `url`. Токен — JWT HS256 с claims `sub`, `node`, `drive`, `tenant`,
   `epoch`, `schema_version`, `mode`, `room`, `iat`, `exp`.

## Протокол хаба (сервер-к-серверу)

Эндпоинты хаба идут без auth-middleware Laravel — только через `ResolveCollaborationCall`,
который требует **оба** барьера: room-JWT в `Authorization: Bearer` (подпись и `exp`;
комната разбирается и совпадает с claims `node`/`drive`/`epoch`) и HMAC-SHA256 подпись
`"{timestamp}.{rawBody}"` ключом `hub.webhook_secret` (`X-Vaulter-Collab-Timestamp` +
`X-Vaulter-Collab-Signature`). Устаревшая метка времени и повтор подписи отвергаются; хаб
кладёт в каждое тело случайный `nonce`. Иначе — `401`. Общий секрет сам по себе больше не
является личностью.

Каждый вызов перечитывает живое состояние: узел существует, не в корзине, принадлежит диску
и тенанту токена, диск активен или в архиве, epoch комнаты текущий, права пользователя —
на этот момент. Отзыв `Edit` действует на следующем вызове хаба.

| Эндпоинт | Хук хаба | Тело | Ответ |
|---|---|---|---|
| `POST collab/authenticate` | `onAuthenticate` | `{ room }` | `{ allowed, mode, userId, epoch }`; `409 collaboration_epoch_mismatch` для прошлого поколения. |
| `POST collab/load` | `onLoadDocument` | `{ room }` | `{ state, content, epoch, schemaVersion, stateVersion, type }` — base64 Yjs-state или `null` и JSON для однократного сидирования. |
| `POST collab/store` | `onStoreDocument` | `{ room, state, content, seed? }` | `{ changed, epoch, state_version, yjs_state_size }`. |

`collab/store`: read-only токен, пользователь без `Edit`, архивный диск или токен другой
версии схемы — `403`; чужое поколение — `409 collaboration_epoch_mismatch`; `seed: true`
пишется, только пока у документа нет Yjs-state (иначе `409 collaboration_seed_conflict`);
`content` обязателен для типов, чей `projectFromState()` возвращает `null` (оба встроенных),
и проверяется кодеком типа (`422 invalid_document_content`); state больше `max_state_bytes` —
`413 document_state_too_large`.

### Загрузка и сидирование — только на хабе

`onLoadDocument` хаба применяет сохранённый Yjs-state. Документ без него строится из JSON
**один раз** на сервере через `@hocuspocus/transformer` и схему хаба
(`hocuspocus-server/src/schema.js`) и сохраняется с `seed: true`.

::: danger Никогда не сидируйте на клиенте
В коллаборативном режиме **не** вызывайте `setContent()` редактора и не вставляйте JSON в
`Y.Doc`: два клиента, конвертирующие один JSON, создают разные Yjs-вставки, и слияние
покажет контент дважды. `VaulterDocumentNode` поэтому передаёт редактору `content: null`.
:::

### Read-only

Read-only проверяется дважды: хаб помечает `ro`-соединение read-only (Hocuspocus отбрасывает
его изменения) и не пишет в read-only контексте, а бэкенд отвергает запись с `ro`-токеном.
После истечения токена хаб отвергает каждое сообщение и закрывает соединение кодом `4001`.

## Развёртывание

Контракт окружения, Docker и health-эндпоинты — на странице
[Хаб Hocuspocus](/ru/guide/hocuspocus-server). На стороне Laravel:

```dotenv
VAULTER_COLLAB_DRIVER=hocuspocus
VAULTER_COLLAB_URL=wss://collab.example.com
VAULTER_COLLAB_SECRET=<32+ символов случайных>     # ключ room-JWT, только Laravel
VAULTER_COLLAB_WEBHOOK_SECRET=<другой случайный>   # общий с хабом, подписывает вызовы
```

## Фронтенд (`@vaulter/vue`)

`useNodeCollaboration` получает сессию, открывает `@hocuspocus/provider` на свежем `Y.Doc` и
обрабатывает терминальные закрытия хаба: на **4409 `epoch_changed`** провайдер и старый
`Y.Doc` уничтожаются, запрашивается новая сессия, и `doc` (`ShallowRef<Y.Doc>`) **заменяется**
новым документом — перепривяжите редактор и не переносите старое содержимое; на **4001**
запрашивается новая сессия, `Y.Doc` сохраняется в пределах того же поколения. Опция
`supportedSchemaVersion` отправляется с запросом сессии; документ в более новой схеме
открывается read-only, `schemaOutdated` = `true`.

`VaulterDocumentNode` в коллаборативном режиме передаёт редактору `collaborative: true`,
`content: null`, `ydoc` (заменяется после restore), присутствие и состояние соединения;
проп `supported-schema-version` и событие `schema-outdated` сообщают о необходимости
обновить приложение.

## Дальше

- [Хаб Hocuspocus](/ru/guide/hocuspocus-server)
- [Ошибки](/ru/guide/errors) — `collaboration_*` и `document_state_too_large`
- [ADR-0001 — Транспорт совместного редактирования](/adr/0001-collaboration-transport)
