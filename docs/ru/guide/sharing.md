---
title: Публичные ссылки (share)
description: "Bearer-ссылки (share) — отдельный публичный канал, разрешаемый по токену: он выдаёт ровно свою битовую маску разрешений на своём наборе узлов, пересечённую с item-safety."
outline: deep
---

# Публичные ссылки (share)

**Bearer-ссылка (share)** — это секретный токен, который даёт доступ к одному узлу (и,
опционально, к его поддереву) **без того, чтобы получатель был зарегистрированным субъектом**.
Это **отдельный канал совместного доступа**: публичная ссылка (share) разрешается в *собственном*
проходе и выдаёт **ровно свою битовую маску разрешений** на своём наборе узлов, пересечённую с
item-safety. Она **никогда не объединяется с identity-ACL**: `AccessResolver` не вызывается для
запроса, авторизованного по share.

::: info Два разных канала
Эта страница описывает публичные ссылки на **узлы** (`v_node_shares`, канал Drive). Легаси-share
на **документы** (`GET /shares/{token}`, `Vaulter\Models\Documents\DocumentShare`) — это отдельная,
более старая поверхность, которая выдаёт stringly-typed уровень на `Document`. Новый код должен
использовать публичные ссылки на узлы.
:::

## Как это работает

Выпуск и разрешение публичной ссылки управляются двумя разными авторитетами:

- **Выпуск (minting)** ссылки идёт через **identity-ACL**: аутентифицированный владелец должен
  обладать `Ability::Share` на узле (бит `SHARE`). Это гейт на то, *кто вправе создать публичную
  ссылку*.
- **Разрешение (resolving)** ссылки — это **отдельный проход совместного доступа**: у публичных
  эндпоинтов нет auth-middleware, есть только `ResolveShareToken`, который хеширует токен, находит
  живой share, прогоняет гейты, пересекает с item-safety и создаёт `ShareContext`. Этот контекст
  выдаёт только биты самой ссылки на её наборе узлов — ничего унаследованного от какой-либо
  identity.

Сырой токен **никогда не хранится**. Персистится только его хеш `sha256` (`token_hash`, `UNIQUE`
`char(64)`) и короткий, несекретный `token_prefix`. Сырой токен возвращается создателю **ровно
один раз** в момент выпуска (`MintedShareLink`) и не восстанавливается заново.

## Поверхность владельца

`ShareController` (`Vaulter\Http\Controllers\Sharing\ShareController`) — это аутентифицированная
поверхность владельца. Каждое действие требует `Ability::Share` на узле, что обеспечивает
`AccessResolver`, — отказ превращается в `403` через `PermissionDeniedException`.

| Метод    | Маршрут                              | Действие  | Право (ability) |
| -------- | ------------------------------------ | --------- | -------------- |
| `GET`    | `/nodes/{node}/shares`               | `index`   | `Share` на узле |
| `POST`   | `/nodes/{node}/shares`               | `store`   | `Share` на узле |
| `DELETE` | `/nodes/{node}/shares/{share}`       | `destroy` | `Share` на узле |

Маршруты монтируются под настроенным префиксом (по умолчанию `api/vaulter`). Идентификатор
`{share}` — это простой скаляр (не route-model-bound): `destroy` проверяет, что он принадлежит
авторизованному узлу, прежде чем отзывать, поэтому чужой share id никогда нельзя отозвать через
этот узел (и он даёт `404`, ничего не выдающий).

### Создать ссылку

`POST /nodes/{node}/shares` выпускает ссылку и возвращает сырой токен **один раз** (`201`). Все
входные поля опциональны; `permissions` по умолчанию — обычный `READ` (безопасный минимум для
публичной ссылки). Провалидированный запрос (`StoreShareLinkRequest`) принимает:

| Поле              | Тип       | По умолчанию     | Примечания |
| ----------------- | --------- | ---------------- | ----- |
| `permissions`     | int       | `1` (`READ`)     | Битовая маска CRUDS, ограниченная `0..31` (`Permission::allBits()`). |
| `level`           | string    | `reader`         | `reader`/`commenter`/`writer`/`organizer`/`owner`; если `permissions` не задан, используются биты `bits()` этого уровня. |
| `scope`           | string    | `item`           | `item` (только узел) или `subtree` (узел + его потомки). |
| `password`        | string    | `null`           | 1–255 символов; хешируется до попадания в БД (`Hash::make`) — сырым никогда не хранится. |
| `require_login`   | bool      | `false`          | Требовать аутентифицированного пользователя хоста для разрешения. |
| `allow_discovery` | bool      | `false`          | Несекретный флаг обнаружения для UX на стороне владельца. |
| `expires_at`      | ISO-8601  | `null` (никогда) | Должен быть `after:now`. |
| `max_uses`        | int       | `null` (∞)       | Бюджет использований; `min:1`. |

::: code-group

```bash [curl]
# Выпустить read-only subtree-ссылку с паролем и сроком жизни 7 дней.
curl -X POST https://app.test/api/vaulter/nodes/01J.../shares \
  -H 'Authorization: Bearer <user-token>' \
  -H 'Content-Type: application/json' \
  -d '{
        "level": "reader",
        "permissions": 1,
        "scope": "subtree",
        "password": "s3cret",
        "expires_at": "2026-07-07T00:00:00Z",
        "max_uses": 100
      }'
```

```php [PHP]
use Vaulter\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;

// Контроллер сначала авторизует Ability::Share; Action только выпускает + персистит.
$minted = app(CreateShareLinkAction::class)->execute(
    node: $node,
    data: new ShareLinkInput(
        permissions: Permission::Read->value,
        level: GrantLevel::Reader,
        scope: GrantScope::Subtree,
        password: 's3cret',
        requireLogin: false,
        allowDiscovery: false,
        expiresAt: now()->addDays(7),
        maxUses: 100,
        grantedBy: $actorId,
    ),
);

// Сырой токен здесь и ТОЛЬКО здесь — возвращается ровно один раз.
$rawToken = $minted->token;   // напр. "kQ3...48-символьный..."
$share    = $minted->share;   // персистированный NodeShare (только token_hash)
```

```vue [Vue]
<script setup lang="ts">
import { useNodeShares } from '@vaulter/vue'

const { create, lastCreated, clearLastCreated } = useNodeShares(nodeId)

async function mint() {
  const created = await create({
    level: 'reader',
    scope: 'subtree',
    password: 's3cret',
    maxUses: 100,
  })
  // created.token + created.url отдаются ОДИН раз через lastCreated.
  navigator.clipboard.writeText(created.url)
}
</script>
```

:::

Ответ на создание несёт сырой `token` и полный `url` (собранный из
`config('vaulter.share.url_base')` + токен). **Каждый ответ list/show их опускает** — токен не
восстановить из сохранённой строки, так что зафиксируйте его прямо сейчас.

```json
{
  "data": {
    "id": "01J...",
    "node_id": "01J...",
    "token_prefix": "kQ3aB7xZ",
    "permissions": 1,
    "level": "reader",
    "scope": "subtree",
    "has_password": true,
    "require_login": false,
    "allow_discovery": false,
    "expires_at": "2026-07-07T00:00:00.000000Z",
    "max_uses": 100,
    "use_count": 0,
    "revoked_at": null,
    "created_at": "2026-06-30T10:00:00.000000Z",
    "token": "kQ3aB7xZ...48-char-raw-token...",
    "url": "/share/kQ3aB7xZ...48-char-raw-token..."
  }
}
```

### Список ссылок

`GET /nodes/{node}/shares` возвращает живые + отозванные ссылки узла с курсорной пагинацией
(`per_page`, 1–200, по умолчанию 50). Список несёт форму гранта, гейты и счётчики жизненного цикла
(`use_count`, `max_uses`, `expires_at`, `revoked_at`) — но **никогда** `token` или `token_hash`.
Несекретный `token_prefix` отдаётся, чтобы владелец мог узнать ссылку в своём UI.

### Отозвать ссылку

`DELETE /nodes/{node}/shares/{share}` проставляет `revoked_at`, чтобы share **больше никогда не
разрешался** (resolver фильтрует по `revoked_at`). Операция **идемпотентна** — повторный отзыв уже
отозванного share — это тихий no-op, который ничего не эмитит.

```php
use Vaulter\Actions\Sharing\RevokeShareLinkAction;

app(RevokeShareLinkAction::class)->execute(share: $share, actorId: $actorId);
```

## Публичная поверхность

`PublicShareController` — это публичная поверхность, разрешаемая по токену. У её маршрутов **нет
auth-middleware** — только `ResolveShareToken`, который уже создал `ShareContext`. Единственная
авторизация здесь — это собственная битовая маска share на его собственном наборе узлов.

| Метод  | Маршрут                     | Действие   | Требуемый бит |
| ------ | --------------------------- | ---------- | ------------ |
| `GET`  | `/share/{token}`            | `show`     | `View` (`READ`) |
| `GET`  | `/share/{token}/nodes`      | `nodes`    | `View` (`READ`), только `scope=subtree` |
| `GET`  | `/share/{token}/download`   | `download` | `Download` (`READ`) |

- **`show`** возвращает метаданные целевого узла (`SharedNodeResource` — намеренно легче, чем
  ресурс узла на стороне владельца; в нём **нет** карты прав на конкретного актора). Для
  папки-share со `scope=subtree` непосредственные дети встраиваются, чтобы держатель мог пройтись
  на один уровень без второго вызова.
- **`nodes`** перечисляет прямых детей в области видимости — **только** когда `scope=subtree`.
  Share со `scope=item` никогда не листит; он открывает ровно один узел (иначе `404`).
- **`download`** стримит текущую head-версию целевого файла. Бюджет использований потребляется
  **атомарно до стриминга** (единственный условный `UPDATE`, ограниченный `max_uses`), поэтому
  запрос сверх бюджета проигрывает гонку и отклоняется, прежде чем будет отдан хоть один байт.
  Предпочтителен `temporaryUrl` от `StorageDriver` (подписанный редирект на 5 минут); иначе байты
  стримятся с диска.

::: code-group

```bash [curl]
# Пароль едет в заголовке, чтобы секрет не попал в URL/логи.
curl https://app.test/api/vaulter/share/<token> \
  -H 'X-Share-Password: s3cret'

# Листинг поддерева (только scope=subtree):
curl https://app.test/api/vaulter/share/<token>/nodes \
  -H 'X-Share-Password: s3cret'

# Скачать файл за ссылкой:
curl -L https://app.test/api/vaulter/share/<token>/download \
  -H 'X-Share-Password: s3cret' -o file.bin
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

`ResolveShareToken` переводит `ShareResult` от resolver'а в HTTP-статус. Причины отказа намеренно
слиты воедино, чтобы зонд не мог определить, *какой именно* гейт не прошёл.

| Исход              | Статус | Когда |
| ------------------ | ------ | ---- |
| **Ok**             | пропуск | Живой share разрешился; `ShareContext` сохранён. |
| **NotFound**       | `404`   | Неверный / просроченный / отозванный / превысивший `max_uses` токен, **или** цель не прошла item-safety (в корзине / soft-deleted / отвязана). |
| **PasswordRequired** | `401` | Share защищён паролем, а пароль не передан или неверен. Тело несёт `"password_required": true`. |
| **LoginRequired**  | `401`   | У share стоит `require_login`, а аутентифицированного пользователя хоста нет. Тело несёт `"login_required": true`. |

::: tip Пароль в заголовке против query
Middleware читает пароль **сначала** из заголовка `X-Share-Password` (чтобы секрет никогда не
попал в URL или access-log), затем откатывается к параметру `password` в query/body.
:::

## Область видимости и item-safety

Share выдаёт свои биты на ограниченном **наборе узлов** (`ShareContext`):

- **`scope=item`** → только сам расшаренный узел.
- **`scope=subtree`** → расшаренный узел **плюс его потомки**, ограниченные **тем же деревом
  хранилища**. Обход поддерева никогда не может уйти к соседнему узлу, к предку или к другому
  хранилищу — `containsNode` отвергает любого кандидата за границей хранилища, поэтому
  subtree-share никогда нельзя использовать, чтобы выбраться из своего дерева.

Каждое разрешение дополнительно проходит **item-safety** (`§2.7`): целевой узел не должен быть в
корзине (`trashed_at`) или soft-deleted и должен принадлежать реальному хранилищу (граница
тенант/хранилище). Share на небезопасном узле разрешается в `NotFound` — **неотличимо от неверного
токена**. При скачивании блоб дополнительно должен быть `Committed` (pending- или tombstoned-блоб
никогда не отдаётся публично).

## Модель безопасности

Защита зашита в код, а не прикручена сверху:

- **Сырой токен никогда не персистится.** Хранятся только `sha256(token)` и несекретный префикс;
  сырой токен возвращается один раз. (`ShareToken::hash`, `MintedShareLink`.)
- **Timing-safe сравнение.** Даже после совпадения по хешу resolver перепроверяет через
  `hash_equals` (`ShareToken::hashesMatch`), чтобы путь разрешения никогда не был timing-оракулом.
- **Непрозрачные отказы.** Неверный / просроченный / отозванный / сверх бюджета / небезопасная цель
  — всё схлопывается в единый `404`, поэтому зонд не может отличить «неверный токен» от «цель в
  корзине».
- **Проверка живости в момент чтения.** Срок, отзыв и бюджет использований проверяются **в момент
  чтения**, а не по крону — просроченный или отозванный share просто никогда не разрешается
  (`NodeShare::scopeLive`, `isExhausted`).
- **Атомарный бюджет без гонок.** `max_uses` обеспечивается единственным условным `UPDATE`
  (`consumeUse`), поэтому два одновременных публичных запроса никогда не проталкивают `use_count`
  за бюджет.
- **Только allow.** Share — это allow-only грант ровно своих битов; вето-запрет из identity-ACL на
  этом пути не участвует. `ShareContext::allows()` читает чисто битовую маску самого share.

## Конфигурация

```php
// config/vaulter.php
'share' => [
    // \Vaulter\Contracts\Sharing\ShareTokenGenerator, которым выпускается сырой токен.
    // Хранится только его хеш sha256. По умолчанию: RandomShareTokenGenerator (48 символов).
    'token_generator' => RandomShareTokenGenerator::class,

    // Публичная база, из которой в ответе на создание собирается URL share (сырой токен
    // дописывается в конец). env: VAULTER_SHARE_URL_BASE
    'url_base' => env('VAULTER_SHARE_URL_BASE', '/share'),

    // Сколько ведущих символов сырого токена хранится как несекретный префикс.
    'prefix_length' => 8,
],
```

Сменяемая модель `NodeShare` привязывается через `config('vaulter.models.node_share')`, а имя её
таблицы — через `config('vaulter.table_names.node_shares')`. Дефолтный генератор share-токена
выпускает 48-символьную случайную буквенно-цифровую строку; замените его на короткие/префиксные
токены, привязав собственный `ShareTokenGenerator`, — см. [Свой генератор
share-токенов](/ru/guide/recipes).

## События

Оба события реализуют `ShouldDispatchAfterCommit`, поэтому слушатель никогда не видит незакоммиченную
строку. Ни одно не несёт сырого токена (персистированный `NodeShare` держит только хеш).

| Событие                                        | Диспатчится, когда |
| ---------------------------------------------- | --------------- |
| `Vaulter\Events\Sharing\NodeShareCreated`      | Ссылка выпущена. Несёт `NodeShare` + `actorId`. |
| `Vaulter\Events\Sharing\NodeShareRevoked`      | Ссылка отозвана (только при реальной смене состояния — повторный отзыв тихий). |

## См. также

- [Авторизация](/ru/concepts/authorization) — identity-ACL, гейтящий *кто вправе выпустить* ссылку.
- [Загрузки](/ru/guide/uploads) — путь резюмируемого приёма, создающий файловые узлы, которые вы
  расшариваете.
- [Свой генератор share-токенов](/ru/guide/recipes) — сменить формат токена.
- [Frontend-composables](/ru/guide/vue) — `useNodeShares` и публичный namespace клиента.
