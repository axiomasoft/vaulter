---
title: Совместимость API
description: Что покрывает обещание совместимости Vaulter 1.0 — операции, снимки, enum'ы, исключения, события, ключи конфигурации и путь обновления — и что оно не покрывает (модели, Actions, таблицы).
outline: deep
---

# Совместимость API

С версии 1.0 Vaulter следует [семантическому версионированию](https://semver.org/lang/ru/). На этой
странице — что именно входит в обещание. До 1.0 ничего не заморожено: переименования и удаления
делаются сразу, и каждое описано в
[`UPGRADE.md`](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) (см.
[ADR-0020](/adr/0020-authorized-public-api) и [ADR-0026](/adr/0026-public-api-snapshots)).

## Что входит в публичный контракт

| Поверхность | Обещание |
| --- | --- |
| Операции и handle | Интерфейсы `Vaulter\Contracts\**` (`DriveHandle`, `NodeOperations`, `FolderOperations`, `FileOperations`, `TrashOperations`, `AccessOperations`, `ShareOperations`, `ActivityOperations`), фасад `Vaulter` и операции документов. Имена методов, **имена параметров** (вызывающие используют именованные аргументы), типы параметров и результатов. |
| Снимки и страницы | `NodeSnapshot`, `FileRevisionSnapshot`, `GrantSnapshot`, `ShareLinkSnapshot`, `ActivityEntrySnapshot`, `DriveSnapshot`, `QuotaSnapshot`, `MintedShareLink` и `Page<T>`: их свойства, **только для чтения**. Конструкторы помечены `@internal`: снимки создаёт Vaulter, хост — никогда. |
| Enum'ы | Публичные enum'ы помечены `@closed` (набор значений фиксирован: новое значение — MAJOR) или `@extensible` (клиент обязан обрабатывать неизвестное значение). Неизвестный `NodeKind` или тип документа трактуйте как «только скачать». |
| Исключения | Классы и машинные коды (`node_not_found`, `quota_exceeded`, …) из раздела [Ошибки](/ru/guide/errors). Код не меняется; новый код — MINOR. |
| События | Классы Laravel-событий и их публичные поля. |
| Конфигурация | Ключи `config/vaulter.php` и конфигов других пакетов. Устаревший ключ работает через нормализатор до следующего MAJOR. |
| Путь обновления | Миграции обновления выполняются по порядку с любой схемы 1.x; после 1.0 миграции только аддитивные (expand, migrate, contract). |
| REST | Маршруты и JSON версии API из URL. v1 замораживается с её выходом (см. [REST](/reference/rest-api)). |

Изменение — **MAJOR**, если оно убирает или переименовывает что-то из таблицы, сужает тип параметра,
расширяет тип результата, на который может опираться вызывающий, меняет машинный код или убирает значение
enum'а. Необязательный параметр, метод у результата, новый код исключения, новое значение `@extensible`
enum'а, ключ конфигурации или событие — **MINOR**.

## Пакеты и версии

Все PHP-пакеты выходят с одной версией и одним тегом, и каждый split-репозиторий
(`axioma-studio/vaulter-core`, `-media`, `-drive`, `-documents`, `-filament` и метапакет
`axioma-studio/vaulter`) получает один и тот же тег. Пакеты требуют друг друга как `self.version`,
поэтому смешать версии нельзя. `axioma-studio/vaulter-azgard` выходит только после тега самого AzGuard;
до тех пор он исключён из релиза. У `@vaulter/vue` своя semver-линия, а поддерживаемую линию REST API
он объявляет в `package.json` (`"vaulter": { "api": "1" }`). Состав релиза — в `release/packages.json`.

## Что не входит

- **Eloquent-модели** (`Vaulter\Models\**`), их колонки, связи и scope'ы. Они `@internal`: операции
  никогда их не возвращают, а запись модели вне операции отклоняется (см. ниже).
  `DriveHandle::model()` нужен пакетам Vaulter и тестам.
- **Actions, Queries, репозитории** и всё остальное в `src/`, что не названо выше.
- **Таблицы и колонки.** Схема стабильна (после 1.0 — только аддитивные миграции), но доступ к ней —
  через PHP API; см. [Совместимость с БД](/ru/guide/database-compatibility).
- Любой класс или метод с `@internal` в докблоке и всё в пространствах имён `Testing`.

## Защита записи

Модели Vaulter отклоняют `save()`, `delete()` и массовые `update`, `delete`, `insert`, `upsert`,
`increment` вне операции Vaulter и бросают `WriteOutsideOperationException`
(`write_outside_operation`, см. [Ошибки](/ru/guide/errors#write-outside-operation)). Это вторая линия
защиты за инвариантами в Actions, а не песочница: `DB::table()` и сырой SQL не перехватываются.
`vaulter.database.write_guard = false` (`VAULTER_DB_WRITE_GUARD=false`) — только если вашему коду
нужно время на переход.

## Как писать код, переживающий обновления

```php
$drive = Vaulter::drive($project)->actingAs($user);

$report = $drive->files()->upload($source);                    // NodeSnapshot
$page = $drive->nodes()->children($report->parentId);          // Page<NodeSnapshot>
$drive->nodes()->rename($report->id, 'Q3.pdf', expectedSequence: $report->sequence);

foreach ($page->items as $node) {
    // читайте $node->id, ->name, ->kind … снимок не создают и не меняют
}
$next = $drive->nodes()->children($report->parentId, new ListOptions(cursor: $page->nextCursor));
```

- Передавайте `$node->id` (строку) или сам снимок; идентификаторы сравнивайте как строки.
- Курсор непрозрачен: возвращайте `nextCursor` как есть.
- Обрабатывайте неизвестные значения enum'ов и `null` в `document` у узла с недоступным типом.
