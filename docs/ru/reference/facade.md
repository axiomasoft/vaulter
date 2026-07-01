---
title: Фасад и Manager
description: Фасад Vaulter и VaulterManager — публичная точка входа для интеграции с хост-приложением.
outline: deep
---

# Фасад и Manager

`Vaulter\Facades\Vaulter` — публичная точка входа пакета. Это тонкий фасад Laravel
поверх `Vaulter\VaulterManager`, который регистрируется как синглтон контейнера
(алиас `'vaulter'`) в `VaulterServiceProvider`. Каждый метод ниже — это реальный
метод на `VaulterManager`.

```php
use Vaulter\Facades\Vaulter;

$drive = Vaulter::for($project);          // открыть Drive-хэндл
$panel = Vaulter::panel('project_drive'); // резолв Storage Panel
```

::: info Резолвьте как удобно
Фасад, `app(VaulterManager::class)` и алиас контейнера `'vaulter'` возвращают один и
тот же синглтон. Внедрите `VaulterManager` в конструктор, если предпочитаете DI
фасаду.
:::

## Сводка методов

| Метод | Возвращает | Назначение |
|---|---|---|
| `for(Model $entity, ?string $panel = null)` | `DriveContext` | Открыть Drive-хэндл, привязанный к хранилищу сущности хоста в панели |
| `forStorage(Storage $storage)` | `DriveContext` | Открыть Drive-хэндл, привязанный к уже разрешённой строке-хранилищу |
| `panel(string $name = 'default')` | `Panel` | Резолв Storage Panel по id |
| `panels()` | `PanelRegistry` | Синглтон-реестр панелей |
| `registerPanel(StoragePanelProvider\|Panel $panel)` | `void` | Зарегистрировать Storage Panel в рантайме |
| `resolvePermissionsWith(PermissionResolver $resolver)` | `void` | Привязать легаси-резолвер разрешений на документном пути |
| `permissionResolver()` | `?PermissionResolver` | Разрешённый легаси-резолвер разрешений или `null` |
| `registerDocumentType(string $type, array $definition)` | `void` | Зарегистрировать пользовательский тип документа |
| `documentTypes()` | `array` | Зарегистрированные пользовательские типы документов |
| `registerHandler(DocumentHandler\|string $handler)` | `void` | Зарегистрировать document handler (расширяемость по форматам) |
| `handlers()` | `DocumentHandlerRegistry` | Реестр document handler'ов |
| `registerExporter(DocumentExporter $exporter)` | `void` | Зарегистрировать пользовательский формат экспорта |
| `exporter(string $format)` | `DocumentExporter` | Резолв экспортёра по ключу формата |
| `exporterFormats()` | `list<string>` | Все зарегистрированные ключи форматов экспорта |
| `driver(?string $name = null)` | `CollaborationDriver` | Резолв драйвера совместного редактирования |

::: warning Docblock фасада vs. manager
Docblock `@method` на фасаде `Vaulter` аннотирует лишь часть этих методов
(`for`, `forStorage`, `panel`, `panels`, `registerPanel`, `resolvePermissionsWith`,
`permissionResolver`, `registerDocumentType`, `documentTypes`, `driver`). Остальные
методы — `registerHandler`, `handlers`, `registerExporter`, `exporter`,
`exporterFormats` — это реальные методы `VaulterManager`, вызываемые через фасад;
просто их (пока) нет в блоке `@method`, поэтому ваша IDE может их не автодополнять.
Вызывайте `app(VaulterManager::class)`, если хотите полную статическую типизацию.
:::

## Точки входа в Drive

### `for(Model $entity, ?string $panel = null): DriveContext`

Основная точка входа для интеграции. Находит-или-создаёт строку `v_storages` для
сущности хоста в рамках панели (идемпотентно — корневая папка подготавливается
автоматически) и возвращает эргономичный `DriveContext`, методы которого делегируют
Drive-Action'ам и репозиториям. По умолчанию панель — `'default'`.

```php
$drive = Vaulter::for($project);                 // панель по умолчанию
$drive = Vaulter::for($project, 'project_drive'); // именованная панель
```

### `forStorage(Storage $storage): DriveContext`

Открыть Drive-хэндл, привязанный к уже разрешённой строке `Storage`. Это шов, который
использует HTTP-слой: `Storage`, связанный через route-model binding (разрешённый
через сменяемый `config('vaulter.models.storage')`), напрямую отображается в
`DriveContext` без повторного вывода владельца. Панель хэндла отражает
`$storage->panel`, поэтому его Action'ы выполняются в правильном scope панели.

```php
$drive = Vaulter::forStorage($storage);
```

Полную поверхность методов хэндла см. в [`DriveContext`](/ru/guide/drive).

## Панели

### `panel(string $name = 'default'): Panel`

Резолв Storage Panel по id (по умолчанию `'default'`). Делегирует
`PanelRegistry`.

### `panels(): PanelRegistry`

Синглтон-реестр панелей контейнера — по одной `Panel` на id. Строится из
`config('vaulter.panels')` (FQCN провайдеров) плюс `config('vaulter.default_panel')`.

### `registerPanel(StoragePanelProvider|Panel $panel): void`

Зарегистрировать Storage Panel в рантайме — либо через провайдер, либо через уже
собранную `Panel`. Реестр валидирует её и ключует по id.

```php
Vaulter::registerPanel(new ProjectStoragePanel());          // провайдер
Vaulter::registerPanel(Panel::make('reports', /* … */));    // собранная Panel
```

## Авторизация (легаси-путь документов)

### `resolvePermissionsWith(PermissionResolver $resolver): void`

Указать Vaulter, как делегировать авторизацию хост-приложению для **легаси-пути
документов** (`Vaulter\Contracts\Access\PermissionResolver`). Альтернатива —
задать `config('vaulter.permission_resolver')` через FQCN.

### `permissionResolver(): ?PermissionResolver`

Возвращает резолвер, привязанный в рантайме, или — если такого нет — инстанцирует
тот, что назван в `config('vaulter.permission_resolver')`. Возвращает `null`, когда
не задан ни один.

::: tip Это легаси-полоса
Для Drive ACL авторизация идёт через сменяемую полосу `HostRoleDriver`
(`config('vaulter.drivers.permission')`) в связке с принадлежащим Vaulter
`AccessResolver` — а не через этот резолвер. См.
[Contracts → Access](/ru/reference/contracts#access).
:::

## Типы документов и handler'ы

### `registerDocumentType(string $type, array $definition): void` / `documentTypes(): array`

Зарегистрировать и прочитать обратно пользовательские определения типов документов
(хранятся в памяти на manager'е). Встроенные типы документов настраиваются отдельно
через `config('vaulter.enabled_document_types')`.

### `registerHandler(DocumentHandler|string $handler): void` / `handlers(): DocumentHandlerRegistry`

Шов расширяемости по форматам. Добавить новый формат — это один класс + один вызов:
`registerHandler()` принимает FQCN или инстанс и делегирует привязанному к
контейнеру `DocumentHandlerRegistry` (единый источник истины для резолва
mime → расширение → возможность → приоритет). Ни миграций, ни правок ядра.

```php
Vaulter::registerHandler(\App\Vaulter\CadHandler::class);
```

Также можно перечислить FQCN handler'ов в `config('vaulter.handlers')` или привязать
их к тегированной привязке контейнера `'vaulter.handlers'`. См.
[Recipes → Custom handler](/ru/guide/recipes).

## Экспортёры

### `registerExporter(DocumentExporter $exporter): void`

Зарегистрировать пользовательский формат экспорта. Экспортёр ключуется по
собственному `format()`.

### `exporter(string $format): DocumentExporter`

Резолв экспортёра по ключу формата. Сначала смотрит на экспортёры, зарегистрированные
в рантайме, затем на `config('vaulter.exporters')` (резолвя FQCN через контейнер).
Бросает `Vaulter\Exceptions\Documents\UnknownExportFormatException` для неизвестного
формата.

### `exporterFormats(): list<string>`

Все известные ключи форматов экспорта — объединение настроенных и
зарегистрированных в рантайме экспортёров. Встроенные — `html` и `markdown`.

```php
Vaulter::registerExporter(new \App\Vaulter\PdfExporter());
Vaulter::exporterFormats(); // ['html', 'markdown', 'pdf']
```

См. [Recipes → Custom export](/ru/guide/recipes).

## Совместное редактирование

### `driver(?string $name = null): CollaborationDriver`

Резолв драйвера совместного редактирования. По умолчанию имя —
`config('vaulter.collaboration.driver')` (`reverb` или `hocuspocus`); класс драйвера
читается из `config('vaulter.collaboration.drivers.{name}.class')` и резолвится через
контейнер. Неизвестный или несоответствующий драйвер бросает
`Vaulter\Exceptions\VaulterException`.

```php
$driver = Vaulter::driver();            // настроенный по умолчанию
$driver = Vaulter::driver('hocuspocus'); // явный драйвер
```

## См. также

- [Справочник конфигурации](/ru/reference/configuration) — все config-ключи
- [Справочник контрактов](/ru/reference/contracts) — интерфейсы, которые эти методы принимают и возвращают
- [Справочник событий](/ru/reference/events) — что диспатчится по мере работы с диском
- [Справочник команд](/ru/reference/commands) — поверхность Artisan
