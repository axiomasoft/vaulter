---
title: Экспорт
description: Экспорт документа в файл и регистрация своего экспортёра (PDF, DOCX, …) по типу документа и формату.
outline: deep
---

# Экспорт

Экспорт документа в **файл** — HTML и Markdown из коробки — и подключение своего экспортёра
(PDF, DOCX, …) без правок ядра. Экспорт принадлежит `vaulter-documents` и идёт по
[пути документа](./documents.md).

## Контракт экспортёра

Экспортёр превращает один тип документа в один формат. Реестр находит его по паре
**(тип документа, формат)**:

```php
namespace Vaulter\Documents\Contracts;

interface DocumentExporter
{
    public function key(): string;          // 'vaulter/html'
    public function format(): string;       // 'html' — значение ?format=
    public function mimeType(): string;
    public function extension(): string;    // без точки
    /** @return list<string> ключи типов документов, которые он умеет отдавать */
    public function documentTypes(): array;
    public function export(DocumentContent $content, DocumentType $type): string;
}
```

Экспортёр получает **только декодированное inline-содержимое**. Ему нельзя читать storage, blob'ы
и URL (архитектурный тест проверяет это **только** для встроенных экспортёров; для ваших классов это
требование, которое вы соблюдаете сами, а не то, что проверяет тест), поэтому экспорт не достанет
байты, которые шлюз выдачи не разрешил. Картинки и вложения остаются ссылками. Внутри `export()`
сузьте содержимое до понятного класса, иначе бросайте `UnsupportedDocumentContentException`.

Бинарные форматы возвращают закодированные байты файла и объявляют соответствующий mime-тип.

## Встроенные экспортёры

| Ключ | Формат | mime | расширение | Типы документов |
|---|---|---|---|---|
| `vaulter/html` | `html` | `text/html` | `html` | `vaulter/rich-text`, `vaulter/markdown` |
| `vaulter/markdown` | `markdown` | `text/markdown` | `md` | `vaulter/rich-text`, `vaulter/markdown` |

```php
// config/vaulter-documents.php
'exporters' => [HtmlExporter::class, MarkdownExporter::class],
```

Оба используют `TipTapRenderer`: HTML проходит через `symfony/html-sanitizer`, Markdown
экранируется как Markdown. У типа, который не указан ни в одном экспортёре (например, таблица),
форматов экспорта нет.

## Экспорт документа

`GET {prefix}/nodes/{node}/export?format=html|markdown` отвечает **файлом** (формат по умолчанию
`html`):

```http
HTTP/1.1 200 OK
Content-Type: text/markdown
Content-Disposition: attachment; filename="notes.md"; filename*=utf-8''notes.md
Cache-Control: no-cache, private, no-store
X-Content-Type-Options: nosniff
```

Тело — байты экспортёра. Имя — имя узла плюс расширение экспортёра; схему задаёт привязка
`ExportFilenameResolver`. Нужна абилити **`Download`** и прохождение шлюза выдачи содержимого —
previewer получит `403 permission_denied`.

В PHP:

```php
$export = Documents::in($drive)->export($node, 'html');           // рабочее состояние
$export = Documents::in($drive)->export($node, 'html', $revision); // одна ревизия

$export->bytes; $export->filename; $export->mimeType; $export->format;
$export->sourceEpoch; $export->sourceStateVersion; $export->sourceRevisionId;
```

Без ревизии экспорт рендерит **рабочее состояние** на `(sourceEpoch, sourceStateVersion)`, а
`sourceRevisionId` равен `null`; с ревизией — эту неизменяемую ревизию. Экспорт никогда не создаёт
ревизий и не меняет версию состояния.

`sourceEpoch` — всегда epoch коллаборации **документа** на момент экспорта, в том числе для
ревизии: у ревизии собственного epoch нет, она определяется `sourceRevisionId` и своим
`sourceStateVersion`, а epoch лишь говорит, какое поколение комнаты было текущим при рендере.

`$export->lossy` равен `true`, когда сохранённое содержимое не удалось прочитать целиком (записано
более новой схемой или тип читает его лишь частично): байты — это то, что тип смог прочитать, а не
обязательно всё сохранённое. Не кэшируйте и не архивируйте такой рендер как экспорт документа. По
HTTP в этом случае файл несёт заголовок `X-Vaulter-Export-Lossy: 1`.

Если вы кэшируете рендеры, ключ — `(documentId, sourceEpoch, sourceStateVersion | sourceRevisionId,
ключ экспортёра, формат)`, а доступ проверяйте заново при каждом попадании.

```ts
import { useVaulterClient } from '@vaulter/vue'

const file = await useVaulterClient().documentNodes.export(nodeId, 'markdown')
// → { blob: Blob, filename: 'notes.md', mimeType: 'text/markdown' }
```

## Свой экспортёр

Заготовка: `php artisan vaulter:make:exporter Pdf` (создаёт
`app/Vaulter/Exporters/PdfExporter.php` и печатает строку для конфига). Или вручную:

```php
final class PdfExporter implements DocumentExporter
{
    public function __construct(private readonly TipTapRenderer $renderer) {}

    public function key(): string { return 'acme/pdf'; }
    public function format(): string { return 'pdf'; }
    public function mimeType(): string { return 'application/pdf'; }
    public function extension(): string { return 'pdf'; }
    public function documentTypes(): array { return ['vaulter/rich-text']; }

    public function export(DocumentContent $content, DocumentType $type): string
    {
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter($this->format(), TipTapContent::class, $content::class);
        }

        return MyPdfLibrary::fromHtml($this->renderer->toHtml($content));
    }
}
```

Регистрируйте по имени класса в конфиге (безопасно для config:cache), тегом контейнера
`vaulter.exporters` или из провайдера:

```php
// config/vaulter-documents.php
'exporters' => [HtmlExporter::class, MarkdownExporter::class, App\Vaulter\Exporters\PdfExporter::class],

// register()/boot() провайдера, до завершения загрузки приложения
Documents::exporters()->register(PdfExporter::class);
Documents::exporters()->replace('vaulter/html', MyHtmlExporter::class); // заменить встроенный
```

Два экспортёра на одну пару (тип, формат) падают при загрузке с
`DuplicateDocumentExporterException`; заменяйте через `replace()`. После загрузки приложения
реестр замораживается.

## Ошибки

| Код | Статус | Когда |
|---|---|---|
| `permission_denied` | 403 | Актор может смотреть, но не скачивать. |
| `node_not_found` | 404 | Нет доступа, узел в корзине или чужой tenant. |
| `document_type_unavailable` | 409 | Тип документа не установлен. |
| `unknown_export_format` | 422 | У типа нет экспортёра для формата; `allowed` перечисляет доступные. |
| `invalid_document_content` | 422 | Экспортёру передали содержимое, которое он не умеет отдавать. |

## Справочник

- Контракт — `Vaulter\Documents\Contracts\DocumentExporter`
- Реестр — `Vaulter\Documents\Export\DocumentExporterRegistry` (`register`, `replace`, `for`, `formatsFor`)
- Действие — `Vaulter\Documents\Actions\Export\ExportDocumentAction`; результат `Vaulter\Documents\ValueObjects\DocumentExport`
- Встроенные — `HtmlExporter`, `MarkdownExporter`; рендерер `TipTapRenderer` (`toHtml`, `toMarkdown`)

## Дальше

- [Документы-узлы](./documents.md) — создание, редактирование и версии экспортируемого содержимого.
- [Типы документов](./document-types.md) — какие типы есть и как добавить свой.
