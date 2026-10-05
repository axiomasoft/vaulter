---
title: Экспорт
description: Рендер TipTap-содержимого документа в HTML или Markdown, а также регистрация собственного экспортёра (PDF, DOCX, …) без изменения ядра.
outline: deep
---

# Экспорт

Рендерите TipTap-содержимое узла-документа в **HTML** или **Markdown** — для скачивания, отправки
по почте или показа за пределами редактора — и подключайте собственный экспортёр (PDF, DOCX, …), не
трогая ядро. Экспорт работает по [документному пути на узлах](./documents.md).

## Контракт экспортёра

Экспортёр реализует `Vaulter\Contracts\Handlers\DocumentExporter`:

```php
namespace Vaulter\Contracts\Handlers;

interface DocumentExporter
{
    /** The format key (e.g. 'html', 'pdf') — also echoed in the API response. */
    public function format(): string;

    public function mimeType(): string;

    /** File extension, without the dot. */
    public function extension(): string;

    public function export(DocumentContent $content): string;
}
```

`DocumentContent` — тот же опаковый интерфейс payload'а, что хранит write-путь;
контракт не завязан на TipTap. Экспортёр сужает содержимое до конкретной формы,
которую понимает (например, `TipTapContent`), внутри `export()` — так же, как
`FileHandler` сужает дескриптор.

Бинарные форматы возвращают закодированную строку (например, base64) и объявляют
соответствующий mime-тип.

## Встроенные экспортёры

Два поставляются вместе с пакетом и зарегистрированы в конфиге:

```php
// config/vaulter.php
'exporters' => [
    'html'     => Vaulter\Export\HtmlExporter::class,
    'markdown' => Vaulter\Export\MarkdownExporter::class,
],
```

| Формат | Класс | mime | расширение |
|---|---|---|---|
| `html` | `HtmlExporter` | `text/html` | `html` |
| `markdown` | `MarkdownExporter` | `text/markdown` | `md` |

Оба делегируют в `Vaulter\Documents\Support\TipTap\TipTapRenderer`, который обрабатывает общий набор узлов и
меток — заголовки, абзацы, маркированные/нумерованные списки, элементы списков, цитаты, блоки
кода, горизонтальные линии и жёсткие переносы, плюс метки **bold / italic / strike / code /
link** — с экранированием HTML-вывода (`htmlspecialchars`). Неизвестные узлы откатываются к
рендеру своих детей, поэтому нераспознанный узел никогда не ломает экспорт.

::: tip Формат по умолчанию
Если query-параметр `format` не задан, оба эндпоинта экспорта по умолчанию используют `html`.
:::

## Экспорт документного узла

`GET {prefix}/nodes/{node}/export` рендерит head-содержимое [документного узла](./documents.md).
Требует право (ability) `View` на узле (и узел должен быть `kind=document`). Этот маршрут —
часть поверхности Documents, за фичей **`document_versions`**.

::: code-group

```ts [Vue — composable]
import { useDocumentNode } from '@vaulter/vue'

const { exportAs } = useDocumentNode(nodeId)

const result = await exportAs('markdown')
// → { format: 'markdown', content: '# …', mime_type: 'text/markdown', filename: 'notes.md' }
```

```bash [HTTP]
# GET {prefix}/nodes/{node}/export?format=html|markdown
curl 'https://app.test/api/vaulter/nodes/77/export?format=markdown'
# → { "data": { "format": "markdown", "content": "# …",
#               "mime_type": "text/markdown", "filename": "notes.md" } }
```

```ts [Vue — client directly]
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()
const result = await client.documentNodes.export(nodeId, 'html')
```

:::

Ответ на экспорт узла — это `NodeDocumentExport` (`Vaulter\Documents\ValueObjects\DocumentExport`),
который **включает `mime_type`**:

```ts
interface NodeDocumentExport {
  format: string        // the exporter's format key
  content: string       // the rendered output
  mime_type: string     // the exporter's mime type
  filename: string      // derived from the node name + the exporter extension
}
```

::: info Экспорт ограничен возможностями обработчика
Узел отдаёт форматы экспорта только тогда, когда его разрешённый обработчик **редактируемый или
поддерживает превью** (`NodeExportService::availableFormats`). Узел только-на-скачивание (архив,
неизвестный бинарник) не отдаёт ни одного формата экспорта. См.
[Обработчики документов](./document-handlers.md).
:::

## Регистрация собственного экспортёра (PDF / DOCX)

Тяжёлые конвертеры (PDF, DOCX) намеренно **не** входят в поставку — им нужны большие рендереры.
Они представляют собой чистый шов для регистрации: реализуйте `DocumentExporter` и
зарегистрируйте его. Ядро менять не нужно.

### 1. Реализуйте экспортёр

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Exporters;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Documents\Support\TipTap\TipTapRenderer;
use Vaulter\Exceptions\Documents\UnsupportedDocumentContentException;
use Vaulter\Documents\ValueObjects\TipTapContent;

final class PdfExporter implements DocumentExporter
{
    public function __construct(
        private readonly TipTapRenderer $renderer,   // reuse the built-in HTML renderer
    ) {}

    public function format(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function export(DocumentContent $content): string
    {
        // Контракт опаковый — этот экспортёр понимает только TipTap-документы,
        // поэтому сужаем перед рендером. Бросаем исключение пакета, чтобы хост,
        // ловящий VaulterException, поймал и это.
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter(
                $this->format(), TipTapContent::class, $content::class,
            );
        }

        // Render the TipTap doc to HTML, then to a PDF with your library of choice
        // (e.g. dompdf / wkhtmltopdf / a service). A BINARY format returns an
        // ENCODED string (base64 here) and advertises its mime above.
        $html = $this->renderer->toHtml($content);

        $pdfBytes = MyPdfLibrary::fromHtml($html);   // your renderer

        return base64_encode($pdfBytes);
    }
}
```

::: tip Бинарные форматы
Для бинарного вывода (PDF, DOCX) возвращайте **закодированную** строку (выше — base64) и объявляйте
настоящий mime-тип. Клиент декодирует её обратно в байты для скачивания.
:::

### 2. Зарегистрируйте его

::: code-group

```php [config — cache-safe FQCN map]
// config/vaulter.php — add to the exporters map (format => FQCN)
'exporters' => [
    'html'     => Vaulter\Export\HtmlExporter::class,
    'markdown' => Vaulter\Export\MarkdownExporter::class,
    'pdf'      => App\Vaulter\Exporters\PdfExporter::class,   // [!code ++]
],
```

```php [runtime — Vaulter::registerExporter]
// In a service provider's boot() — pass an INSTANCE
use Vaulter\Facades\Vaulter;
use App\Vaulter\Exporters\PdfExporter;

Vaulter::registerExporter(app(PdfExporter::class));
```

:::

::: tip Конфиг против рантайма
`config('vaulter.exporters')` — это карта FQCN (безопасная для config-cache, резолвится через
контейнер при первом использовании). `Vaulter::registerExporter()` принимает уже собранный
экземпляр — рантайм-шов. Экспортёр, зарегистрированный в рантайме, побеждает конфиговый с тем же
ключом.
:::

### 3. Используйте его

`?format=pdf` теперь резолвится в ваш экспортёр на обоих эндпоинтах экспорта и появляется в
`availableFormats` узла (когда обработчик поддерживает экспорт):

```bash
curl 'https://app.test/api/vaulter/nodes/77/export?format=pdf'
# → { "data": { "format": "pdf", "content": "<base64…>",
#               "mime_type": "application/pdf", "filename": "notes.pdf" } }
```

`Vaulter::exporterFormats()` возвращает каждый зарегистрированный формат (конфиг + рантайм, без
дубликатов) — список, из которого берут данные валидация запроса и `availableFormats`.

## Ошибки

| Исключение / статус | Когда |
|---|---|
| `Exceptions\Documents\UnknownExportFormatException` | Экспорт запрашивает формат без зарегистрированного экспортёра (в сообщении перечислены доступные). |
| `422 Unprocessable` | Query-параметр `format` у легаси `documents/{id}/export` не является зарегистрированным форматом экспортёра (правило валидации). |
| `Exceptions\NotADocumentException` | Экспорт узла нацелен на узел, который не `kind=document`. |

## Справочник

- Контракт — `Vaulter\Contracts\Handlers\DocumentExporter`
- Встроенные — `Vaulter\Export\HtmlExporter`, `Vaulter\Export\MarkdownExporter`
- Рендерер — `Vaulter\Documents\Support\TipTap\TipTapRenderer` (`toHtml`, `toMarkdown`)
- Экспорт узла — `Vaulter\Documents\NodeExportService` (`export`, `availableFormats`)
- Шов менеджера — `Vaulter\VaulterManager::registerExporter()` / `::exporter()` / `::exporterFormats()`
- Enum — `Vaulter\Enums\Documents\ExportFormat`

## Дальше

- [Документные узлы](./documents.md) — создавайте, редактируйте и версионируйте содержимое, которое
  экспортируете.
- [Обработчики документов](./document-handlers.md) — в какой поверхности открывается узел и как
  добавить новый формат без миграции.
