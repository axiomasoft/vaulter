---
title: Авторизация
description: Как Vaulter решает, кому что можно — собственный движок AccessResolver, делегирование ролей хосту, алгоритм объединения ролей, границы наследования, флаги способностей для UI и fail-loud null-драйвер.
outline: deep
---

# Авторизация

Vaulter отвечает ровно на один вопрос авторизации: **может ли этот актор выполнить эту
способность над этим узлом?** Ответ вычисляет единственный движок, принадлежащий самому
Vaulter, — `AccessResolver`. Он складывает две полосы (lanes):

1. **пер-нодовый ACL** (строки `v_node_grants`) — собственное хранилище грантов Vaulter (версионируемые роли);
2. **сменную ролевую полосу хоста** (`PermissionDriver`) — мост к системе ролей вашего
   приложения.

Сам резолвер **не** контракт и **не** сменный — сменна только ролевая полоса (драйвер).

::: info Две полосы, один авторитет
`AccessResolver` (`Vaulter\Access\AccessResolver`) — единственный авторитет, к которому
обращаются все Policy, `DriveHandle`, контроллеры и Resource. Хост подключается к нему
через контракт `PermissionDriver`, но никогда не подменяет сам резолвер.
:::

## Публичная поверхность

| Метод | Возвращает | Назначение |
|---|---|---|
| `can(Authorizable $actor, Ability $ability, Node $node)` | `bool` | Единая точка «да/нет», которую зовут Policy и `DriveHandle`. |
| `effectiveGrants(Authorizable $actor, Node $node)` | `GrantSet` | Свёрнутый результат всего алгоритма — разрешённые биты и флаги override хоста. |
| `abilitiesFor(Authorizable $actor, Node $node)` | `array<string, bool>` | Каждая `Ability` → разрешена/запрещена; заполняет объект `abilities` для UI. |
| `canCreateInWorkspace(Authorizable $actor, Storage $storage)` | `bool` | Гейт создания папок/загрузок, где ещё нет узла. |
| `sharedNodeIds(Authorizable $actor)` | `list<int\|string>` | Кандидаты-узлы, на которые у актора есть живой allow-грант. |
| `filterSharedWith(Authorizable $actor, iterable $candidates)` | `list<Node>` | Перепроверяет кандидатов «поделились со мной» полным алгоритмом. |

```php
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;

$resolver = app(AccessResolver::class);

if ($resolver->can($user, Ability::Edit, $node)) {
    // актор может редактировать этот узел
}
```

`can()` — это сахар над `effectiveGrants($actor, $node)->allows($ability)`: алгоритм
живёт в одном месте, остальные методы лишь читают его результат.

## Способности и роли

Vaulter рассуждает одним целым числом. У каждой способности **свой бит**
(`Vaulter\Enums\Access\Permission`), его возвращает `Ability::bits()`:

| Способность | Бит | Способность | Бит |
|---|---|---|---|
| `View` | 1 | `Download` | 64 |
| `Edit` | 2 | `Move` | 128 |
| `Create` | 4 | `Copy` | 256 |
| `Delete` | 8 | `Trash` | 512 |
| `Share` | 16 | `Restore` | 1024 |
| `Comment` | 32 | `Manage` | 2048 |

`Permission::all()` равно 4095. View и Download разделены: можно видеть узел, не имея права его
скачать или скопировать.

### Роли v1

Грант хранит **роль** (`Vaulter\Access\Roles\Role`) и её версию; разрешает пару `RoleRegistry`.
Роль — закрытый набор способностей, каждая встроенная роль включает предыдущую:

| Роль | Способности |
|---|---|
| `previewer` | View |
| `reader` | previewer + Download |
| `commenter` | reader + Comment |
| `writer` | commenter + Edit, Create, Move, Copy, Trash, Restore |
| `organizer` | writer + Share |
| `owner` | organizer + Manage, Delete |

Выпущенная роль не меняется: исправленная роль выходит новой версией, а сохранённый грант
продолжает разрешаться в то, что ему было выдано. Неизвестный ключ или версия — это
`UnknownRoleException` (`unknown_role`, 422). Неявного владельца нет: создатель узла не получает
прав от самого факта создания (`v_nodes.owner_id` удалён) — выдайте роль явно.

## Алгоритм

`effectiveGrants()` выполняет три шага. Deny и ранжирования нет.

```
1. Override хоста (PermissionDriver::override) — абсолютный, в обе стороны.
2. Собрать применимые роли: прямые гранты на узле, subtree-гранты предков до первого узла
   с inherit_grants = false включительно, и роли, которые вернул драйвер.
3. allowed = объединение способностей этих ролей.
```

### Шаг 1 — override хоста

Резолвер сначала спрашивает активный `PermissionDriver::override($actor, $scope)`:

- `Effect::Allow` → `GrantSet::superAllowed()` (полный доступ);
- `Effect::Deny` → `GrantSet::superDenied()` (нет доступа) — единственная блокировка в модели,
  и она принадлежит хосту, а не гранту;
- `null` — override нет, идём дальше.

Override **абсолютен** и не зависит от строк ACL.

### Шаг 2 — роли до границы наследования

Драйвер разворачивает актора в **набор субъектов** через `subjectsFor($actor)`. Резолвер идёт
по цепочке предков **от ближайшего** и собирает живые гранты этих субъектов:

- грант **на самом узле** действует всегда, при любой области;
- грант на **предке** действует только при области `subtree`
  (`GrantScope::inheritsDownward()`).

Обход **ограничен хранилищем** и не пересекает `storage_id`. Срок действия проверяется на
чтении в запросе грантов; крона нет.

#### Границы наследования

У узла есть флаг `inherit_grants`. Когда обход доходит до узла с `inherit_grants = false`, его
**собственные** гранты учитываются, а всё, что выше, — нет. Тем, кому нужен доступ, выдают
прямой грант на узле-границе. Управление (обе операции требуют `Manage` на узле):

```php
$access = $drive->actingAs($owner)->access();

$access->restrictInheritance($node);                                  // остаются только прямые гранты
$access->restrictInheritance($node, InheritanceRestriction::CopyCurrent);
$access->restoreInheritance($node);                                   // прямые гранты сохраняются
```

`copy_current` сначала даёт каждому, кто сейчас наследует доступ, прямой subtree-грант на узле —
роль, покрывающую унаследованные, не шире и не дольше унаследованного.

### Шаг 3 — объединение

Каждый применимый грант добавляет способности своей роли; `max(rank)` нет, ничего не вычитается.
Роль от драйвера (`Decision{Role}`) объединяется с грантами так же: ролевая полоса хоста может
поднять доступ, но не понизить. `AccessScope`, передаваемый драйверу, не знает узла: профиль,
владелец диска и тенант.

## Операции над несколькими узлами

| Операция | Требуется |
|---|---|
| copy | `View` + `Download` + `Copy` на **каждом** копируемом узле, `Create` в назначении |
| move | `Move` на узле, `Create` в назначении |
| restore | `Restore` на узле, `Create` в назначении, если узел возвращается не в исходного родителя |
| purge | `Delete` |

## Объяснение решения

`access()->explain($node, $ability)` возвращает `AccessExplanation`: `allowed`, `ability`,
`reason`, `sources` (`AccessSource`: грант, субъект, роль и версия, узел-источник, унаследован ли,
срок), `restrictions` и `boundaryNodeId` — узел, на котором наследование остановилось.

## GrantSet

`effectiveGrants()` возвращает `Vaulter\Access\ValueObjects\GrantSet`:

| Поле | Смысл |
|---|---|
| `allowedBits` | объединение битов всех применимых ролей (гранты + драйвер) |
| `superAllow` / `superDeny` | короткое замыкание override хоста |

Конструкторы: `GrantSet::none()`, `GrantSet::superAllowed()`, `GrantSet::superDenied()`.

## Флаги способностей для UI

Резолвер вычисляет плоскую карту способностей, чтобы фронтенд показывал только доступные
действия — без дублирования логики прав в JavaScript и лишних запросов.

`NodeResource` заполняет свой объект `abilities` через
`Vaulter\Access\ValueObjects\NodeAbilities::for($actor, $node, $resolver)`, который зовёт
`abilitiesFor()`. Когда актора нет, **все флаги false** (клиент показывает read-only
оболочку, а не рекламирует недоступные действия).

```json
{
  "data": {
    "id": "01J...",
    "name": "Q3 Proposal",
    "abilities": {
      "view": true, "download": true, "comment": true, "edit": true,
      "create": true, "move": false, "copy": true, "trash": false,
      "restore": false, "share": false, "manage": false, "delete": false
    }
  }
}
```

На клиенте каждый ресурс узла отдаёт свою карту `abilities` — привязывайте UI напрямую к этим
флагам (отсутствующее право трактуется как `false`).

## Гейт создания (узла ещё нет)

У создания папок и загрузок нет узла для проверки, поэтому они идут через
`canCreateInWorkspace($actor, $storage)`, который делегирует напрямую полосе драйвера
`canCreateInWorkspace($actor, $workspaceType, $workspaceId)`.

## «Поделились со мной»

`sharedNodeIds()` быстрым проходом по индексу субъектов в `v_node_grants` собирает
кандидатов-узлы, на которые у актора есть живой грант. Затем кандидаты **перепроверяются**
полным алгоритмом в `filterSharedWith()` — потому что граница наследования или override хоста
могли отозвать видимость, которую подразумевал «сырой» грант.

## Fail-loud: null-драйвер

Драйвер по умолчанию — `database` (полностью автономная полоса без зависимостей от
хоста). Для локального старта есть **открытый** драйвер `Vaulter\Testing\NullPermissionDriver`,
дающий любому актору полный доступ через абсолютный override `Allow`.

`PermissionDriverManager` отвергает null-драйвер **в продакшене**:

```php
if (is_a($class, NullPermissionDriver::class, allow_string: true) && $this->isProduction) {
    throw MissingPermissionDriverException::nullDriverInProduction($key);
}
```

::: danger Никогда не выкатывайте null-драйвер
`null` выбираем только вне продакшена и существует лишь для того, чтобы потребитель мог
запустить и пощупать Vaulter без настройки реальной системы ролей. Отсутствующий или
`null`-драйвер **никогда** не откроет продакшен молча — он падает громко на этапе
резолвинга.
:::

Драйвер `azgard` требует `composer require axioma-studio/vaulter-azgard`.
Provider моста сам регистрирует драйвер и реальный адаптер guard; настройки находятся
в `vaulter-azgard.*`, а модель пользователя должна реализовать `AzgardBackedUser`.

## Дальше

- [Драйверы прав](/extending/permission-drivers) (EN) — четыре полосы `PermissionDriver` и их
  настройка.
- [Гранты](/guides/grants) (EN) — строки `v_node_grants`: субъекты, роли, scope, срок,
  `GrantNodeAccess`.
- ADR-0002 (делегирование авторизации) и ADR-0007 (seam AzGuard), ADR-0025 (модель доступа 1.0: роли, без deny).
