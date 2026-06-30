---
title: Совместное редактирование
description: Совместное редактирование документов в реальном времени на Yjs — Reverb (без отдельного сервиса) или внешний хаб Hocuspocus; рукопожатие токена, права из ability и развёртывание hocuspocus-server.
outline: deep
---

# Совместное редактирование

Vaulter редактирует **узлы-документы** (`kind = document`, контент в
`v_node_document_versions`) совместно в реальном времени. Бесконфликтное слияние
обеспечивает **Yjs** — бинарное CRDT-состояние является **источником истины**; JSON TipTap
это производная проекция. Транспорт скрыт за контрактом `NodeCollaborationDriver`, с двумя
реализациями на выбор через конфиг.

::: warning Перевод в процессе
На русском пока доступны разделы по выбору драйвера, конфигу и развёртыванию. Полное
описание потока рукопожатия, контракта вебхуков и фронтенд-обвязки `@vaulter/vue` — на
английской странице [Realtime Collaboration](/guide/collaboration).
:::

## Два драйвера

Yjs остаётся источником истины в обоих случаях; различается только транспорт.

| Драйвер | Транспорт | Отдельный сервис? | Авторизация |
|---|---|---|---|
| **`reverb`** (по умолчанию) | Yjs поверх Laravel Reverb (приватный канал) | Нет — чистый Laravel | Авторизация подписки через channel-auth Laravel |
| **`hocuspocus`** | Внешний Node-хаб Hocuspocus (`hocuspocus-server/`) | Node-сервис | Подписанный room-JWT + вебхуки сервер-к-серверу |

Драйвер выбирается через `config('vaulter.collaboration.driver')` (или переменную
окружения `VAULTER_COLLAB_DRIVER`).

::: tip Начните с Reverb
Драйвер по умолчанию `reverb` **не требует отдельного сервиса**. Хаб Hocuspocus имеет смысл
разворачивать, когда нужен выделенный Node-сервер совместного редактирования. Переключиться
можно одним конфигом — формат данных, который потребляет Vue-клиент, идентичен.
:::

## Конфигурация

Все ключи находятся под `config('vaulter.collaboration')`:

```php
// config/vaulter.php
'collaboration' => [
    'driver' => env('VAULTER_COLLAB_DRIVER', 'reverb'),   // reverb | hocuspocus
    'channel_prefix' => 'vaulter.documents',              // пространство имён комнаты
    'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),

    'drivers' => [
        'reverb' => [
            'connection' => env('VAULTER_COLLAB_CONNECTION', 'reverb'),
        ],
        'hocuspocus' => [
            'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
            'secret' => env('VAULTER_COLLAB_SECRET'),      // ключ подписи room-JWT (HS256)
            'token_ttl' => 3600,                           // секунды
        ],
    ],
],
```

::: warning Фича-флаг
Эндпоинты совместного редактирования (`nodes/{node}/collab/token`, а также вебхуки
`collab/authenticate` / `collab/store`) монтируются только при включённой фиче
**`document_versions`** (схема `v_node_document_versions`). Если запрос отдаёт 404 —
проверьте `config('vaulter.features.document_versions')`.
:::

## Reverb (по умолчанию, без отдельного сервиса)

```bash
composer require laravel/reverb
php artisan reverb:install
php artisan reverb:start
```

При `driver = reverb` поле `token` сессии — это имя **приватного канала**
(`vaulter.documents.{nodeId}`), а `connection` — имя broadcast-соединения. Vue-клиент
подключается к каналу через Laravel Echo; channel-auth авторизует подписку через
`AccessResolver`. Room-JWT не выпускается, обратных вызовов в Laravel нет.

::: warning Мост Echo↔Yjs предоставляете вы
Reverb — это broadcast-транспорт Laravel, а не провайдер Yjs. Для драйвера `reverb` нужно
передать `createReverbProvider`, который связывает Laravel Echo и awareness Yjs (см.
английскую страницу). Для Hocuspocus мост не нужен.
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
      # ДОЛЖЕН совпадать с vaulter.collaboration.webhook_secret на стороне Laravel.
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
| `VAULTER_COLLAB_WEBHOOK_SECRET` | *(не задан)* | Общий секрет в заголовке `X-Vaulter-Collab-Secret`. **Должен совпадать** с `vaulter.collaboration.webhook_secret` на стороне Laravel. Если не задан — хаб аутентифицирует вебхуки только пересланным room-JWT. |
| `VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS` | `10000` | Таймаут round-trip вебхука в мс. |

::: danger Согласуйте секреты
`VAULTER_COLLAB_WEBHOOK_SECRET` на **хабе** должен совпадать с
`vaulter.collaboration.webhook_secret` на **Laravel** (одно и то же имя переменной с обеих
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
