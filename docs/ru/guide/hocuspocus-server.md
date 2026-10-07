# Сервер Hocuspocus (Docker)

Опциональный Node-сервис, обеспечивающий **совместное редактирование документов** в реальном
времени (Yjs/TipTap). Это рекомендуемый — и «родной» для TipTap — бэкенд совместного редактирования.

> **Reverb — не транспорт для совместного редактирования.** Laravel Reverb — это шина
> широковещания общего назначения (уведомления, presence, активность). Совместное редактирование
> работает на Hocuspocus; драйвер совместного редактирования `reverb` считается устаревшим.
> См. [Совместное редактирование в реальном времени](./collaboration).

Он спроектирован как **внешний переиспользуемый образ** — один опубликованный образ обслуживает
много проектов. Вы **ссылаетесь** на него, настраиваете целиком через переменные окружения и
никогда не копируете его исходники в своё приложение:

```yaml
services:
  vaulter-collab:
    image: ghcr.io/axiomasoft/vaulter-hocuspocus:1   # pin @sha256:… in production
    ports: ["127.0.0.1:1234:1234"]   # loopback only — front it with a TLS reverse proxy
    environment:
      VAULTER_BACKEND_URL: "https://app.example.com"
      VAULTER_COLLAB_ALLOWED_ORIGINS: "https://app.example.com"
    restart: unless-stopped
```

Образ **не зависит от бэкенда**: он аутентифицирует и сохраняет данные, обращаясь обратно к
вебхукам *вашего* Laravel-приложения, поэтому один и тот же образ работает во всех проектах,
которые встраивают Vaulter.

## Как это устроено

```
 browser (writer/viewer) ──ws(token = room JWT, doc = room name)──▶  hub
        │                                                            │
        │                                  onConnect → Origin allowlist (CSWSH)
        │                                  onAuthenticate ─POST /collab/authenticate─┐
        │                                  onStoreDocument ─POST /collab/store───────┤
        └──── POST /api/vaulter/nodes/{node}/collab/token ──▶ Laravel ◀──────────────┘
                                                              persists yjs_state (bytea)
```

1. Клиент вызывает `POST /api/vaulter/nodes/{node}/collab/token` на Laravel и получает
   room JWT (`{room, sub, readOnly, exp}`, HS256) плюс `url` хаба.
2. Он открывает WebSocket к хабу, передавая этот JWT как **token**, а имя комнаты
   (`vaulter.documents.<nodeId>`) — как имя документа.
3. Хаб проверяет `Origin`, затем пересылает JWT + комнату на
   `POST {backend}/api/vaulter/collab/authenticate`. `{allowed:false}` (или любой не-2xx)
   отклоняет сокет; `readOnly` понижает уровень соединения, так что сервер отбрасывает правки
   зрителя.
4. `onStoreDocument` (с debounce) кодирует **бинарное** состояние Yjs в base64 и отправляет его
   на `POST {backend}/api/vaulter/collab/store`, который сохраняет его в
   `v_documents.yjs_state` (рабочее состояние). Бинарник Yjs — источник истины.

Хабу **не нужен** секрет для подписи JWT — Laravel выпускает токен, а клиент его пересылает; хаб
лишь ретранслирует его в вебхук, который и проверяет подпись.

## Контракт окружения

Вся конфигурация — через переменные окружения (12-factor; секреты не зашиты в образ). Хаб
**валидирует на старте и падает сразу (fail fast)**, если обязательные production-значения
отсутствуют.

### Подключение

| Переменная | По умолчанию | Назначение |
| --- | --- | --- |
| `VAULTER_COLLAB_PORT` | `1234` | WebSocket-порт, к которому подключаются клиенты (`ws(s)://host:PORT`). |
| `VAULTER_COLLAB_HEALTH_PORT` | `1235` | HTTP-порт health — `GET /health` → `200`. Отдельно от WS-порта. |
| `VAULTER_BACKEND_URL` | `http://localhost` | Базовый URL вашего Laravel-приложения, к которому хаб обращается обратно. **Обязательно в production.** |
| `VAULTER_API_PREFIX` | `api/vaulter` | Префикс маршрутов Vaulter. Вебхуки резолвятся в `{BACKEND}/{PREFIX}/collab/{authenticate,store}`. |
| `VAULTER_LARAVEL_WEBHOOK_URL` | *(производное)* | Явная полная база вебхуков; переопределяет две предыдущие переменные (reverse proxy / раздельный хост). |
| `VAULTER_COLLAB_WEBHOOK_SECRET` | *(не задано)* | Общий секрет, передаваемый в `X-Vaulter-Collab-Secret`. Должен совпадать с `vaulter-documents.collaboration.hub.webhook_secret` в Laravel. Не задан ⇒ хаб аутентифицирует вебхуки только по пересланному room JWT (никогда не «нараспашку»). |
| `VAULTER_COLLAB_WEBHOOK_TIMEOUT_MS` | `10000` | Таймаут туда-обратно для вебхука. |

### Безопасность

| Переменная | По умолчанию | Назначение |
| --- | --- | --- |
| `VAULTER_COLLAB_ALLOWED_ORIGINS` | *(пусто)* | **Защита от CSWSH.** Список ТОЧНЫХ браузерных origin через запятую, которым разрешено подключаться. **Обязательно в production** (без него хаб отказывается стартовать). `*` разрешает любой (только для dev). Не-браузерный клиент (без `Origin`) разрешён всегда — он не является вектором CSWSH и по-прежнему проходит через вебхук аутентификации. |
| `VAULTER_COLLAB_MAX_PAYLOAD_BYTES` | `4194304` (4 MiB) | Максимальный размер WebSocket-фрейма (дефолт `ws` в 100 MiB чрезмерно велик для обновлений Yjs). |
| `VAULTER_COLLAB_THROTTLE` | `15` | Число попыток подключения с одного IP до временного бана. |
| `VAULTER_COLLAB_THROTTLE_SECONDS` | `60` | Окно (в секундах), за которое считаются попытки. |
| `VAULTER_COLLAB_BAN_TIME` | `5` | Длительность бана (в минутах) после превышения лимита. |

### Liveness / нагрузка на сохранение

| Переменная | По умолчанию | Назначение |
| --- | --- | --- |
| `VAULTER_COLLAB_TIMEOUT_MS` | `30000` | Интервал heartbeat для обнаружения «мёртвого» пира. Согласуйте с idle-таймаутом вашего балансировщика/прокси. |
| `VAULTER_COLLAB_DEBOUNCE_MS` | `5000` | Debounce для `onStoreDocument` — больше = меньше записей на сохранение при активном редактировании. |
| `VAULTER_COLLAB_MAX_DEBOUNCE_MS` | `30000` | Жёсткий потолок перед принудительным сохранением (ограничивает худший случай окна несохранённых данных). |

### Масштабирование / эксплуатация

| Переменная | По умолчанию | Назначение |
| --- | --- | --- |
| `VAULTER_COLLAB_REDIS_URL` | *(не задано)* | Если задано, включает расширение Redis для **многоинстансной** синхронизации документов + awareness. Принимает полный URL `redis://`/`rediss://` (auth, TLS, db). |
| `VAULTER_COLLAB_INSTANCE_NAME` | `vaulter-hocuspocus` | Уникальное имя на каждый инстанс (корреляция логов; обязательно для fan-out через Redis). |
| `VAULTER_COLLAB_QUIET` | `true` | Подавить стартовый баннер. |
| `VAULTER_COLLAB_LOG` | `false` | Включить расширение Logger (подробное; выключайте при масштабе). |
| `NODE_ENV` | `production` | `production` ужесточает валидацию (обязательны allowlist origin + backend URL). Опубликованный образ по умолчанию `production`; задайте `development` для локального стенда с открытым origin. |

## Безопасность в production

- **Терминируйте TLS на reverse proxy и отдавайте только `wss://`.** Хаб говорит по `ws`;
  поставьте nginx/Traefik впереди, пробросьте WebSocket-upgrade и заблокируйте открытый текст.
  Держите read-таймаут прокси больше `VAULTER_COLLAB_TIMEOUT_MS`, чтобы он не рвал здоровые
  сокеты, но конечным.
- **Задайте `VAULTER_COLLAB_ALLOWED_ORIGINS`** равным точным origin вашего приложения. Никогда
  не используйте `*` в production. (В `production` хаб без этого не стартует.)
- **Держите `MAX_PAYLOAD` небольшим** — обновления Yjs инкрементальны и крохотны; низкий потолок
  притупляет DoS переразмеренными сообщениями. (Теперь хаб применяет этот потолок к нижележащему
  серверу `ws`; дефолт библиотеки в ~100 MiB не используется никогда.)
- **Room JWT короткоживущий** и привязан ровно к одному узлу; вебхук перепроверяет доступ,
  поэтому зритель не может писать никогда (сервер отбрасывает его обновления).
- **Никогда не выставляйте WebSocket-порт (1234) напрямую в интернет.** Привяжите его к
  loopback хоста / вашей приватной сети и достигайте только через reverse proxy. Референсный
  `docker-compose.yml` публикует его на `127.0.0.1` именно по этой причине.
- **Троттлинг по IP доверяет заголовкам прокси — прокси ОБЯЗАН их устанавливать.** Расширение
  Throttle выводит IP клиента из `X-Real-IP` / `X-Forwarded-For`, откатываясь к адресу сокета.
  Клиент, достучавшийся до хаба напрямую, может подделать эти заголовки и полностью обойти бан
  за флуд рукопожатий. Поэтому reverse proxy должен **перезаписывать** (не дописывать) оба
  заголовка реальным адресом клиента, а хаб не должен быть достижим иначе, чем через него.

Минимальный блок upgrade для nginx (обратите внимание на IP-заголовки — они несущие для
троттлинга):

```nginx
location /collab/ {
    proxy_pass http://vaulter-collab:1234/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    # OVERWRITE the client IP so a forged X-Real-IP / X-Forwarded-For can't defeat the throttle.
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 75s;   # > the hub heartbeat, but finite
}
```

Traefik делает это за вас: `forwardedHeaders` учитываются только от `trustedIPs`, поэтому,
оставив дефолт (не доверять ничему), вы заставляете Traefik переписывать
`X-Forwarded-For`/`X-Real-IP` из реального соединения. За любым прокси ограничьте
`trustedIPs`/`set_real_ip_from` самим прокси и держите хаб вне любого публичного интерфейса.

## Масштабирование (многоинстансное)

Запустите несколько инстансов за балансировщиком и задайте `VAULTER_COLLAB_REDIS_URL`, чтобы
клиенты, подключённые к разным инстансам, сходились на одном документе через Redis pub/sub.
Дайте каждому инстансу уникальное `VAULTER_COLLAB_INSTANCE_NAME`.

> Redis здесь — для **межинстансной синхронизации, а не для персистентности** (долговременным
> `yjs_state` по-прежнему владеет ваше Laravel-приложение). Учтите, что каждое сообщение
> обрабатывается на каждом инстансе, поэтому Redis даёт корректность/HA между узлами — а не
> сырую пропускную способность. Если вы можете детерминированно маршрутизировать данный документ
> на один узел (sticky-сессии / шардирование), этого fan-out можно избежать.

## Корректное завершение

Хаб останавливается по `SIGINT`/`SIGTERM` (`stopOnSignals` из Hocuspocus), давая незавершённым
окнам debounce сброситься перед выходом. Дайте оркестратору период `preStop`/drain, чтобы
существующие сокеты успели закрыться.

## Переиспользование между проектами

Образ намеренно не привязан к проекту. Направьте каждый деплой на его собственный Laravel-бэкенд
(`VAULTER_BACKEND_URL`) и origin; один и тот же тег
`ghcr.io/axiomasoft/vaulter-hocuspocus` обслуживает их все. В production закрепляйте digest
(`@sha256:…`), чтобы каждый потребитель запускал ровно один и тот же артефакт.

## Локальная разработка

Этот репозиторий поставляет исходники в `hocuspocus-server/` и `docker-compose.yml`, который
может либо тянуть опубликованный образ, либо собирать `build: .` из исходников. Для realtime-стенда
e2e см. [`e2e/README.md`](https://github.com/axioma-studio/vaulter/tree/main/e2e) и цель
`make e2e-hub`.
