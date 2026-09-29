Ниже — готовый Markdown-документ для сохранения, например, как `audits/2026-09-29-deep-architecture-audit.md`. Главный вывод: у Vaulter уже сильная техническая база, но до «идеального расширяемого пакета» мешают противоречия публичного API, перегруженный `core`, несогласованная терминология, небезопасный программный API и недостаточно формализованная модель расширения документов.

***

# Vaulter: глубокий архитектурный, API- и security-аудит

**Дата:** 2026-09-29  
**Репозиторий:** [axiomasoft/vaulter](https://github.com/axiomasoft/vaulter)  
**Проверенная ветка:** `main`  
**Проверенный HEAD:** [`8f07701866a69d8790fda5dc1fe9bbd9287be6fd`](https://github.com/axiomasoft/vaulter/commit/8f07701866a69d8790fda5dc1fe9bbd9287be6fd)  
**Формат аудита:** архитектура, публичный API, расширяемость, структура директорий, терминология, конфигурация, REST API, безопасность, модель данных, тестирование и release engineering.

## Статус проверки

Аудит основан на статическом анализе текущей структуры монорепозитория, Composer- и npm-пакетов, конфигурации, публичного менеджера и фасада, контрактов, маршрутов, ключевых Drive-операций, моделей, документации и сохранённых внутренних отчётов.

Это не полный line-by-line penetration test. В рамках данного прохода не выполнялись свежая установка зависимостей, полный CI matrix, Docker build, динамическое тестирование PostgreSQL-конкурентности, fuzzing HTTP API и реальное тестирование S3/Hocuspocus.

## Краткий вердикт

Vaulter уже значительно сложнее обычного Laravel-пакета: это фактически embeddable document-management platform с файловым деревом, CAS, версиями, ACL, публичными ссылками, resumable uploads, collaboration, комментариями, webhooks, Vue SDK и Filament-интеграцией. Текущая [структура монорепозитория](https://github.com/axiomasoft/vaulter/tree/main/packages) подтверждает наличие отдельных пакетов `core`, `drive`, `media`, `documents`, `vue`, `filament` и `azgard`.

Главная проблема сейчас не в недостатке функций, а в отсутствии единой канонической модели продукта. В кодовой базе одновременно существуют:

- legacy-концепция «document types»;
- универсальное дерево `Node`;
- `FileHandler` и `DocumentHandlerRegistry`;
- `NodeKind`;
- `enabled_document_types`;
- `registerDocumentType()`;
- редакторные движки;
- exporter registry;
- backend UI descriptors;
- отдельные понятия `workspace`, `storage`, `panel`, `drive` и `owner`.

До добавления новых возможностей следует стабилизировать терминологию, границы пакетов и публичные контракты.

## Оценка зрелости

Оценки ниже являются экспертной оценкой архитектурной готовности, а не измеренной метрикой.

| Область | Оценка | Состояние |
|---|---:|---|
| Общая архитектурная база | 7/10 | Сильная инженерная работа, но размыты bounded contexts |
| Публичный PHP API | 5/10 | Удобный facade entry point, но API неоднозначен и протекает Eloquent |
| Расширяемость форматов | 6/10 | Хороший registry pattern, но document types и handlers не объединены |
| Конфигурируемость | 6/10 | Очень много точек настройки, но конфигурация перегружена и дублируется |
| Безопасность дизайна | 7/10 | Много продуманных механизмов, но есть опасные границы и defaults |
| Secure-by-default | 5/10 | Некоторые важные функции требуют ручного усиления host-приложением |
| Структура директорий | 6/10 | Пакеты выделены, но внутри сохраняется горизонтальная раскладка |
| Документация | 7/10 | Объёмная документация, но присутствуют существенные расхождения с кодом |
| Release readiness | 4/10 | Последний merge явно не объявляет готовность релиза и пропустил CI |
| Готовность к SemVer 1.0 | 4/10 | Публичная поверхность пока слишком нестабильна |

## Приоритетные блокеры

| Приоритет | Проблема | Риск | Рекомендация |
|---|---|---|---|
| P0 | README описывает несуществующий или устаревший `PermissionResolver` API | Пользователь не сможет выполнить базовую интеграцию по инструкции | Немедленно синхронизировать README, код и contracts reference |
| P0 | Программный Drive API не выполняет авторизацию | Host-код может случайно обойти ACL | Разделить authorised public API и internal operations |
| P0 | `registerDocumentType()` не образует полноценную точку расширения | Ложное обещание расширяемости | Заменить на типизированный `DocumentTypeRegistry` |
| P0 | Вероятная несовместимость ULID/UUID со storage-scoped CAS | Риск неправильной дедупликации и изоляции | Проверить `storageIdForScope()` и весь CAS contract |
| P0 | Composer metadata указывает другой GitHub owner | Ошибочные ссылки Packagist/Composer | Заменить `axioma-studio/vaulter` на фактический URL |
| P0 | Текущий HEAD не подтверждён полным CI | Невоспроизводимый релиз | Обязательный clean-install release matrix |
| P1 | `core` владеет слишком многими доменами | Высокая связанность пакетов | Сократить `core` или переименовать его в `kernel` |
| P1 | Конфигурация содержит legacy, дублирующиеся и пересекающиеся ключи | Ошибки эксплуатации | Ввести typed normalized configuration |
| P1 | Tenant isolation делегируется пользовательским моделям | Риск cross-tenant access | Ввести обязательный `TenantResolver` и fail-closed scoping |
| P1 | Upload policy различается между HTTP и programmatic API | Bypass ограничений | Перенести проверки в application/domain layer |
| P1 | Нет чёткой REST API version boundary | Будущие изменения станут breaking | Ввести `/v1` и OpenAPI contract |
| P1 | Термины `storage`, `workspace`, `panel`, `drive` пересекаются | Высокая когнитивная нагрузка | Принять glossary ADR и провести последовательное переименование |

# Проверка публичного API

## Критическое расхождение README

В README предлагается реализовать `Vaulter\Contracts\PermissionResolver` и зарегистрировать его через:

```php
Vaulter::resolvePermissionsWith(
    new \App\Vaulter\PermissionResolver()
);
```

Это находится в текущем [README](https://github.com/axiomasoft/vaulter/blob/main/README.md). Однако в актуальном [`VaulterManager`](https://github.com/axiomasoft/vaulter/blob/main/packages/core/src/VaulterManager.php) такого метода нет, а действующая модель авторизации построена вокруг `HostRoleDriver`, `AccessResolver` и `permission_drivers`.

В [актуальной конфигурации](https://github.com/axiomasoft/vaulter/blob/main/packages/core/config/vaulter.php) присутствуют `DatabasePermissionResolver`, `GatePermissionResolver`, `SpatiePermissionResolver` и `AzgardPermissionResolver`, но публичный контракт описывается уже как `HostRoleDriver`.

Это P0-документационная ошибка, потому что затрагивает самый первый integration path.

Необходимо выбрать одну модель:

- `PermissionResolver` как понятный публичный термин;
- либо `HostRoleDriver`, если он действительно выражает нужную семантику;
- либо разделить их: `PermissionDriver` получает права host-приложения, а `AccessResolver` объединяет их с node grants.

Предпочтительный вариант:

```php
interface PermissionDriver
{
    public function permissionsFor(
        Actor $actor,
        Workspace $workspace,
        ?Node $node = null,
    ): PermissionSet;
}
```

Регистрация:

```php
Vaulter::permissions()->use(
    AppVaulterPermissionDriver::class
);
```

`Resolver` лучше оставить внутреннему orchestrator, который собирает итоговое решение из нескольких источников.

## Текущий manager

[`VaulterManager`](https://github.com/axiomasoft/vaulter/blob/main/packages/core/src/VaulterManager.php) одновременно отвечает за:

- создание Drive handle;
- разрешение package seams;
- storage panels;
- document types;
- file handlers;
- exporters.

Это уже несколько независимых registry и factory responsibilities. Пока класс небольшой, но он неизбежно превратится в service locator.

Рекомендуемая публичная поверхность:

```php
Vaulter::drive($owner);
Vaulter::profiles();
Vaulter::formats();
Vaulter::documentTypes();
Vaulter::exporters();
```

Каждый метод должен возвращать отдельный типизированный manager/registry. Сам `VaulterManager` тогда останется агрегирующим facade root, но не будет непосредственно реализовывать все операции регистрации.

## `registerDocumentType()`

Текущая реализация:

```php
private array $documentTypes = [];

public function registerDocumentType(string $type, array $definition): void
{
    $this->documentTypes[$type] = $definition;
}
```

Она хранит неограниченный `array<string,mixed>` только в памяти singleton. Документация также прямо говорит, что definitions хранятся in-memory, тогда как built-in document types отдельно задаются через `enabled_document_types`. См. [facade reference](https://github.com/axiomasoft/vaulter/blob/main/docs/reference/facade.md).

У этой точки расширения отсутствуют:

- типизированное описание document type;
- валидация ключа;
- collision policy;
- связь с persistence;
- связь с content schema;
- связь с handler registry;
- связь с frontend editor registry;
- связь с exporter registry;
- проверка, что type можно создать;
- lifecycle регистрации;
- механизм freeze после boot;
- serializable descriptor для API.

В текущем виде это не полноценный extension point. Это storage для произвольных metadata.

Следует либо удалить API до публичного релиза, либо заменить его на реальный контракт.

## Предлагаемый registry

```php
interface DocumentType
{
    public function key(): DocumentTypeKey;

    public function label(): string;

    public function capabilities(): DocumentCapabilities;

    public function contentCodec(): DocumentContentCodec;

    public function schema(): DocumentSchema;

    public function editor(): EditorDescriptor;

    /**
     * @return list<ExportFormat>
     */
    public function exportFormats(): array;
}
```

Регистрация:

```php
Vaulter::documentTypes()->register(
    MarkdownDocumentType::class
);
```

Или через provider:

```php
final class MarkdownDocumentServiceProvider extends ServiceProvider
{
    public function boot(DocumentTypeRegistry $types): void
    {
        $types->register(MarkdownDocumentType::class);
    }
}
```

Laravel Scout применяет понятный pattern `Manager::extend()` для регистрации пользовательских движков; аналогичный подход будет знаком Laravel-разработчикам. [laravel](https://laravel.com/framework/docs/12.x/scout)

## `DriveHandle` и `DriveContext`

Публичный контракт называется [`DriveHandle`](https://github.com/axiomasoft/vaulter/blob/main/packages/core/src/Contracts/Drive/DriveHandle.php), а реализация — [`DriveContext`](https://github.com/axiomasoft/vaulter/blob/main/packages/drive/src/DriveContext.php). Документация при этом периодически объявляет возвращаемым типом именно `DriveContext`, хотя `VaulterManager` возвращает `DriveHandle`.

Ни одно из названий не является идеальным:

- `Handle` звучит как низкоуровневый дескриптор ресурса;
- `Context` обычно означает ambient state или execution context;
- фактически объект является bound service API для конкретного workspace/storage.

Предлагаемая схема:

```php
interface Drive
{
    public function workspace(): Workspace;
    public function nodes(): NodeOperations;
    public function folders(): FolderOperations;
    public function files(): FileOperations;
    public function trash(): TrashOperations;
}
```

Реализация:

```php
final readonly class EloquentDrive implements Drive
{
}
```

Так контракт получает простое доменное имя, а infrastructure detail остаётся в реализации.

## Протекание Eloquent

`DriveHandle` принимает и возвращает:

- `Storage`;
- `Node`;
- `Illuminate\Database\Eloquent\Collection`.

Это делает public API:

- жёстко привязанным к текущей схеме хранения;
- зависимым от lazy loading;
- сложным для альтернативной реализации;
- неудобным для async/remote backend;
- потенциально нестабильным при изменении Eloquent models;
- плохо пригодным для strict SemVer.

Swappable models не устраняют проблему: пользователь может подставить subclass, но контракт всё равно выражен через конкретные базовые модели.

Предпочтительно разделить API:

```php
interface Drive
{
    public function root(): NodeReference;

    public function children(
        NodeReference $parent,
        ?NodeQuery $query = null,
    ): NodePage;
}
```

Для Laravel-пользователей можно оставить явный escape hatch:

```php
$node->eloquentModel();
$workspace->eloquentModel();
```

Но Eloquent не должен быть обязательной частью каждого публичного контракта.

## Возврат `Collection`

Метод:

```php
public function list(?Node $folder = null): Collection;
```

имеет сразу несколько проблем:

- `list` не объясняет, что возвращаются только direct children;
- отсутствуют pagination и sorting options;
- возвращается infrastructure collection;
- невозможно гарантировать производительность на больших папках;
- невозможно позже перейти на cursor pagination без breaking change.

Предлагаемое имя:

```php
public function children(
    ?NodeReference $folder = null,
    ?ListNodesOptions $options = null,
): NodePage;
```

`NodePage` может содержать items, cursor и total только там, где total действительно вычисляется.

# Предлагаемое именование

## Главные термины

Следует принять единую терминологию до дальнейшего расширения.

| Текущий термин | Проблема | Рекомендуемый термин |
|---|---|---|
| `Storage` | Смешивается с physical storage и Laravel Storage facade | `Workspace` или `DriveRoot` |
| `StorageManager` | Неясно, управляет дисками или logical roots | `WorkspaceProvisioner` |
| `StoragePanel` | `Panel` ассоциируется с UI и Filament | `DriveProfile` |
| `PanelRegistry` | Неясная доменная роль | `DriveProfileRegistry` |
| `DriveHandle` | Низкоуровневая семантика | `Drive` |
| `DriveContext` | Слишком абстрактно | `EloquentDrive` или `BoundDrive` |
| `FileHandler` | В действительности определяет формат и UI capabilities | `FormatDriver` |
| `DocumentHandlerRegistry` | Registry работает и с binary files | `FormatRegistry` |
| `DocumentExporter` | Экспорт может относиться к разным content types | `ExportDriver` |
| `FileVersion` | Путается с package/API version | `FileRevision` |
| `NodeDocumentVersion` | Длинное и инфраструктурное имя | `DocumentRevision` |
| `DocumentsModels` | Грамматически и архитектурно неясно | `DocumentModelRegistry` |
| `*Data` | Не сообщает роль объекта | `*Input`, `*Command` или `*Options` |
| `forceDelete` | Infrastructure wording | `purge` |
| `uploadVersion` | Неясно: версия upload protocol или file revision | `addRevision` |
| `for()` | Очень общее имя | `drive()` |
| `forStorage()` | Смешивает facade и persistence | `open()` или internal factory |

## Канонический glossary

Рекомендуемая модель:

- **Owner** — модель host-приложения, например Project или Course.
- **Workspace** — логическое файловое пространство, привязанное к Owner.
- **Drive profile** — конфигурационный профиль Workspace.
- **Node** — элемент дерева.
- **Folder** — контейнерный Node.
- **File** — Node с бинарным содержимым.
- **Document** — Node со структурированным редактируемым содержимым.
- **Shortcut** — Node, ссылающийся на другой Node.
- **Revision** — неизменяемый snapshot содержимого File или Document.
- **Blob** — immutable binary object в content-addressed storage.
- **Format** — MIME/extension-level представление файла.
- **Document type** — бизнес- и content-level тип редактируемого документа.
- **Editor** — клиентский компонент для document type или format.
- **Exporter** — преобразование содержимого в output format.
- **Permission driver** — адаптер прав host-приложения.
- **Access resolver** — внутренний компонент, объединяющий host permissions, grants и share access.

# Типы документов

## Нынешнее противоречие

В README заявлены четыре document types: rich text, table, markdown и template. В конфигурации присутствуют:

```php
'enabled_document_types' => [
    'richtext',
    'table',
    'markdown',
    'template',
],
```

Одновременно:

- `NodeKind` содержит только structural kind `Document`;
- `FileHandler` разрешается по MIME, extension, capability и priority;
- Documents package регистрирует `TipTapHandler`;
- spreadsheet feature отдельно включает `UniverHandler`;
- `registerDocumentType()` хранит произвольный array;
- frontend имеет отдельные composables для document node и spreadsheet.

Это означает, что «document type» сейчас используется минимум в трёх смыслах:

1. structural node kind;
2. content schema;
3. editor implementation.

Их нужно развести.

## Structural kind

[`NodeKind`](https://github.com/axiomasoft/vaulter/blob/main/packages/core/src/Enums/Drive/NodeKind.php) должен оставаться закрытым enum:

```text
folder
file
document
shortcut
```

Закрытость enum здесь полезна: это набор фундаментальных вариантов хранения и поведения дерева. Добавление нового редактора не должно требовать нового `NodeKind`.

## Document type

Document type должен определять:

- content schema;
- content codec;
- default content;
- validation;
- capabilities;
- editor key;
- supported collaboration protocol;
- export support;
- preview support;
- migration между schema versions.

Примеры ключей:

```text
rich-text
markdown
template
spreadsheet
```

Текущий ключ `table` неоднозначен. Он может означать таблицу внутри TipTap, самостоятельный tabular document или spreadsheet. Если речь о полноценном табличном редакторе, правильнее `spreadsheet`.

## File format

[`FileHandler`](https://github.com/axiomasoft/vaulter/blob/main/packages/core/src/Contracts/Handlers/FileHandler.php) на самом деле является format driver. Его хорошие стороны:

- string ID вместо sealed enum;
- MIME wildcard;
- extension fallback;
- priority;
- per-file capabilities;
- frontend descriptor.

Но интерфейс не должен называться «document-processing handler», если он также обрабатывает обычные файлы, preview и download-only fallback.

Предлагается:

```php
interface FormatDriver
{
    public function key(): FormatDriverKey;

    public function supports(FileDescriptor $file): bool;

    public function priority(): int;

    public function capabilities(FileDescriptor $file): FileCapabilities;

    public function presentation(FileDescriptor $file): PresentationDescriptor;
}
```

Метод `supports()` инкапсулирует MIME и extension matching. При необходимости registry может предварительно индексировать drivers по declared patterns через дополнительный интерфейс.

## Backend и Vue

Текущий `HandlerUi` связывает backend handler с Vue component/open route. Это удобно, но создаёт coupling PHP package с конкретной frontend implementation.

Лучше, чтобы backend возвращал:

```json
{
  "format_driver": "tiptap",
  "document_type": "rich-text",
  "capabilities": ["view", "edit", "export", "collaborate"]
}
```

Frontend самостоятельно регистрирует renderer/editor:

```ts
registry.register('tiptap', () => import('./editors/TipTapEditor.vue'))
```

Так сторонний backend extension не обязан знать имя Vue-компонента, а React/Svelte-клиенты не зависят от Vue descriptor.

# Рекомендуемый PHP API

## Минимальный facade

```php
$drive = Vaulter::drive(
    owner: $project,
    profile: 'project-files',
);
```

Не рекомендуется добавлять в корневой facade десятки методов. Дальнейшие действия должны быть сгруппированы:

```php
$folder = $drive->folders()->create(
    CreateFolderInput::make('Contracts')
        ->inside($drive->root())
);

$file = $drive->files()->upload(
    source: UploadSource::fromUploadedFile($request->file('file')),
    options: UploadOptions::make()
        ->inside($folder)
        ->named('contract.pdf'),
);

$drive->nodes()->move(
    node: $file,
    destination: $archive,
);

$drive->trash()->restore($file);
$drive->trash()->purge($file);
```

## Более простой вариант

Если fluent sub-APIs покажутся избыточными:

```php
$drive->createFolder(...);
$drive->uploadFile(...);
$drive->addFileRevision(...);
$drive->moveNode(...);
$drive->copyNode(...);
$drive->renameNode(...);
$drive->trashNode(...);
$drive->restoreNode(...);
$drive->purgeNode(...);
$drive->listChildren(...);
```

Этот вариант менее элегантен, но лучше текущего смешения коротких общих глаголов.

## Переименование методов

| Сейчас | Предлагается | Причина |
|---|---|---|
| `Vaulter::for()` | `Vaulter::drive()` | Явное доменное назначение |
| `Vaulter::forStorage()` | `Vaulter::open()` | Не привязывает API к модели `Storage` |
| `panel()` | `profile()` | Устраняет конфликт с UI panel |
| `registerPanel()` | `profiles()->register()` | Registry API |
| `registerHandler()` | `formats()->register()` | Определяет фактическую роль |
| `handlers()` | `formats()` | Устраняет слишком общий термин |
| `registerExporter()` | `exporters()->register()` | Симметричный registry |
| `list()` | `children()` | Точная семантика |
| `move()` | `moveTo()` или `moveNode()` | Ясное направление |
| `copy()` | `copyTo()` или `copyNode()` | Ясное направление |
| `trash()` | `trashNode()` | Уменьшает неоднозначность |
| `forceDelete()` | `purge()` | Совпадает с domain language |
| `uploadVersion()` | `addRevision()` | Не смешивает upload и version |
| `createFolder()` | оставить | Уже достаточно ясно |

## Аргументы методов

Сейчас destination в разных местах называется `parent`, `into` и `target`. Следует принять один словарь:

- `parent` — текущий parent relation;
- `destination` — новое место при move/copy;
- `folder` — destination для upload/create;
- `target` — объект, над которым совершается операция;
- `source` — входное содержимое.

Не следует использовать `into` в DTO и `parent` в соседнем публичном методе для одного понятия.

## `actorId`

Практически все write methods принимают `int|string|null $actorId`. Это недостаточно безопасная модель.

`actorId` является audit metadata, а не доказательством identity или разрешения. Любой host-код может передать ID другого пользователя. Поэтому его нельзя использовать как authorization context.

Предпочтительно:

```php
interface Actor
{
    public function id(): ActorId;
}
```

Или:

```php
$drive = Vaulter::drive($project)
    ->actingAs($user);
```

После этого actor нельзя произвольно менять в каждом method call.

Для системных операций:

```php
$drive->asSystem(SystemActor::maintenance());
```

Такая операция должна быть явной и логируемой.

# Авторизация

## Программный bypass

[`DriveContext`](https://github.com/axiomasoft/vaulter/blob/main/packages/drive/src/DriveContext.php) прямо документирует, что authorization выполняется на HTTP controller boundary, а сам handle остаётся pure delegation surface.

Одновременно README и facade documentation называют этот handle главным public integration entry point.

Следовательно, существуют два разных security contract:

- HTTP API — предположительно авторизованный;
- PHP facade API — неавторизованный.

Это опасный дизайн. Разработчик может считать, что вызов:

```php
Vaulter::drive($project)->purge($node);
```

применяет те же правила, что REST endpoint, но фактически этого не происходит.

## Варианты исправления

### Вариант A: public API всегда авторизует

```php
$drive = Vaulter::drive($project)->actingAs($user);
$drive->files()->upload(...);
```

Каждая операция вызывает `AccessResolver`.

Это рекомендуемый вариант.

### Вариант B: два явных API

```php
Vaulter::drive($project)->actingAs($user);
Vaulter::operations()->unsafeForWorkspace($workspace);
```

Internal API должен содержать `unsafe`, `internal` или `withoutAuthorization` в имени, чтобы bypass нельзя было выполнить случайно.

### Вариант C: application service

HTTP controllers и PHP facade вызывают один application service, который всегда принимает `AuthorizationContext`. Internal jobs создают system context.

## Routes middleware

В конфигурации default middleware содержит:

```php
'middleware' => [
    'api',
    RecordVaulterRequestMetrics::class,
],
```

В нём нет явного `auth`. При этом комментарии к webhook management утверждают, что route требует authenticated user. Это требует отдельной проверки всех controllers и request guards.

Безопасный default:

```php
'middleware' => [
    'api',
    'auth:sanctum',
    RecordVaulterRequestMetrics::class,
],
```

Если пакет не может навязать guard, установка должна требовать явного выбора:

```php
'auth' => [
    'middleware' => null,
],
```

И `vaulter:doctor` должен отказываться подтверждать production readiness при `null`.

# Upload API

## Слишком широкий `mixed`

Текущий `upload()` принимает:

```php
mixed $source
```

Поддерживаются:

- resource;
- PSR-7 stream;
- `SplFileInfo`;
- `UploadedFile`;
- raw string.

Проблемы:

- невозможно статически определить, является строка content bytes или path;
- PSR stream преобразуется через `(string) $source`, что потенциально загружает всё содержимое в память;
- resource принимается без явной проверки seekability;
- public contract не объясняет ownership ресурса и кто должен его закрывать;
- programmatic API может обойти HTTP FormRequest limits.

Предлагаемый контракт:

```php
interface UploadSource
{
    /** @return resource */
    public function stream();

    public function originalName(): ?string;

    public function declaredMimeType(): ?string;

    public function size(): ?int;
}
```

Factories:

```php
UploadSource::fromPath($path);
UploadSource::fromStream($stream);
UploadSource::fromBytes($bytes);
UploadSource::fromUploadedFile($file);
UploadSource::fromPsrStream($stream);
```

Это убирает неоднозначность и позволяет явно документировать memory behavior.

## MIME validation

Конфигурация Drive допускает пустой `allowed_mime`, то есть любые MIME types. При этом upload action сохраняет переданный MIME type, а расширение получает из original filename.

OWASP рекомендует не доверять пользовательскому `Content-Type`, использовать allowlist, проверять сигнатуру содержимого, ограничивать размер и хранить upload вне webroot. [cheatsheetseries.owasp](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)

Нужен pipeline:

```php
interface UploadInspector
{
    public function inspect(
        UploadSource $source,
        UploadPolicy $policy,
    ): InspectionResult;
}
```

Built-in stages:

- actual size detection;
- MIME sniffing через `finfo`;
- extension/MIME consistency;
- forbidden filename detection;
- malware adapter;
- archive inspection;
- quarantine decision;
- content hash;
- image validation/re-encoding;
- PDF/document CDR hook.

## Policy должна быть общей

Ограничения нельзя оставлять только в `UploadFileRequest`. Иначе PHP API, queue job или custom controller сможет их обойти.

Проверки должны находиться в application layer:

```php
UploadFile::execute(
    AuthorizedOperationContext $context,
    UploadFileInput $input,
);
```

FormRequest должен только преобразовывать HTTP input в `UploadFileInput`.

# Потенциальная ID-проблема

## ULID и CAS scope

Конфигурация объявляет ULID стратегией по умолчанию. Модель `Node` и другие модели документируют идентификаторы как `int|string`.

Однако [`UploadFileAction`](https://github.com/axiomasoft/vaulter/blob/main/packages/drive/src/Actions/UploadFileAction.php) содержит:

```php
private function storageIdForScope(UploadFileData $data): ?int
{
    $storageId = $data->storage->id;

    return is_numeric($storageId) ? (int) $storageId : null;
}
```

Аналогично обрабатывается `tenant_id`.

Для ULID или UUID7 `is_numeric()` вернёт `false`, и CAS получит `null`. Если `dedup.scope=storage`, это может привести к:

- потере storage scope;
- неверному storage key;
- конфликтам дедупликации;
- нарушению isolation;
- ошибкам garbage collection.

Это следует считать P0 verification item.

Правильный тип:

```php
public function storageIdForScope(
    UploadFileData $data
): int|string|null;
```

Лучше ввести value object:

```php
final readonly class ScopeIdentifier
{
    public function __construct(
        public string $value,
    ) {}
}
```

Необходимо проверить весь путь:

```text
UploadFileAction
→ BlobStore
→ ContentStore
→ storage key builder
→ blob unique indexes
→ garbage collector
→ reconciliation
```

И написать integration tests для `bigint`, `ulid` и `uuid7` при каждом dedup scope.

# Конкурентность и транзакции

## Quota race

`UploadFileAction` проверяет quota до DB transaction:

```php
$this->assertWithinQuota(...);

return DB::transaction(...);
```

Если два upload выполняются параллельно, оба могут увидеть одинаковый `used_bytes` и пройти pre-check.

Финальная безопасность зависит от реализации `adjustUsedBytes()` и блокировок, которые в рассмотренном action не видны. Нужен обязательный PostgreSQL concurrency test:

1. Workspace имеет свободно 10 MB.
2. Два параллельных upload по 8 MB.
3. Только один должен быть committed.
4. Второй должен получить domain error.
5. `used_bytes` должен совпасть с фактическими active revisions.
6. CAS не должен оставить необслуживаемый blob.

Надёжная реализация:

- `SELECT ... FOR UPDATE` workspace row;
- повторная проверка quota внутри transaction;
- atomic conditional update;
- database check, где это возможно;
- reconciliation command как дополнительная защита.

Предварительная проверка может остаться для fast failure, но не должна быть единственным enforcement.

## Version number race

`nextVersionNo($file)` должен проверяться при конкурентной загрузке двух revisions. Нужны:

- unique index `(file_id, version_no)`;
- lock текущего file head;
- retry при serialization/unique conflict;
- тест на одновременный commit.

## Name conflict race

`assertNameAvailable()` выполняется до основной transaction. Корректность должна обеспечиваться уникальным DB constraint для active siblings, а pre-check должен использоваться только ради удобного сообщения.

Нужны отдельные тесты для:

- одинакового имени в одной папке;
- одинакового имени в разных папках;
- trash/restore conflict;
- case sensitivity;
- Unicode normalization;
- параллельного create/upload/copy.

## Object storage внутри transaction

`BlobStore::commit()` вызывается внутри DB transaction. Но S3/Flysystem side effects не откатываются вместе с SQL transaction.

Это требует явного saga contract:

1. Stage bytes.
2. Compute hash.
3. Promote object.
4. Commit metadata.
5. При SQL rollback объект становится orphan.
6. Reconciler удаляет orphan только после grace period.

Такое поведение допустимо, но должно быть формально задокументировано и проверено fault-injection tests. Нельзя создавать впечатление, что DB transaction атомарно включает object storage.

## Events внутри transaction

`FileUploaded` и `NodeVersionCommitted` dispatch выполняется до завершения transaction. Обычный listener может:

- попытаться прочитать ещё не committed state;
- отправить webhook;
- запустить queue job;
- создать внешний side effect, даже если transaction затем откатится.

Следует:

- использовать after-commit events;
- либо собирать domain events и dispatch после commit;
- либо писать outbox row в той же transaction.

# Tenant isolation

## Текущий контракт

Конфигурация прямо говорит, что при включённом tenant column Vaulter сам не применяет scoping и ожидает tenant-aware custom model.

Это слишком опасный extension contract для document management package.

Ошибки host-приложения могут привести к:

- cross-tenant route model binding;
- search leakage;
- shared-node leakage;
- duplicate-name conflicts между tenants;
- cross-tenant CAS dedup leakage;
- неверному garbage collection;
- загрузке Node по глобальному ID без workspace verification.

## Предлагаемый контракт

```php
interface TenantResolver
{
    public function current(): ?TenantIdentifier;
}
```

Все repositories должны получать tenant context явно или через immutable operation context:

```php
final readonly class OperationContext
{
    public function __construct(
        public Actor $actor,
        public ?TenantIdentifier $tenant,
        public WorkspaceReference $workspace,
    ) {}
}
```

Production behavior должно быть fail-closed:

- tenant mode enabled + tenant unresolved → exception;
- node tenant не совпадает с context → not found;
- workspace tenant не совпадает → not found;
- storage-scoped query без tenant predicate → architecture test failure.

Custom Eloquent model может расширять поведение, но не должен быть единственным security boundary.

# Безопасность

## Сильные стороны

В текущей конфигурации уже заложены хорошие решения:

- share tokens хранятся как hash;
- share links получают default и maximum TTL;
- download response использует `nosniff`;
- active MIME types не входят в inline whitelist;
- upload chunks имеют отдельный hard limit;
- upload metadata ограничены allowlist и длиной;
- webhook delivery запрещает redirects;
- webhook delivery проверяет IP ranges;
- DNS result предполагается pin к соединению;
- collaboration webhook использует timestamped HMAC;
- replay signatures должны запоминаться;
- null permission driver запрещается в production;
- TipTap schema использует allowlist;
- executable URL schemes должны блокироваться.

Это показывает, что безопасность рассматривалась как часть дизайна, а не была добавлена постфактум.

## Webhook SSRF

Текущая application-level защита выглядит сильнее обычной реализации. Однако OWASP рекомендует defense in depth: application validation должна дополняться network-level egress policy. [cheatsheetseries.owasp](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)

Для production следует рекомендовать:

- отдельный egress proxy для webhooks;
- deny-by-default security group;
- запрет доступа к metadata service;
- audit log разрешённых destination IP;
- повторную DNS/IP проверку перед каждым retry;
- отдельный timeout budget;
- response body hard limit;
- контроль портов;
- запрет credentials в URL;
- canonical URL normalization;
- тесты IPv4-mapped IPv6;
- тесты decimal/octal IP forms;
- тесты CNAME chain и DNS rebinding.

## Share links

Нужно дополнительно проверить:

- constant-time token hash comparison;
- single-node или subtree semantics;
- access после перемещения node;
- доступ к trashed/quarantined descendants;
- revoke race;
- share links при tenant migration;
- brute-force resistance;
- rate-limit key, чтобы attacker не мог менять IP headers;
- отсутствие raw token в logs, metrics, events и exception context.

## Collaboration

Следует сделать обязательными startup checks:

- `webhook_secret` задан;
- JWT secret задан;
- эти secrets различаются;
- WebSocket URL использует `wss://` в production;
- callback URL использует HTTPS;
- token TTL ограничен;
- room name включает tenant/workspace scope либо проверяется сервером;
- read-only client не может отправить update через socket;
- store callback не принимает состояние для другого node;
- replay cache работает при нескольких Laravel instances.

## Malware и quarantine

Для DMS желательно иметь first-class quarantine lifecycle:

```text
staged
→ inspecting
→ clean
→ available
```

или:

```text
staged
→ inspecting
→ infected
→ quarantined
```

Capability `download` не должен быть доступен до завершения проверки, если policy требует inspection.

Интеграция должна быть pluggable:

```php
interface ContentScanner
{
    public function scan(BlobReference $blob): ScanResult;
}
```

Поддерживаемые adapters могут включать ClamAV, external scanning service и no-op scanner для development.

## Secret scanning

Поскольку встроенное расширенное сканирование GitHub для репозитория не было доступно, следует добавить независимые CI-инструменты:

- Gitleaks;
- TruffleHog;
- Composer audit;
- npm audit;
- OSV-Scanner;
- Dependabot или Renovate;
- SBOM через CycloneDX;
- container scanning для Hocuspocus image.

# Конфигурация

## Конфигурационный монолит

[`packages/core/config/vaulter.php`](https://github.com/axiomasoft/vaulter/blob/main/packages/core/config/vaulter.php) объединяет:

- models;
- table names;
- ID strategies;
- panels;
- migrations;
- features;
- handlers;
- TipTap schema;
- tenancy;
- database connection;
- permission drivers;
- Gate;
- Spatie;
- AzGuard;
- deduplication;
- garbage collection;
- storage;
- shares;
- downloads;
- trash;
- uploads;
- webhooks;
- routes;
- rate limits;
- versions;
- documents;
- Drive;
- exporters;
- collaboration;
- observability.

Это слишком много независимых подсистем для одного publishable configuration file.

## Дублирующиеся концепции

Примеры:

- `id_type` и `ids.default`;
- `max_versions` и `versions.max`;
- `share_token_generator` и `share.token_generator`;
- `features.versions` и `features.document_versions`;
- `enabled_document_types` и runtime `documentTypes`;
- `storage.default_disk` и panel/storage disk;
- `middleware` как общий список при разных security requirements routes.

Legacy aliases полезны на migration period, но не должны оставаться равноправной частью канонической конфигурации.

## Разделение файлов

Рекомендуется:

```text
config/
├── vaulter.php
├── vaulter-drive.php
├── vaulter-documents.php
├── vaulter-media.php
├── vaulter-access.php
├── vaulter-collaboration.php
├── vaulter-sharing.php
└── vaulter-webhooks.php
```

`vaulter.php` должен содержать только:

- enabled modules;
- database connection;
- ID strategy;
- route prefix;
- global middleware;
- profiles.

Laravel package discovery, configuration publishing и provider-based loading являются стандартным путём построения пакетов. Spatie package tools также ожидает предсказуемые `src`, `config`, `database`, `resources` и `routes` directories. [github](https://github.com/spatie/laravel-package-tools)

## Typed configuration

Нельзя читать raw config arrays во всех слоях.

На startup конфигурация должна нормализоваться:

```php
final readonly class DriveConfiguration
{
    public function __construct(
        public int $maxUploadBytes,
        public array $allowedMimeTypes,
        public NamePolicy $namePolicy,
    ) {}
}
```

Преимущества:

- единая валидация;
- предсказуемые defaults;
- меньше `Config::...` static calls;
- проще тестирование;
- легче IDE navigation;
- понятнее ошибка при неверном FQCN;
- меньше silent fallbacks.

## `vaulter:doctor`

Нужна production diagnostics command:

```bash
php artisan vaulter:doctor
```

Она должна проверять:

- доступность database connection;
- наличие всех tables и indexes;
- соответствие migrations текущим features;
- корректность ID strategy;
- совместимость morph columns;
- tenant configuration;
- auth middleware;
- permission driver binding;
- CAS disk;
- write/read/delete probe;
- queue configuration;
- scheduler tasks;
- Hocuspocus secrets;
- webhook secrets;
- HTTPS/WSS requirements;
- installed optional packages;
- class/interface compatibility models;
- uniqueness handler IDs;
- uniqueness document type IDs;
- exporter collisions;
- route collisions;
- config cache compatibility;
- production usage null/test drivers.

# Структура пакетов

## Текущий `core`

[`packages/core/src`](https://github.com/axiomasoft/vaulter/tree/main/packages/core/src) содержит:

- Access;
- Actions;
- Activity;
- Attributes;
- CAS;
- Console;
- Contracts;
- Data;
- Drivers;
- Engines;
- Enums;
- Events;
- Exceptions;
- Facades;
- HTTP;
- Jobs;
- Models;
- Observability;
- Panels;
- Policies;
- Repositories;
- Rules;
- Schema;
- Sharing;
- Support;
- Testing;
- ValueObjects;
- Webhooks.

Это не «shared kernel» в строгом смысле. Это большая часть platform backend.

Варианты:

1. Сократить пакет до действительно общих контрактов.
2. Переименовать `core` в `kernel` и перестать описывать его как минимальное ядро.
3. Вернуть часть моделей, схемы и HTTP behavior в owning packages.

## Рекомендуемые boundaries

```text
packages/
├── contracts/
├── kernel/
├── drive/
├── media/
├── documents/
├── collaboration/
├── sharing/
├── webhooks/
├── vue/
├── filament/
└── integrations/
    └── azgard/
```

Однако создавать слишком много Composer packages также не следует. Практический компромисс:

```text
core
  shared contracts, IDs, profiles, installation, common events

drive
  workspaces, nodes, folders, files, revisions, uploads, trash

media
  blobs, content store, deduplication, GC

documents
  structured content, document types, comments, export

collaboration
  Yjs, Hocuspocus, collaboration callbacks

integrations
  Vue, Filament, AzGuard
```

Sharing и webhooks можно оставить в core до появления реальной необходимости в отдельной установке.

## Владение schema

Сейчас Documents behavior и models принадлежат `vaulter-documents`, но schema некоторых documents tables остаётся в core. Это упрощает migration ordering, но размывает ownership.

Нужно формально выбрать один принцип:

- package owns behavior, models, tables and migrations;
- либо kernel owns canonical schema, а feature packages только активируют behavior.

Смешанная модель без формального правила будет создавать циклические зависимости.

Предпочтительно: package, который владеет aggregate, владеет и migrations. Kernel может предоставлять migration coordinator и dependency ordering.

## Namespaces

Composer packages не должны неявно конкурировать за один широкий namespace prefix без строгой ownership policy.

Предпочтительная схема:

```text
Vaulter\
Vaulter\Core\
Vaulter\Drive\
Vaulter\Media\
Vaulter\Documents\
Vaulter\Collaboration\
Vaulter\Filament\
```

Root namespace `Vaulter\` оставить только для facade и нескольких stable top-level contracts.

PSR-4 требует соответствия subnamespace и directory path с учётом регистра. Архитектурные тесты должны дополнительно проверять, какой package имеет право объявлять каждый namespace prefix. [php-fig](https://www.php-fig.org/psr/psr-4/)

# Структура директорий

## Горизонтальная раскладка

В Drive package текущая папка `Actions` содержит в одном уровне:

- `CopyNodeAction`;
- `CreateFolderAction`;
- `ForceDeleteNodeAction`;
- `MoveNodeAction`;
- `RenameNodeAction`;
- `RestoreNodeAction`;
- `TrashNodeAction`;
- `UploadFileAction`;
- поддиректорию `Uploads`.

Пока файлов мало, структура читается. После добавления shortcuts, locks, preview generation, sharing и policies папка станет неудобной.

## Предлагаемая раскладка

```text
packages/drive/src/
├── Actions/
│   ├── Folders/
│   │   └── CreateFolder.php
│   ├── Nodes/
│   │   ├── CopyNode.php
│   │   ├── MoveNode.php
│   │   └── RenameNode.php
│   ├── Files/
│   │   ├── UploadFile.php
│   │   └── AddFileRevision.php
│   ├── Trash/
│   │   ├── TrashNode.php
│   │   ├── RestoreNode.php
│   │   └── PurgeNode.php
│   └── Uploads/
│       ├── InitiateUpload.php
│       ├── AppendUploadChunk.php
│       ├── CompleteUpload.php
│       └── AbortUpload.php
├── Data/
│   ├── Folders/
│   ├── Nodes/
│   ├── Files/
│   └── Uploads/
├── Events/
│   ├── Files/
│   ├── Nodes/
│   └── Uploads/
├── Exceptions/
│   ├── Files/
│   ├── Nodes/
│   ├── Quota/
│   └── Uploads/
├── Http/
│   ├── Controllers/
│   │   ├── Files/
│   │   ├── Folders/
│   │   ├── Nodes/
│   │   ├── Trash/
│   │   └── Uploads/
│   ├── Requests/
│   └── Resources/
├── Models/
├── Queries/
├── Repositories/
└── Support/
```

Группировать следует только при наличии хотя бы двух-трёх файлов в category. Папка с одним файлом без ожидаемого роста создаёт лишнюю глубину.

## Actions suffix

Есть два допустимых варианта:

```text
Actions/Nodes/MoveNodeAction.php
```

или:

```text
Actions/Nodes/MoveNode.php
```

Рекомендуется второй: папка уже сообщает architectural role. Класс остаётся именем use case, метод — `execute()`.

```php
final readonly class MoveNode
{
    public function execute(MoveNodeInput $input): NodeReference;
}
```

Главное — применить правило ко всему проекту.

## `Data` objects

`CreateDriveFolderData`, `MoveNodeData` и другие названия слишком общие.

Использовать:

- `CreateFolderInput` — параметры use case;
- `MoveNodeInput`;
- `UploadFileInput`;
- `ListNodesQuery`;
- `UploadOptions`;
- `FileDescriptor`;
- `NodeReference`;
- `AccessDecision`.

`DTO` и `Data` не должны заменять предметное назначение объекта.

## Controllers

[`NodeController`](https://github.com/axiomasoft/vaulter/blob/main/packages/drive/src/Http/Controllers/NodeController.php) имеет размер около 17 KB и обслуживает listing, search, trash, folders, uploads и node mutations.

Его следует разделить:

```text
Nodes/ListNodesController
Nodes/ShowNodeController
Nodes/RenameNodeController
Nodes/MoveNodeController
Nodes/CopyNodeController
Folders/CreateFolderController
Files/UploadFileController
Trash/ListTrashController
Trash/RestoreNodeController
Trash/PurgeNodeController
Search/SearchNodesController
```

Single-action controllers упростят:

- policy review;
- middleware;
- route documentation;
- OpenAPI generation;
- unit tests;
- dependency lists;
- security auditing.

# REST API

## Versioning

Текущие [Drive routes](https://github.com/axiomasoft/vaulter/blob/main/packages/drive/routes/drive.php) публикуются под:

```text
api/vaulter
```

Без явной API version.

До первого публичного стабильного релиза следует перейти на:

```text
/api/vaulter/v1
```

Package SemVer недостаточно для REST clients: backend package может обновляться независимо от Vue SDK или внешних consumers.

## Route naming

Routes должны получить names:

```php
->name('vaulter.v1.nodes.show')
```

Это нужно для:

- route generation;
- compatibility snapshots;
- integration tests;
- host customization;
- observability labels.

## Нынешние action endpoints

Текущие endpoints:

```text
POST   /nodes/{node}/move
POST   /nodes/{node}/copy
POST   /nodes/{node}/restore
DELETE /nodes/{node}/force
DELETE /nodes/{node}
```

Они практически приемлемы, но `force` — infrastructure terminology. Лучше:

```text
DELETE /trash/{node}
```

или:

```text
POST /nodes/{node}/purge
```

Если операция destructive и неидемпотентная, первый вариант лучше выражает permanent deletion из trash.

## Listing, search и trash

Вместо отдельных разнородных endpoint styles можно использовать:

```text
GET /workspaces/{workspace}/nodes?parent={id}
GET /workspaces/{workspace}/nodes?filter[trashed]=true
GET /workspaces/{workspace}/nodes?search=contract
```

Однако отдельный search endpoint оправдан, если search имеет отличающуюся response model или backend.

Главное — зафиксировать contract, а не механически следовать REST purity.

## Нужные HTTP capabilities

До 1.0 рекомендуется добавить:

- cursor pagination;
- stable sorting;
- filtering schema;
- idempotency keys для create/copy/upload initiation;
- `ETag` или revision token;
- `If-Match` для rename/move/content update;
- единый error envelope;
- machine-readable error codes;
- request correlation ID;
- OpenAPI 3.1 schema;
- generated TypeScript client types;
- deprecation headers;
- maximum page size;
- consistent `404` вместо authorization-revealing `403` там, где нужна resource hiding.

## Ошибки

Предлагаемый envelope:

```json
{
  "error": {
    "code": "node_name_conflict",
    "message": "A node with this name already exists.",
    "details": {
      "parent_id": "01...",
      "name": "contract.pdf"
    },
    "request_id": "..."
  }
}
```

Public `code` является SemVer contract. Текст `message` может меняться.

# Модель данных

## Сильная сторона unified tree

Единый `Node` для folder, file, document и shortcut — хорошее решение:

- одинаковые move/rename rules;
- одно дерево;
- единые grants;
- единый trash;
- одна ordering model;
- общая activity history;
- меньше duplicated tree logic.

Использование recursive CTE и cycle detection в [`Node`](https://github.com/axiomasoft/vaulter/blob/main/packages/core/src/Models/Drive/Node.php) выглядит обоснованным.

## `Node` и `File`

Одновременно существуют:

- `Node(kind=file)`;
- связанная модель `File`;
- `FileVersion`;
- `Blob`.

Это нормальная нормализованная схема, но терминологически легко запутаться.

Предлагаемая aggregate model:

```text
Node
└── FileContent
    └── FileRevision
        └── Blob
```

Если оставлять класс `File`, документация должна ясно говорить:

- `Node` — tree identity;
- `File` — content aggregate;
- `FileRevision` — immutable history entry;
- `Blob` — physical bytes.

## Soft delete и trash

Следует унифицировать:

- Laravel `deleted_at`;
- domain `trashed_at`;
- `trashed_by`;
- `trashed_from_parent_id`;
- subtree state.

Нужно решить, является ли trash обычным SoftDeletes или самостоятельным domain state.

Для дерева чаще безопаснее самостоятельный trash lifecycle, потому что:

- subtree может быть logically trashed через ancestor;
- restore должен знать прежнего parent;
- конфликт имён возникает на restore;
- purge затрагивает revisions и blob references.

Если используется domain trash, `SoftDeletes` не должен создавать вторую независимую модель удаления без чёткой необходимости.

# Core и package coupling

## Контракты зависят от моделей

`DriveHandle` живёт в core, но принимает `Vaulter\Models\Drive\Node` и `Storage`, также принадлежащие core, тогда как реализация находится в drive package.

Это означает, что core владеет не только seam, но и значительной частью Drive domain model. Поэтому утверждение «core alone ships no Drive features» формально верно только для behavior, но не для domain/schema.

Необходимо выбрать:

- core действительно владеет Drive domain model, а drive является application package;
- либо drive владеет моделями, contracts и migrations;
- либо модели перемещаются в отдельный domain package.

## Optional package seams

`resolveSeam()` с `MissingPackageException` — хорошая идея: generic container error преобразуется в понятное сообщение с указанием Composer package.

Но optional package behavior должен быть discoverable до runtime. `vaulter:doctor` и install command должны заранее сообщать:

```text
Drive API requested, but axioma-studio/vaulter-drive is not installed.
```

# Release engineering

## Текущий статус

Последний merge commit прямо сообщает, что CI был намеренно пропущен и merge не утверждает release readiness: [`8f077018`](https://github.com/axiomasoft/vaulter/commit/8f07701866a69d8790fda5dc1fe9bbd9287be6fd).

Сохранённый [post-archive review](https://github.com/axiomasoft/vaulter/blob/main/audits/2026-09-29-post-archive-review.md) ранее зафиксировал:

- отсутствие полной Laravel 12/13 CI проверки;
- отсутствие Node 20/22 matrix verification;
- отсутствие fresh install;
- отсутствие Docker build;
- отсутствие реальных release actions;
- несовместимость отдельных release gate assumptions;
- проблемы lockfile reproducibility.

После этого появились remediation commits, включая синхронизацию Hocuspocus lockfile и verifier permissions, но текущий merge всё равно не объявляет релиз готовым.

## Composer metadata

Root и core manifests используют homepage/support URL:

```text
https://github.com/axioma-studio/vaulter
```

Фактический репозиторий:

```text
https://github.com/axiomasoft/vaulter
```

См. [root composer.json](https://github.com/axiomasoft/vaulter/blob/main/composer.json) и [core composer.json](https://github.com/axiomasoft/vaulter/blob/main/packages/core/composer.json).

Это нужно исправить до публикации:

- homepage;
- support.issues;
- support.source;
- README badges;
- package metadata;
- split repository remotes;
- npm metadata;
- generated documentation links.

## Package descriptions

`vaulter-core` описывается как пакет, содержащий file manager, TipTap editor, version history и collaboration, хотя эти возможности распределены по optional packages.

Описание core должно быть уже:

> Shared Laravel kernel, contracts, storage profiles and common infrastructure for Vaulter packages.

Umbrella package может иметь полное продуктовое описание.

## Release matrix

Обязательный release gate:

| Проверка | Matrix |
|---|---|
| PHP | 8.4 |
| Laravel | 12, 13 |
| Database | PostgreSQL primary, SQLite smoke |
| IDs | bigint, ULID, UUID7 |
| Vue | supported Node versions |
| Hocuspocus | Node 22+ |
| Install | clean Composer and npm install |
| Config | normal and cached |
| Packages | each package isolated and umbrella install |
| Docker | Hocuspocus image build and health test |
| API | OpenAPI validation and route snapshot |
| Upgrade | previous release → current migrations |
| Security | Composer/npm audit, secrets, container scan |
| Packaging | Composer validate, npm pack dry-run |
| Split | package split verification |

# Тестовая стратегия

## Architecture tests

Добавить automated package boundary rules:

```text
Core must not depend on Documents implementation.
Documents may depend on Core contracts.
Drive may depend on Media contracts, not Media internals.
Vue package must not infer undocumented backend fields.
Filament must remain optional.
Testing namespaces must not be imported by production code.
```

Для PHP можно использовать Deptrac или custom PHPStan rules.

## Public API snapshots

Необходимо автоматически фиксировать:

- public classes;
- public interfaces;
- public methods;
- exception hierarchy;
- config keys;
- route names;
- OpenAPI schema;
- event names;
- event payloads;
- table names;
- command names.

Для Composer packages полезен backward compatibility checker, запускаемый относительно последнего tag.

## Contract tests для extensions

Каждая сторонняя extension point должна иметь reusable test suite:

```php
DocumentTypeContractTest::for(MarkdownDocumentType::class);
FormatDriverContractTest::for(PdfPreviewDriver::class);
PermissionDriverContractTest::for(AppPermissionDriver::class);
IdStrategyContractTest::for(SnowflakeIdStrategy::class);
```

Проверки registry:

- duplicate IDs;
- deterministic priority;
- unsupported content;
- config cache;
- serialization;
- invalid FQCN;
- missing optional dependency;
- replacement policy;
- boot freeze.

## PostgreSQL concurrency

SQLite недостаточно для проверки:

- row locks;
- recursive CTE behavior;
- concurrent version numbers;
- quota races;
- partial unique indexes;
- skip locked;
- deadlocks;
- outbox relay claims;
- upload session claims;
- GC/reconciliation.

Критические concurrency tests должны выполняться на реальном PostgreSQL.

## Property-based tests

Особенно полезны для:

- node tree moves;
- cycle prevention;
- Unicode names;
- path separator rejection;
- URL validation;
- MIME/extension combinations;
- share token parsing;
- webhook IP range parsing;
- TipTap schema validation;
- upload metadata;
- idempotency.

## Fault injection

Нужны сценарии остановки на каждом шаге:

```text
bytes staged
object promoted
blob row created
revision created
node repointed
used_bytes adjusted
event recorded
transaction committed
job dispatched
```

После любого injected failure система должна либо завершить операцию, либо быть восстановима reconciliation command без потери существующих данных.

# Документация

## Внутренние коды в docblocks

В production source встречается большое количество ссылок вида:

```text
P2 #5
ADR-0015
F-P3.3-02
H.5
D10
R-11
```

ADR-ссылки полезны, но внутренние remediation IDs и plan references сильно засоряют публичные docblocks.

Проблемы:

- внешний пользователь не понимает контекст;
- код выглядит как незавершённый проектный журнал;
- идентификаторы устаревают;
- docblocks становятся длиннее реализации;
- сложнее поддерживать API reference.

Рекомендуется:

- оставить только объяснение invariant;
- добавить короткую ссылку `@see docs/adr/0015-...`;
- task IDs перенести в commit history и audit documents;
- удалить `§2.4`, если спецификация не является стабильным публичным документом.

## Документационные уровни

Предлагается разделить:

```text
docs/
├── getting-started/
├── concepts/
├── guides/
├── extensions/
├── reference/
├── security/
├── operations/
├── upgrades/
└── internals/
    └── adr/
```

В `reference` должны быть generated или проверяемые материалы:

- config;
- contracts;
- events;
- routes;
- commands;
- exceptions.

## Проверка snippets

Все PHP/TS examples из документации должны автоматически извлекаться и проверяться:

- PHP syntax;
- referenced class existence;
- method existence;
- TypeScript typecheck;
- route existence;
- config key existence.

Это предотвратило бы расхождение с `resolvePermissionsWith()`.

# Предлагаемая целевая структура

```text
packages/
├── core/
│   ├── src/
│   │   ├── Contracts/
│   │   ├── Configuration/
│   │   ├── Ids/
│   │   ├── Profiles/
│   │   ├── Installation/
│   │   ├── Events/
│   │   └── VaulterManager.php
│   ├── config/
│   ├── database/
│   └── tests/
├── drive/
│   ├── src/
│   │   ├── Actions/
│   │   │   ├── Files/
│   │   │   ├── Folders/
│   │   │   ├── Nodes/
│   │   │   ├── Trash/
│   │   │   └── Uploads/
│   │   ├── Contracts/
│   │   ├── Models/
│   │   ├── Queries/
│   │   ├── Repositories/
│   │   ├── Http/
│   │   │   └── V1/
│   │   └── DriveServiceProvider.php
│   ├── config/
│   ├── database/
│   ├── routes/
│   └── tests/
├── media/
│   ├── src/
│   │   ├── Blobs/
│   │   ├── Content/
│   │   ├── Deduplication/
│   │   ├── GarbageCollection/
│   │   └── Inspection/
│   └── tests/
├── documents/
│   ├── src/
│   │   ├── DocumentTypes/
│   │   ├── Content/
│   │   ├── Revisions/
│   │   ├── Comments/
│   │   ├── Export/
│   │   ├── Collaboration/
│   │   └── Http/
│   └── tests/
├── vue/
├── filament/
└── integrations/
    └── azgard/
```

# План миграции

## Этап 0: зафиксировать baseline

- Остановить добавление новых feature areas.
- Исправить README authorization example.
- Исправить Composer/npm repository URLs.
- Запустить полный clean-install CI.
- Зафиксировать текущий REST schema.
- Зафиксировать public PHP API snapshot.
- Проверить ULID/UUID CAS scope.
- Проверить auth middleware.
- Проверить programmatic authorization bypass.
- Выпустить последний pre-refactor tag.

## Этап 1: glossary ADR

Создать ADR с каноническими определениями:

- Workspace;
- Drive;
- Profile;
- Node;
- File;
- Document;
- Revision;
- Blob;
- Document type;
- Format driver;
- Permission driver;
- Access resolver.

После принятия ADR новые имена должны применяться ко всему новому коду.

## Этап 2: новый API параллельно

Добавить:

```php
Vaulter::drive()
Vaulter::profiles()
Vaulter::formats()
Vaulter::documentTypes()
Vaulter::exporters()
```

Старые методы оставить как deprecated wrappers.

```php
/** @deprecated Use Vaulter::drive() */
public function for(Model $entity, ?string $panel = null): Drive
{
    return $this->drive($entity, profile: $panel);
}
```

## Этап 3: typed inputs

Заменить positional methods с множеством nullable arguments:

```php
upload(
    mixed $source,
    ?Node $into = null,
    ?string $name = null,
    ?string $mimeType = null,
    ?string $originalName = null,
    int|string|null $actorId = null,
)
```

на:

```php
upload(
    UploadSource $source,
    UploadOptions $options,
): FileReference;
```

## Этап 4: единая авторизация

- Ввести `Actor` и `OperationContext`.
- Обязать public Drive API авторизовать операции.
- Оставить explicit internal API.
- Перенести policy enforcement из FormRequests в application layer.
- Добавить tenant context.

## Этап 5: registry redesign

- Удалить untyped `registerDocumentType()`.
- Ввести `DocumentTypeRegistry`.
- Переименовать handlers в format drivers.
- Отделить backend capabilities от Vue component registration.
- Ввести collision и freeze policies.
- Добавить extension contract tests.

## Этап 6: package boundaries

- Определить владельцев models и migrations.
- Сократить core.
- Разложить Actions, Data, Events, Exceptions и HTTP по subdomains.
- Ввести architecture tests.
- Разделить большой service provider на registrars.

## Этап 7: REST v1

- Ввести `/v1`.
- Добавить route names.
- Описать OpenAPI 3.1.
- Унифицировать errors.
- Добавить cursor pagination.
- Добавить idempotency.
- Добавить optimistic concurrency.

## Этап 8: security hardening

- Upload inspection pipeline.
- Quarantine.
- Auth middleware validation.
- Tenant fail-closed behavior.
- Egress network policy для webhooks.
- Secret and dependency scanning.
- Security regression suite.
- Threat model document.

## Этап 9: 1.0 readiness

1. Все package manifests валидны.
2. Все ссылки ведут в существующие repositories.
3. Fresh install проходит без локальных path dependencies.
4. Laravel 12/13 matrix зелёная.
5. PostgreSQL concurrency suite зелёная.
6. Vue/Hocuspocus builds воспроизводимы.
7. Docker image собирается.
8. Upgrade path проверен.
9. Public API diff пуст относительно release candidate.
10. REST OpenAPI diff классифицирован.
11. Security checklist закрыт.
12. Документационные snippets исполняются.

# Что не следует делать

- Не добавлять новые document types через ещё один config array.
- Не превращать `VaulterManager` в глобальный service locator.
- Не считать swappable Eloquent models полноценной абстракцией persistence.
- Не оставлять authorization только в HTTP controllers.
- Не оставлять upload validation только в FormRequests.
- Не вводить десятки feature toggles без проверки совместимых комбинаций.
- Не создавать отдельный package для каждой маленькой подсистемы.
- Не выполнять массовое переименование без deprecated compatibility layer.
- Не замораживать public API до устранения `document type`/`handler` противоречия.
- Не публиковать 1.0, пока fresh-install и release matrix не будут воспроизводимы.

# Definition of done

Vaulter можно считать архитектурно готовым к стабильному релизу, когда выполняются следующие условия:

- Laravel-разработчик устанавливает пакет и получает рабочую конфигурацию за несколько минут.
- Ошибка в auth или tenant configuration приводит к fail-closed behavior.
- Любой write use case проходит через единый authorization и policy layer.
- Новый document type добавляется без изменения core.
- Новый format driver добавляется без миграции.
- Backend extension не обязана знать Vue component name.
- Public API не требует конкретную Eloquent model.
- Package boundaries проверяются автоматически.
- Config keys не дублируют друг друга.
- Все extension registries типизированы и детерминированы.
- REST API имеет версию и OpenAPI contract.
- PHP API проверяется на backward compatibility.
- Upload, quota, versioning и GC имеют concurrency/fault tests.
- Release создаётся только из clean reproducible build.
- Документация компилируется и проверяет собственные code examples.

Самая правильная следующая работа — не расширение feature set, а отдельный refactoring milestone: **Canonical Domain Model and Public API 1.0**. В него следует включить glossary ADR, новый facade API, `DocumentTypeRegistry`, авторизованный operation context, typed upload source, переразбиение директорий и проверку package boundaries.