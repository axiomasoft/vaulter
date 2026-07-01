---
title: Гранты
description: ACL-гранты на узлы — субъект, уровень, эффект, область и срок действия — экшены GrantNodeAccess / RevokeNodeAccess, REST-поверхность, события и Vue-composable useNodeGrants.
outline: deep
---

# Гранты

**Грант** — это одна строка в `v_node_grants`, которая говорит: *у этого субъекта такой
доступ к этому узлу*. Гранты — собственное ACL-хранилище Vaulter, сырьё, которое обходит и
сворачивает [`AccessResolver`](/ru/concepts/authorization). На этой странице разобраны модель
гранта, как записывать и отзывать гранты, REST-поверхность и порождаемые события.

::: info Куда встают гранты
Гранты — это **полоса node-ACL**. [Полоса ролей](/ru/guide/permission-drivers) (роли вашего
хоста) сворачивается отдельно. Грант может дать только тот доступ, что в нём заявлен — но
грант `deny` — это финальное вето, которое не переопределит ни одна роль.
:::

## Анатомия гранта

`Vaulter\Models\Access\NodeGrant` (таблица `v_node_grants`):

| Колонка | Тип | Смысл |
|---|---|---|
| `node_id` | id | узел, на котором сидит грант |
| `subject_type` | string | зарегистрированный morph-алиас — `user` / `role` / `team` / алиас хоста |
| `subject_id` | id | ключ субъекта, которому адресован грант |
| `level` | `GrantLevel` | `reader` / `commenter` / `writer` / `organizer` / `owner` |
| `permissions` | int | опциональные дополнительные CRUDS-биты, склеиваются по OR с битами уровня |
| `effect` | `Effect` | `allow` (расширяет) или `deny` (сужает — финальное вето) |
| `scope` | `GrantScope` | `item` (только этот узел) или `subtree` (спускается вниз) — по умолчанию `subtree` |
| `expires_at` | datetime? | срок действия, проверяемый на чтении; `null` = никогда не истекает |
| `granted_by` | id? | актор, создавший грант |
| `tenant_id` | id? | присутствует, когда включена мультитенантность |

Модель сменяема — направьте `config('vaulter.models.grant')` на подкласс (например, чтобы
добавить скоуп по тенанту). `inherited` / `inherited_from_node_id` зарезервированы под
опциональную материализованную read-модель и остаются `false`/`null`: источник истины — прямые
строки плюс дерево.

### Субъект

Грант адресуется `Vaulter\Access\ValueObjects\Subject` — паре `(type, id)`. `type` всегда
зарегистрированный morph-алиас, никогда не сырой FQCN. Драйвер ролей разворачивает актора в
список субъектов, чьи гранты применимы (см.
[`subjectsFor`](/ru/guide/permission-drivers#the-hostroledriver-contract)); из этого списка
Vaulter строит `WHERE (subject_type, subject_id) IN (...)`.

```php
use Vaulter\Access\ValueObjects\Subject;

$user = Subject::ofModel($actor);        // morph-алиас + ключ
$role = new Subject(type: 'role', id: 'editor');
$team = Subject::make('team', $teamId);
```

### Уровень + permissions

Грант хранит ранжированный `level`, опциональную дополнительную битовую маску `permissions`
либо и то, и другое. Резолвер нормализует грант в биты через `level->bits() | permissions`, так
что грант с уровнем плюс дополнительный бит `SHARE` даёт и то, и другое. Таблицу
уровень → биты см. в
[Права (abilities) и уровни](/ru/concepts/authorization#abilities-levels-and-the-cruds-bitmask).

### Эффект

`Vaulter\Enums\Access\Effect` — `allow` расширяет доступ, `deny` сужает. `deny` — это
**финальное вето** после прохода max/OR: роль хоста никогда не воскресит уровень, запрещённый
через deny, а `deny` на том же узле, что и `allow`, всегда побеждает. См.
[свёртку deny-вето](/ru/concepts/authorization#step-3-the-deny-veto-fold-explicit-beats-inherited).

### Область + наследование

`Vaulter\Enums\Access\GrantScope` — `item` действует только на узле, где он сидит; `subtree`
(по умолчанию) вдобавок спускается на потомков во время обхода предков, **пока** не встретится
узел-отсечка с `inherit_grants = false`. Наследуются вниз только гранты `subtree`
(`GrantScope::inheritsDownward()`).

### Срок действия

`expires_at` проверяется **на чтении**, никогда не по крону. Запрос грантов фильтрует
`expires_at IS NULL OR expires_at > now`, так что истёкшая строка до резолвера не доходит.

## Запись гранта

`Vaulter\Actions\Access\GrantNodeAccessAction` сохраняет грант (или deny). Он **идемпотентен**
по уникальному ключу гранта: повторная выдача тому же субъекту той же области/эффекта обновляет
уровень/биты/срок. Запись идёт в транзакции и порождает `NodeGrantCreated`.

```php
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Data\Access\GrantNodeAccessData;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantScope;

$grant = app(GrantNodeAccessAction::class)->execute(
    node: $node,
    data: new GrantNodeAccessData(
        subject: Subject::ofModel($collaborator),
        level: GrantLevel::Writer,
        effect: Effect::Allow,            // по умолчанию
        scope: GrantScope::Subtree,       // по умолчанию — спускается на потомков
        permissions: 0,                   // опциональные дополнительные CRUDS-биты
        expiresAt: now()->addDays(7),     // опционально
        grantedBy: $actor->getKey(),
    ),
);
```

::: warning Экшен не авторизует
`GrantNodeAccessAction` сохраняет; он не переавторизует. Вызывающая сторона (контроллер /
политика) обязана проверить, что актор может `Share`/`Manage` узел, **до** обращения к экшену.
HTTP-поверхность ниже делает ровно это.
:::

### Грант deny

Чтобы вырезать исключение внутри в остальном расшаренного поддерева, запишите `deny`:

```php
new GrantNodeAccessData(
    subject: Subject::ofModel($contractor),
    level: GrantLevel::Reader,
    effect: Effect::Deny,           // вето — перебивает более дальние и ролевые allow
    scope: GrantScope::Subtree,
);
```

Благодаря deny-вето это скрывает узел (и его поддерево — при области `subtree`) от подрядчика,
даже если вышестоящий `allow` или роль хоста иначе дали бы доступ.

## Отзыв грантов

`Vaulter\Actions\Access\RevokeNodeAccessAction` удаляет **все** гранты субъекта на узле — оба
эффекта, обе области — и возвращает число удалённых. Он порождает `NodeGrantRevoked` только
тогда, когда что-то реально отозвано (пустой отзыв — тихий no-op). Транзакционный.

```php
use Vaulter\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Access\ValueObjects\Subject;

$removed = app(RevokeNodeAccessAction::class)->execute(
    node: $node,
    subject: Subject::ofModel($collaborator),
    actorId: $actor->getKey(),
);
```

## REST-поверхность

Node-ACL выставлена под префиксом Drive (по умолчанию `api/vaulter`). **Каждое** действие
требует права (ability) `Share` на узле — только тот, кто может расшарить узел, может менять его
гранты. Отказ отдаёт 403 через `PermissionDeniedException`.

| Метод | Путь | Действие |
|---|---|---|
| `GET` | `nodes/{node}/grants` | список грантов на узле (курсорная пагинация) |
| `POST` | `nodes/{node}/grants` | выдать/запретить субъекту (201) |
| `DELETE` | `nodes/{node}/grants` | отозвать гранты субъекта (204) |

::: tip Где гранты проявляются на стороне листингов
Когда фича `grants` включена и активен драйвер `database`, **листинги** Drive сужаются до
достижимого по грантам набора кандидатов актора, а `GET {prefix}/shared` возвращает
кросс-хранилищный набор «со мной поделились». Оба описаны в разделе
[Авторизация › Access-aware листинги](/ru/concepts/authorization#access-aware-listings) — включая
семантику «надмножество кандидатов против точного набора» и поведение открытого драйвера.
:::

::: code-group
```bash [grant]
curl -X POST https://app.test/api/vaulter/nodes/01J.../grants \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "subject_type": "user",
    "subject_id": "42",
    "level": "writer",
    "effect": "allow",
    "scope": "subtree",
    "permissions": 0,
    "expires_at": "2026-12-31T23:59:59Z"
  }'
```

```bash [revoke]
curl -X DELETE https://app.test/api/vaulter/nodes/01J.../grants \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{ "subject_type": "user", "subject_id": "42" }'
```

```json [grant response 201]
{
  "data": {
    "id": "01J...",
    "node_id": "01J...",
    "subject_type": "user",
    "subject_id": "42",
    "level": "writer",
    "permissions": 0,
    "effect": "allow",
    "scope": "subtree",
    "expires_at": "2026-12-31T23:59:59Z",
    "created_at": "2026-06-30T10:00:00Z"
  }
}
```
:::

### Валидация запроса

`StoreNodeGrantRequest` валидирует тело:

| Поле | Правила |
|---|---|
| `subject_type` | required, string, max 255, зарегистрированный тип субъекта |
| `subject_id` | required |
| `level` | required, одно из значений `GrantLevel` |
| `effect` | nullable enum (`allow`/`deny`), по умолчанию `allow` |
| `scope` | nullable enum (`item`/`subtree`), по умолчанию `subtree` |
| `permissions` | nullable integer, `0..31` |
| `expires_at` | nullable date |

## События

Оба события реализуют `ShouldDispatchAfterCommit` — они срабатывают только после коммита
транзакции гранта, так что слушатель никогда не видит незакоммиченный грант.

| Событие | Полезная нагрузка | Когда |
|---|---|---|
| `Vaulter\Events\Access\NodeGrantCreated` | `NodeGrant $grant`, `int\|string\|null $actorId` | грант создан или обновлён |
| `Vaulter\Events\Access\NodeGrantRevoked` | `Node $node`, `Subject $subject`, `int $revoked`, `int\|string\|null $actorId` | гранты субъекта отозваны |

Нижестоящий слушатель может инкрементить кэш `acl_version` или записать активность:

```php
use Vaulter\Events\Access\NodeGrantCreated;

Event::listen(function (NodeGrantCreated $event): void {
    Cache::increment("acl_version:{$event->grant->node_id}");
});
```

## На клиенте — useNodeGrants

`@vaulter/vue` поставляет `useNodeGrants(nodeId)` для реактивного управления грантами узла,
опираясь на namespace `grants` внедрённого клиента
(`client.grants.list` / `grant` / `revoke`).

```vue
<script setup lang="ts">
import { useNodeGrants } from '@vaulter/vue'

const { grants, loading, list, grant, revoke } = useNodeGrants(nodeId)

await list()

await grant({
  subjectType: 'user',
  subjectId: 42,
  level: 'writer',
  effect: 'allow',
  scope: 'subtree',
})

await revoke({ subjectType: 'user', subjectId: 42 })
</script>
```

`grant()` добавляет запись в начало реактивного списка; `revoke()` сбрасывает все гранты этого
субъекта. Компонент `VaulterNodeGrantsPanel` оборачивает это в готовый к вставке UI грантов —
см. [Vue-компоненты](/ru/guide/vue).

## См. также

- [Авторизация](/ru/concepts/authorization) — как гранты обходятся, сворачиваются и вето.
- [Драйверы прав](/ru/guide/permission-drivers) — полоса ролей, сворачиваемая вместе с грантами.
- Детали грантов в рамках панели и наследования по поддереву: `node_grants`, `inherit_grants`.
