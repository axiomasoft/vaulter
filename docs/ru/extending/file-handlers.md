---
title: Обработчики документов
description: Реестр расширяемости по форматам — возможности, маршрутизация по mime/расширению и добавление нового формата файлов одним классом и без миграции.
outline: deep
---

# Обработчики документов

Обработчики документов — это слой **расширяемости по форматам** в Vaulter. Обработчик заявляет
набор mime-типов и/или расширений, объявляет, что он умеет делать с файлом (редактировать /
показывать превью / скачивать / генерировать миниатюру / совместно редактировать) и возвращает
фронтендовый дескриптор монтирования — в каком Vue-компоненте клиент откроет узел.
`Vaulter\Drive\Handlers\FileHandlerRegistry` подбирает лучший обработчик для файла, и клиент
направляет узел на эту поверхность **без зашитой mime-логики**. Реестр и его базовые обработчики
(`PreviewHandler`/`DownloadOnlyHandler`) принадлежат drive — хост только с Drive, без других
пакетов, всё равно резолвит preview/download для любого файла. Обработчики обслуживают только
**файлы**: узел-документ представляется своим [типом документа](./document-types.md), а не обработчиком.

Главное свойство: **добавление нового формата — это операция «один класс + одна регистрация»: без
миграции, без правки ядра, без ограничения каким-либо запечатанным enum.** Строковые ключи реестра
(mime / расширение) — единственный источник истины; никакой enum их не ограничивает.

::: tip Куда это встраивается
[Узлы-документы](/ru/guides/documents) несут *контент*; обработчики решают вопрос *поверхности*.
Обработчик — это ещё и то, через что маршрутизируется узел-бинарный файл (изображение, PDF, архив):
обработчики покрывают каждый листовой узел, а не только `kind=document`. Про рендер контента в файл
см. [Экспорт](/ru/guides/export).
:::

## Контракт

Обработчик реализует `Vaulter\Drive\Contracts\Handlers\FileHandler`:

<!-- snippet: signature Vaulter\Drive\Contracts\Handlers\FileHandler -->

```php
namespace Vaulter\Drive\Contracts\Handlers;

use Vaulter\Drive\ValueObjects\Handlers\FileDescriptor;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

interface FileHandler
{
    /** A stable, unique id surfaced to the client (e.g. 'tiptap', 'preview', 'download'). */
    public function key(): string;

    /** Claimed mime types — exact ("application/pdf"), wildcard ("image/*") or "*". @return list<string> */
    public function mimeTypes(): array;

    /** Claimed lower-case extensions, without the dot (the mime fallback). @return list<string> */
    public function extensions(): array;

    /** Resolution priority — higher wins among handlers matching the same file + capability. */
    public function priority(): int;

    /** What this handler can do with THIS file (computed per file). */
    public function capabilities(FileDescriptor $file): ContentCapabilities;

    /** The front-end mount descriptor — viewer key + props the client mounts. */
    public function viewer(FileDescriptor $file): ViewerDescriptor;
}
```

### Возможности (capabilities)

`Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities` — это то, что обработчик объявляет по каждому
файлу. `downloadable` по умолчанию `true` (что угодно можно скачать как байты); более богатые флаги
включаются явно:

<!-- snippet: host -->

```php
new ContentCapabilities(
    editable:      false,
    previewable:   false,
    downloadable:  true,   // default
    thumbnailable: false,
    collaborative: false,
);
```

Они один-к-одному соответствуют кейсам `Vaulter\Drive\Enums\Handlers\Capability`, по которым фильтрует
реестр: `Edit`, `Preview`, `Download`, `Thumbnail`, `Collaborate`.

::: warning `previewable` ≠ безопасно для инлайна
`previewable` означает, что обработчик *может* отрисовать превью — он **не** утверждает, что байты
безопасно инлайнить без песочницы. HTML/SVG можно показать как превью, но они — вектор XSS, если не
изолировать их в песочнице; встроенный `PreviewHandler` намеренно **не** заявляет `text/html` или
`image/svg+xml` и помечает `inline_safe` только pdf/изображения. Песочницу решает клиент; возможность
лишь сообщает, что превью существует.
:::

### Фронтендовый дескриптор монтирования

`Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor` сообщает клиенту, какой Vue-компонент монтировать, через
какой маршрут открывать узел и какие дополнительные сериализуемые props передать:

<!-- snippet: host -->

```php
new ViewerDescriptor(
    key: 'vaulter/pdf-preview',          // viewer, который монтирует клиент
    props: ['page' => 1],                // произвольные сериализуемые props
);
```

Это встраивается в payload узла (как `NodePresentation` — `id` + `document_type` +
`type_available` + `capabilities` + `ui`; у файла `document_type` равен `null`), чтобы
Vue-клиент направил узел, не прогоняя mime-логику заново. Компонент
[`VaulterDocumentNode`](#монтирование-подобранного-обработчика-во-vue) сопоставляет `ui.key` дескриптора
с конкретным редактором.

### Дескриптор файла

`Vaulter\Drive\ValueObjects\Handlers\FileDescriptor` — это неизменяемое описание, по которому реестр
маршрутизирует: заявленный mime, определённый сервером **по сигнатуре** mime, расширение и размер —
без обращения к байтам или базе данных. Маршрутизация опирается на `canonicalMime()`.

::: danger Безопасность: маршрутизация никогда не доверяет mime от клиента
`canonicalMime()` предпочитает определённый сервером `detectedMime` и откатывается к присланному
клиентом `claimedMime` только тогда, когда сигнатуру снять не удалось. Подделанный `Content-Type`
никогда не сможет навязать обработчик редактирования/превью на враждебные байты.
:::

## Файлы — не документы

Загруженный файл `.docx`, `.doc`, `.md` или `.xlsx` — это **файл**: он резолвится в
`PreviewHandler` или `DownloadOnlyHandler` и никогда не объявляется редактируемым или совместным,
пока не появится импорт «файл → документ». Редактируемое содержимое живёт в узлах-документах,
представление которых задаёт их [тип документа](./document-types.md) (`vaulter/rich-text`,
`vaulter/markdown`, `vaulter/spreadsheet` или собственный).

## Алгоритм подбора

`Vaulter\Drive\Handlers\FileHandlerRegistry::resolve(FileDescriptor $file, Capability $cap)`
возвращает лучший обработчик и **никогда не возвращает null**. Порядок такой:

1. **Сначала mime** — собираем обработчики, заявившие канонический (снятый по сигнатуре) mime: точное
   совпадение (`application/pdf`) бьёт семейство по маске (`image/*`). Голый catch-all `*` здесь
   исключён, чтобы он не замыкал следующий шаг накоротко.
2. **Откат на расширение** — только когда ни один конкретный mime-обработчик не совпал, собираем
   обработчики, заявившие расширение файла.
3. **Фильтр по возможностям** — оставляем только те обработчики, чьи `capabilities(file)`
   удовлетворяют запрошенной `Capability`.
4. **Сортировка по приоритету** — побеждает наибольший `priority()`.
5. **Откат на null-object** — если ни один подходящий не совпал, `DownloadOnlyHandler`
   (приоритет `-1000`, заявляет `*`, только скачивание) — гарантированный терминальный результат.

Результаты подбора мемоизируются по `(canonicalMime, extension, capability)`, поэтому папка из
похожих файлов сортирует набор обработчиков один раз.

## Встроенные обработчики

Набор по умолчанию регистрируется из конфига (`config('vaulter-drive.handlers')`):

<!-- snippet: config -->

```php
// config/vaulter-drive.php
'handlers' => [
    Vaulter\Drive\Handlers\Builtin\PreviewHandler::class,
    Vaulter\Drive\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

| Обработчик | `key` | Заявляет | Возможности | UI-компонент | Приоритет |
|---|---|---|---|---|---|
| `PreviewHandler` | `vaulter/preview` | `application/pdf`, `image/*`; ext `pdf`, `png`, `jpg`, `jpeg`, `gif`, `webp`, `avif`, `bmp` | preview · thumbnail | `vaulter/pdf-preview` | 80 |
| `DownloadOnlyHandler` | `vaulter/download` | `*` (catch-all) | download | `vaulter/download` | −1000 |

Узел-документ несёт mime документа Vaulter (`application/vnd.vaulter.document+json`), но через
этот реестр не маршрутизируется: `NodePresenter` ядра передаёт каждый узел вида `document`
kind-презентеру, который модуль documents вносит в слот `KindPresenter` drive (ключ — вид узла, `document`).

## Подбор обработчика для узла

Представление (`presentation`) входит в каждый узел, чтобы клиент знал, какую поверхность монтировать:

::: code-group

<!-- snippet: shell -->

```bash [HTTP]
# GET {prefix}/v1/nodes/{node}  → data.presentation
curl https://app.test/api/vaulter/v1/nodes/77
# a PDF file:
# → { "data": { …, "presentation": { "handler": "vaulter/preview", "document_type": null, "type_available": true,
#               "capabilities": { "editable": false, "previewable": true,
#                                 "downloadable": true, "thumbnailable": true,
#                                 "collaborative": false },
#               "viewer": { "key": "vaulter/pdf-preview", "props": {} } } } }
# a rich-text document (handler is null: a document is routed by its type):
# → { "data": { …, "presentation": { "handler": null, "document_type": "vaulter/rich-text", "type_available": true,
#               "capabilities": { "editable": true, "previewable": true,
#                                 "downloadable": false, "thumbnailable": false,
#                                 "collaborative": true },
#               "viewer": { "key": "vaulter/tiptap", "props": { "preset": "rich-text" } } } } }
```

<!-- snippet: host -->

```php [PHP — the resolver]
use Vaulter\Drive\Contracts\Handlers\NodePresenter;

$descriptor = app(NodePresenter::class)->present($node);
// NodePresentation: ->id, ->documentType, ->typeAvailable, ->capabilities, ->ui (->toArray() для сериализации)
```

<!-- snippet: host -->

```ts [Vue — composable]
import { useVaulterClient } from '@vaulter/vue'

const node = await useVaulterClient().nodes.get(nodeId)
// node.presentation → { handler, document_type, type_available, capabilities, viewer }
```

:::

Для файла `NodePresenter` строит `FileDescriptor` из узла (mime, которому доверяет сервер) и спрашивает
реестр на **самом широком** уровне возможностей (`Download` — его удовлетворяет любой обработчик),
чтобы победил **самый специфичный** совпавший обработчик; дальше дескриптор сообщает полный набор
возможностей этого обработчика, чтобы клиент мог ветвиться по ним.

## Добавление нового формата — разобранный пример

Это главное. Вот полный обработчик для файлов **CSV** — превью + скачивание, без миграции, без правки
ядра.

### 1. Реализуем обработчик

<!-- snippet: host -->

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Handlers;

use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\ValueObjects\Handlers\FileDescriptor;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

final class CsvHandler implements FileHandler
{
    public function key(): string
    {
        return 'acme/csv';
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return ['text/csv', 'application/csv'];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return ['csv'];
    }

    public function priority(): int
    {
        // Above DownloadOnly (-1000), below TipTap (100) — a clean win for CSV files.
        return 70;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(
            editable:      false,
            previewable:   true,   // render a read-only table preview
            downloadable:  true,
            thumbnailable: false,
            collaborative: false,
        );
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(
            key: 'acme/csv-preview',   // your Vue component
            props:     ['delimiter' => ','],
        );
    }
}
```

Это **вся** правка на бэкенде. Миграции нет: узел CSV — это просто узел `kind=file`, уже лежащий в
`v_nodes`; обработчик лишь добавляет маршрутизацию + возможности. Реестр — источник истины, ни один
enum не тронут.

### 2. Регистрируем

Выберите то, что подходит вашему приложению. Все три способа сливаются в один реестр (класс попадает
не более одного раза, поэтому регистрация в двух местах безопасна):

::: code-group

<!-- snippet: config -->

```php [config — cache-safe FQCN list]
// config/vaulter-drive.php — append to the handlers list (FQCN only, config-cache safe)
'handlers' => [
    Vaulter\Drive\Handlers\Builtin\PreviewHandler::class,
    App\Vaulter\Handlers\CsvHandler::class,        // [!code ++]
    Vaulter\Drive\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

<!-- snippet: host -->

```php [runtime — Vaulter::handlers()->register]
// In a service provider's boot() — FQCN or an instance
use Vaulter\Drive\Facades\Vaulter;
use App\Vaulter\Handlers\CsvHandler;

Vaulter::handlers()->register(CsvHandler::class);
```

<!-- snippet: host -->

```php [tagged container binding]
// In a service provider's register() — the conditional/host extension channel
$this->app->tag([App\Vaulter\Handlers\CsvHandler::class], 'vaulter.file_handlers');
```

:::

::: tip Какой канал выбрать?
- **config** безопасен для кеша (только FQCN, переживает `config:cache`) — вариант по умолчанию для
  статического обработчика.
- **`Vaulter::handlers()->register()`** — шов для рантайма: регистрируйте условно на boot; повторное
  наполнение слитого набора + сброс кеша сохраняют корректность последующего подбора.
- **тегированная привязка** — канал, которым core регистрирует свои базовые обработчики;
  используйте его для обработчика, который поставляет пакет.
:::

### 3. И оно просто работает

Теперь любой узел, чей снятый по сигнатуре mime — `text/csv` (или чьё имя оканчивается на `.csv`),
подбирается на ваш обработчик. `presentation` узла содержит `handler: "csv"` с вашими
возможностями и `viewer.key: "acme/csv-preview"`, и клиент монтирует ваш компонент. Ни одна миграция не
выполнялась, ни один файл ядра не менялся.

::: details Список всех зарегистрированных обработчиков
`Vaulter::handlers()->all()` возвращает каждый обработчик из слитого набора, по убыванию приоритета
(поверхность интроспекции, из которой хост/админ перечисляет форматы). Null-object `DownloadOnlyHandler`
включён как терминальная запись.
:::

## Монтирование подобранного обработчика во Vue

`presentation.viewer.key` узла называет **вьюер**, который монтирует клиент. `VaulterViewer`
(`@vaulter/vue`) ищет ключ в реестре вьюеров приложения и монтирует компонент, передавая ему
`node`, флаг `readonly` и `viewer.props` обработчика. Узел без ключа, с ключом, который
приложение не зарегистрировало, или с типом документа, который это приложение не умеет
обрабатывать (`type_available = false`), уходит во вьюер `vaulter/unsupported` — элемент всё
равно виден, назван и доступен для скачивания.

Встроенные ключи (`vaulter/tiptap`, `vaulter/pdf-preview`,
`vaulter/image-preview`, `vaulter/download`, `vaulter/unsupported`) ставит плагин. Свои — или
замену встроенных — добавляет опция плагина `viewers`: явный ES-импорт, а не неявный глобал:

<!-- snippet: host -->

```ts
// main.ts — один раз, до монтирования приложения
import { createApp } from 'vue'
import { VaulterPlugin } from '@vaulter/vue'
import App from './App.vue'
import AppTipTapEditor from './AppTipTapEditor.vue'   // TipTap-редактор, который поставляет хост

createApp(App)
  .use(VaulterPlugin, {
    baseUrl: '/api/vaulter',
    viewers: {
      'vaulter/tiptap': AppTipTapEditor,                        // заменить встроенный ключ
      'acme/csv-preview': () => import('./AppCsvPreview.vue'),  // ключ вьюера нашего CSV-обработчика
    },
  })
  .mount('#app')
```

Запись-функция — загрузчик (ленивый импорт); компонент монтируется напрямую. Отрисовать узел
вьюером, который выбрал сервер:

<!-- snippet: host -->

```vue
<script setup lang="ts">
import { VaulterViewer } from '@vaulter/vue'
import type { Node } from '@vaulter/vue'

defineProps<{ node: Node }>()
</script>

<template>
  <VaulterViewer :node="node" />
</template>
```

`useViewerRegistry()` возвращает реестр для изменений в рантайме (`register`, `unregister`,
`has`, `keys`). Регистрации принадлежат одному приложению: два приложения на странице не делят
вьюеры.

Вьюер `vaulter/tiptap` — «безголовая» оболочка редактирования
(`VaulterDocumentNode`): она загружает контент, автосохраняет и, когда
презентация **коллаборативна**, модуль `vaulter-collaboration` активен и установлен `@vaulter/vue-collaboration`, открывают Yjs-сессию. Сам движок
редактора — ваш: замените ключ как выше или заполните слот оболочки по умолчанию. Путь
автосохранения — в [Узлы-документы](/ru/guides/documents#рабочее-состояние-и-чекпоинты).

## Генератор

<!-- snippet: shell -->

```bash
php artisan vaulter:make:file-handler Csv
```

Создаёт обработчик в `app/Vaulter/FileHandlers/Csv.php` и Pest-тест в `tests/Unit/Vaulter/`, который запускает `FileHandlerContractTests`. Команда печатает строку для
`vaulter-drive.handlers` и не меняет ваш конфиг. Существующий файл не перезаписывается без `--force`.

## Справочник

- Контракт — `Vaulter\Drive\Contracts\Handlers\FileHandler`
- Реестр — `Vaulter\Drive\Handlers\FileHandlerRegistry` (`resolve`, `register`, `all`) — принадлежит drive
- Резолвер — `Vaulter\Drive\Handlers\NodePresenter` (дескриптор по узлу) — принадлежит drive
- Шов менеджера — `Vaulter\Drive\Vaulter::handlers()->register()` / `::handlers()`
- VO — `ContentCapabilities`, `ViewerDescriptor`, `NodePresentation`, `FileDescriptor`
- Enum — `Vaulter\Drive\Enums\Handlers\Capability`
- Встроенные — `PreviewHandler`, `DownloadOnlyHandler` (документы: см. [Типы документов](./document-types.md))
- Фронтовые вьюеры (`@vaulter/vue`) — опция плагина `viewers`, `VaulterViewer`, `createViewerRegistry`, `useViewerRegistry`
