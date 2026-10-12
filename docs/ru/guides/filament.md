---
title: Админ-плагин для Filament
description: Управляйте хранилищами Vaulter, узлами, ACL-грантами, публичными ссылками и лентой активности прямо из вашей панели Filament 5 — любое чтение и запись идут через авторизованный handle диска.
outline: deep
---

# Админ-плагин для Filament

`axioma-studio/vaulter-filament` — это опциональный плагин для **Filament 5**, который добавляет
управление Vaulter в существующую панель Filament. Он регистрирует два семейства ресурсов:

- домен **Drive / права** — `Drive`, `Node`, `NodeGrant`, `NodeShare`, `Activity`;
- **легаси-поверхность вокруг документов** — `Document`, `Folder`, `Share`.

Ресурсы узлов и дисков читают и пишут через авторизованный [`DriveHandle`](/ru/reference/php-api) —
`Vaulter::drive($drive)->actingAs(PanelActor::current())` — и его snapshot-DTO; ресурсы грантов, публичных ссылок
и активности используют те же ядровые операции с актором панели. Панель не вызывает ядровые
Actions и Repositories и сама не вычисляет гранты, поэтому не может обойти ACL.

::: tip Смежное чтение
Drive-UI для конечных пользователей живёт в [пакете Vue](/ru/guides/vue). Модель авторизации, которой
подчиняются эти ресурсы, описана в разделе [Авторизация](/ru/concepts/access-model).
:::

## Установка

<!-- snippet: shell -->

```bash
composer require axioma-studio/vaulter-filament
```

Плагин поставляет свой провайдер через Laravel package discovery
(`Vaulter\Filament\VaulterFilamentServiceProvider`). Опубликуйте конфиг, когда захотите
включать/выключать ресурсы или переименовать навигационную группу:

<!-- snippet: shell -->

```bash
php artisan vendor:publish --tag=vaulter-filament-config
```

Это создаст `config/vaulter-filament.php`.

## Регистрация плагина

Добавьте `VaulterPlugin::make()` в массив `plugins()` вашего провайдера панели:

<!-- snippet: host -->

```php
use Filament\Panel;
use Vaulter\Filament\VaulterPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            VaulterPlugin::make(),
        ]);
}
```

Идентификатор плагина — `vaulter`. Если вам нужен сам экземпляр плагина, полученный обратно из панели
(ради его конфига / переключателей), используйте типизированный аксессор вместо инлайн-приведения:

<!-- snippet: host -->

```php
use Vaulter\Filament\VaulterPlugin;

$plugin = VaulterPlugin::current($panel); // возвращает VaulterPlugin
```

`register()` вызывает `$panel->resources([...])` только для тех ресурсов, у которых включён
конфиг-переключатель (см. [Конфигурация](#конфигурация)); сначала регистрируется домен Drive / права,
затем легаси-поверхность документов.

## Конфигурация

`config/vaulter-filament.php` состоит из двух секций:

<!-- snippet: config -->

```php
return [
    // Навигационная группа Filament, под которой перечислены все ресурсы Vaulter.
    'navigation_group' => 'Vaulter',

    // Переключатели ресурсов, которые регистрирует плагин. Каждый по умолчанию true.
    'resources' => [
        // Домен Drive / права (ядро M1–M4).
        'drive'    => true,
        'node'     => true,
        'grant'    => true,
        'share'    => true,
        'activity' => true,
    ],
];
```

Переведите ключ в `false`, чтобы убрать ресурс из панели. Плагин читает каждый переключатель через
`config('vaulter-filament.resources.{key}', true)` — пропущенное или небулево значение трактуется как
`true`.

::: tip Панель только для Drive
Если ваше хост-приложение не использует легаси-таблицы документов, задайте `document`, `folder` и
`legacy_share` в `false`, чтобы показать только ресурсы Drive / права.
:::

## Как работает авторизация

Каждый ресурс домена Drive / права определяет действующего администратора через
`Vaulter\Filament\Support\PanelActor` и работает через handle, который ядро выдаёт этому администратору.
Handle решает, что для него существует и что ему можно, поэтому изменение правил доступа в ядре доходит до
панели без правок панели:

<!-- snippet: host -->

```php
use Vaulter\Facades\Vaulter;
use Vaulter\Filament\Support\PanelActor;

$handle = Vaulter::drive($drive)->actingAs(PanelActor::current());

$handle->nodes()->rename(node: $id, name: $name); // 404 / 403 так же, как по HTTP
```

`PanelActor` даёт две вещи:

| Метод | Возвращает | Для чего |
|--------|---------|----------|
| `PanelActor::current()` | `?Authorizable` | актор для `actingAs(...)`. `null` → ни дисков, ни строк (read-only оболочка) |
| `PanelActor::id()` | `int\|string\|null` | id действующего администратора для атрибуции. `null` → «нет актора», никогда не выдуманный id |

Строковое действие **видно** только тогда, когда права handle на этот узел (`nodes()->abilitiesMany()`) его
разрешают, а при выполнении операция проверяет доступ ещё раз — поддельный вызов получает ту же ошибку, что и
HTTP API. Drive появляется в панели, если администратор видит его корневой узел; тот, у кого есть грант только
на вложенную папку, работает с ней через API или клиент Vue, а не через таблицы панели.

## Ресурсы

### Домен Drive / права

#### DriveResource

**Преимущественно на чтение** — вид на владеемые полиморфные корни дисков. Drive провизионируются `Vaulter\Drive\DriveProvisioner` (get-or-create на хост-сущности), а не создаются из панели — поэтому этот
ресурс **не может создавать и редактировать** строку (`canCreate()` возвращает `false`); он лишь
перечисляет и инспектирует.

- **Модель**: определяется через `app(\Vaulter\Configuration\VaulterConfig::class)->model('drive')` (по умолчанию `Vaulter\Models\Drive\Drive`).
- **Таблица**: имя, владелец (`owner_type #owner_id`), диск, использованные байты, квота (`∞`, когда
  безлимит), статус, дата создания. Сортировка по умолчанию — `created_at desc`.
- **Infolist** (страница просмотра): секции Drive / Owner / Usage, с человекочитаемым форматом байтов.
- **Страницы**: `index` (список) и `view` (`/{record}`). Классы: `ListDrives` и `ViewDrive` в
  `Vaulter\Filament\Resources\DriveResource\Pages`.

#### NodeResource

Сердце панели: единое дерево узлов `folder | file | document | shortcut`. Мутации — это **никогда** не
редактирование плоской формы: каждое строковое действие вызывает операцию авторизованного
[`DriveHandle`](/ru/reference/php-api):

| Строковое действие | Вызов handle | Право-гейт |
|------------|-----------|----------------|
| Rename | `nodes()->rename(...)` | `Ability::Edit` |
| Move | `nodes()->move(...)` (назначение — папки, куда администратор может создавать; пусто = корень) | `Ability::Move` на узле, `Create` на назначении |
| Trash | `nodes()->trash(...)` | `Ability::Trash` |
| Restore | `trash()->restore(...)` (видно только на узле в корзине) | `Ability::Restore` |
| Delete permanently | `trash()->purge(...)` (видно только на узле в корзине) | `Ability::Delete` |

<!-- snippet: host -->

```php
// Внутри NodeResource: обработчик строкового действия rename.
PanelDrives::handle($driveId)->nodes()->rename(node: $id, name: $name);
```

- **Модель**: `app(\Vaulter\Configuration\VaulterConfig::class)->model('node')` (`Vaulter\Models\Drive\Node`).
- **Строки**: custom records Filament, собранные из листингов handle (`children()` по каждому диску и
  `trash()->list()`), не более первых 2000 узлов. За таблицей нет Eloquent-запроса.
- **Таблица**: иконка типа (`folder` → folder, `file` → document, `document` → document-text,
  `shortcut` → arrow-top-right), имя (корневые строки показывают `(root)`), бейдж типа, хранилище,
  родитель, размер, дата помещения в корзину, дата создания.
- **Фильтры**: тип (`SelectFilter` по кейсам `NodeKind`), хранилище и тернарный `Trashed`
  (`Live only` / `Trashed only`).
- **Корзина**: строки из корзины приходят из листинга trash, поэтому фильтр Trashed переключает между
  двумя наборами; purge-узлов больше не существует. Сортировка, поиск (по имени) и фильтры работают по
  загруженным строкам.
- **Create**: отключено — папки/файлы создаются через операции диска, а не через форму ресурса.
- **Страницы**: только `index`.

#### NodeGrantResource

Перечисление, создание и отзыв пер-узловых ACL-грантов, которые резолвер сворачивает в эффективные
разрешения. Грант адресует субъекта (`subject_type` + `subject_id`) на узле, задавая **роль** (`role_key`),
область **item|subtree** и опциональный срок истечения. Гранты только позитивные.

Обе мутации идут через операции `access()` handle и требуют `Ability::Manage` на целевом узле:

- **Create** (действие в шапке страницы списка — `Create grant`) →
  `access()->grant(...)` (идемпотентный upsert по ключу гранта).
- **Revoke** (строковое действие) → `access()->revoke(...)`.

<!-- snippet: host -->

```php
// NodeGrantResource::grantFromData — создание через handle.
$handle->access()->grant($nodeId, new GrantInput(
    subject: new Subject(type: $subjectType, id: $subjectId),
    role: $role,                         // ключ зарегистрированной роли, например reader | writer
    scope: GrantScope::from($scope),     // item | subtree
    expiresAt: $expiresAt,
));
```

- **Модель**: `app(\Vaulter\Configuration\VaulterConfig::class)->model('grant')` (`Vaulter\Models\Access\NodeGrant`).
- **Таблица**: узел, субъект (`type #id`), бейдж роли, область, срок истечения (`Never`, когда null),
  дата создания. Фильтр: роль.
- **Поля формы**: узел, тип субъекта (зарегистрированный morph-алиас, например `user` / `role` /
  `team`), id субъекта, роль, область, срок истечения.
- **Create**: только через действие в шапке (`canCreate()` — `false`).
- **Страницы**: только `index`.

::: warning Тип субъекта — это morph-алиас
Поле формы `subject_type` ожидает **зарегистрированный morph-алиас** (`user`, `role`, `team`), а не
полностью квалифицированный класс. Регистрируйте свои алиасы через `Relation::enforceMorphMap([...])`.
:::

#### NodeShareResource

Перечисление, создание и отзыв **bearer-ссылок (share-links)** на узле. Сырой токен **никогда не
сохраняется и не показывается в таблице** — только несекретный `token_prefix` плюс статус
live/expired/revoked/exhausted. Сырой токен показывается **ровно один раз**, в момент создания, через
постоянное уведомление.

- **Create** (действие в шапке страницы списка — `Create share link`) → `shares()->create(...)`
  (создаёт + хеширует токен; сырое значение показывается один раз).
- **Revoke** (строковое действие) → `shares()->revoke(...)`.

Обе требуют `Ability::Share` на узле.

<!-- snippet: host -->

```php
$minted = Vaulter::drive($owner)->actingAs($user)->shares()->create($node, new ShareLinkInput(
    role: $role,                              // previewer | reader | commenter | writer
    audience: ShareAudience::from($audience), // anyone | authenticated | tenant
    scope: GrantScope::from($scope),          // item | subtree
    expiresAt: $expiresAt,
    password: $password,
    maxUses: $maxUses,
));

// Показывается ровно один раз — потом из строки уже не восстановить:
//   app(\Vaulter\Configuration\SharingConfig::class)->urlBase() . '/' . $minted->token
```

- **Модель**: `app(\Vaulter\Configuration\VaulterConfig::class)->model('node_share')` (`Vaulter\Models\Sharing\NodeShare`).
- **Таблица**: узел, бейдж `token_prefix…`, роль, область, вычисляемый бейдж статуса
  (`Live` / `Revoked` / `Expired` / `Exhausted`), счётчик использований
  (`n / max`, когда есть лимит), срок истечения, дата создания. Фильтр: тернарный `Revoked`.
- **Поля формы**: узел, роль, аудитория, область, опциональный пароль, max-uses, срок истечения.
- **Create**: только через действие в шапке.
- **Страницы**: только `index`.

#### ActivityResource

**Только на чтение** — вид на append-only аудит-лог `v_activity`: глагол, актор, цель и время. Модель
по построению append-only, поэтому ресурс не создаётся, не редактируется и не удаляется
(`canCreate`/`canEdit`/`canDelete` — все возвращают `false`).

- **Модель**: `app(\Vaulter\Configuration\VaulterConfig::class)->model('activity')` (`Vaulter\Models\Activity\Activity`).
- **Таблица**: бейдж глагола, актор (`type #id`), цель (`type #id`), хранилище, когда. Фильтр: глагол
  (`SelectFilter` по кейсам `EventType`).
- **Infolist** (страница просмотра): секции Event / Actor / Target / Request (секция Request включает
  `KeyValueEntry` для `context` плюс ip / user-agent / request-id).
- **Страницы**: `index` (список) и `view` (`/{record}`). Классы: `ListActivities` и `ViewActivity` в
  `Vaulter\Filament\Resources\ActivityResource\Pages`.

### Легаси-поверхность вокруг документов

Она остаётся для хостов, всё ещё сидящих на таблицах вокруг документов. Каждый ресурс определяет свою
модель через хелпер `VaulterConfig::model(string)`, так что подменённая модель по-прежнему остаётся под контролем.

| Ресурс | Модель по умолчанию | Чем управляет |
|----------|---------------|-----------------|
| `DocumentResource` | `Vaulter\Models\Documents\Document` | искомые/сортируемые документы (заголовок, тип, workspace), фильтр корзины, форму редактирования и действия delete / restore / force-delete |
| `FolderResource` | `Vaulter\Models\Documents\DocumentFolder` | дерево папок workspace (список, создание, редактирование, удаление) |
| `ShareResource` | `Vaulter\Models\Documents\DocumentShare` | токенизированные публичные ссылки на документы — список и **отзыв** (только на чтение; ссылки создаются через API) |

Выключите их через конфиг-ключи `document`, `folder` и `legacy_share`.

## Подменяемость моделей

Каждый ресурс определяет свою модель через хелпер `Vaulter\Configuration\VaulterConfig::model(string)`
(`storageModel()`, `nodeModel()`, `grantModel()`, `nodeShareModel()`, `activityModel()`, …). Если ваше
хост-приложение подменяет модель Vaulter на подкласс, Filament-ресурсы продолжают управлять ею без
каких-либо изменений.

## Почему отдельный пакет

Filament — тяжёлая опциональная зависимость. Держа админку в отдельном пакете, мы добиваемся того, что
приложения, не использующие Filament, никогда её не тянут, а её CI гоняется изолированно против
Filament-совместимого Laravel — так что она никогда не ограничивает матрицу тестов Laravel для ядрового
пакета.

## См. также

- [Пакет Vue](/ru/guides/vue) — Drive-UI для конечных пользователей.
- [Авторизация](/ru/concepts/access-model) — `AccessResolver`, `Ability`, гранты и публичные ссылки,
  которым подчиняются эти ресурсы.
- [Публичные ссылки](/ru/guides/sharing) — канал публичных ссылок (share), которым управляет
  `NodeShareResource`.
