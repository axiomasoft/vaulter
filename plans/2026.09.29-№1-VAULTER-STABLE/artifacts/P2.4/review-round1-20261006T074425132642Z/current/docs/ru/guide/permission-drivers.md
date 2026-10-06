---
title: Драйверы прав
description: Четыре полосы PermissionDriver — Database (по умолчанию), Gate, Spatie v8 teams и шов AzGuard — как выбрать и настроить каждую, и адаптер AzgardGuard из отдельного моста.
outline: deep
---

# Драйверы прав

[`AccessResolver`](/ru/concepts/authorization) владеет ACL на уровне узла и итоговым решением.
**Ролевая полоса**, которую он подмешивает, — сменяемая: это контракт `PermissionDriver`, и
Core поставляет `database`, `gate` и `spatie`; отдельный мост поставляет `azgard`. Именно здесь вы мостите систему идентичности и
ролей вашего приложения в Vaulter.

::: info Контракт не видит узла
`PermissionDriver` никогда не получает `Node` и не оперирует действиями-магическими-строками —
только актором `Authorizable` и `AccessScope` (координаты панели + workspace + владелец узла).
Это и делает каждый драйвер сменяемым, а resolver — единственной инстанцией власти.
:::

## Контракт PermissionDriver

`Vaulter\Contracts\Access\PermissionDriver`:

```php
interface PermissionDriver
{
    // Per-subject role decision, or null when the host has nothing to say.
    // Folded as roleAllow into granted = max(aclAllow, roleAllow) — it can RAISE
    // access but never override a deny (the final veto still applies).
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision;

    // An absolute super-admin override, or null. Allow/Deny short-circuit BOTH
    // directions before any ACL walk.
    public function override(Authorizable $actor, AccessScope $scope): ?Effect;

    // Expand an actor into the Subjects whose grants apply (user + roles + teams).
    // Vaulter builds WHERE (subject_type, subject_id) IN (...) from this list.
    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array;

    // May the actor create nodes in a workspace? (folder/upload create path).
    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool;
}
```

Метод `resolve()` драйвера может только **поднять** доступ (он возвращает разрешающий `Decision`
или `null`); вето-запрет остаётся делом ACL. `subjectsFor()` — это то, что позволяет строке
`v_node_grants`, адресованной роли или команде, совпасть с актором.

## Выбор драйвера

Активный драйвер — это один config-ключ (и ENV-переменная):

```php
// config/vaulter.php
'drivers' => [
    'permission' => env('VAULTER_PERMISSION_DRIVER', 'database'),
],
```

```bash
# .env
VAULTER_PERMISSION_DRIVER=database   # database | gate | spatie | azgard | null
```

Карта ключ → FQCN лежит в `permission_drivers`. Зарегистрируйте свой драйвер, добавив FQCN,
реализующий `PermissionDriver` (безопасно для кэша конфига — только FQCN, без замыканий):

```php
// config/vaulter.php
'permission_drivers' => [
    'database' => \Vaulter\Access\Drivers\DatabasePermissionDriver::class,
    'gate'     => \Vaulter\Access\Drivers\GatePermissionDriver::class,
    'spatie'   => \Vaulter\Access\Drivers\SpatiePermissionDriver::class,
    'null'     => \Vaulter\Testing\NullPermissionDriver::class, // non-prod only
    // 'tenant' => \App\Vaulter\TenantRoleDriver::class,
],
```

`PermissionDriverManager` резолвит и мемоизирует драйвер по ключу; неизвестный ключ бросает
`MissingPermissionDriverException::unknownDriver()`, а `null` в продакшене бросает
`nullDriverInProduction()` — см. [Авторизация → fail-loud](/ru/concepts/authorization#fail-loud-the-null-driver).

## Database — полоса по умолчанию, автономная

`Vaulter\Access\Drivers\DatabasePermissionDriver` **не** вводит никакой внешней системы
ролей. Каждое решение — это строка `v_node_grants`, разрешаемая `AccessResolver`; сам драйвер
лишь добавляет собственную идентичность актора в набор субъектов.

| Метод | Поведение |
|---|---|
| `subjectsFor` | `[Subject::ofModel($actor)]` — только сам пользователь. |
| `override` | `null` — обхода для супер-админа нет. |
| `resolve` | `null` — ACL является единственной инстанцией власти. |
| `canCreateInWorkspace` | `true` — гранты `CREATE` на уровне узла на целевой папке всё равно применяются через resolver. |

Это та самая полоса «из коробки», благодаря которой Vaulter работает в чистом Laravel-приложении
без хостовой авторизации. Чтобы добавить субъектов-роли/команды без единого внешнего пакета,
унаследуйтесь от неё и допишите записи `Subject`:

```php
final class TeamAwareRoleDriver extends DatabasePermissionDriver
{
    public function subjectsFor(Authorizable $actor): array
    {
        $subjects = parent::subjectsFor($actor); // [Subject(user)]

        foreach ($actor->teams as $team) {
            $subjects[] = new Subject(type: 'team', id: $team->id);
        }

        return $subjects;
    }
}
```

## Gate — встроенная полоса Laravel

`Vaulter\Access\Drivers\GatePermissionDriver` мостит хост, который уже авторизует через
права (abilities) / политики Gate. Он идёт вместе с фреймворком — ноль дополнительных
зависимостей.

- **`override`** спрашивает настраиваемое супер-право (по умолчанию `vaulter.super`). Когда Gate
  его разрешает, актор коротко замыкается на полный доступ; иначе — уступает (`null`, никогда не
  жёсткий запрет).
- **`resolve`** спрашивает у Gate каждое сопоставленное право (от низкого к высокому) и
  подмешивает **наивысший разрешённый уровень** в `Decision`. Проверка Gate не видит узла — она
  получает `{panel, workspace_type, workspace_id}` из `AccessScope`, но никогда `Node`.
- **`subjectsFor`** = только `[Subject(actor)]`. Gate не выставляет перечислимых субъектов-ролей
  / команд, поэтому строки грантов, адресованные роли/команде, этой полосой не совпадают —
  используйте для этого Spatie/AzGuard.
- **`canCreateInWorkspace`** спрашивает право на создание, передавая ему `[$workspaceType, $workspaceId]`.

Каждая проверка идёт через `Gate::forUser($actor)`, поэтому драйвер не хранит состояния.
Отсутствующее право проглатывается как «не разрешено» — ненастроенный хостовый gate никогда не
бросает исключение в resolver.

### Конфигурация

```php
// config/vaulter.php — used only when drivers.permission = 'gate'
'gate' => [
    'abilities' => [
        'reader'    => 'vaulter.view',
        'commenter' => 'vaulter.comment',
        'writer'    => 'vaulter.edit',
        'organizer' => 'vaulter.organize',
        'owner'     => 'vaulter.manage',
    ],
    'super_ability'  => env('VAULTER_GATE_SUPER_ABILITY', 'vaulter.super'),
    'create_ability' => env('VAULTER_GATE_CREATE_ABILITY', 'vaulter.create'),
],
```

Карта `abilities` связывает `GrantLevel` из Vaulter со строкой хостового права Gate. Сопоставляйте
только те уровни, которые вы действительно закрываете через gate. Реализуйте соответствующие
политики:

```php
// A host policy / Gate definition
Gate::define('vaulter.edit', fn ($user, array $context) =>
    $user->isEditorOf($context['workspace_type'], $context['workspace_id']));

Gate::define('vaulter.super', fn ($user) => $user->isAdmin());

Gate::define('vaulter.create', fn ($user, string $type, int|string $id) =>
    $user->mayCreateIn($type, $id));
```

## Spatie — роли, разрешения и v8 teams

`Vaulter\Access\Drivers\SpatiePermissionDriver` мостит хост на
`spatie/laravel-permission`, включая фичу **v8 teams**. Он инкапсулирует весь танец переключения
команд v8, так что тот никогда не протекает в resolver.

- **`subjectsFor`** = актор + его субъекты-роли (+ его субъект-команда, когда teams включены),
  так что строка гранта, адресованная `role`/`team`, совпадает.
- **`resolve`** проверяет каждое сопоставленное разрешение через `$actor->hasPermissionTo($name)`,
  **в области команды** для workspace (`setPermissionsTeamId($scope->workspaceId)`), затем
  восстанавливает предыдущий team id и загруженные связи актора. Наивысший разрешённый уровень
  становится `Decision`.
- **`override`** спрашивает настраиваемое супер-разрешение.
- **`canCreateInWorkspace`** спрашивает разрешение на создание в контексте команды workspace.

::: warning Опциональная зависимость
`spatie/laravel-permission` — **опциональная** хостовая зависимость. Каждое обращение к API Spatie
в рантайме защищено `class_exists(PermissionRegistrar::class)`, так что vaulter-core не несёт
жёсткой зависимости. Выбор `spatie` без установки пакета деградирует до безопасного запрета
(`null`/`false`), а не до фатальной ошибки.
:::

### Конфигурация

```php
// config/vaulter.php — used only when drivers.permission = 'spatie'
'spatie' => [
    'permissions' => [
        'reader'    => 'vaulter.view',
        'commenter' => 'vaulter.comment',
        'writer'    => 'vaulter.edit',
        'organizer' => 'vaulter.organize',
        'owner'     => 'vaulter.manage',
    ],
    'super_permission'  => env('VAULTER_SPATIE_SUPER_PERMISSION', 'vaulter.super'),
    'create_permission' => env('VAULTER_SPATIE_CREATE_PERMISSION', 'vaulter.create'),
    'teams'             => env('VAULTER_SPATIE_TEAMS', false),
    'role_subject_type' => 'role',
    'team_subject_type' => 'team',
],
```

`teams` откатывается на этот ключ **только** тогда, когда собственный `config('permission.teams')`
хоста не задан — Spatie-конфиг хоста имеет приоритет. `role_subject_type` / `team_subject_type` —
это morph-алиасы, которым адресуются строки грантов на роль/команду.

### Переключение команды и шов кэша

Полный танец v8 — захватить текущий team id, `setPermissionsTeamId($workspaceId)`, проверить,
восстановить в `finally` — целиком живёт внутри драйвера, поэтому конкурентные панели никогда не
перетекают друг в друга. После того как хост меняет роли/разрешения актора **вне** собственных
хелперов assign/remove Spatie, он обязан сбросить кэш, чтобы устаревшее, привязанное к команде
разрешение не выдало отозванный уровень при следующем resolve:

```php
use Vaulter\Access\Drivers\SpatiePermissionDriver;

SpatiePermissionDriver::forgetCachedPermissions($actor);
```

Это no-op, когда Spatie не установлен.

## AzGuard — шов на уровне ресурса

`Vaulter\Azgard\AzgardPermissionDriver` находится в отдельном пакете
`axioma-studio/vaulter-azgard`; ядро не зависит от AzGuard/Corex.

```bash
composer require axioma-studio/vaulter-azgard
```

Обнаруживаемый Laravel provider сам регистрирует ключ `azgard` в
`vaulter.permission_drivers`, сохраняя явное переопределение хоста, и привязывает
`Vaulter\Azgard\Contracts\AzgardGuard` к реальному адаптеру. Затем выберите
`VAULTER_PERMISSION_DRIVER=azgard`. Без установленного моста этот ключ неизвестен
и вызывает `MissingPermissionDriverException`.

Составной порядок разрешения — node-ACL → роль в области workspace → global. Этот драйвер владеет
полосой **роли в области workspace**:

- **`override`** = `isSuperAdmin()` → `Effect::Allow`, иначе `null` (никогда не запрет).
- **`resolve`** сопоставляет определяющее право (ability) каждого ранжированного уровня с
  разрешением AzGuard и спрашивает `hasPermissionIn($workspaceType, $workspaceId, $permission)`;
  наивысший разрешённый уровень становится `Decision`. (`AccessScope` не видит узла, поэтому это
  проверка в области workspace.)
- **`subjectsFor`** = только `[Subject(actor)]` — AzGuard *отвечает* на проверки, а не перечисляет
  субъектов-роли. Хост, адресующий строки грантов на роли AzGuard, должен унаследоваться и
  дописать эти субъекты.
- **`canCreateInWorkspace`** спрашивает `hasPermissionIn($type, $id, $createPermission)`.

Пробы «уровень → определяющее право» таковы: `Reader → View`, `Commenter → Comment`,
`Writer → Edit`, `Organizer → Delete`, `Owner → Manage`.

### Конфигурация

В отличие от Gate/Spatie (с ключами по `GrantLevel`), карта AzGuard **центрирована на действиях** —
с ключами по `Ability`:

```php
// config/vaulter-azgard.php — used only when drivers.permission = 'azgard'
return [
    'panel_id' => null, // null resolves the panel from the permission key
    'permissions' => [
        'view'     => 'documents.view',
        'download' => 'documents.download',
        'comment'  => 'documents.comment',
        'edit'     => 'documents.update',
        'create'   => 'documents.create',
        'move'     => 'documents.update',
        'copy'     => 'documents.update',
        'trash'    => 'documents.delete',
        'delete'   => 'documents.delete',
        'restore'  => 'documents.delete',
        'share'    => 'documents.manage',
        'manage'   => 'documents.manage',
    ],
    'create_permission' => env('VAULTER_AZGARD_CREATE_PERMISSION', 'documents.create'),
];
```

### Адаптер AzgardGuard

`Vaulter\Azgard\Contracts\AzgardGuard` — это узкий шов, с которым говорит драйвер. Он выставляет
ровно те три проверки, которые нужны драйверу, — и ничего из собственного API AzGuard:

```php
interface AzgardGuard
{
    // Per-resource scoped permission (the per-node lane).
    public function hasScopedPermission(string $permission, object $entity): bool;

    // Workspace-scoped permission (the lane resolve()/create actually drive).
    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;

    // The global SuperAdmin wildcard.
    public function isSuperAdmin(): bool;
}
```

Если актор сам реализует `AzgardGuard`, драйвер использует его напрямую.
Иначе provider моста даёт `AzgardGuardAdapter` для текущего пользователя,
реализующего `Vaulter\Azgard\Contracts\AzgardBackedUser`. Для гостя или другого
пользователя он возвращает `NullAzgardGuard`, который не даёт разрешений.
Хосту нужно добавить `AzgardBackedUser` на модель пользователя и согласовать morph-типы.

::: details Необязательное переопределение адаптера
Мост уже привязывает настоящий адаптер. Хост может переопределить `AzgardGuard`, делегируя на `hasScopedPermission`
AzGuard / `hasPermissionIn` Corex `ContextGuard` и wildcard SuperAdmin. Формы методов повторяют
AzGuard 1:1, поэтому адаптер — тонкий сквозной проход:

```php
// In a host service provider
$this->app->bind(\Vaulter\Azgard\Contracts\AzgardGuard::class, function ($app) {
    $actor = $app['auth']->user();

    return new class ($actor) implements \Vaulter\Azgard\Contracts\AzgardGuard {
        public function __construct(private object $actor) {}

        public function hasScopedPermission(string $permission, object $entity): bool
        {
            return $this->actor->hasScopedPermission($permission, $entity);
        }

        public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool
        {
            return $this->actor->hasPermissionIn($contextType, $contextId, $permission);
        }

        public function isSuperAdmin(): bool
        {
            return $this->actor->isSuperAdmin();
        }
    };
});
```

**Согласование morph-типов (ответственность хоста):** morph-типы workspace/владельца должны быть
зарегистрированными morph-алиасами Corex, согласованными с `vaulter.ids.default=ulid` +
`corex.ids.strategy=Ulid` + `AZ_GUARD_MORPH_TYPE=ulid`. Драйвер передаёт строку типа workspace
насквозь без изменений — он никогда не резолвит алиас сам.
:::

### Тестирование полосы AzGuard

`Vaulter\Azgard\Testing\FakeAzgardGuard` — настраиваемый in-memory guard
из `vaulter-azgard`. Установите мост со всеми его Composer-зависимостями даже для
тестов с фейком; фейк заменяет проверки AzGuard, но не требования установки пакета.

```php
use Vaulter\Azgard\Testing\FakeAzgardGuard;

// grant one workspace permission
app()->bind(\Vaulter\Azgard\Contracts\AzgardGuard::class,
    fn () => FakeAzgardGuard::grantingWorkspace('team', $teamId, 'documents.update'));

// or a super-admin
app()->bind(\Vaulter\Azgard\Contracts\AzgardGuard::class,
    fn () => FakeAzgardGuard::superAdmin());
```

## Сравнение драйверов

| | Database | Gate | Spatie | AzGuard |
|---|---|---|---|---|
| Дополнительная зависимость | нет | нет (встроенный) | опциональная `spatie/laravel-permission` | `axioma-studio/vaulter-azgard` + его зависимости |
| Карта с ключами по | — | `GrantLevel` | `GrantLevel` | `Ability` |
| Перечисляет субъектов-роли/команды | через наследование | нет | да | нет |
| Обход для супер-админа | нет | право `vaulter.super` | супер-разрешение | wildcard SuperAdmin |
| Область workspace | гранты на уровне узла | node-blind контекст | в области команды | `hasPermissionIn` |
| Безопасно, когда не настроен | н/д | да | да (запрет) | только с установленным мостом |

## См. также

- [Авторизация](/ru/concepts/authorization) — как ответы драйвера подмешиваются в итоговое
  решение.
- [Гранты](/ru/guide/grants) — строки `v_node_grants`, с которыми сопоставляется набор субъектов.
- [Рецепт мультитенантности](/ru/guide/recipes) — сквозная связка тенант + роль.
- ADR-0002 (делегирование авторизации), ADR-0007 (шов AzGuard).
