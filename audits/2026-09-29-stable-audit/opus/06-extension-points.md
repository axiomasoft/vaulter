# 06 — Точки расширения

Цель: каждая точка расширения типизирована, детерминирована, переживает `config:cache`, имеет
политику коллизий и контрактный набор тестов для автора расширения ([D35](02-decisions.md#d35)).
Решения: [D14](02-decisions.md#d14), [D15](02-decisions.md#d15), [D17](02-decisions.md#d17),
[D18](02-decisions.md#d18), [D11](02-decisions.md#d11), [D10](02-decisions.md#d10).

## 1. Общие правила реестров

| Правило | Значение |
|---|---|
| Источник регистрации | FQCN в конфиге **или** тег контейнера в `register()` provider'а **или** `->register()` до `app->booted()` |
| Замыкания в конфиге | Запрещены (config:cache) |
| Ключ | `vendor/name`, `^[a-z0-9-]+/[a-z0-9-]+$`; префикс `vaulter/` зарезервирован за first-party |
| Дубликат ключа | `Duplicate*Exception` при регистрации; замена — только явный `replace(key, impl)` с логом `info` |
| Порядок | Детерминирован: priority DESC, затем key ASC; не зависит от порядка provider'ов |
| Freeze | На `app->booted()` реестр замораживается; `register()` после — `RegistryFrozenException`. Octane: реестр singleton, неизменяем, безопасен |
| Ошибка FQCN | Не класс / не реализует контракт → `InvalidConfigurationException` при boot (все окружения) |
| Сериализация наружу | Только скалярные дескрипторы; FQCN и PHP-объекты в API не уходят |
| Контрактные тесты | `Vaulter\Testing\Contracts\<Point>ContractTests` (Pest-датасеты/трейты) |

## 2. File handler (core)

```php
namespace Vaulter\Contracts\Handlers;

interface FileHandler
{
    public function key(): string;                                   // было id(); 'vendor/name'
    /** @return list<string> точные MIME или 'type/*' */
    public function mimeTypes(): array;
    /** @return list<string> расширения без точки, lower-case */
    public function extensions(): array;
    public function priority(): int;                                 // builtin: preview 10, download 0
    /** Не должен зависеть от полей дескриптора, кроме MIME и расширения (мемоизация). */
    public function capabilities(FileDescriptor $file): ContentCapabilities;
    public function viewer(FileDescriptor $file): ViewerDescriptor;
}

interface FileHandlerRegistry
{
    public function register(FileHandler|string $handler): void;
    public function replace(string $key, FileHandler|string $handler): void;
    public function resolve(FileDescriptor $file, Capability $capability = Capability::Download): FileHandler;
    /** @return list<FileHandler> */
    public function all(): array;
}
```

- Регистрация: `config('vaulter.handlers')` (список FQCN) + тег `vaulter.file_handlers` + `Vaulter::handlers()->register()`.
- Разрешение: MIME (детектированный, затем заявленный) → wildcard → расширение → `vaulter/download` (всегда
  последний, всегда есть).
- `ContentCapabilities { editable, previewable, downloadable, thumbnailable, collaborative }` (без `richText`).
- `ViewerDescriptor { key: string, props: array<string, scalar|array> }`.
- Только для `kind = file`. Для документов презентацию даёт тип документа (§3), для папок — фиксированная
  (`vaulter/folder`).
- `NodePresenter::present(Node): NodePresentation { handler: ?string, documentType: ?string, typeAvailable: bool,
  capabilities: ContentCapabilities, viewer: ?ViewerDescriptor }`.
- Контрактные тесты: уникальность ключа, стабильность capabilities для одинаковых (mime, ext), viewer key по
  формату, отсутствие исключений на пустом MIME/расширении.

## 3. Document type (documents)

```php
namespace Vaulter\Documents\Contracts;

interface DocumentType
{
    public function key(): string;                 // 'vaulter/rich-text'
    public function label(): string;
    public function schemaVersion(): int;          // >= 1; текущая версия, в которой пишется контент
    public function codec(): DocumentCodec;
    public function defaultContent(): DocumentContent;
    public function viewer(): ViewerDescriptor;    // ключ клиентского редактора
    public function capabilities(): ContentCapabilities;
}

interface DocumentCodec
{
    /** Проверить и нормализовать сырой ввод клиента. @throws InvalidDocumentContentException */
    public function decode(array $raw, int $schemaVersion): DocumentContent;
    public function maxSizeBytes(): ?int;          // null = глобальный documents.max_content_size
}

interface DocumentContent
{
    public function toArray(): array;
    public function contentHash(): string;         // sha256 канонической сериализации
    public function sizeInBytes(): int;
}

/** Необязательный: тип поддерживает совместное редактирование. */
interface SupportsCollaboration
{
    public function collaborationProtocol(): string;                     // 'yjs'
    /** JSON-проекция из бинарного состояния (для экспорта/поиска) или null, если хаб присылает JSON сам. */
    public function projectFromState(string $state): ?DocumentContent;
}

/** Необязательный: тип умеет поднимать старые версии схемы. */
interface UpgradesSchema
{
    /** Поднять контент с $fromVersion до schemaVersion(). Идемпотентно. */
    public function upgrade(array $content, int $fromVersion): array;
}
```

Реестр:

```php
namespace Vaulter\Documents\Types;

final class DocumentTypeRegistry
{
    public function register(DocumentType|string $type): void;
    public function replace(string $key, DocumentType|string $type): void;
    public function has(string $key): bool;
    public function get(string $key): DocumentType;          // @throws UnknownDocumentTypeException
    public function find(string $key): ?DocumentType;        // null для удалённых расширений
    /** @return array<string, DocumentType> */
    public function all(): array;
}
```

Жизненный цикл контента:

| Шаг | Поведение |
|---|---|
| create | тип должен быть зарегистрирован и разрешён профилем; контент — `codec()->decode()` или `defaultContent()`; `v_documents.type`, `schema_version = schemaVersion()` |
| update | `decode(raw, schemaVersion())`; ревизия пишется с текущей `schema_version` |
| read старой ревизии | если `revision.schema_version < type.schemaVersion()` и тип `UpgradesSchema` — upgrade на лету при чтении (не переписывая историю); иначе отдаётся как есть |
| тип отсутствует | `DocumentSnapshot::typeAvailable = false`, контент `OpaqueContent`, viewer `vaulter/unsupported`; edit/export/collab → `DocumentTypeUnavailableException` |
| удаление расширения | данные не трогаются; doctor показывает документы неизвестных типов (`vaulter:doctor` → warning с количеством) |

Встроенные типы:

| Ключ | Codec | Коллаборация | Экспорт | Viewer |
|---|---|---|---|---|
| `vaulter/rich-text` | `TipTapCodec` (схема: allow-list nodes/marks + `tiptap.extra_*`) | Yjs | html, markdown (с потерями) | `vaulter/tiptap` (props: `preset: rich-text`) |
| `vaulter/markdown` | `TipTapCodec` с ограниченной схемой (узлы, представимые в Markdown) | Yjs | markdown (без потерь), html | `vaulter/tiptap` (props: `preset: markdown`) |
| `vaulter/spreadsheet` | `OpaqueJsonCodec` (JSON-объект, лимит размера) | нет | — (T2: xlsx/csv) | `vaulter/univer` |

Контрактные тесты: `decode(defaultContent()->toArray())` round-trip; hash детерминирован; `maxSizeBytes`
соблюдается; `upgrade()` идемпотентен; ключ по формату.

## 4. Exporter (documents)

```php
interface DocumentExporter
{
    public function key(): string;                 // 'vaulter/html'
    public function format(): string;              // 'html' — сегмент URL /export/{format}
    public function mimeType(): string;
    public function extension(): string;
    /** @return list<string> ключи типов документов */
    public function documentTypes(): array;
    public function export(DocumentContent $content, DocumentType $type): string;
}
```

`DocumentExporterRegistry::for(string $typeKey, string $format): DocumentExporter`, `formatsFor(string $typeKey): list<string>`.
Коллизия — два экспортёра на одну пару (тип, формат) без `replace()` → исключение при boot.

## 5. Permission driver (core)

```php
namespace Vaulter\Contracts\Access;

interface PermissionDriver
{
    /** Решение ролевого слоя хоста для субъекта в области; null — «нечего сказать». Может только повысить allow. */
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision;

    /** Абсолютный override (super-admin allow / blanket deny); null — нет. */
    public function override(Authorizable $actor, AccessScope $scope): ?Effect;

    /** @return list<Subject> пользователь + роли/команды хоста, чьи grants применяются. */
    public function subjectsFor(Authorizable $actor): array;

    /** Может ли actor создавать узлы в drive данного владельца (создание без существующего узла). */
    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool;
}

final readonly class AccessScope
{
    public function __construct(
        public string $profile,
        public OwnerRef $driveOwner,        // host owner drive (было workspaceType/workspaceId)
        public int|string $driveId,
        public ?Subject $nodeOwner = null,
        public int|string|null $tenantId = null,
    ) {}
}
```

- Встроенные ключи: `database`, `gate`, `spatie` (core), `azgard` (vaulter-azgard регистрирует ключ сам),
  `null` (только тесты; в production — исключение boot).
- Маркер `GrantAuthoritativeDriver` остаётся, но больше **не** влияет на видимость листинга (D12 — точная
  оценка для всех драйверов); используется только как оптимизация кандидатов SQL.
- Выбор драйвера: `Profile::permissionDriver()` → иначе `access.permission_driver`.
- Контрактные тесты: одинаковые решения `AccessResolver` на общем фикстуре для любого драйвера
  (существующий тест превращается в публичный `PermissionDriverContractTests`).

## 6. Tenancy (core)

```php
namespace Vaulter\Contracts\Tenancy;

interface TenantResolver
{
    /** Текущий тенант или null (null при включённой tenancy → отказ операции). */
    public function current(): int|string|null;
}
```

```php
/** Необязательный: резолвер умеет назвать тенант модели-владельца (проверка при provisioning). */
interface ResolvesOwnerTenant
{
    public function tenantOf(Model $owner): int|string|null;
}
```

Регистрация: `vaulter.tenancy.resolver` (FQCN). Готовые реализации пакет не поставляет; документация даёт
рецепты для `spatie/laravel-multitenancy` и `stancl/tenancy` (по 10 строк).

## 7. Загрузки (drive)

```php
namespace Vaulter\Drive\Contracts\Uploads;

interface UploadInspector
{
    public function key(): string;
    public function priority(): int;               // выполняются по priority DESC
    /** @throws UploadRejectedException */
    public function inspect(UploadFacts $facts, UploadPolicy $policy, OperationContext $context): void;
}

final readonly class UploadFacts   // Vaulter\Drive\Data\Files\UploadFacts
{
    public function __construct(
        public string $name, public string $extension, public int $size, public string $sha256,
        public ?string $detectedMimeType, public ?string $declaredMimeType, public string $stagedPath,
    ) {}
}
```

Встроенные (тег `vaulter.upload_inspectors`): `FilenameInspector` (100), `SizeInspector` (90),
`MimeConsistencyInspector` (80), `ScanDispatchInspector` (0 — выставляет `scan_status = pending` и ставит job).

AV-шов (core):

```php
namespace Vaulter\Contracts\Cas;

interface ContentScanner
{
    public function key(): string;
    public function scan(Blob $blob, BlobStore $store): ScanVerdict;   // enum: Clean, Infected, Failed
}
```

`vaulter.uploads.scan.scanner` (FQCN или null). Таймаут/исключение сканера → `Failed`, не `Clean`.

## 8. Содержимое узла по kind (core)

`NodeContentLifecycle` — [D17](02-decisions.md#d17). Регистрация тегом `vaulter.content_lifecycles`;
одна реализация на kind (дубликат → исключение при boot). `CopyResult { int $addedBytes }`.

## 9. CAS (core-контракты, media-реализации)

| Контракт | Изменение |
|---|---|
| `ContentStore` | `keyFor(BlobScope $scope, string $sha256): string` вместо `(string $scope, …)` |
| `StorageDriver` | без изменений |
| `BlobStore` | `commit(mixed $stream, BlobScope $scope, ?string $disk = null): Blob`; `attach(FileRevision, Blob, array $attributes = [])`; `detach(FileRevision, Blob, …)`; `read(Blob): mixed` |

Замена реализаций — `vaulter-media.content_store|storage_driver|blob_store`.

## 10. Прочие заменяемые сервисы

| Контракт | Ключ конфигурации | По умолчанию |
|---|---|---|
| `ShareTokenGenerator` | `vaulter.sharing.token_generator` | `RandomShareTokenGenerator` |
| `OutboundUrlGuard` | `vaulter.webhooks.delivery.url_guard` | `DefaultOutboundUrlGuard` |
| `MetricsRecorder` / `TraceRecorder` | `vaulter.observability.*` | null-реализации |
| `CollaborationDriver` | `vaulter-documents.collaboration.driver` | `hocuspocus` |
| `HostUserResolver`, `ExportFilenameResolver` | bind в контейнере | default-реализации |
| `IdStrategy` | `vaulter.ids.strategies` | bigint/ulid/uuid7 |
| `DoctorCheck` | тег `vaulter.doctor_checks` | проверки пакетов |
| Модели | `vaulter.models.*` (kernel), `vaulter-drive.models.*`, `vaulter-documents.models.*` | классы пакетов; подкласс обязан наследовать базовую модель (проверка boot) |

## 11. Клиентские viewers (Vue)

Сервер отдаёт `viewer.key`; клиент сопоставляет ключ компоненту через реестр экземпляра приложения
([11-frontend-sdk.md](11-frontend-sdk.md)). Правило: бэкенд-расширение **никогда** не называет Vue-компонент;
пакет расширения поставляет пару «PHP-тип/обработчик + npm-модуль с компонентом под тем же ключом».

## 12. Генераторы

`vaulter:make:file-handler`, `vaulter:make:document-type`, `vaulter:make:exporter`,
`vaulter:make:permission-driver`, `vaulter:make:profile` создают класс, регистрацию (строка для конфига или
provider) и Pest-тест, подключающий соответствующий контрактный набор.
