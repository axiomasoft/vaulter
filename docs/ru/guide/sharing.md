---
title: Публичные ссылки (share)
description: "Bearer-ссылки (share) — отдельный публичный канал, разрешаемый по токену: он даёт ровно способности одной роли выбранной аудитории на своём наборе узлов, пересечённые с item-safety."
outline: deep
---

# Публичные ссылки (share)

**Bearer-ссылка (share)** — это секретный токен, который даёт доступ к одному узлу (и,
опционально, к его поддереву) **без того, чтобы получатель был зарегистрированным субъектом**.
Это **отдельный канал совместного доступа**: ссылка разрешается в *собственном* проходе и даёт
**ровно способности своей роли** на своём наборе узлов, пересечённые с item-safety. Она
**никогда не объединяется с identity-ACL**: `AccessResolver` не вызывается для запроса,
авторизованного по ссылке.

У ссылки четыре значимых настройки:

| Настройка  | Значения | Смысл |
| ---------- | -------- | ----- |
| **role**   | `previewer`, `reader`, `commenter`, `writer` | Что может держатель. Previewer видит узел, но никогда не получает оригинал (нет `download`). `organizer` и `owner` расшарить нельзя. |
| **audience** | `anyone`, `authenticated`, `tenant` | Кто может открыть: одного токена достаточно, нужен вошедший пользователь хоста или вошедший пользователь того же арендатора, что и drive. |
| **scope**  | `item`, `subtree` | Только узел или узел и его потомки до ближайшей границы наследования. |
| **generation** | целое | Растёт при каждой ротации; завершает все визиты, открытые прежним токеном. |

::: info Два разных канала
Эта страница описывает ссылки на **узлы** (`v_node_shares`, канал Drive). Легаси-ссылки на
**документы** (`GET /shares/{token}` без версии, `Vaulter\Models\Documents\DocumentShare`) —
отдельная, более старая поверхность, выдающая строковый уровень на `Document`. Новый код должен
использовать ссылки на узлы.
:::

## Роли и аудитории

| Роль        | Способности | Аудитория |
| ----------- | ----------- | --------- |
| `previewer` | `view` | любая |
| `reader`    | `view`, `download` | любая |
| `commenter` | `view`, `download`, `comment` | любая |
| `writer`    | commenter + `edit`, `create`, `move`, `copy`, `trash`, `restore` | только `authenticated` или `tenant` |
| `organizer`, `owner` | — | никогда (`share_role_not_allowed`) |

Версия роли хранится вместе со ссылкой (`role_key`, `role_version`) и навсегда сохраняет свой
смысл. Пара проверяется заново при **каждом** визите: сохранённое сочетание, которое правила
больше не допускают, разрешается в `404`.

`tenant` означает, что посетитель вошёл **и** `TenantResolver::current()` хоста равен арендатору
drive — точное сравнение ключей арендаторов, никогда не суффикс e-mail. Посетитель из другого
арендатора получает тот же непрозрачный `404`, что и при неверном токене. Нужен
`vaulter.tenancy.enabled`; без арендаторов создание ссылки `tenant` бросает
`InvalidArgumentException`.

## Как это работает

Выпуском и разрешением ссылки управляют два разных органа:

- **Выпуск** ссылки идёт через **identity-ACL**: аутентифицированный владелец должен иметь
  `Ability::Share` на узле. Это гейт на то, *кто вправе создать публичную ссылку*.
- **Разрешение** ссылки — это **отдельный проход совместного доступа**: у публичных маршрутов нет
  auth-middleware, только `ResolveShareToken`, который хеширует токен, находит живую ссылку,
  прогоняет гейты, пересекает с item-safety и создаёт `ShareContext`. Этот контекст даёт только
  способности роли на её наборе узлов — ничего унаследованного от какой-либо идентичности.

Сырой токен **никогда не хранится**. Это `base64url(random_bytes(32))` (43 символа). Сохраняются
только его хеш `sha256` (`token_hash`, `UNIQUE` `char(64)`) и короткий несекретный
`token_prefix`. Сырой токен возвращается создателю **ровно один раз** при выпуске
(`MintedShareLink`) и не выводится повторно.

## Поверхность владельца

Аутентифицированные маршруты владельца в [REST API v1](/ru/reference/rest-api#ссылки-shares)
вызывают `Vaulter::drive($drive)->actingAs($user)->shares()`. Каждое действие требует
`Ability::Share` на узле: кто видит узел, но не может его расшарить, получает
`403 permission_denied`, кто не видит — `404 node_not_found`.

| Метод    | Маршрут                                      | Имя              |
| -------- | -------------------------------------------- | ---------------- |
| `GET`    | `/v1/nodes/{node}/shares`                    | `shares.index`   |
| `POST`   | `/v1/nodes/{node}/shares`                    | `shares.store`   |
| `PATCH`  | `/v1/nodes/{node}/shares/{share}`            | `shares.update`  |
| `POST`   | `/v1/nodes/{node}/shares/{share}/rotate`     | `shares.rotate`  |
| `DELETE` | `/v1/nodes/{node}/shares/{share}`            | `shares.destroy` |

Маршруты монтируются под настроенным префиксом плюс `/v1` (по умолчанию `api/vaulter/v1`) и
называются `vaulter.v1.*`. `{share}` — простой id: изменение, ротация и отзыв проверяют, что он
принадлежит авторизованному узлу, поэтому чужую ссылку нельзя изменить через этот узел (и ответ —
ничего не раскрывающий `404`).

### Создать ссылку

`POST /v1/nodes/{node}/shares` выпускает ссылку и возвращает сырой токен **один раз** (`201`).
Все поля необязательны. Проверенный запрос (`StoreShareLinkRequest`) принимает:

| Поле         | Тип      | По умолчанию   | Примечание |
| ------------ | -------- | -------------- | ---------- |
| `role`       | строка   | `reader`       | Ключ роли. Какие роли подходят какой аудитории, решает Action (`share_role_not_allowed`). |
| `audience`   | строка   | `anyone`       | `anyone`, `authenticated` или `tenant`. |
| `scope`      | строка   | `item`         | `item` (только узел) или `subtree` (узел + потомки). |
| `password`   | строка   | `null`         | 1–255 символов; хешируется до записи в БД (`Hash::make`) — в открытом виде не хранится. |
| `expires_at` | ISO-8601 | сейчас + 72 ч  | Должно быть `after:now`, в пределах `vaulter.sharing.max_ttl_hours` **и** `maxShareLinkTtlDays` профиля drive. |
| `max_uses`   | int      | `null` (∞)     | Число успешных визитов; `min:1`. |

::: code-group

```bash [curl]
# Ссылка только для чтения, на поддерево, с паролем, для вошедших пользователей, на 7 дней.
curl -X POST https://app.test/api/vaulter/v1/nodes/01J.../shares \
  -H 'Authorization: Bearer <user-token>' \
  -H 'Content-Type: application/json' \
  -d '{
        "role": "reader",
        "audience": "authenticated",
        "scope": "subtree",
        "password": "s3cret",
        "expires_at": "2026-07-07T00:00:00Z",
        "max_uses": 100
      }'
```

```php [PHP]
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Sharing\ShareAudience;

// shares() авторизует Ability::Share на узле; Action только выпускает и сохраняет.
$minted = Vaulter::drive($owner)->actingAs($user)->shares()->create($node, new ShareLinkInput(
    role: 'reader',
    audience: ShareAudience::Authenticated,
    scope: GrantScope::Subtree,
    expiresAt: now()->addDays(7),
    password: 's3cret',
    maxUses: 100,
));

// Сырой токен есть здесь и ТОЛЬКО здесь — возвращается ровно один раз.
$rawToken = $minted->token;   // 43 символа
$link     = $minted->link;    // ShareLinkSnapshot (без токена)
```

```vue [Vue]
<script setup lang="ts">
import { useNodeShares } from '@vaulter/vue'

const { create, lastCreated, clearLastCreated } = useNodeShares(nodeId)

async function mint() {
  const created = await create({
    role: 'reader',
    audience: 'authenticated',
    scope: 'subtree',
    password: 's3cret',
    maxUses: 100,
  })
  // created.token + created.url доступны ОДИН раз через lastCreated.
  navigator.clipboard.writeText(created.url)
}
</script>
```

:::

Ответ на создание несёт сырой `token` и полный `url` (собранный из
`config('vaulter.sharing.url_base')` + токен). **Все остальные ответы их опускают** — токен не
восстановить из сохранённой строки, поэтому сохраните его сейчас. Ответ не сохраняется кэшем
(`Cache-Control: no-store`).

```json
{
  "data": {
    "id": "01J...",
    "node_id": "01J...",
    "role": "reader",
    "role_version": 1,
    "audience": "authenticated",
    "scope": "subtree",
    "expires_at": "2026-07-07T00:00:00.000000Z",
    "max_uses": 100,
    "use_count": 0,
    "has_password": true,
    "token_generation": 1,
    "created_by": "42",
    "created_at": "2026-06-30T10:00:00.000000Z",
    "token": "kQ3aB7xZ...43-char-raw-token...",
    "url": "/share/kQ3aB7xZ...43-char-raw-token..."
  }
}
```

### Список ссылок

`GET /v1/nodes/{node}/shares` возвращает живые ссылки узла как `{ "data": [ … ] }` (у узла мало
ссылок, поэтому список без страниц). Каждая несёт роль, аудиторию, scope, флаг пароля, поколение
токена и счётчики (`use_count`, `max_uses`, `expires_at`) — но **никогда** `token`, его хеш или
префикс.

### Изменить ссылку

`PATCH /v1/nodes/{node}/shares/{share}` меняет `expires_at`, `max_uses`, `password` и `audience`
**живой** ссылки. **Токен не меняется.** Пропущенное поле остаётся; `"password": null` снимает
пароль, `"max_uses": null` — лимит. Роль и scope задаются при создании — другой грант это другая
ссылка, — а смена аудитории отклоняется (`share_role_not_allowed`), если сохранённая роль ей не
подходит. Новый срок подчиняется тем же границам, что и при создании. Смена пароля или аудитории
завершает сессии ссылки, открытые до изменения.

```php
use Vaulter\Data\Sharing\ShareLinkUpdate;

Vaulter::drive($owner)->actingAs($user)->shares()->update($node, $share, new ShareLinkUpdate(
    expiresAt: now()->addDay(),
    removePassword: true,
));
```

### Ротация ссылки

`POST /v1/nodes/{node}/shares/{share}/rotate` заменяет токен. **Старый токен перестаёт
разрешаться в момент коммита ротации**, `token_generation` растёт на единицу, поэтому все визиты,
открытые старым токеном, недействительны, а новый сырой токен возвращается **один раз**. Роль,
аудитория, срок, лимит, пароль и `use_count` сохраняются. Ротация отозванной ссылки — `404`.

```php
$minted = Vaulter::drive($owner)->actingAs($user)->shares()->rotate($node, $share);
$minted->token; // новый сырой токен, показывается один раз
```

### Отозвать ссылку

`DELETE /v1/nodes/{node}/shares/{share}` проставляет `revoked_at`, и ссылка **больше никогда не
разрешается** (resolver фильтрует по `revoked_at`). Операция **идемпотентна** — повторный отзыв
уже отозванной ссылки — тихий no-op, ничего не эмитящий.

## Публичная поверхность

Публичные маршруты, разрешаемые по токену, работают на стеке `public`: **без auth-middleware**,
с ограничителем `throttle:vaulter-public`, затем `ResolveShareToken`, который создаёт
`ShareContext`. Единственная авторизация здесь — роль ссылки на её собственном наборе узлов.
Каждый ответ — включая ошибки и ограничение частоты — `Cache-Control: private, no-store` с
`Referrer-Policy: no-referrer`, чтобы ни кэш, ни страница, открытая по ссылке, не сохранили URL с
токеном.

| Метод  | Маршрут                               | Имя                      | Нужная способность |
| ------ | ------------------------------------- | ------------------------ | ------------------ |
| `GET`  | `/v1/shares/{token}`                  | `public-shares.show`     | `view` |
| `GET`  | `/v1/shares/{token}/nodes`            | `public-shares.nodes`    | `view`, только `scope=subtree` |
| `GET`  | `/v1/shares/{token}/download?node=`   | `public-shares.download` | `download` |

- **`show`** возвращает метаданные целевого узла (`SharedNodeResource` — намеренно легче ресурса
  узла владельца; в нём **нет** карты способностей конкретного актора, а `id` и `parent_id` —
  непрозрачные проекции, никогда не настоящие id узлов).
- **`nodes`** перечисляет прямых детей в области ссылки курсорной страницей — **только** при
  `scope=subtree`. Ссылка `scope=item` ничего не перечисляет; она открывает ровно один узел
  (иначе `404 share_not_found`).
- **`download`** отдаёт файл потоком: сам узел ссылки или, с `?node=<непрозрачный id>`, одного из
  детей, которых показывает `nodes`, — любой другой id даёт `404 share_not_found`. Ссылка
  **previewer** получает `403 permission_denied`: оригинал никогда не выдаётся. Границы
  наследования ниже расшаренной папки применяются вживую, по текущему дереву. См.
  [Скачивание и отзыв](#скачивание-и-отзыв).

### Визиты и сессии ссылки

`max_uses` считает **успешные визиты**, а не запросы. Первый запрос, прошедший все гейты,
расходует одно использование (один условный `UPDATE`, ограниченный `max_uses`, `token_generation`
и `revoked_at`, поэтому два параллельных визита не перерасходуют лимит, а ротация или отзыв,
закоммиченные первыми, выигрывают), и ответ несёт подписанную **сессию ссылки** в заголовке
`X-Share-Session`. Отправляйте её обратно — следующие запросы относятся к тому же визиту:
открыть превью, листать список и скачивать стоит **одно** использование. Запрос без неё — новый
визит.

Сессия — это HMAC ссылки, её поколения и настроек, решающих, кто может её открыть (токен, роль,
аудитория, пароль); она действует час и никогда дольше срока ссылки. Она не несёт секрета и сама
ничего не даёт: каждый запрос всё равно проходит полный резолв, включая аудиторию, пароль и
проверки политики ниже. Посетитель, чья сессия уже засчитана, продолжает работать после
исчерпания лимита; каждый *новый* визит получает `404`.

::: code-group

```bash [curl]
# Пароль едет в заголовке, чтобы секрет не попал в URL или логи.
curl -i https://app.test/api/vaulter/v1/shares/<token> \
  -H 'X-Share-Password: s3cret'
# → X-Share-Session: eyJ2MXx...

# Остальная часть визита передаёт сессию и не тратит новых использований:
curl https://app.test/api/vaulter/v1/shares/<token>/nodes \
  -H 'X-Share-Password: s3cret' -H 'X-Share-Session: eyJ2MXx...'

# Скачать файл за ссылкой (reader, commenter, writer):
curl https://app.test/api/vaulter/v1/shares/<token>/download \
  -H 'X-Share-Password: s3cret' -H 'X-Share-Session: eyJ2MXx...' -o file.bin
```

```vue [Vue]
<script setup lang="ts">
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()

const node      = await client.nodeShares.publicResolve(token)   // GET /share/{token}
const children  = await client.nodeShares.publicNodes(token)     // GET /share/{token}/nodes
const dlUrl     = client.nodeShares.publicDownloadUrl(token)     // GET /share/{token}/download
</script>
```

:::

### Исходы разрешения

`ResolveShareToken` переводит `ShareResult` resolver'а в ответ problem+json. Причины отказа
намеренно слиты, чтобы зонд не мог определить, *какой* гейт не прошёл.

| Исход              | Ответ | Когда |
| ------------------ | ----- | ----- |
| **Ok**             | пропуск | Живая ссылка разрешилась; `ShareContext` сохранён, визит засчитан. |
| **NotFound**       | `404 share_not_found` | Неверный / истёкший / отозванный / заменённый токен, новый визит сверх `max_uses`, посетитель вне аудитории `tenant`, роль или срок, которые правила больше не допускают, **или** цель не прошла item-safety (в корзине / отвязана). |
| **PasswordRequired** | `401 share_password_required` | Ссылка защищена паролем, а пароль не передан или неверен. |
| **LoginRequired**  | `401 actor_required` | Аудитория `authenticated` или `tenant`, а вошедшего пользователя хоста нет. |

::: tip Пароль в заголовке или в query
Middleware читает пароль **сначала** из заголовка `X-Share-Password` (чтобы секрет не попал в URL
или access-log), затем из параметра `password` в query/body.
:::

## Срок жизни и политика drive

Ссылка всегда истекает. Без `expires_at` Action ставит «сейчас плюс
`vaulter.sharing.default_ttl_hours`»; явное значение не может превышать
`vaulter.sharing.max_ttl_hours`. Сверх того профиль drive может ограничить все ссылки своих
drive:

```php
$profile->drivePolicy(fn (DrivePolicyBuilder $policy) => $policy->maxShareLinkTtlDays(7));
```

С ограничением `expires_at` не может быть позже *сейчас + 7 дней* — при создании или изменении
ссылки (`422 share_link_ttl_exceeded`) **и при каждом визите**. Ужесточение политики поэтому
действует сразу: старая ссылка, чей срок дальше нового предела, разрешается в `404`, пока
владелец её не сократит.

## Область и item-safety

Ссылка даёт свою роль на ограниченном **наборе узлов** (`ShareContext`):

- **`scope=item`** → только сам расшаренный узел.
- **`scope=subtree`** → расшаренный узел **плюс его потомки** в **том же дереве drive**, вживую:
  доступ к потомку следует текущему дереву и останавливается на первом узле с
  `inherit_grants = false` (см. `restrictInheritance()`). Обход никогда не уходит к соседу,
  предку или в другой drive.

Каждое разрешение дополнительно проходит **item-safety** (`§2.7`): целевой узел не в корзине
(`trashed_at`) и принадлежит реальному drive (граница арендатора/drive), в профиле drive всё ещё
включён `share_links`, а выпустивший ссылку всё ещё имеет `Ability::Share` на узле. Ссылка на
небезопасный узел разрешается в `NotFound` — **неотличимо от неверного токена**. При скачивании
ревизия дополнительно должна быть пригодна к выдаче — закоммиченные байты и чистое состояние
проверки, — иначе ответ `423 content_unavailable` до любого байта.

## Скачивание и отзыв

Скачивания по ссылкам идут через ту же единственную точку выдачи, что и все аутентифицированные
скачивания ([Выдача файлов](/ru/reference/rest-api#выдача-файлов)): каждый запрос заново
разрешает ссылку (поколение токена, отзыв, срок, аудиторию, политику) и заново проверяет
состояние проверки точной ревизии до первого байта. Скачивания по ссылкам **всегда идут потоком**
через приложение, даже при `vaulter.downloads.delivery_mode` = `direct_signed`, поэтому
отозванная или заменённая ссылка перестаёт отдавать байты со следующего запроса. Уже начатое
скачивание не прерывается. Заголовки: `Content-Disposition: attachment` с RFC 6266 `filename*`,
`X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`, `Accept-Ranges: none` и
`Cache-Control: private, no-store`.

## Модель безопасности

Защита — в коде, а не прикручена сбоку:

- **Сырой токен не сохраняется.** Хранятся только `sha256(token)` и несекретный префикс; сырой
  токен возвращается один раз (`ShareToken::hash`, `MintedShareLink`). Токены, хеши и пароли не
  попадают в ленту активности, outbox, логи или исключения — и при изменении, и при ротации.
- **Сравнение с постоянным временем.** Даже после совпадения поиска по хешу resolver
  перепроверяет через `hash_equals` (`ShareToken::hashesMatch`), чтобы путь резолва не стал
  временным оракулом.
- **Непрозрачные отказы.** Неверный / истёкший / отозванный / заменённый / сверх лимита / чужой
  арендатор / небезопасная цель — все сводятся к одному `404`.
- **Живость при чтении.** Срок, отзыв, ротация, лимит и предел политики проверяются **при
  чтении**, никогда не cron'ом.
- **Атомарный лимит без гонок.** Визит засчитывается одним условным `UPDATE`, поэтому два
  параллельных визита не поднимут `use_count` выше лимита.
- **Только разрешение.** Ссылка — грант-разрешение ровно способностей своей роли; override
  identity-ACL на этом пути не участвует.

## Конфигурация

```php
// config/vaulter.php
'sharing' => [
    // \Vaulter\Contracts\Sharing\ShareTokenGenerator, выпускающий сырой токен.
    // Хранится только его sha256. По умолчанию: RandomShareTokenGenerator (43 символа).
    'token_generator' => RandomShareTokenGenerator::class,

    // Публичная основа URL ссылки в ответе на создание (к ней добавляется сырой
    // токен). env: VAULTER_SHARE_URL_BASE
    'url_base' => env('VAULTER_SHARE_URL_BASE', '/share'),

    // Сколько первых символов сырого токена хранится как несекретный префикс.
    'prefix_length' => 8,

    // Срок жизни ссылки по умолчанию и максимальный (часы).
    'default_ttl_hours' => env('VAULTER_SHARE_DEFAULT_TTL_HOURS', 72),
    'max_ttl_hours' => env('VAULTER_SHARE_MAX_TTL_HOURS', 720),
],
```

Заменяемая модель `NodeShare` задаётся через `config('vaulter.models.node_share')`, имя таблицы —
через `config('vaulter.table_names.node_shares')`. Генератор токенов по умолчанию выпускает 43
символа `base64url(random_bytes(32))`; замените его, привязав свой `ShareTokenGenerator`, — см.
[Свой генератор share-токенов](/ru/guide/recipes).

## Ошибки

| Код | Статус | Когда |
| --- | ------ | ----- |
| `share_role_not_allowed` | `422` | Роль нельзя выдать этой аудитории (writer для `anyone`; organizer или owner). |
| `share_link_ttl_exceeded` | `422` | `expires_at` позже, чем допускает `maxShareLinkTtlDays` профиля. |
| `unknown_role` | `422` | Ключ роли не зарегистрирован. |
| `permission_denied` | `403` | Ссылка previewer запросила оригинал, или у владельца нет `Share`. |
| `share_not_found` | `404` | Публичный запрос, который ничего не открывает (см. [Исходы разрешения](#исходы-разрешения)). |
| `share_password_required` | `401` | Ссылка с паролем без пароля. |
| `content_unavailable` | `423` | Ревизия файла ссылки не чистая. |

## События

Оба события диспатчатся синхронно внутри транзакции, поэтому строки активности и outbox
сохраняются атомарно с изменением. Ни одно не несёт сырого токена (сохранённый `NodeShare` держит
только хеш), а их контекст активности/outbox перечисляет `role`, `role_version`, `audience` и
`scope`.

| Событие                                        | Когда диспатчится |
| ---------------------------------------------- | ----------------- |
| `Vaulter\Events\Sharing\NodeShareCreated`      | Ссылка выпущена. Несёт `NodeShare` + `actorId`. |
| `Vaulter\Events\Sharing\NodeShareRevoked`      | Ссылка отозвана (только при реальной смене состояния — повторный отзыв тихий). |

Изменение и ротация ссылки пока не эмитят собственного события; конвейер событий 1.0 их
добавляет.

## См. также

- [Авторизация](/ru/concepts/authorization) — identity-ACL, решающий, *кто вправе выпустить* ссылку.
- [Загрузки](/ru/guide/uploads) — путь резюмируемого приёма, создающий файловые узлы, которые вы
  расшариваете.
- [Свой генератор share-токенов](/ru/guide/recipes) — сменить формат токена.
- [Frontend-composables](/ru/guide/vue) — `useNodeShares` и публичный namespace клиента.
