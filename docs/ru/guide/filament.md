---
title: Админ-плагин для Filament
description: Управляйте хранилищами Vaulter, узлами, ACL-грантами, публичными ссылками и лентой активности прямо из вашей панели Filament 5 — каждая мутация проходит через ядровые Actions и проверяется через AccessResolver.
outline: deep
---

# Админ-плагин для Filament

`axioma-studio/vaulter-filament` — это опциональный плагин для **Filament 5**, который добавляет
управление Vaulter в существующую панель Filament. Он регистрирует два семейства ресурсов:

- домен **Drive / права** — `Storage`, `Node`, `NodeGrant`, `NodeShare`, `Activity`;
- **легаси-поверхность вокруг документов** — `Document`, `Folder`, `Share`.

Каждая запись, которую делает панель, проходит через **ядровый Action** (никогда напрямую через
Eloquent), а каждое строковое действие и проверка видимости проходят через ядровый
[`AccessResolver`](/ru/concepts/authorization) — так что панель не может обойти ACL.

::: tip Смежное чтение
Drive-UI для конечных пользователей живёт в [пакете Vue](/ru/guide/vue). Модель авторизации, которой
подчиняются эти ресурсы, описана в разделе [Авторизация](/ru/concepts/authorization).
:::

## Установка

```bash
composer require axioma-studio/vaulter-filament
```

Плагин поставляет свой провайдер через Laravel package discovery
(`Vaulter\Filament\VaulterFilamentServiceProvider`). Опубликуйте конфиг, когда захотите
включать/выключать ресурсы или переименовать навигационную группу:

```bash
php artisan vendor:publish --tag=vaulter-filament-config
```

Это создаст `config/vaulter-filament.php`.

## Регистрация плагина

Добавьте `VaulterPlugin::make()` в массив `plugins()` вашего провайдера панели:

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

```php
use Vaulter\Filament\VaulterPlugin;

$plugin = VaulterPlugin::current($panel); // возвращает VaulterPlugin
```

`register()` вызывает `$panel->resources([...])` только для тех ресурсов, у которых включён
конфиг-переключатель (см. [Конфигурация](#configuration)); сначала регистрируется домен Drive / права,
затем легаси-поверхность документов.

## Конфигурация

`config/vaulter-filament.php` состоит из двух секций:

```php
return [
    // Навигационная группа Filament, под которой перечислены все ресурсы Vaulter.
    'navigation_group' => 'Vaulter',

    // Переключатели ресурсов, которые регистрирует плагин. Каждый по умолчанию true.
    'resources' => [
        // Домен Drive / права (ядро M1–M4).
        'storage'  => true,
        'node'     => true,
        'grant'    => true,
        'share'    => true,
        'activity' => true,

        // Легаси-поверхность вокруг документов.
        'document'     => true,
        'folder'       => true,
        'legacy_share' => true,
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
`Vaulter\Filament\Support\PanelActor` и спрашивает у ядрового резолвера, обладает ли этот администратор
нужным [`Ability`](/ru/guide/abilities) на целевом узле:

```php
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Filament\Support\PanelActor;

$actor = PanelActor::current(); // аутентифицированный пользователь панели как Authorizable, либо null

if ($actor !== null && app(AccessResolver::class)->can($actor, Ability::Edit, $node)) {
    // действие предлагается / разрешено
}
```

`PanelActor` даёт две вещи:

| Метод | Возвращает | Для чего |
|--------|---------|----------|
| `PanelActor::current()` | `?Authorizable` | актор, передаваемый в `AccessResolver::can(...)`. `null` → карта прав, где всё `false` (read-only оболочка) |
| `PanelActor::id()` | `int\|string\|null` | `actorId`, штампуемый на ядровом Action (`granted_by` / `trashed_by` …). `null` → атрибутируется как «нет актора», никогда как выдуманный id |

Строковое действие **видно** только тогда, когда актор обладает соответствующим правом (ability), так
что панель никогда не предлагает операцию, которую администратор не может выполнить.

## Ресурсы

### Домен Drive / права

#### StorageResource

**Преимущественно на чтение** — вид на владеемые полиморфные корни дисков. Хранилища провизионируются
ядровым `DriveProvisioner` (get-or-create на хост-сущности), а не создаются из панели — поэтому этот
ресурс **не может создавать и редактировать** строку (`canCreate()` возвращает `false`); он лишь
перечисляет и инспектирует.

- **Модель**: определяется через `Config::storageModel()` (по умолчанию `Vaulter\Models\Drive\Drive`).
- **Таблица**: имя, владелец (`owner_type #owner_id`), диск, использованные байты, квота (`∞`, когда
  безлимит), статус, дата создания. Сортировка по умолчанию — `created_at desc`.
- **Infolist** (страница просмотра): секции Storage / Owner / Usage, с человекочитаемым форматом байтов.
- **Страницы**: `index` (список) и `view` (`/{record}`).

#### NodeResource

Сердце панели: единое дерево узлов `folder | file | document | shortcut`. Мутации — это **никогда** не
редактирование плоской формы: каждое строковое действие вызывает ядровый Drive Action через
[`DriveHandle`](/ru/reference/facade):

| Строковое действие | Ядровый путь | Право-гейт |
|------------|-----------|----------------|
| Rename | `Vaulter::forStorage($node->storage)->rename(...)` | `Ability::Edit` |
| Move | `…->move(...)` (место назначения выбирается из папок хранилища; пусто = корень) | `Ability::Move` |
| Trash | `…->trash(...)` | `Ability::Trash` |
| Restore | `…->restore(...)` (видно только на узле в корзине) | `Ability::Restore` |
| Delete permanently | `…->forceDelete(...)` (видно только на узле в корзине) | `Ability::Delete` |

```php
// Внутри NodeResource: обработчик строкового действия rename.
Vaulter::forStorage($record->storage)->rename(
    node: $record,
    name: $name,
    actorId: PanelActor::id(),
);
```

- **Модель**: `Config::nodeModel()` (`Vaulter\Models\Drive\Node`).
- **Таблица**: иконка типа (`folder` → folder, `file` → document, `document` → document-text,
  `shortcut` → arrow-top-right), имя (корневые строки показывают `(root)`), бейдж типа, хранилище,
  родитель, размер, дата помещения в корзину, дата создания.
- **Фильтры**: тип (`SelectFilter` по кейсам `NodeKind`), хранилище и тернарный `Trashed`
  (`Live only` / `Trashed only`).
- **Запрос**: сбрасывает `SoftDeletingScope`, чтобы мягко **удалённые в корзину** строки (`trashed_at`)
  были видны фильтру Trashed, тогда как жёстко удалённые (`deleted_at`) строки остаются скрытыми.
- **Create**: отключено — папки/файлы создаются через Drive Actions, а не через форму ресурса.
- **Страницы**: только `index`.

#### NodeGrantResource

Перечисление, создание и отзыв пер-узловых ACL-грантов, которые резолвер сворачивает в эффективные
разрешения. Грант адресует субъекта (`subject_type` + `subject_id`) на узле, задавая **уровень** плюс
дополнительные **биты CRUDS**, эффект **allow|deny**, область **item|subtree** и опциональный срок
истечения.

Обе мутации маршрутизируются через ядровые Actions и требуют `Ability::Share` на целевом узле:

- **Create** (действие в шапке страницы списка — `Create grant`) →
  `GrantNodeAccessAction` (идемпотентный upsert по ключу гранта).
- **Revoke** (строковое действие) → `RevokeNodeAccessAction` (снимает все гранты, которыми обладает
  субъект).

```php
// NodeGrantResource::grantFromData — создание через ядровый Action.
app(GrantNodeAccessAction::class)->execute(
    node: $node,
    data: new GrantNodeAccessInput(
        subject: new Subject(type: $subjectType, id: $subjectId),
        level: GrantLevel::from($level),     // reader | commenter | editor | manager
        effect: Effect::from($effect),       // allow | deny
        scope: GrantScope::from($scope),     // item | subtree
        permissions: $extraBits,             // 0–31, OR-ится поверх битов уровня
        expiresAt: $expiresAt,
        grantedBy: PanelActor::id(),
    ),
);
```

- **Модель**: `Config::grantModel()` (`Vaulter\Models\Access\NodeGrant`).
- **Таблица**: узел, субъект (`type #id`), бейдж уровня, бейдж эффекта (deny → danger), область, сырые
  биты (по умолчанию скрыты), срок истечения (`Never`, когда null), дата создания. Фильтры: эффект,
  уровень.
- **Поля формы**: узел, тип субъекта (зарегистрированный morph-алиас, например `user` / `role` /
  `team`), id субъекта, уровень, эффект, область, дополнительные биты CRUDS (0–31), срок истечения.
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

- **Create** (действие в шапке страницы списка — `Create share link`) → `CreateShareLinkAction`
  (создаёт + хеширует токен; сырое значение показывается один раз).
- **Revoke** (строковое действие) → `RevokeShareLinkAction` (штампует `revoked_at`; идемпотентно).

Обе требуют `Ability::Share` на узле.

```php
$minted = app(CreateShareLinkAction::class)->execute(
    node: $node,
    data: new ShareLinkInput(
        permissions: $bits > 0 ? $bits : Permission::Read->value, // по умолчанию READ (1)
        level: GrantLevel::from($level),
        scope: GrantScope::from($scope),   // item | subtree
        password: $password,
        requireLogin: $requireLogin,
        allowDiscovery: $allowDiscovery,
        expiresAt: $expiresAt,
        maxUses: $maxUses,
        grantedBy: PanelActor::id(),
    ),
);

// Показывается ровно один раз — потом из строки уже не восстановить:
//   Config::shareUrlBase() . '/' . $minted->token
```

- **Модель**: `Config::nodeShareModel()` (`Vaulter\Models\Sharing\NodeShare`).
- **Таблица**: узел, бейдж `token_prefix…`, уровень, область, вычисляемый бейдж статуса
  (`Live` / `Revoked` / `Expired` / `Exhausted`), иконка require-login, счётчик использований
  (`n / max`, когда есть лимит), срок истечения, дата создания. Фильтр: тернарный `Revoked`.
- **Поля формы**: узел, уровень, область, разрешения (0–31, по умолчанию READ), опциональный пароль,
  require-login, allow-discovery, max-uses, срок истечения.
- **Create**: только через действие в шапке.
- **Страницы**: только `index`.

#### ActivityResource

**Только на чтение** — вид на append-only аудит-лог `v_activity`: глагол, актор, цель и время. Модель
по построению append-only, поэтому ресурс не создаётся, не редактируется и не удаляется
(`canCreate`/`canEdit`/`canDelete` — все возвращают `false`).

- **Модель**: `Config::activityModel()` (`Vaulter\Models\Activity\Activity`).
- **Таблица**: бейдж глагола, актор (`type #id`), цель (`type #id`), хранилище, когда. Фильтр: глагол
  (`SelectFilter` по кейсам `EventType`).
- **Infolist** (страница просмотра): секции Event / Actor / Target / Request (секция Request включает
  `KeyValueEntry` для `context` плюс ip / user-agent / request-id).
- **Страницы**: `index` (список) и `view` (`/{record}`).

### Легаси-поверхность вокруг документов

Она остаётся для хостов, всё ещё сидящих на таблицах вокруг документов. Каждый ресурс определяет свою
модель через хелпер `Config::*Model()`, так что подменённая модель по-прежнему остаётся под контролем.

| Ресурс | Модель по умолчанию | Чем управляет |
|----------|---------------|-----------------|
| `DocumentResource` | `Vaulter\Models\Documents\Document` | искомые/сортируемые документы (заголовок, тип, workspace), фильтр корзины, форму редактирования и действия delete / restore / force-delete |
| `FolderResource` | `Vaulter\Models\Documents\DocumentFolder` | дерево папок workspace (список, создание, редактирование, удаление) |
| `ShareResource` | `Vaulter\Models\Documents\DocumentShare` | токенизированные публичные ссылки на документы — список и **отзыв** (только на чтение; ссылки создаются через API) |

Выключите их через конфиг-ключи `document`, `folder` и `legacy_share`.

## Подменяемость моделей

Каждый ресурс определяет свою модель через хелпер `Vaulter\Support\Config::*Model()`
(`storageModel()`, `nodeModel()`, `grantModel()`, `nodeShareModel()`, `activityModel()`, …). Если ваше
хост-приложение подменяет модель Vaulter на подкласс, Filament-ресурсы продолжают управлять ею без
каких-либо изменений.

## Почему отдельный пакет

Filament — тяжёлая опциональная зависимость. Держа админку в отдельном пакете, мы добиваемся того, что
приложения, не использующие Filament, никогда её не тянут, а её CI гоняется изолированно против
Filament-совместимого Laravel — так что она никогда не ограничивает матрицу тестов Laravel для ядрового
пакета.

## См. также

- [Пакет Vue](/ru/guide/vue) — Drive-UI для конечных пользователей.
- [Авторизация](/ru/concepts/authorization) — `AccessResolver`, `Ability`, гранты и публичные ссылки,
  которым подчиняются эти ресурсы.
- [Публичные ссылки](/ru/guide/sharing) — канал публичных ссылок (share), которым управляет
  `NodeShareResource`.
