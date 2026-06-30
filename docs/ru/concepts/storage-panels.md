---
title: Storage Panels (Панели хранилищ)
description: Изолированные диски для любой host-сущности без дополнительных миграций — билдер Panel, хуки StoragePanelProvider, флаги функций и редкий продвинутый режим собственных таблиц.
outline: deep
---

# Storage Panels (Панели хранилищ)

**Storage Panel** (панель хранилища) — это именованный конверт конфигурации,
который вы привязываете к любой host-сущности (`User`, `Team`, `Project`,
`Workspace` — какой угодно вашей модели). Это ось хранения/изоляции Vaulter: она
определяет, в каких таблицах живёт диск, какие у него функции, как он
представляет узлы и какой формы у него первичные ключи.

Главное свойство — **ноль дополнительных миграций**. По умолчанию каждая панель
использует *одну* общую схему `v_*`, которая поставляется с Vaulter.
Провижининг диска для новой host-сущности не создаёт таблицу, не запускает
миграцию и не требует никакого скаффолдинга — вы пишете один класс-провайдер,
регистрируете его FQCN и вызываете `Vaulter::for($entity)`.

::: tip Панель ≠ тип хранилища
Панель — это **ось хранения/изоляции**. Она строго отделена от **типа
хранилища** (`private` | `team` | `public`), который является политикой-пресетом
и *переносится* панелью. У одной панели всегда ровно один тип хранилища; много
панелей могут разделять один тип.
:::

## Ментальная модель

```
Host-сущность ──владеет──▶  строка v_storages  ──root──▶  дерево v_nodes
 (User/Team/…)               (panel = 'project')           (папки, файлы, документы)
      ▲                           ▲
      │                           │ колонка-дискриминатор panel
   Vaulter::for($entity, 'project')
      │
   PanelRegistry ──get('project')──▶ value-объект Panel
                                       (таблицы, модели, функции, стратегия id)
```

- Одна **общая схема** (`v_storages`, `v_nodes`, `v_blobs`, …) хранит данные всех
  панелей.
- Колонка `panel` в `v_storages` и `v_nodes` — это дискриминатор, который держит
  панели раздельно на общей схеме.
- Тройка `(owner_type, owner_id, panel)` однозначно идентифицирует диск, поэтому
  одна и та же сущность может владеть отдельным диском в каждой панели.

См. [Архитектуру](/guide/architecture) о том, как панели соседствуют с
деревом узлов, CAS-блобами и резолвером доступа, и [Дерево узлов](/guide/drive)
о том, что лежит внутри хранилища.

## Быстрый старт: панель по умолчанию

Чтобы начать пользоваться Vaulter, ничего объявлять не нужно. Каждая установка
поставляется со встроенной панелью `default`, зарегистрированной автоматически,
поэтому пакет работает «из коробки» на общей схеме `v_*`:

```php
use Vaulter\Facades\Vaulter;

// Без аргумента панели → встроенная панель 'default'.
$drive = Vaulter::for($user);

$drive->createFolder('Invoices');
```

`Vaulter::for($entity)` **получает-или-создаёт** строку `v_storages` для этой
сущности внутри панели (идемпотентно — повторный вызов возвращает ту же строку),
провижинит корневую папку и возвращает [`DriveContext`](/reference/facade). Это
основная точка интеграции пакета.

```php
// VaulterManager::for — сигнатура
public function for(Model $entity, ?string $panel = null): DriveContext
```

Когда `$panel` равен `null`, он по умолчанию `'default'`. Чтобы нацелиться на
другую панель, передайте её id:

```php
$drive = Vaulter::for($project, 'project');
```

## Объявление панели

Панель настраивается подклассом `StoragePanelProvider`. Вы строите панель
текуче внутри `panel()`:

::: code-group

```php [app/Vaulter/Panels/ProjectStoragePanel.php]
<?php

declare(strict_types=1);

namespace App\Vaulter\Panels;

use App\Models\Project;
use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

final class ProjectStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('project')              // id панели для Vaulter::for($e, 'project')
            ->host(Project::class)        // документирующее намерение: host-модель
            ->storageType('team')         // пресет политики: private|team|public
            ->feature('share_links', true);
    }
}
```

```php [config/vaulter.php]
'panels' => [
    \App\Vaulter\Panels\ProjectStoragePanel::class,
],
```

:::

Провайдеры регистрируются **только по FQCN** в `config('vaulter.panels')` —
никогда не как замыкания — чтобы конфиг оставался кэш-безопасным (`config:cache`
работает). На бутстрапе [`PanelRegistry`](#реестр-панелей) резолвит каждый
провайдер в value-объект `Panel` и валидирует его.

::: info Панель по умолчанию регистрируется первой
`PanelRegistry::fromConfig()` регистрирует провайдер из
`config('vaulter.default_panel')` **первым**, затем ваши панели по порядку. Так
как регистрация идёт по id, панель, объявившая `id('default')`,
*переопределяет* встроенную панель по умолчанию.
:::

### Скаффолдинг через `vaulter:make-panel`

Провайдер вручную пишут редко. Генератор создаёт тонкий, редактируемый провайдер
в `app/Vaulter/Panels`:

```bash
php artisan vaulter:make-panel project_drive --host="App\Models\Project"
```

Сигнатура и опции:

| Опция | Эффект |
|---|---|
| `name` (аргумент) | id панели (snake/kebab), напр. `project_drive`. |
| `--host=` | FQCN host-модели, в которую встраивается хранилище (выдаёт `->host(...)`). |
| `--prefix=` | Префикс имён таблиц — **включает продвинутый режим собственных таблиц**. |
| `--no-grants` | Сгенерировать панель без поузловых грантов (выдаёт `->withoutGrants()`). |
| `--id=` | Стратегия первичного ключа (`ulid` \| `uuid7` \| `bigint`) — **только в режиме собственных таблиц**, выдаётся лишь вместе с `--prefix`. |
| `--force` | Перезаписать провайдер, если он уже существует. |

Команда **не трогает** опубликованный конфиг и **не генерирует** миграции — по
умолчанию панель является конвертом конфигурации с нулём миграций. Она явно
печатает следующие шаги:

```
Storage panel [project_drive] scaffolded.
Next steps:
  1. Register the provider in config/vaulter.php under 'panels':
         \App\Vaulter\Panels\ProjectDriveStoragePanel::class,
  2. Edit the provider: feature flags, fill/present hooks, pipes.
  3. ONLY if you set a tablePrefix: run php artisan vaulter:panel:migrations project_drive
  4. Add any custom columns in your own follow-up migration.
```

::: warning FQCN вы регистрируете сами
`make-panel` пишет класс, но оставляет `config('vaulter.panels')` нетронутым (он
никогда не редактирует опубликованный конфиг). Добавьте напечатанный FQCN в
массив `panels` сами — пока вы этого не сделаете, панель невидима.
:::

## Билдер `Panel`

`Vaulter\Panels\Panel` — это текучий мутабельный билдер в стиле Filament. Панель
объявляет только то, что *переопределяет*; любое неустановленное значение
откатывается к глобальному конфигу Vaulter. Порядок разрешения всегда
**override → prefix → global**.

| Метод | Назначение | Откат к |
|---|---|---|
| `id(string)` | id панели (ключ в реестре). | — (обязателен) |
| `host(string)` | FQCN host-модели (намерение/документация). | `null` |
| `storageType(string)` | Пресет политики типа хранилища: `private`/`team`/`public`. | `'private'` |
| `grants()` / `withoutGrants()` | Включить/выключить поузловые гранты. | включено |
| `feature(string, bool = true)` | Переключить одну функцию для этой панели. | глобальные `features` |
| `features(array)` | Слить карту переопределений функций. | глобальные `features` |
| `models(array)` | Карта «ключ сущности → класс модели» (свои модели панели). | `config('vaulter.models.*')` |
| `pipes(array)` | Добавить дополнительные классы pipe конвейера. | `[]` |
| `resource(string)` | FQCN Filament-ресурса для этой панели. | `null` |
| `tablePrefix(string)` | **Режим собственных таблиц** — префикс таблиц панели. | общая схема |
| `tables(array)` | **Режим собственных таблиц** — явная карта «база → физическая таблица». | общая схема |
| `idStrategy(string)` | **Только собственные таблицы** — стратегия ключей панели. | `config('vaulter.ids.default')` |

::: details Как разрешаются `model()` и `table()`
`Panel::model($entity)` возвращает переопределение `models()` панели, если оно
есть, иначе `config("vaulter.models.{$entity}")` (так host может глобально
заменить любую модель, а панель — переопределить её локально).

`Panel::table($base)` возвращает запись `tables()`, если она есть, иначе
`"{prefix}_{base}"` при заданном `tablePrefix()`, иначе общую таблицу по
умолчанию через `Config::table($base)`. Без префикса и без карты панель резолвит
общие таблицы `v_*` — это путь с нулём миграций.
:::

## Флаги функций

Панель может включать или выключать функции Vaulter независимо от глобальных
дефолтов `config('vaulter.features')`. Переопределения на уровне панели
побеждают; иначе панель использует встроенные дефолты по каждой функции.

```php
return $panel
    ->id('public_docs')
    ->storageType('public')
    ->withoutGrants()                 // grants → off для этой панели
    ->feature('share_links', true)    // share links → on
    ->features([
        'document_versions' => true,
        'activity' => false,
    ]);
```

Встроенные дефолты по функциям (используются, когда ни панель, ни вызов
`feature()` не задают значение):

| Функция | По умолчанию | Примечания |
|---|---|---|
| `grants` | следует `hasGrants` | `withoutGrants()` выключает. |
| `versions` | on | История версий файлов (`v_files`/`v_file_versions`). |
| `blobs` | on | Контент-адресуемое хранилище блобов. |
| `tags` | on | |
| `activity` | on | Лента аудита/активности. |
| `uploads` | on | Возобновляемые загрузки. |
| `extra_data` | on | JSON-колонка-«люк» `extra_data`. |
| `reliability` | on | Транзакционный outbox + вебхуки. |
| `share_links` | off | Bearer share-ссылки. |
| `public_listing` | off | |
| `document_versions` | off | Версии документов, привязанные к узлу. |

::: tip Функции управляют схемой, а не только поведением
В режиме собственных таблиц (ниже) флаги функций также решают, **какие таблицы
создаются** для панели. `blobs` гейтит `v_blobs`; `versions` — таблицы
файлов/версий; `grants` — `v_node_grants`; `share_links` — `v_node_shares`;
`document_versions` — `v_node_document_versions`; `uploads` — таблицы загрузок;
`activity` — `v_activity`; `reliability` — таблицы outbox/вебхуков. `v_storages`
и `v_nodes` создаются всегда.
:::

См. [Конфигурацию](/ru/guide/configuration#features) о глобальной карте функций.

## Хуки провайдера

`StoragePanelProvider` предоставляет четыре необязательных хука, чтобы панель
могла обогащать конвейер узлов и их представление **без форка пакета**. Все
четыре по умолчанию — no-op; переопределяйте только нужное.

| Хук | Когда выполняется | По умолчанию |
|---|---|---|
| `fillNode(array $data): array` | Доп. атрибуты для сохранения на сохраняемом узле. | `[]` |
| `presentNode(array $node): array` | Доп. атрибуты при представлении узла. | `[]` |
| `pipes(): array` | Доп. классы pipe конвейера (ingest/move/create). | `[]` |
| `booted(Panel $panel): void` | Один раз, после регистрации панели. | no-op |

```php
final class ProjectStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('project')->storageType('team');
    }

    /** Помечаем каждый узел этой панели источником project-drive. */
    public function fillNode(array $data): array
    {
        return ['extra_data' => ['source' => 'project-drive']];
    }

    /** Добавляем вычисляемый атрибут при сериализации узла клиенту. */
    public function presentNode(array $node): array
    {
        return ['badge' => 'Project'];
    }

    public function booted(Panel $panel): void
    {
        // напр. прогреть кэш, залогировать или зарегистрировать слушатель панели.
    }
}
```

::: info `pipes()` провайдера vs `pipes()` билдера
Добавить pipe конвейера можно двумя путями: хук `pipes()` провайдера или вызов
билдера `->pipes([...])` внутри `panel()`. Оба добавляют в список pipe панели;
форма билдера держит FQCN провалидированными на бутстрапе (см. ниже).
:::

## Реестр панелей

Все разрешённые панели живут в одном контейнер-синглтоне
`Vaulter\Panels\PanelRegistry`. Это единственный источник истины — каждый
value-объект `Panel` под ключом своего id.

```php
use Vaulter\Facades\Vaulter;

$registry = Vaulter::panels();         // PanelRegistry
$panel    = Vaulter::panel('project'); // Panel по id (по умолчанию: 'default')

$registry->has('project');             // bool
$registry->all();                      // array<string, Panel>
$registry->default();                  // панель 'default'
```

Панель можно зарегистрировать и в рантайме (напр. в тесте или host-специфичном
сервис-провайдере):

```php
Vaulter::registerPanel(new App\Vaulter\Panels\ProjectStoragePanel);
// или уже построенный экземпляр Panel:
Vaulter::registerPanel($panel);
```

### Валидация на бутстрапе (fail loud)

Реестр валидирует каждую панель при регистрации, поэтому сломанная панель падает
громко **на бутстрапе**, а не портит запрос позже:

- Каждый указанный FQCN модели должен существовать **и** быть Eloquent-`Model`.
- Каждый указанный pipe и FQCN `resource` должны существовать.
- Поканельная `idStrategy()` **отвергается на общей схеме** — она когерентна
  только когда у панели есть собственные таблицы.

Нарушение бросает `Vaulter\Exceptions\Panels\InvalidPanelException`; запрос
неизвестного id бросает `Vaulter\Exceptions\Panels\UnknownPanelException`. См.
[справочник ошибок](/guide/errors).

```php
// Эта панель отвергается на бутстрапе — idStrategy без собственных таблиц:
$panel->id('bad')->idStrategy('uuid7'); // ← InvalidPanelException::idStrategyWithoutOwnTables
```

## Продвинутое: режим собственных таблиц

::: warning Это редкий случай
**Большинству панелей собственные таблицы не нужны.** Дефолт на общей схеме
(ноль миграций) — рекомендуемый путь. Берите собственные таблицы только при
конкретной причине физически изолировать данные панели — отдельная политика
retention/GC, жёсткая граница тенантности на уровне таблиц или иная стратегия
первичных ключей для одного диска.
:::

Панель включает поканельные таблицы, объявив **либо** `tablePrefix()`, **либо**
явную карту `tables()`. Любой из этих вариантов переводит `Panel::hasOwnTables()`
в `true`:

```php
return $panel
    ->id('project')
    ->host(Project::class)
    ->storageType('team')
    ->tablePrefix('project_drive')   // → project_drive_storages, project_drive_nodes, …
    ->idStrategy('uuid7')            // теперь когерентно: у панели свои таблицы
    ->models([
        'nodes' => \App\Models\ProjectNode::class,
    ]);
```

С `tablePrefix('project_drive')` вызов `Panel::table('nodes')` резолвит
`project_drive_nodes` вместо общей `v_nodes`.

### Генерация миграции

Миграция нужна только панелям с собственными таблицами. Сгенерируйте её:

```bash
php artisan vaulter:panel:migrations project
```

Сгенерированный файл намеренно **тонкий и git-видимый**: он резолвит панель из
реестра во время миграции и делегирует определения колонок в `PanelTablesBuilder`,
поэтому никогда не расходится с общей схемой. *Эволюция* схемы — отдельная
забота: вы добавляете кастомные колонки в собственных последующих миграциях, а
не регенерацией этого файла.

```php
// Сгенерированная миграция целиком:
return new class extends Migration
{
    public function up(): void
    {
        (new PanelTablesBuilder)->createAll(
            app(PanelRegistry::class)->get('project'),
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropAll(
            app(PanelRegistry::class)->get('project'),
        );
    }
};
```

`PanelTablesBuilder` создаёт только те таблицы, которые включают
[функции](#флаги-функций) панели, в порядке зависимостей, со всеми внешними
ключами, указывающими на *собственные* разрешённые таблицы панели — так
префиксованная панель никогда по ошибке не сошлётся на общую схему.

::: tip Путь вывода
По умолчанию миграция пишется в `database/migrations` вашего приложения.
Переопределите назначение через `config('vaulter.migrations.path')`.
:::

::: danger Панель на общей схеме не может сгенерировать миграцию
Запуск `vaulter:panel:migrations` на панели без префикса и без карты таблиц
отвергается — панель по умолчанию поставляется с базовыми миграциями и не
нуждается в сгенерированном файле:

```
Vaulter panel [team] writes to the shared default-panel schema and needs no
generated migration. Give it a tablePrefix() or a tables() map to generate
per-panel tables.
```
:::

### Модели на панель

Модель общей схемы (`Vaulter\Models\Drive\Node` и т.д.) корректна для панели по
умолчанию — она резолвит общую таблицу `v_*` и глобальную стратегию ключей. Но
**префиксованной** панели нужны *конкретные* классы моделей на панель, чтобы
очередь, broadcast или регидрат Scout попадали в правильную таблицу (а не в
рантайм-«грабли» `$table`).

Подключите модель трейтом `HasVaulterPanel`, объявив панель и сущность:

```php
use Vaulter\Models\Concerns\HasVaulterPanel;
use Vaulter\Models\Drive\Node;

final class ProjectNode extends Node
{
    use HasVaulterPanel;

    protected string $vaulterPanel = 'project';
    protected string $vaulterEntity = 'nodes';
}
```

Теперь `getTable()` резолвит
`app(PanelRegistry::class)->get('project')->table('nodes')` (напр.
`project_drive_nodes`), и в режиме собственных таблиц стратегия ключей берётся из
той же панели. Свяжите модель с панелью, чтобы Vaulter её разрешал:

```php
->models(['nodes' => \App\Models\ProjectNode::class])
```

::: info Стратегия id поканельна только в режиме собственных таблиц
`idStrategy()` отвергается на бутстрапе на общей схеме (поканельная форма ключа
на общих таблицах некогерентна). На общей схеме каждая панель использует
глобальный `config('vaulter.ids.default')`. В режиме собственных таблиц
переопределение `idStrategy()` панели побеждает, откатываясь к глобальному
дефолту, если не задано.
:::

## Разобранный пример: общая схема vs собственные таблицы

::: code-group

```php [Общая схема (ноль миграций)]
// Публичный диск с share-ссылками на ТЕХ ЖЕ таблицах v_* — миграция не нужна.
final class TeamStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('team')
            ->storageType('public')
            ->withoutGrants()
            ->feature('share_links', true);
    }
}
```

```php [Собственные таблицы (продвинутое)]
// Физически изолированный диск на таблицах project_drive_* с ключами uuid7.
final class ProjectStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('project')
            ->host(Project::class)
            ->storageType('team')
            ->tablePrefix('project_drive')
            ->idStrategy('uuid7')
            ->models(['nodes' => ProjectNode::class]);
    }
}
// Затем: php artisan vaulter:panel:migrations project
```

:::

## См. также

- [Конфигурация](/ru/guide/configuration) — глобальные ключи `models`,
  `features`, `ids`, `table_names` и `panels`, к которым откатывается панель.
- [Архитектура](/guide/architecture) — где панели в слоях.
- [Дерево узлов](/guide/drive) — что содержит хранилище.
- [Справочник ошибок](/guide/errors) — `InvalidPanelException` / `UnknownPanelException`.
- ADR-0009 — запись решения о панелях хранилищ.
