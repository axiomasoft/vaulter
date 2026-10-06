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
$panel = Vaulter::profiles()->get('project_drive'); // резолв Storage Panel
```

::: info Резолвьте как удобно
Фасад, `app(VaulterManager::class)` и алиас контейнера `'vaulter'` возвращают один и
тот же синглтон. Внедрите `VaulterManager` в конструктор, если предпочитаете DI
фасаду.
:::

## Сводка методов

| Метод | Возвращает | Назначение |
|---|---|---|
| `for(Model $entity, ?string $panel = null)` | `DriveHandle` | Открыть Drive-хэндл, привязанный к хранилищу сущности хоста в панели |
| `forStorage(Drive $storage)` | `DriveHandle` | Открыть Drive-хэндл, привязанный к уже разрешённой строке-хранилищу |
| `registerDocumentType(string $type, array $definition)` | `void` | Зарегистрировать пользовательский тип документа |
| `documentTypes()` | `array` | Зарегистрированные пользовательские типы документов |
| `profiles()` | `ProfileRegistry` | Именованные профили Drive (`get`, `register`, `registerProvider`) |
| `handlers()` | `FileHandlerRegistry` | Реестр document handler'ов |
| `registerExporter(DocumentExporter $exporter)` | `void` | Зарегистрировать пользовательский формат экспорта |
| `exporter(string $format)` | `DocumentExporter` | Резолв экспортёра по ключу формата |
| `exporterFormats()` | `list<string>` | Все зарегистрированные ключи форматов экспорта |

Фасад аннотирует все публичные методы менеджера. Для регистрации используйте `profiles()` и `handlers()`.

## Точки входа в Drive

### `for(Model $entity, ?string $panel = null): DriveHandle`

Основная точка входа для интеграции. Находит-или-создаёт строку `v_storages` для
сущности хоста в рамках панели (идемпотентно — корневая папка подготавливается
автоматически) и возвращает эргономичный `DriveHandle`, методы которого делегируют
Drive-Action'ам и репозиториям. По умолчанию панель — `'default'`.

```php
$drive = Vaulter::for($project);                 // панель по умолчанию
$drive = Vaulter::for($project, 'project_drive'); // именованная панель
```

### `forStorage(Drive $storage): DriveHandle`

Открыть Drive-хэндл, привязанный к уже разрешённой строке `Drive`. Это шов, который
использует HTTP-слой: `Drive`, связанный через route-model binding (разрешённый
через сменяемый `config('vaulter.models.drive')`), напрямую отображается в
`DriveHandle` без повторного вывода владельца. Панель хэндла отражает
`$storage->profile`, поэтому его Action'ы выполняются в правильном scope панели.

```php
$drive = Vaulter::forStorage($storage);
```

Полную поверхность методов хэндла см. в [`DriveHandle`](/ru/guide/drive).

## Профили

### `profiles(): ProfileRegistry`

Получите профиль через `Vaulter::profiles()->get('project')` или зарегистрируйте
провайдер через `Vaulter::profiles()->registerProvider(new ProjectProfile)`.
См. [Профили Drive](/ru/concepts/storage-panels).

## Типы документов и handler'ы

### `registerDocumentType(string $type, array $definition): void` / `documentTypes(): array`

Зарегистрировать и прочитать обратно пользовательские определения типов документов
(хранятся в памяти на manager'е). Встроенные типы документов настраиваются отдельно
через `config('vaulter.enabled_document_types')`.

### `handlers(): FileHandlerRegistry`

Шов расширяемости по форматам. Добавить новый формат — это один класс + один вызов:
`handlers()->register()` принимает FQCN или инстанс и делегирует привязанному к
контейнеру `Vaulter\Handlers\FileHandlerRegistry` (принадлежит core с P0.4/D10 —
единый источник истины для резолва mime → расширение → возможность → приоритет,
резолвится даже без установленного `vaulter-documents`). Ни миграций, ни правок
ядра, ни обязательного пакета.

```php
Vaulter::handlers()->register(\App\Vaulter\CadHandler::class);
```

Также можно перечислить FQCN handler'ов в `config('vaulter.handlers')` или привязать
их к тегированной привязке контейнера `'vaulter.file_handlers'`. См.
[Recipes → Custom handler](/ru/guide/recipes).

## Экспортёры

### `registerExporter(DocumentExporter $exporter): void`

Зарегистрировать пользовательский формат экспорта. Экспортёр ключуется по
собственному `format()`.

### `exporter(string $format): DocumentExporter`

Резолв экспортёра по ключу формата. Сначала смотрит на экспортёры, зарегистрированные
в рантайме, затем на `config('vaulter-documents.exporters')` (резолвя FQCN через контейнер).
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
`config('vaulter-documents.collaboration.driver')` (`hocuspocus`); класс драйвера
читается из `config('vaulter-documents.collaboration.driver')` и резолвится через
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
