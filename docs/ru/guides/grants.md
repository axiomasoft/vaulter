---
title: Гранты
description: ACL-гранты на узлы — субъект, роль, область и срок действия — экшены GrantNodeAccess / RevokeNodeAccess, REST-поверхность, события и Vue-composable useGrants.
outline: deep
---

# Гранты

**Грант** — это одна строка в `v_node_grants`, которая говорит: *у этого субъекта такой
доступ к этому узлу*. Гранты — собственное ACL-хранилище Vaulter, сырьё, которое обходит и
сворачивает [`AccessResolver`](/ru/concepts/access-model). На этой странице разобраны модель
гранта, как записывать и отзывать гранты, REST-поверхность и порождаемые события.

::: info Куда встают гранты
Гранты — это **полоса node-ACL**. [Полоса ролей](/ru/extending/permission-drivers) (роли вашего
хоста) сворачивается отдельно. Грант только добавляет доступ — grant-deny не существует; чтобы
отрезать поддерево от грантов сверху, используйте [границу наследования](/ru/concepts/access-model#границы-наследования).
:::

## Анатомия гранта

`Vaulter\Drive\Models\Access\NodeGrant` (таблица `v_node_grants`, уникальна по узлу, субъекту и области):

| Колонка | Тип | Смысл |
|---|---|---|
| `node_id` | id | узел, на котором сидит грант |
| `subject_type` | string | зарегистрированный morph-алиас — `user` / `role` / `team` / алиас хоста |
| `subject_id` | id | ключ субъекта, которому адресован грант |
| `role_key` | string | `previewer` / `reader` / `commenter` / `writer` / `organizer` / `owner` |
| `role_version` | int | версия роли, с которой выдан грант (сейчас `1`) |
| `scope` | `GrantScope` | `item` (только этот узел) или `subtree` (спускается вниз) — по умолчанию `subtree` |
| `expires_at` | datetime? | срок действия, проверяемый на чтении; `null` = никогда не истекает |
| `granted_by` | id? | актор, создавший грант |
| `tenant_id` | id? | присутствует, когда включена мультитенантность |

Модель сменяема — направьте `config('vaulter.models.grant')` на подкласс (например, чтобы
добавить скоуп по тенанту). `inherited` / `inherited_from_node_id` зарезервированы под
опциональную материализованную read-модель и остаются `false`/`null`: источник истины — прямые
строки плюс дерево.

### Субъект

Грант адресуется `Vaulter\Drive\Access\ValueObjects\Subject` — паре `(type, id)`. `type` всегда
зарегистрированный morph-алиас, никогда не сырой FQCN. Драйвер ролей разворачивает актора в
список субъектов, чьи гранты применимы (см.
[`subjectsFor`](/ru/extending/permission-drivers#контракт-permissiondriver)); из этого списка
Vaulter строит `WHERE (subject_type, subject_id) IN (...)`.

<!-- snippet: host -->

```php
use Vaulter\Drive\Access\ValueObjects\Subject;

$user = Subject::ofModel($actor);        // morph-алиас + ключ
$role = new Subject(type: 'role', id: 'editor');
$team = Subject::make('team', $teamId);
```

### Роль

Грант хранит ключ роли и версию, с которой он выдан, поэтому последующее изменение роли
выходит новой версией и не меняет сохранённые гранты. Неизвестный ключ или версия бросает
`UnknownRoleException` (`unknown_role`, 422). Таблицу роль → способности см. в
[Способности и роли](/ru/concepts/access-model#способности-и-роли).

### Без deny

Гранты только добавляют доступ. Эффективный доступ — **объединение** ролей всех применимых
грантов до границы наследования; административная блокировка принадлежит permission-драйверу
хоста (`override()`), а не гранту. Поддерево ограничивается через
`access()->restrictInheritance($node)`.

### Область + наследование

`Vaulter\Drive\Enums\Access\GrantScope` — `item` действует только на узле, где он сидит; `subtree`
(по умолчанию) вдобавок спускается на потомков во время обхода предков, **до первого узла с
`inherit_grants = false` включительно** — гранты выше него ниже не проходят. Наследуются вниз только гранты `subtree`
(`GrantScope::inheritsDownward()`).

### Срок действия

`expires_at` проверяется **на чтении**, никогда не по крону. Запрос грантов фильтрует
`expires_at IS NULL OR expires_at > now`, так что истёкшая строка до резолвера не доходит.

## Запись гранта

`access()->grant()` (поверх `Vaulter\Drive\Actions\Access\GrantNodeAccessAction`) сохраняет грант.
Он **идемпотентен** по уникальному ключу гранта: повторная выдача тому же субъекту той же
области обновляет роль и срок. Запись идёт в транзакции и порождает `NodeGrantCreated`.

<!-- snippet: host -->

```php
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\GrantScope;

$grant = Vaulter::drive($drive)->actingAs($actor)->access()->grant(
    $node,
    new GrantInput(
        subject: Subject::ofModel($collaborator),
        role: RoleRegistry::WRITER,
        scope: GrantScope::Subtree,       // по умолчанию — спускается на потомков
        expiresAt: now()->addDays(7),     // опционально
    ),
);
```

::: warning Экшен не авторизует
`GrantNodeAccessAction` сохраняет; он не переавторизует. `access()->grant()` и HTTP-поверхность
ниже сначала проверяют право `Manage` на узле; при прямом вызове экшена сделайте
то же самое.
:::

### Отгородить поддерево

Чтобы подпапка была недоступна команде, у которой есть доступ выше, остановите наследование на
ней и выдайте нужным людям прямые гранты:

<!-- snippet: host -->

```php
$access = Vaulter::drive($drive)->actingAs($owner)->access();

$access->grant($hr, new GrantInput(subject: Subject::ofModel($hrUser), role: RoleRegistry::READER));
$access->restrictInheritance($hr);                                  // empty: остаются только прямые гранты
// $access->restrictInheritance($hr, InheritanceRestriction::CopyCurrent); // сохранить текущий доступ
$access->restoreInheritance($hr);                                   // снова открыть для грантов сверху
```

Оба вызова требуют `Manage` на узле. `copy_current` сначала даёт каждому субъекту, который
сейчас наследует доступ, прямой subtree-грант — роль, покрывающую унаследованные, не шире и не
дольше унаследованного.

## Отзыв грантов

`Vaulter\Drive\Actions\Access\RevokeNodeAccessAction` удаляет **все** гранты субъекта на узле — обе
области — и возвращает число удалённых. Он порождает `NodeGrantRevoked` только
тогда, когда что-то реально отозвано (пустой отзыв — тихий no-op). Транзакционный.

<!-- snippet: host -->

```php
use Vaulter\Drive\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Drive\Access\ValueObjects\Subject;

$removed = app(RevokeNodeAccessAction::class)->execute(
    node: $node,
    subject: Subject::ofModel($collaborator),
    actorId: $actor->getKey(),
);
```

## REST-поверхность

Node-ACL — часть [REST API v1](/ru/reference/rest-api#доступ-гранты-и-наследование)
(префикс по умолчанию `api/vaulter/v1`). **Каждое** действие требует права `Manage` на узле.
Organizer может создавать ссылки через `Share`, но управление грантами требует доступа owner:
кто видит узел, получает `403 permission_denied`, кто не видит — `404 node_not_found`.

| Метод | Путь | Имя |
|---|---|---|
| `GET` | `v1/nodes/{node}/grants` | `grants.index` — гранты узла как `{ "data": [ … ] }` |
| `POST` | `v1/nodes/{node}/grants` | `grants.store` — выдать субъекту роль (201) |
| `DELETE` | `v1/nodes/{node}/grants/{grant}` | `grants.destroy` — отозвать один грант (204) |
| `POST` | `v1/nodes/{node}/inheritance/restrict` | `access.inheritance.restrict` — `{"mode": "empty" \| "copy_current"}` |
| `POST` | `v1/nodes/{node}/inheritance/restore` | `access.inheritance.restore` |

Маршруты наследования возвращают узел (с новым `ETag`). Ограничение может снять ваш собственный
унаследованный доступ; изменение остаётся в силе, а узел возвращается со всеми способностями
`false`.

::: tip Где гранты проявляются на стороне листингов
Когда фича `grants` включена и активен драйвер `database`, **листинги** Drive сужаются до
достижимого по грантам набора кандидатов актора, а `GET {prefix}/shared` возвращает
кросс-хранилищный набор «со мной поделились». Оба описаны в разделе
[Авторизация › Access-aware листинги](/ru/concepts/access-model) — включая
семантику «надмножество кандидатов против точного набора» и поведение открытого драйвера.
:::

::: code-group
<!-- snippet: shell -->

```bash [grant]
curl -X POST https://app.test/api/vaulter/v1/nodes/01J.../grants \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "subject": { "type": "user", "id": "42" },
    "role": "writer",
    "scope": "subtree",
    "expires_at": "2026-12-31T23:59:59Z"
  }'
```

<!-- snippet: shell -->

```bash [revoke]
curl -X DELETE https://app.test/api/vaulter/v1/nodes/01J.../grants/01J... \
  -H 'Authorization: Bearer <token>'
```

```json [grant response 201]
{
  "data": {
    "id": "01J...",
    "node_id": "01J...",
    "subject": { "type": "user", "id": "42" },
    "role": "writer",
    "role_version": 1,
    "scope": "subtree",
    "expires_at": "2026-12-31T23:59:59.000000Z",
    "created_by": "1",
    "created_at": "2026-06-30T10:00:00.000000Z"
  }
}
```
:::

### Валидация запроса

`CreateGrantRequest` валидирует тело:

| Поле | Правила |
|---|---|
| `subject.type` | required, string, max 255, зарегистрированный тип субъекта |
| `subject.id` | required |
| `role` | required, один из зарегистрированных ключей ролей |
| `scope` | nullable enum (`item`/`subtree`), по умолчанию `subtree` |
| `expires_at` | nullable date, в будущем |

## События

Оба события реализуют `ShouldDispatchAfterCommit` — они срабатывают только после коммита
транзакции гранта, так что слушатель никогда не видит незакоммиченный грант.

| Событие | Полезная нагрузка | Когда |
|---|---|---|
| `Vaulter\Drive\Events\Access\NodeGrantCreated` | `NodeGrant $grant`, `int\|string\|null $actorId` | грант создан или обновлён |
| `Vaulter\Drive\Events\Access\NodeGrantRevoked` | `Node $node`, `Subject $subject`, `int $revoked`, `int\|string\|null $actorId` | гранты субъекта отозваны |

Нижестоящий слушатель может инкрементить кэш `acl_version` или записать активность:

<!-- snippet: host -->

```php
use Vaulter\Drive\Events\Access\NodeGrantCreated;

Event::listen(function (NodeGrantCreated $event): void {
    Cache::increment("acl_version:{$event->grant->node_id}");
});
```

## На клиенте — useGrants

`@vaulter/vue` поставляет `useGrants(nodeId)` для реактивного управления грантами узла,
опираясь на namespace `grants` внедрённого клиента
(`client.grants.list` / `grant` / `revoke`).

<!-- snippet: host -->

```vue
<script setup lang="ts">
import { useGrants } from '@vaulter/vue'

const { grants, loading, list, grant, revoke } = useGrants(nodeId)

await list()

const created = await grant({
  subject: { type: 'user', id: 42 },
  role: 'writer',
  scope: 'subtree',
})

await revoke(created.id)
</script>
```

`grant()` добавляет запись в начало реактивного списка; `revoke(grantId)` снимает именно этот грант. Компонент `VaulterGrantsPanel` оборачивает это в готовый к вставке UI грантов —
см. [Vue-компоненты](/ru/guides/vue).

## См. также

- [Авторизация](/ru/concepts/access-model) — как гранты обходятся и объединяются до границы.
- [Драйверы прав](/ru/extending/permission-drivers) — полоса ролей, сворачиваемая вместе с грантами.
- Детали грантов в рамках панели и наследования по поддереву: `node_grants`, `inherit_grants`.
