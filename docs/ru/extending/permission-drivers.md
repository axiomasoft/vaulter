---
title: Драйверы прав
description: Четыре полосы PermissionDriver — Database (по умолчанию), Gate, Spatie v8 teams и шов AzGuard — как выбрать и настроить каждую, и адаптер AzGuardGuard из отдельного моста.
outline: deep
---

# Драйверы прав

[`AccessResolver`](/ru/concepts/access-model) владеет ACL на уровне узла и итоговым решением.
**Ролевая полоса**, которую он подмешивает, — сменяемая: это контракт `PermissionDriver`, и
Core поставляет `database`, `gate` и `spatie`; отдельный мост поставляет `azguard`. Именно здесь вы мостите систему идентичности и
ролей вашего приложения в Vaulter.

::: info Контракт не видит узла
`PermissionDriver` никогда не получает `Node` и не оперирует действиями-магическими-строками —
только актором `Authorizable` и `AccessScope` (профиль + диск + тенант).
Это и делает каждый драйвер сменяемым, а resolver — единственной инстанцией власти.
:::

## Контракт PermissionDriver

`Vaulter\Drive\Contracts\Access\PermissionDriver`:

<!-- snippet: signature Vaulter\Drive\Contracts\Access\PermissionDriver -->

```php
interface PermissionDriver
{
    // Per-subject role decision, or null when the host has nothing to say.
    // The role the host grants here (Decision{Role}), or null. It is UNIONED with the
    // node grants — it can RAISE access but never lowers it.
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision;

    // An absolute super-admin override, or null. Allow/Deny short-circuit BOTH
    // directions before any ACL walk.
    public function override(Authorizable $actor, AccessScope $scope): ?Effect;

    // Expand an actor into the Subjects whose grants apply (user + roles + teams).
    // Vaulter builds WHERE (subject_type, subject_id) IN (...) from this list.
    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array;

    // May the actor create nodes in a workspace? (folder/upload create path).
    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool;
}
```

Метод `resolve()` драйвера может только **поднять** доступ (он возвращает разрешающий `Decision`
или `null`); административная блокировка — дело `override()`, а не `Decision`. Драйвер, дающий
несколько ролей, возвращает `Decision::union($roles)`. `subjectsFor()` — это то, что позволяет строке
`v_node_grants`, адресованной роли или команде, совпасть с актором.

## Выбор драйвера

Активный драйвер — это один config-ключ (и ENV-переменная):

<!-- recipe: tests/Integration/Recipes/PermissionDriverRecipeTest.php#permission-driver-select -->

```php
// config/vaulter.php
'access' => [
    'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'database'),
],
```

<!-- snippet: shell -->

```bash
# .env
VAULTER_PERMISSION_DRIVER=database   # database | gate | spatie | azguard | null
```

Карта ключ → FQCN лежит в `vaulter-drive.access.permission_drivers`. Зарегистрируйте свой драйвер, добавив FQCN,
реализующий `PermissionDriver` (безопасно для кэша конфига — только FQCN, без замыканий):

<!-- recipe: tests/Integration/Recipes/PermissionDriverRecipeTest.php#permission-driver-map -->

```php
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\Drivers\GatePermissionDriver;
use Vaulter\Drive\Access\Drivers\SpatiePermissionDriver;
use Vaulter\Drive\Testing\NullPermissionDriver;

// config/vaulter.php
'access' => ['permission_drivers' => [
    'database' => DatabasePermissionDriver::class,
    'gate' => GatePermissionDriver::class,
    'spatie' => SpatiePermissionDriver::class,
    'null' => NullPermissionDriver::class, // non-prod only
    // 'tenant' => \App\Vaulter\TenantRoleDriver::class,
]],
```

`PermissionDriverManager` резолвит и мемоизирует драйвер по ключу; неизвестный ключ бросает
`MissingPermissionDriverException::unknownDriver()`, а `null` в продакшене бросает
`nullDriverInProduction()` — см. [Авторизация → fail-loud](/ru/concepts/access-model#fail-loud-null-драйвер).

## Database — полоса по умолчанию, автономная

`Vaulter\Drive\Access\Drivers\DatabasePermissionDriver` **не** вводит никакой внешней системы
ролей. Каждое решение — это строка `v_node_grants`, разрешаемая `AccessResolver`; сам драйвер
лишь добавляет собственную идентичность актора в набор субъектов.

| Метод | Поведение |
|---|---|
| `subjectsFor` | `[Subject::ofModel($actor)]` — только сам пользователь. |
| `override` | `null` — обхода для супер-админа нет. |
| `resolve` | `null` — ACL является единственной инстанцией власти. |
| `canCreateIn` | `true` — гранты `CREATE` на уровне узла на целевой папке всё равно применяются через resolver. |

Это та самая полоса «из коробки», благодаря которой Vaulter работает в чистом Laravel-приложении
без хостовой авторизации. Чтобы добавить субъектов-роли/команды без единого внешнего пакета,
унаследуйтесь от неё и допишите записи `Subject`:

<!-- snippet: host -->

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

`Vaulter\Drive\Access\Drivers\GatePermissionDriver` мостит хост, который уже авторизует через
права (abilities) / политики Gate. Он идёт вместе с фреймворком — ноль дополнительных
зависимостей.

- **`override`** спрашивает настраиваемое супер-право (по умолчанию `vaulter.super`). Когда Gate
  его разрешает, актор коротко замыкается на полный доступ; иначе — уступает (`null`, никогда не
  блокировка).
- **`resolve`** спрашивает у Gate право каждой сопоставленной роли и
  возвращает как `Decision` **объединение разрешённых ролей**. Проверка Gate не видит узла — она
  получает `{profile, drive_owner, drive_id, tenant_id}` из `AccessScope`, но никогда `Node`.
- **`subjectsFor`** = только `[Subject(actor)]`. Gate не выставляет перечислимых субъектов-ролей
  / команд, поэтому строки грантов, адресованные роли/команде, этой полосой не совпадают —
  используйте для этого Spatie/AzGuard.
- **`canCreateIn`** спрашивает право на создание, передавая тот же массив контекста.

Каждая проверка идёт через `Gate::forUser($actor)`, поэтому драйвер не хранит состояния.
Отсутствующее право проглатывается как «не разрешено» — ненастроенный хостовый gate никогда не
бросает исключение в resolver.

### Конфигурация

<!-- snippet: config -->

```php
// config/vaulter-drive.php — used only when access.permission_driver = 'gate'
'access' => ['gate' => [
    'abilities' => [
        'previewer' => 'vaulter.preview',
        'reader'    => 'vaulter.view',
        'commenter' => 'vaulter.comment',
        'writer'    => 'vaulter.edit',
        'organizer' => 'vaulter.organize',
        'owner'     => 'vaulter.manage',
    ],
    'super_ability'  => env('VAULTER_GATE_SUPER_ABILITY', 'vaulter.super'),
    'create_ability' => env('VAULTER_GATE_CREATE_ABILITY', 'vaulter.create'),
]],
```

Карта `abilities` связывает ключ роли Vaulter со строкой хостового права Gate. Сопоставляйте
только те роли, которые вы действительно закрываете через gate; ключ, не являющийся ролью,
бросает `UnknownRoleException`. Реализуйте соответствующие
политики:

<!-- snippet: host -->

```php
// A host policy / Gate definition
Gate::define('vaulter.edit', fn ($user, array $context) =>
    $user->isEditorOf($context['drive_owner']->type, $context['drive_owner']->id));

Gate::define('vaulter.super', fn ($user) => $user->isAdmin());

Gate::define('vaulter.create', fn ($user, array $context) =>
    $user->mayCreateIn($context['drive_owner']->type, $context['drive_owner']->id));
```

## Spatie — роли, разрешения и v8 teams

`Vaulter\Drive\Access\Drivers\SpatiePermissionDriver` мостит хост на
`spatie/laravel-permission`, включая фичу **v8 teams**. Он инкапсулирует весь танец переключения
команд v8, так что тот никогда не протекает в resolver.

- **`subjectsFor`** = актор + его субъекты-роли (+ его субъект-команда, когда teams включены),
  так что строка гранта, адресованная `role`/`team`, совпадает.
- **`resolve`** проверяет каждое сопоставленное разрешение через `$actor->hasPermissionTo($name)`,
  **в области команды** для workspace (`setPermissionsTeamId($scope->driveOwner->id)`), затем
  восстанавливает предыдущий team id и загруженные связи актора. Объединение разрешённых ролей
  становится `Decision`.
- **`override`** спрашивает настраиваемое супер-разрешение.
- **`canCreateIn`** спрашивает разрешение на создание в контексте команды workspace.

::: warning Опциональная зависимость
`spatie/laravel-permission` — **опциональная** хостовая зависимость. Каждое обращение к API Spatie
в рантайме защищено `class_exists(PermissionRegistrar::class)`, так что vaulter-core не несёт
жёсткой зависимости. Выбор `spatie` без установки пакета деградирует до безопасного запрета
(`null`/`false`), а не до фатальной ошибки.
:::

### Конфигурация

<!-- snippet: config -->

```php
// config/vaulter-drive.php — used only when access.permission_driver = 'spatie'
'access' => ['spatie' => [
    'permissions' => [
        'previewer' => 'vaulter.preview',
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
]],
```

`teams` откатывается на этот ключ **только** тогда, когда собственный `config('permission.teams')`
хоста не задан — Spatie-конфиг хоста имеет приоритет. `role_subject_type` / `team_subject_type` —
это morph-алиасы, которым адресуются строки грантов на роль/команду.

### Переключение команды и шов кэша

Полный танец v8 — захватить текущий team id, `setPermissionsTeamId($scope->driveOwner->id)`, проверить,
восстановить в `finally` — целиком живёт внутри драйвера, поэтому конкурентные панели никогда не
перетекают друг в друга. После того как хост меняет роли/разрешения актора **вне** собственных
хелперов assign/remove Spatie, он обязан сбросить кэш, чтобы устаревшее, привязанное к команде
разрешение не выдало отозванную роль при следующем resolve:

<!-- snippet: host -->

```php
use Vaulter\Drive\Access\Drivers\SpatiePermissionDriver;

SpatiePermissionDriver::forgetCachedPermissions($actor);
```

Это no-op, когда Spatie не установлен.

## AzGuard — шов на уровне ресурса

`Vaulter\AzGuard\AzGuardPermissionDriver` находится в отдельном пакете
`axiomasoft/vaulter-azguard`; ядро не зависит от AzGuard. Мост требует AzGuard v1
(`axiomasoft/azguard`). Тегированного релиза AzGuard нет, поэтому мост закреплён на коммите:
`"axiomasoft/azguard": "dev-main#9a6dc0e…"` (полный sha — в `packages/azguard/composer.json`).

<!-- snippet: shell -->

```bash
composer require axiomasoft/vaulter-azguard
```

Обнаруживаемый Laravel provider предлагает модуль `azguard`, который регистрирует ключ `azguard` в
`vaulter-drive.access.permission_drivers`, сохраняя явное переопределение хоста. Затем выберите
`VAULTER_PERMISSION_DRIVER=azguard`. Без установленного моста этот ключ неизвестен
и вызывает `MissingPermissionDriverException`.

Составной порядок разрешения — node-ACL → роль в области workspace → global. Этот драйвер владеет
полосой **роли в области workspace**:

- **`override`** = `null`. `isSuperAdmin()` в AzGuard — не решение, поэтому мост никогда не
  превращает его в абсолютный allow Vaulter: super-admin AzGuard получает роль **owner** через
  те же решения в workspace (ниже), которые сохраняют политику scope, ограничения, политики и
  правила тенантов AzGuard.
- **`resolve`** проверяет каждое право каждой роли через сопоставленное разрешение AzGuard,
  передавая актора решения в `hasPermissionIn($actor, $workspaceType, $workspaceId, $permission)`.
  Роль выдаётся только после всех успешных проверок; отсутствие сопоставления запрещает роль.
  Объединение полностью разрешённых ролей становится `Decision`. (`AccessScope` не видит узла,
  поэтому это проверка в области workspace.)
- **`subjectsFor`** = только `[Subject(actor)]` — AzGuard *отвечает* на проверки, а не перечисляет
  субъектов-роли. Хост, адресующий строки грантов на роли AzGuard, должен унаследоваться и
  дописать эти субъекты.
- **`canCreateIn`** спрашивает `hasPermissionIn($actor, $type, $id, $createPermission)`.

Одно разрешение не даёт остальные права роли. Например, View и Comment без Download
дают максимум Previewer: Comment и Download остаются запрещены, поскольку Commenter включает
Download. Для выдачи полного набора актор должен иметь все сопоставленные разрешения роли.

### Панель AzGuard (хост)

Хост объявляет панель AzGuard v1, которой принадлежат разрешения Vaulter:

- модель пользователя — субъект панели и реализует
  `Vaulter\AzGuard\Contracts\AzGuardBackedUser` — собственный `AzGuardSubject` AzGuard,
  который трейт `AzGuard\Concerns\HasAzGuard` закрывает без дополнительных методов;
- разрешения из карты (`documents.view`, …) — разрешения панели;
- каждый morph-алиас владельца диска, в котором проверяет драйвер (`team`, `project`, …), —
  **тип assignment scope** панели. Полоса workspace —
  `hasPermission($permission, on: AssignmentScopeRef::of($type, $id))`; корректный алиас, не
  объявленный панелью, получает отказ (fail-closed). С `AssignmentScopePolicy::inherit(...)`
  грант на весь тенант (включая роль super-admin) действует в каждом workspace; с
  `isolated(...)` считаются только гранты внутри workspace;
- идентичности AzGuard — алиасы типов (`[a-z0-9][a-z0-9_.-]*`), а не имена классов:
  зарегистрируйте модели пользователя и workspace в morph map. Владелец диска с типом-не-алиасом
  (например, morph по имени класса) — ошибка конфигурации: AzGuard бросает
  `InvalidAssignmentScopeException`, а не отказывает.

<!-- snippet: host -->

```php
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Sources\Database\DatabaseSource;

final class AppPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'app';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class)
            ->permissions([DocumentsPermission::class, DatabaseSource::make()])
            ->roles([SuperAdminRole::class])
            ->scopes(AssignmentScopePolicy::inherit(new TeamScope)); // type 'team'
    }
}
```

### Конфигурация

В отличие от Gate/Spatie (с ключами по ключу роли), карта AzGuard **центрирована на действиях** —
с ключами по `Ability`:

<!-- snippet: config -->

```php
// config/vaulter-azguard.php — used only when access.permission_driver = 'azguard'
return [
    'panel_id' => null, // AzGuard `guard:`; null lets AzGuard select the panel
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
    'create_permission' => env('VAULTER_AZGUARD_CREATE_PERMISSION', 'documents.create'),
];
```

`panel_id` передаётся в AzGuard как `guard:`. При `null` AzGuard выбирает панель по полному
имени (`app:documents.view`) или префиксу панели в разрешении, иначе по панели запроса,
иначе по панели модели пользователя; карта по умолчанию называет локальные ключи этой панели.
Поэтому префикс ресурсов этой панели не должен быть `documents`: AzGuard не загрузит панель,
префикс которой совпадает с первым сегментом одного из её разрешений (`PrefixConflictException`).
Задайте панели другой `resourcePrefix()` (или `resourcePrefix(false)`) либо сопоставьте права с
разрешениями, которые не начинаются с префикса. Задайте `VAULTER_AZGUARD_PANEL`, если модель пользователя входит в
несколько панелей или проверки не должны зависеть от панели запроса; если панель выбрать
нельзя, AzGuard бросает исключение. Панели с тенантами нужен текущий тенант; без него AzGuard
отказывает в проверке.

### Адаптер AzGuardGuard

`Vaulter\AzGuard\Contracts\AzGuardGuard` — это узкий шов, с которым говорит драйвер. Он выставляет
ровно те два решения AzGuard, которые нужны драйверу, — и ничего из собственного API AzGuard:

<!-- snippet: signature Vaulter\AzGuard\Contracts\AzGuardGuard -->

```php
use Illuminate\Contracts\Auth\Access\Authorizable;

interface AzGuardGuard
{
    public function hasScopedPermission(Authorizable $actor, string $permission, object $entity): bool;

    public function hasPermissionIn(Authorizable $actor, string $contextType, int|string $contextId, string $permission): bool;
}
```

Драйвер всегда отвечает за **актора** решения, а не за того, кто сейчас делает запрос:

1. Если актор сам реализует `AzGuardGuard`, драйвер использует его напрямую.
2. Иначе используется класс guard, который **хост** назвал в `vaulter-azguard.guard` (см. переопределение ниже).
3. Иначе для актора, реализующего `Vaulter\AzGuard\Contracts\AzGuardBackedUser`, драйвер строит
   stateless `AzGuardGuardAdapter` в панели `vaulter-azguard.panel_id`, передавая актора каждой проверке.
4. Любой другой актор не получает полосу AzGuard (ничего не повышается, `canCreateIn` — `false`).

Мост не привязывает собственный guard: guard никогда не строится из пользователя **запроса** для
решения о другом акторе. Проверка от имени другого пользователя или предпросмотр прав не наследуют
права пользователя запроса, а вызов без пользователя запроса — хаб коллаборации сам определяет
субъекта токена — решается собственными правами актора в AzGuard.

::: details Необязательное назначение одного guard для всех акторов
Задайте в `vaulter-azguard.guard` класс, реализующий `AzGuardGuard` (проверяется на boot; по умолчанию
`null`). Драйвер явно передаёт актора каждому вызову guard. Общий guard должен использовать
этот аргумент, а не получать или сохранять пользователя текущего запроса. Поставляемый
`AzGuardGuardAdapter` не хранит пользователя и вызывает AzGuard v1 для переданного актора:

<!-- snippet: host -->

```php
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Vaulter\AzGuard\Contracts\AzGuardBackedUser;

// config/vaulter-azguard.php: 'guard' => \App\Support\HostAzGuardGuard::class,
final class HostAzGuardGuard implements \Vaulter\AzGuard\Contracts\AzGuardGuard
{
    public function hasScopedPermission(Authorizable $actor, string $permission, object $entity): bool
    {
        return $actor instanceof AzGuardBackedUser && $entity instanceof Model
            && $actor->hasPermission($permission, on: $entity);
    }

    public function hasPermissionIn(Authorizable $actor, string $contextType, int|string $contextId, string $permission): bool
    {
        return $actor instanceof AzGuardBackedUser
            && $actor->hasPermission($permission, on: AssignmentScopeRef::of($contextType, $contextId));
    }
}
```

Драйвер передаёт строку типа workspace насквозь без изменений — он никогда не резолвит
алиас сам.
:::

### Тестирование полосы AzGuard

`Vaulter\AzGuard\Testing\FakeAzGuardGuard` — настраиваемый in-memory guard
из `vaulter-azguard`. Установите мост со всеми его Composer-зависимостями даже для
тестов с фейком; фейк заменяет проверки AzGuard, но не требования установки пакета.

<!-- snippet: host -->

```php
use Vaulter\AzGuard\Testing\FakeAzGuardGuard;

// name the fake as the host's explicit guard, then hand out the configured instance
config()->set('vaulter-azguard.guard', FakeAzGuardGuard::class);

// grant one workspace permission
app()->instance(FakeAzGuardGuard::class, FakeAzGuardGuard::grantingWorkspace('team', $teamId, 'documents.update'));

// or a super-admin (every check allowed → owner role)
app()->instance(FakeAzGuardGuard::class, FakeAzGuardGuard::superAdmin());
```

Для end-to-end тестов на настоящем движке используйте собственные хелперы AzGuard
(`AzGuard\Testing\InteractsWithAzGuard`): `actingAsWithPermissions($user, ['documents.update'],
on: AssignmentScopeRef::of('team', $teamId))` и `actingAsSuperAdmin($user)` сохраняют настоящие
гранты через конвейер изменений AzGuard.

## Сравнение драйверов

| | Database | Gate | Spatie | AzGuard |
|---|---|---|---|---|
| Дополнительная зависимость | нет | нет (встроенный) | опциональная `spatie/laravel-permission` | `axiomasoft/vaulter-azguard` + его зависимости |
| Карта с ключами по | — | ключ роли | ключ роли | `Ability` |
| Перечисляет субъектов-роли/команды | через наследование | нет | да | нет |
| Обход для супер-админа | нет | право `vaulter.super` | супер-разрешение | нет — super-admin AzGuard → роль owner через решения |
| Область workspace | гранты на уровне узла | node-blind контекст | в области команды | `hasPermissionIn` |
| Безопасно, когда не настроен | н/д | да | да (запрет) | только с установленным мостом |

## Генератор

<!-- snippet: shell -->

```bash
php artisan vaulter:make:permission-driver Workspace
```

Создаёт драйвер-проход в `app/Vaulter/PermissionDrivers/Workspace.php` и Pest-тест в `tests/Unit/Vaulter/`, который запускает `PermissionDriverContractTests`. Команда печатает строку для
`vaulter-drive.access.permission_drivers` и не меняет ваш конфиг. Существующий файл не перезаписывается без `--force`.

## Тесты вашего расширения

`Vaulter\Drive\Testing\Contracts\PermissionDriverContractTests` проверяет драйвер так же, как
встроенные. Общий фикстур (`PermissionFixture`) строится через Operations: грант writer на
поддерево для актора на родительской папке и дочерняя папка с ограниченным наследованием и без
собственного гранта.

<!-- snippet: host -->

```php
use Vaulter\Drive\Testing\Contracts\PermissionDriverContractTests;

it('decides like the reference driver', function (): void {
    (new PermissionDriverContractTests)->assertSameDecisionsAsReference(new AcmeRoleDriver);
});
```

`assertSameDecisionsAsReference()` сравнивает каждую `Ability` на родителе и на потомке с
database-драйвером и падает, если ваш решает иначе. Используйте его для драйвера, который не
добавляет собственных ролей, субъектов и override; драйвер, который их добавляет, проверяйте на
ожидаемых вами решениях через `assertPermissionDriverContract()` на своём фикстуре.

Проверки видимости для тестов хоста — в трейте `InteractsWithVaulter`:
`assertNodeVisibleTo($user, $node)` и `assertNodeNotVisibleTo($user, $node)` спрашивают через
Operations драйва, как это сделал бы запрос.

## См. также

- [Авторизация](/ru/concepts/access-model) — как ответы драйвера подмешиваются в итоговое
  решение.
- [Гранты](/ru/guides/grants) — строки `v_node_grants`, с которыми сопоставляется набор субъектов.
- [Рецепт мультитенантности](/ru/guides/recipes) — сквозная связка тенант + роль.
- ADR-0002 (делегирование авторизации), ADR-0007 (шов AzGuard), ADR-0025 (модель доступа 1.0: роли, без deny).
