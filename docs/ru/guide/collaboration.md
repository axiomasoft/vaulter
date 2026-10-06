---
title: Совместное редактирование
description: Совместное редактирование документов в реальном времени на Yjs и внешний хаб Hocuspocus; рукопожатие токена, права из ability и развёртывание hocuspocus-server.
outline: deep
---

# Совместное редактирование

Vaulter редактирует **узлы-документы** (`kind = document`, контент в
`v_node_document_versions`) совместно в реальном времени. Бесконфликтное слияние
обеспечивает **Yjs** — бинарное CRDT-состояние является **источником истины**; JSON TipTap
это производная проекция. Транспорт скрыт за контрактом `CollaborationDriver`, с двумя
реализациями на выбор через конфиг.

::: warning Перевод в процессе
На русском пока доступны разделы по выбору драйвера, конфигу и развёртыванию. Полное
описание потока рукопожатия, контракта вебхуков и фронтенд-обвязки `@vaulter/vue` — на
английской странице [Realtime Collaboration](/guide/collaboration).
:::

## Драйвер совместного редактирования

Yjs остаётся источником истины. Драйвер `hocuspocus` подключает внешний Node-хаб
(`hocuspocus-server/`) через подписанный room-JWT и вебхуки сервер-к-серверу.
Настройте URL хаба и секрет JWT в `config('vaulter-documents.collaboration')`.

Reverb для совместного редактирования Yjs удалён; используйте Hocuspocus.
Broadcast-каналы комментариев и проверки доступа сохраняются.

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
    'broadcast' => ['channel_prefix' => 'vaulter.documents'],
],
```

::: warning Коллаборация включается отдельно
Установите Documents и включите `vaulter-documents.collaboration.enabled`.
Редактирование документов и ревизии доступны при установке пакета без дополнительного флага.
Оба секрета хаба должны быть заданы и различаться.
:::

## Hocuspocus (внешний Node-хаб)

При `driver = hocuspocus` клиент подключается к WebSocket-URL хаба, предъявляя **room-JWT**
в качестве токена. Хаб не доверяет JWT сам по себе — он вызывает Laravel **обратно**:

- **`onAuthenticate`** → `POST {laravel}/api/vaulter/collab/authenticate` — Laravel
  определяет ability пользователя на узле и отвечает `{allowed, readOnly, user}`.
- **`onStoreDocument`** (с дебаунсом на хабе) → `POST {laravel}/api/vaulter/collab/store` —
  бинарное состояние Yjs (base64) сохраняется в `v_node_document_versions.yjs_state`.

Vaulter выпускает JWT через `firebase/php-jwt` (HS256, подпись ключом `VAULTER_COLLAB_SECRET`):

```bash
composer require firebase/php-jwt
```

Если пакет отсутствует или не задан `drivers.hocuspocus.secret`, драйвер бросает
`CollaborationConfigException` на этапе выпуска токена, а не выдаёт непроверяемый токен.

::: danger Эндпоинты вебхуков никогда не открыты
Маршруты `collab/authenticate` и `collab/store` идут **без** auth-middleware Laravel —
только через `ResolveCollaborationCall`, который аутентифицирует хаб либо общим секретом в
заголовке `X-Vaulter-Collab-Secret`, либо валидным подписанным room-JWT в `Authorization:
Bearer`. Запрос без того и другого получает `401`. JWT-вызов может адресовать только свою
комнату (токен узла A не сохранит в узел B), а read-only JWT-вызов не может писать.
:::

### Развёртывание `hocuspocus-server`

Хаб находится в каталоге `hocuspocus-server/` (`@hocuspocus/server` + `yjs`). Ему нужен
URL бэкенда для вызова вебхуков; ключ подписи JWT ему **не нужен** (Laravel выпускает токен,
клиент его пересылает, хаб лишь ретранслирует его в вебхуки, которые проверяют подпись).

::: code-group

```bash [Напрямую]
cd hocuspocus-server
npm install
npm start            # продакшн
npm run dev          # режим наблюдения
```

```yaml [docker-compose.yml]
services:
  vaulter-collab:
    build: ./hocuspocus-server
    image: vaulter/hocuspocus-server:dev
    ports:
      - "${VAULTER_COLLAB_PORT:-1234}:1234"
    environment:
      VAULTER_COLLAB_PORT: 1234
      VAULTER_COLLAB_HEALTH_PORT: 1235
      VAULTER_BACKEND_URL: ${VAULTER_BACKEND_URL:-http://host.docker.internal}
      VAULTER_API_PREFIX: ${VAULTER_API_PREFIX:-api/vaulter}
      # ДОЛЖЕН совпадать с vaulter-documents.collaboration.hub.webhook_secret на стороне Laravel.
      VAULTER_COLLAB_WEBHOOK_SECRET: ${VAULTER_COLLAB_WEBHOOK_SECRET:-}
    healthcheck:
      test: ["CMD", "node", "-e", "fetch('http://localhost:1235/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"]
      interval: 10s
      timeout: 3s
      retries: 3
    restart: unless-stopped
```

:::

Хаб поднимает небольшой health-эндпоинт на **отдельном** порту (чтобы не мешать апгрейду
WebSocket на основном порту): `GET /health` → `200 {"status":"ok"}`. Нацельте на него
healthcheck в compose/оркестраторе, прежде чем запускать зависимые сервисы.

#### Контракт окружения

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `VAULTER_COLLAB_PORT` | `1234` | WebSocket-порт хаба (клиент подключается к `ws://host:PORT`). |
| `VAULTER_COLLAB_HEALTH_PORT` | `1235` | HTTP-порт health-эндпоинта — `GET /health`. |
| `VAULTER_BACKEND_URL` | `http://localhost` | Базовый URL приложения Laravel, куда хаб шлёт вебхуки (без завершающего слэша). В Docker — имя сервиса приложения или `host.docker.internal`. |
| `VAULTER_API_PREFIX` | `api/vaulter` | Префикс API-маршрутов Vaulter. Вебхуки разрешаются в `{VAULTER_BACKEND_URL}/{VAULTER_API_PREFIX}/collab/{authenticate,store}`. |
| `VAULTER_LARAVEL_WEBHOOK_URL` | *(производная)* | Явное переопределение полного базового URL вебхуков; перекрывает две переменные выше (reverse proxy / разделённый хост). |
| `VAULTER_COLLAB_WEBHOOK_SECRET` | *(не задан)* | Общий секрет в заголовке `X-Vaulter-Collab-Secret`. **Должен совпадать** с `vaulter-documents.collaboration.hub.webhook_secret` на стороне Laravel. Если не задан — хаб аутентифицирует вебхуки только пересланным room-JWT. |
| `VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS` | `10000` | Таймаут round-trip вебхука в мс. |

::: danger Согласуйте секреты
`VAULTER_COLLAB_WEBHOOK_SECRET` на **хабе** должен совпадать с
`vaulter-documents.collaboration.hub.webhook_secret` на **Laravel** (одно и то же имя переменной с обеих
сторон). Ключ подписи JWT (`VAULTER_COLLAB_SECRET`) живёт **только** на стороне Laravel —
хаб его никогда не видит.
:::

На стороне Laravel укажите клиенту на хаб:

```dotenv
VAULTER_COLLAB_DRIVER=hocuspocus
VAULTER_COLLAB_URL=ws://collab.example.com:1234
VAULTER_COLLAB_SECRET=<32+ символов случайных>     # ключ подписи room-JWT (HS256)
VAULTER_COLLAB_WEBHOOK_SECRET=<другой случайный>   # общий с хабом
```

## Дальше

- [ADR-0001 — Транспорт совместного редактирования](/adr/0001-collaboration-transport)
- Полная страница (поток токена, контракт вебхуков, обвязка `@vaulter/vue`):
  [Realtime Collaboration](/guide/collaboration) (англ.)
