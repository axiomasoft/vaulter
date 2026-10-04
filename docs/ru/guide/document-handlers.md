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
`Vaulter\Handlers\FileHandlerRegistry` подбирает лучший обработчик для файла, и клиент
направляет узел на эту поверхность **без зашитой mime-логики**. Реестр и его базовые обработчики
(`PreviewHandler`/`DownloadOnlyHandler`) принадлежат core — хост только с Drive, без других
пакетов, всё равно резолвит preview/download для любого файла; пакет вроде `vaulter-documents`
лишь добавляет более богатые форматы (TipTap, Univer, …).

Главное свойство: **добавление нового формата — это операция «один класс + одна регистрация»: без
миграции, без правки ядра, без ограничения каким-либо запечатанным enum.** Строковые ключи реестра
(mime / расширение) — единственный источник истины; встроенные enum `NodeKind` / `DocumentType`
нужны лишь для удобства и никогда не выступают ограничителем.

::: tip Куда это встраивается
[Узлы-документы](./documents.md) несут *контент*; обработчики решают вопрос *поверхности*.
Обработчик — это ещё и то, через что маршрутизируется узел-бинарный файл (изображение, PDF, архив):
обработчики покрывают каждый листовой узел, а не только `kind=document`. Про рендер контента в файл
см. [Экспорт](./export.md).
:::

## Контракт

Обработчик реализует `Vaulter\Contracts\Handlers\FileHandler`:

```php
namespace Vaulter\Contracts\Handlers;

use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

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

`Vaulter\ValueObjects\Handlers\ContentCapabilities` — это то, что обработчик объявляет по каждому
файлу. `downloadable` по умолчанию `true` (что угодно можно скачать как байты); более богатые флаги
включаются явно:

```php
new ContentCapabilities(
    editable:      false,
    previewable:   false,
    downloadable:  true,   // default
    thumbnailable: false,
    collaborative: false,
);
```

Они один-к-одному соответствуют кейсам `Vaulter\Enums\Handlers\Capability`, по которым фильтрует
реестр: `Edit`, `Preview`, `Download`, `Thumbnail`, `Collaborate`.

::: warning `previewable` ≠ безопасно для инлайна
`previewable` означает, что обработчик *может* отрисовать превью — он **не** утверждает, что байты
безопасно инлайнить без песочницы. HTML/SVG можно показать как превью, но они — вектор XSS, если не
изолировать их в песочнице; встроенный `PreviewHandler` намеренно **не** заявляет `text/html` или
`image/svg+xml` и помечает `inline_safe` только pdf/изображения. Песочницу решает клиент; возможность
лишь сообщает, что превью существует.
:::

### Фронтендовый дескриптор монтирования

`Vaulter\ValueObjects\Handlers\ViewerDescriptor` сообщает клиенту, какой Vue-компонент монтировать, через
какой маршрут открывать узел и какие дополнительные сериализуемые props передать:

```php
new ViewerDescriptor(
    key: 'vaulter/tiptap',     // the Vue component the client mounts
    props:     ['format' => 'richtext'], // arbitrary serialisable props
);
```

Это встраивается в payload узла (как `NodePresentation` — `id` + `capabilities` + `ui`), чтобы
Vue-клиент направил узел, не прогоняя mime-логику заново. Компонент
[`VaulterDocumentNode`](#mounting-the-resolved-handler-in-vue) сопоставляет `ui.key` дескриптора
с конкретным редактором.

### Дескриптор файла

`Vaulter\ValueObjects\Handlers\FileDescriptor` — это неизменяемое описание, по которому реестр
маршрутизирует: заявленный mime, определённый сервером **по сигнатуре** mime, расширение и размер —
без обращения к байтам или базе данных. Маршрутизация опирается на `canonicalMime()`.

::: danger Безопасность: маршрутизация никогда не доверяет mime от клиента
`canonicalMime()` предпочитает определённый сервером `detectedMime` и откатывается к присланному
клиентом `claimedMime` только тогда, когда сигнатуру снять не удалось. Подделанный `Content-Type`
никогда не сможет навязать обработчик редактирования/превью на враждебные байты.
:::

## DocumentType

`Vaulter\Enums\Documents\DocumentType` — это встроенный **синтаксический сахар** поверх реестра:
небольшой набор форматов rich-text с меткой, иконкой и списком расширений TipTap, который
`TipTapHandler` отдаёт как prop `viewer.props.extensions`. Это **не** ограничитель: реестр маршрутизирует
по строкам mime/расширения, а хост добавляет новые форматы
[обработчиком](#adding-a-new-format-a-worked-example), а не расширением этого enum.

| Кейс | value | Расширения TipTap |
|---|---|---|
| `RichText` | `richtext` | StarterKit · Image · Table · Link |
| `Table` | `table` | StarterKit · Table |
| `Markdown` | `markdown` | StarterKit · CodeBlock |
| `Template` | `template` | StarterKit · Placeholder |

Эти четыре значения `value` — то, что принимает поле `format` при [создании узла-документа](./documents.md#creating-a-document-node)
(по умолчанию `richtext`).

## Алгоритм подбора

`Vaulter\Handlers\FileHandlerRegistry::resolve(FileDescriptor $file, Capability $cap)`
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

Набор по умолчанию регистрируется из конфига (`config('vaulter.handlers')`):

```php
// config/vaulter.php
'handlers' => [
    Vaulter\Documents\Engines\Handlers\TipTapHandler::class,
    Vaulter\Handlers\Builtin\PreviewHandler::class,
    Vaulter\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

| Обработчик | `key` | Заявляет | Возможности | UI-компонент | Приоритет |
|---|---|---|---|---|---|
| `TipTapHandler` | `vaulter/tiptap` | `application/vnd.vaulter.document+json`, `text/markdown`, docx/msword; ext `md`, `markdown`, `doc`, `docx`, `vdoc` | edit · preview · **collaborate** | `vaulter/tiptap` | 100 |
| `PreviewHandler` | `vaulter/preview` | `application/pdf`, `image/*`; ext `pdf`, `png`, `jpg`, `jpeg`, `gif`, `webp`, `avif`, `bmp` | preview · thumbnail | `vaulter/pdf-preview` | 80 |
| `DownloadOnlyHandler` | `vaulter/download` | `*` (catch-all) | download | `vaulter/download` | −1000 |

Узел-документ, созданный через `CreateDocumentNodeAction`, помечается mime-типом документа Vaulter
(`application/vnd.vaulter.document+json`), поэтому он всегда маршрутизируется в `TipTapHandler`
независимо от расширения в его имени.

::: details UniverHandler — обработчик таблиц под фичефлагом
`Vaulter\Documents\Engines\Handlers\UniverHandler` (`key: vaulter/univer`, заявляет xlsx/xls, приоритет 90,
редактируемый + с превью, но **однопользовательский** — совместное редактирование в Univer — это
отдельный OT-движок, а не полоса Yjs) **не** входит в список конфига по умолчанию. Сервис-провайдер
добавляет его в тегированный набор `vaulter.file_handlers` **только** тогда, когда включена фича
`spreadsheets`:

```php
// config/vaulter.php
'features' => ['spreadsheets' => true],
```

С выключенным флагом xlsx проваливается в `DownloadOnlyHandler`. Это доказательство того, что
расширяемость «один класс + регистрация» работает и для редактора, не относящегося к rich-text.
:::

## Подбор обработчика для узла

Дескриптор обработчика отдаётся по каждому узлу, чтобы клиент знал, какую поверхность монтировать:

::: code-group

```bash [HTTP]
# GET {prefix}/nodes/{node}/handler
curl https://app.test/api/vaulter/nodes/77/handler
# → { "data": { "id": "vaulter/tiptap",
#               "capabilities": { "editable": true, "previewable": true,
#                                 "downloadable": true, "thumbnailable": false,
#                                 "collaborative": true },
#               "ui": { "key": "vaulter/tiptap",
#                       "props": { "format": "richtext", "extensions": [...] } } } }
```

```php [PHP — the resolver]
use Vaulter\Contracts\Handlers\NodePresenter;

$descriptor = app(NodePresenter::class)->present($node);
// NodePresentation: ->id, ->capabilities, ->ui  (call ->toArray() to serialise)
```

```ts [Vue — composable]
import { useDocumentNode } from '@vaulter/vue'

const { handler, loadHandler } = useDocumentNode(nodeId)
await loadHandler()
// handler.value → { id, capabilities, ui }
```

:::

`NodePresenter` строит `FileDescriptor` из узла (mime, которому доверяет сервер) и спрашивает
реестр на **самом широком** уровне возможностей (`Download` — его удовлетворяет любой обработчик),
чтобы победил **самый специфичный** совпавший обработчик; дальше дескриптор сообщает полный набор
возможностей этого обработчика, чтобы клиент мог ветвиться по ним.

## Добавление нового формата — разобранный пример

Это главное. Вот полный обработчик для файлов **CSV** — превью + скачивание, без миграции, без правки
ядра.

### 1. Реализуем обработчик

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Handlers;

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

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

```php [config — cache-safe FQCN list]
// config/vaulter.php — append to the handlers list (FQCN only, config-cache safe)
'handlers' => [
    Vaulter\Documents\Engines\Handlers\TipTapHandler::class,
    Vaulter\Handlers\Builtin\PreviewHandler::class,
    App\Vaulter\Handlers\CsvHandler::class,        // [!code ++]
    Vaulter\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

```php [runtime — Vaulter::handlers()->register]
// In a service provider's boot() — FQCN or an instance
use Vaulter\Facades\Vaulter;
use App\Vaulter\Handlers\CsvHandler;

Vaulter::handlers()->register(CsvHandler::class);
```

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
- **тегированная привязка** — это то, что использует сам пакет для условного `UniverHandler`.
:::

### 3. И оно просто работает

Теперь любой узел, чей снятый по сигнатуре mime — `text/csv` (или чьё имя оканчивается на `.csv`),
подбирается на ваш обработчик. `GET /nodes/{node}/handler` возвращает `id: "csv"` с вашими
возможностями и `ui.key: "acme/csv-preview"`, и клиент монтирует ваш компонент. Ни одна миграция не
выполнялась, ни один файл ядра не менялся.

::: details Список всех зарегистрированных обработчиков
`Vaulter::handlers()->all()` возвращает каждый обработчик из слитого набора, по убыванию приоритета
(поверхность интроспекции, из которой хост/админ перечисляет форматы). Null-object `DownloadOnlyHandler`
включён как терминальная запись.
:::

## Монтирование подобранного обработчика во Vue

`VaulterDocumentNode` — это мост от дескриптора обработчика к конкретному редактору/превью. Он
загружает обработчик + head-контент, затем монтирует компонент, на который указывает `ui.key`
дескриптора. Есть два источника сопоставления, проверяются по порядку:

1. prop `componentMap` — область действия ограничена одной точкой монтирования `VaulterDocumentNode`;
2. глобальный реестр `registerEngineComponent` (`@vaulter/vue`) — явная функция, которую хост
   вызывает один раз при старте, для движков, общих для всех точек монтирования (VLT-CANON P1.2).

`componentMap` всегда побеждает, если оба источника сопоставляют один и тот же ключ. Ни один из
них не обязателен — используйте тот, что подходит числу точек монтирования в вашем приложении.

```vue
<script setup lang="ts">
import { VaulterDocumentNode } from '@vaulter/vue'
import AppTipTapEditor from './AppTipTapEditor.vue'   // a TipTap editor the host supplies
import AppCsvPreview from './AppCsvPreview.vue'

// Map a handler's `ui.key` (or its `id`) to the component to mount.
const componentMap = {
  'vaulter/tiptap': AppTipTapEditor,   // the TipTap handler's ui.key
  'acme/csv-preview': AppCsvPreview,                    // our CSV handler's ui.key
}
</script>

<template>
  <VaulterDocumentNode :node-id="nodeId" :component-map="componentMap">
    <!-- Fallback for any unmapped handler: a preview/download surface. -->
    <template #fallback="{ handler }">
      No editor registered for {{ handler.id }}.
    </template>
  </VaulterDocumentNode>
</template>
```

Или зарегистрируйте один раз при старте приложения — явный ES-module импорт/вызов, а не
неявный глобал (тот же урок, что стоит за отходом Nextcloud Viewer от
`OCA.Viewer.registerHandler`):

```ts
// main.ts — один раз, до монтирования приложения
import { registerEngineComponent } from '@vaulter/vue'
import AppTipTapEditor from './AppTipTapEditor.vue'
import AppCsvPreview from './AppCsvPreview.vue'

registerEngineComponent('VaulterEditor', AppTipTapEditor)
registerEngineComponent('AppCsvPreview', AppCsvPreview)
```

Теперь каждый `VaulterDocumentNode` на странице подбирает эти компоненты без локального
`componentMap`. `unregisterEngineComponent(id)` удаляет запись; `registeredEngineComponentIds()`
перечисляет, что зарегистрировано сейчас (в основном для диагностики/тестов). Подбор из
реестра **не реактивен**: регистрируйте до монтирования приложения (или как минимум до
монтирования нужного `VaulterDocumentNode`) — уже смонтированный узел не переподберёт компонент
только потому, что `registerEngineComponent` для его `ui.key` вызвали позже.

В dev-режиме `VaulterDocumentNode` логирует один `console.warn` на каждый несопоставленный
`ui.key`/`id` (не повторяется для одного и того же ключа В ПРЕДЕЛАХ ОДНОГО инстанса
компонента), называя оба канала регистрации — тот же пробел, который string-key биндинг
`Nova.booting` у Nova оставляет молчаливым.

Если подобранный обработчик **collaborative** (например, `TipTapHandler`) и совместное редактирование
включено, `VaulterDocumentNode` открывает Yjs-сессию и запускает редактор в режиме совместного
редактирования; иначе он использует путь автосохранения, описанный в
[Узлах-документах](./documents.md#autosave-vs-named-versions).

## Справочник

- Контракт — `Vaulter\Contracts\Handlers\FileHandler`
- Реестр — `Vaulter\Handlers\FileHandlerRegistry` (`resolve`, `register`, `all`) — принадлежит core
- Резолвер — `Vaulter\Handlers\NodePresenter` (дескриптор по узлу) — принадлежит core
- Шов менеджера — `Vaulter\VaulterManager::handlers()->register()` / `::handlers()`
- VO — `ContentCapabilities`, `ViewerDescriptor`, `NodePresentation`, `FileDescriptor`
- Enum — `Vaulter\Enums\Handlers\Capability`
- Встроенные — `TipTapHandler`, `PreviewHandler`, `UniverHandler`, `DownloadOnlyHandler`
- Фронтовый реестр (`@vaulter/vue`) — `registerEngineComponent`, `unregisterEngineComponent`,
  `resolveEngineComponent`, `registeredEngineComponentIds`, `clearEngineComponents`
