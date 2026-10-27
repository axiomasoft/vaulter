---
title: Типы документов
description: Реестр типов документов — встроенные rich text, markdown и spreadsheet, собственные типы, кодеки, версии схемы и что происходит, когда тип удалён.
outline: deep
---

# Типы документов

**Тип документа** определяет, что такое содержимое документа: как проверяется JSON клиента
(*кодек*), как выглядит пустой документ, какой клиентский редактор его открывает (*viewer*),
что клиенту можно с ним делать и могут ли его редактировать несколько человек сразу. Каждый
документ хранит ключ своего типа (`vendor/name`) и версию схемы, в которой записано
содержимое. Класс за ключом никогда не сохраняется и не сериализуется.

Типы живут в одном реестре, `Documents::types()`, который заполняется при загрузке
приложения и после неё замораживается.

::: tip Типы — не обработчики файлов
[Обработчик файла](/ru/extending/file-handlers) описывает **файл** по mime и расширению. Тип
документа описывает узел-**документ**. Загруженный файл `.docx`, `.md` или `.xlsx` остаётся
файлом: его можно просмотреть или скачать, но не редактировать, пока не появится импорт
«файл → документ».
:::

## Встроенные типы

| Ключ | Кодек | Совместная работа | Viewer |
|---|---|---|---|
| `vaulter/rich-text` | `TipTapCodec` со схемой rich text (StarterKit, Image, Table, Link, CodeBlock и `vaulter-documents.tiptap.*`) | Yjs | `vaulter/tiptap` (`preset: rich-text`) |
| `vaulter/markdown` | `TipTapCodec` со схемой markdown (только то, что представимо в Markdown; расширения хоста не применяются) | Yjs | `vaulter/tiptap` (`preset: markdown`) |
| `vaulter/spreadsheet` | `OpaqueJsonCodec` (любой JSON-объект) | нет | `vaulter/univer` |

Rich text и markdown регистрируются по умолчанию. Тип spreadsheet регистрируется, только если
вы его перечислили:

<!-- snippet: config -->

```php
// config/vaulter-documents.php
'types' => [
    \Vaulter\Documents\Types\RichTextDocumentType::class,
    \Vaulter\Documents\Types\MarkdownDocumentType::class,
    \Vaulter\Documents\Types\SpreadsheetDocumentType::class,
],
'default_type' => 'vaulter/rich-text',
```

`default_type` — тип, который `Documents::in($drive)->create()` использует, если тип не указан.
Он обязан быть зарегистрирован, иначе приложение не загрузится.

## Собственный тип

Сгенерируйте класс:

<!-- snippet: shell -->

```bash
php artisan vaulter:make:document-type Kanban
```

Команда создаёт `app/Vaulter/DocumentTypes/KanbanDocumentType.php` с ключом `app/kanban`,
непрозрачным JSON-кодеком и версией схемы 1. Регистрация — за вами: добавьте класс в
`vaulter-documents.types`, пометьте тегом в сервис-провайдере или зарегистрируйте до окончания
загрузки приложения:

<!-- snippet: config -->

```php
// config/vaulter-documents.php
'types' => [/* … */, \App\Vaulter\DocumentTypes\KanbanDocumentType::class],

// или в register() сервис-провайдера
$this->app->tag([KanbanDocumentType::class], 'vaulter.document_types');
$this->app->make(\Vaulter\Documents\Types\DocumentTypeRegistry::class)->register(KanbanDocumentType::class);
```

Тип реализует `Vaulter\Documents\Contracts\DocumentType`:

<!-- snippet: signature Vaulter\Documents\Contracts\DocumentType Vaulter\Documents\Contracts\DocumentCodec -->

```php
interface DocumentType
{
    public function key(): string;                 // 'acme/kanban'
    public function label(): string;
    public function schemaVersion(): int;          // >= 1; версия, в которой пишется новое содержимое
    public function codec(): DocumentCodec;
    public function defaultContent(): DocumentContent;
    public function viewer(): DocumentViewer;    // ключ клиентского редактора
    public function features(): DocumentFeatures;
}

interface DocumentCodec
{
    /** @throws InvalidDocumentContentException */
    public function decode(array $raw, int $schemaVersion): DocumentContent;
    public function maxSizeBytes(): ?int;          // null = vaulter-documents.content.max_size_kb
}
```

Два необязательных интерфейса добавляют поведение:

- `SupportsCollaboration` — `collaborationProtocol()` (`'yjs'`) и `projectFromState()`.
- `UpgradesSchema` — `upgrade(array $content, int $fromVersion): array`. Содержимое, сохранённое
  в более старой версии схемы, поднимается **при чтении**; история не переписывается. Следующее
  сохранение пишет текущую версию. `upgrade()` обязан быть идемпотентным.

Готовые блоки: `Content\OpaqueJsonCodec` и `Content\OpaqueContent` (хранят JSON как есть),
`Content\TipTap\TipTapCodec` с `TipTapSchema::richText()` или `TipTapSchema::markdown()`.

### Правила реестра

- Ключи соответствуют `^[a-z0-9-]+/[a-z0-9-]+$`. Префикс `vaulter/` принадлежит собственным
  типам пакета; сторонний тип с таким ключом отклоняется при загрузке.
- Повторная регистрация ключа бросает `DuplicateDocumentTypeException` при любом порядке
  провайдеров. Подменить тип можно только через `Documents::types()->replace($key, $type)`
  (пишется в лог уровня `info`).
- `all()` упорядочен по ключу, результат не зависит от порядка провайдеров.
- Имя класса, который не существует или не реализует контракт, валит загрузку с
  `InvalidConfigurationException`. Замыкания в конфиге запрещены (`config:cache`).
- После загрузки приложения реестр заморожен: поздний `register()` бросает
  `RegistryFrozenException`. Воркеры Octane разделяют один неизменяемый набор.

### Контрактные тесты

Прогоните свой тип через тот же контракт, что и встроенные:

<!-- snippet: host -->

```php
use Vaulter\Documents\Testing\Contracts\DocumentTypeContractTests;

uses(DocumentTypeContractTests::class);

it('keeps the document type contract', function (): void {
    $this->assertDocumentTypeContract(new KanbanDocumentType);
});
```

Проверяются формат ключа, round-trip `decode(defaultContent()->toArray())`, детерминированный
хеш содержимого, что пустое содержимое укладывается в `maxSizeBytes()` и что `upgrade()`
идемпотентен.

## Когда тип не установлен

Удаление расширения не трогает его документы. Документ незарегистрированного типа:

- остаётся в дереве, его можно листать, копировать, удалять в корзину, восстанавливать и
  удалять навсегда;
- читается как непрозрачный JSON: `DocumentSnapshot::$typeAvailable = false`,
  `$editable = false`, `$content` — `OpaqueContent` с сохранённым содержимым;
- показывается через viewer `vaulter/unsupported` без возможности редактирования и совместной
  работы (`type_available: false` в представлении узла);
- на `update()`, `snapshot()`, `restore()`, `export()` и `collaborationSession()` отвечает
  `409 document_type_unavailable`.

Установите и зарегистрируйте тип снова — тот же документ полностью доступен, ничего не потеряно.

## Содержимое, прочитанное частично

Чтение снисходительно: документ rich text с узлами расширения редактора, которое вы потом
выключили (`vaulter-documents.tiptap.extra_nodes`), всё равно открывается — неизвестные части
опускаются. Сохранить такой вид обратно значило бы удалить их навсегда, поэтому документ
доступен только для чтения: `DocumentSnapshot::$editable = false`, совместная работа
открывается только на чтение (сессия `mode: ro`, поэтому authenticate хаба отвечает read-only, а его append отвергаются), а
`update()` отвечает `409 document_content_degraded`. Включите
расширение снова (или восстановите ревизию без него) — документ снова редактируемый.

То же относится к документу, записанному в **более новой** версии схемы, чем знает
установленный тип: он показывается, но не перезаписывается.

Снисходительное чтение опускает только то, что не является TipTap JSON или не разрешено схемой.
Узел несёт `type`, `attrs` (объект), `marks` (на любом узле, как в ProseMirror) и `content`, либо
строковый `text` у текстового узла; строгая запись отвергает всё остальное
(`422 invalid_document_content`), поэтому всё принятое читается обратно без потерь.

## Представление

Узел-документ представляется своим типом, а не обработчиком файла:

```json
{
  "id": null,
  "document_type": "vaulter/rich-text",
  "type_available": true,
  "capabilities": { "editable": true, "previewable": true, "downloadable": false, "thumbnailable": false, "collaborative": true },
  "ui": { "key": "vaulter/tiptap", "props": { "preset": "rich-text" } }
}
```

`id` (ключ обработчика файла) у документов `null`. Ядро ничего не знает о типах документов:
`vaulter-documents` помечает тегом `Vaulter\Drive\Contracts\Presentation\KindPresenter` для вида
`document`, и `NodePresenter` ядра передаёт ему представление.

## Профили

Профиль может сузить типы, которые можно **создавать** в его дисках:

<!-- snippet: host -->

```php
$profile->documentTypes('vaulter/markdown', 'acme/kanban');
```

Пустой список разрешает все зарегистрированные типы. Создание типа вне списка бросает
`ProfileRestrictionException`.

## Генератор

<!-- snippet: shell -->

```bash
php artisan vaulter:make:document-type Kanban
```

Создаёт тип в `app/Vaulter/DocumentTypes/KanbanDocumentType.php` и Pest-тест в `tests/Unit/Vaulter/`, который запускает `DocumentTypeContractTests`. Команда печатает строку для
`vaulter-documents.types` и не меняет ваш конфиг. Существующий файл не перезаписывается без `--force`.

## Тесты вашего расширения

Кроме контрактного набора выше, `Document::factory()` создаёт документы через
`DocumentOperations`, поэтому у фикстуры есть узел, кодек её типа и первый чекпоинт:

<!-- snippet: host -->

```php
use Vaulter\Documents\Models\Documents\Document;

$document = Document::factory()->richText('Hello')->in($drive)->create();
$board = Document::factory()->ofType('acme/kanban')->named('Roadmap')->in($folder)->create();
```

`richText()` наполняет rich-text-документ, `ofType()` создаёт документ любого установленного
типа с содержимым этого типа по умолчанию, `in()` принимает драйв (его корень) или папку.
Другой тенант вместо текущего выбирается через `forTenant()`.

Типы документов используют `Vaulter\Documents\Data\Types\DocumentViewer` и
`Vaulter\Documents\Data\Types\DocumentFeatures`. Тип без клиентского редактора
возвращает `new DocumentViewer(key: 'vaulter/unsupported')`.

## Сохранённая привязка коллаборации

`SupportsCollaboration::collaborationStateFormat()` задаёт формат сохранённых байтов независимо от wire version. Upgrade JSON не конвертирует состояние коллаборации и не меняет binding. Смена протокола, схемы или формата требует явного transition с новой generation; restore также блокирует старые сессии. См. [collaboration protocols](/ru/extending/collaboration-protocols).
