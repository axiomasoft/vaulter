# 05 — Публичный PHP API (нормативные сигнатуры)

Все сигнатуры ниже — целевые для 0.3/1.0. Типы — PHP 8.4, `declare(strict_types=1)`.
Где метод бросает исключение, указан класс; HTTP-статус — в [10-http-api-v1.md](10-http-api-v1.md) §6.
Решения: [D06](02-decisions.md#d06)–[D09](02-decisions.md#d09), [D13](02-decisions.md#d13),
[D17](02-decisions.md#d17)–[D19](02-decisions.md#d19), [D23](02-decisions.md#d23), [D27](02-decisions.md#d27).

## 1. Как это выглядит у хоста

```php
use Vaulter\Data\Drive\{UploadSource, UploadOptions, ListOptions};
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Facades\Vaulter;

// Контроллер хоста: actor = текущий пользователь guard'а
$drive = Vaulter::drive($project, profile: 'project-files');

$folder = $drive->folders()->create('Contracts');
$file   = $drive->files()->upload(
    UploadSource::fromUploadedFile($request->file('file')),
    UploadOptions::make()->parent($folder)->onConflict(OnNameConflict::Rename),
);
$page   = $drive->nodes()->children($folder, ListOptions::make()->perPage(50));
$drive->nodes()->move($file, destination: $archive);
$drive->nodes()->trash($file);
$drive->trash()->restore($file);

// Очередь: пользователя нет — выбрать явно
Vaulter::drive($project)->actingAs($user)->nodes()->rename($nodeId, 'Final.pdf');
Vaulter::drive($project)->asSystem('import:legacy-crm')->files()->upload(UploadSource::fromPath($path));

// Модель хоста с trait HasDrives
$project->drive()->folders()->create('Specs');
```

## 2. Фасад и менеджер

```php
namespace Vaulter;

final class VaulterManager
{
    /**
     * Owner model → get-or-create drive (owner, profile) и вернуть handle.
     * Drive model   → handle на этот drive; если $profile передан и не совпадает с drive.profile → InvalidArgumentException.
     * @throws UnknownProfileException, ProfileRestrictionException (класс владельца не разрешён профилем),
     *         MissingPackageException (нет vaulter-drive), TenancyMisconfiguredException
     */
    public function drive(Model $ownerOrDrive, ?string $profile = null): DriveHandle;

    /** Найти drive без создания. */
    public function findDrive(Model $owner, ?string $profile = null): ?DriveHandle;

    public function profiles(): ProfileRegistry;
    public function handlers(): FileHandlerRegistry;
    public function permissions(): PermissionDriverManager;

    /** Выполнить замыкание без tenant-фильтра (только команды обслуживания). */
    public function withoutTenancy(Closure $callback): mixed;
}
```

Фасад `Vaulter\Facades\Vaulter` — `@method`-docblock ровно для этих методов. Удалены:
`for`, `forStorage`, `panel`, `panels`, `registerPanel`, `registerDocumentType`, `documentTypes`,
`registerHandler`, `registerExporter`, `exporter`, `exporterFormats`.

Фасад пакета documents:

```php
namespace Vaulter\Documents\Facades;

/**
 * @method static DocumentTypeRegistry     types()
 * @method static DocumentExporterRegistry exporters()
 * @method static DocumentOperations       in(DriveHandle $drive)
 * @method static CommentOperations        comments(DriveHandle $drive)
 */
final class Documents extends Facade {}
```

## 3. Drive handle (контракт core)

```php
namespace Vaulter\Contracts\Drive;

interface DriveHandle
{
    public function model(): Drive;
    public function profile(): Profile;

    /** Разрешённый actor операции. @throws MissingActorException */
    public function actor(): Actor;

    /** Новый handle с явным пользователем. */
    public function actingAs(Authorizable $user): static;

    /** Новый handle, обходящий ACL (не tenancy и не инварианты). $reason — непустая строка ≤ 191. */
    public function asSystem(string $reason): static;

    /** Новый handle с заданным correlation id (иначе — из запроса или новый ULID). */
    public function withCorrelationId(string $correlationId): static;

    /** Корневая папка (создаётся при provisioning). */
    public function root(): Node;

    public function nodes(): NodeOperations;
    public function folders(): FolderOperations;
    public function files(): FileOperations;
    public function trash(): TrashOperations;
    public function access(): AccessOperations;
    public function shares(): ShareOperations;
    public function activity(): ActivityOperations;

    /**
     * Операции пакетов-расширений, связанные с этим handle.
     * @template T of object
     * @param class-string<T> $operations
     * @return T
     * @throws MissingPackageException
     */
    public function extension(string $operations): object;
}
```

Реализация: `Vaulter\Drive\DriveHandle` — `final readonly`, иммутабельна (`actingAs()` и т. п. возвращают клон),
не хранит результатов авторизации между вызовами. `extension()` резолвит класс из контейнера с параметром
`handle: $this`.

`Node|int|string` в сигнатурах ниже обозначен как `NodeKey` (в коде — union-тип, не alias).

## 4. Sub-API: узлы, папки, файлы, корзина

```php
interface NodeOperations
{
    /** @throws NodeNotFoundException (нет узла в drive или нет View) */
    public function find(NodeKey $node): Node;

    /** Дети папки (null — корень), только видимые (D12). */
    public function children(NodeKey|null $folder = null, ?ListOptions $options = null): NodePage;

    /** Поиск по имени в drive, только видимые. */
    public function search(string $term, ?SearchOptions $options = null): NodePage;

    /** @throws NodeNameConflictException, StaleNodeException, InvalidDestinationException (корень) */
    public function rename(NodeKey $node, string $name, ?int $expectedSequence = null): Node;

    /** Destination null — корень. @throws NodeCycleException, InvalidDestinationException, NodeNameConflictException, StaleNodeException */
    public function move(NodeKey $node, NodeKey|null $destination, ?MoveOptions $options = null): Node;

    /** Глубокая копия через NodeContentLifecycle. @throws QuotaExceededException, UnsupportedNodeKindException */
    public function copy(NodeKey $node, NodeKey|null $destination = null, ?CopyOptions $options = null): Node;

    /** pin / position. */
    public function update(NodeKey $node, UpdateNodeOptions $options): Node;

    /** В корзину (поддерево). */
    public function trash(NodeKey $node, ?int $expectedSequence = null): Node;

    /** Флаги способностей actor'а для UI. */
    public function abilities(NodeKey $node): NodeAbilities;
}

interface FolderOperations
{
    /** @throws NodeNameConflictException, InvalidDestinationException */
    public function create(string $name, NodeKey|null $parent = null, ?CreateFolderOptions $options = null): Node;
}

interface FileOperations
{
    /** @throws UploadRejectedException, QuotaExceededException, NodeNameConflictException, InvalidDestinationException */
    public function upload(UploadSource $source, ?UploadOptions $options = null): Node;

    /** Новая ревизия существующего файла. @throws NotAFileException, StaleNodeException, … как upload */
    public function addRevision(NodeKey $file, UploadSource $source, ?RevisionOptions $options = null): FileRevision;

    /** @return CursorPaginator<int, FileRevision> */
    public function revisions(NodeKey $file, ?ListOptions $options = null): CursorPaginator;

    /** Новая голова = копия старой ревизии (история не переписывается). */
    public function restoreRevision(NodeKey $file, FileRevision|int|string $revision): FileRevision;

    /** Поток байтов для скачивания; ревизия null — голова. @throws ContentUnavailableException (scan), PermissionDeniedException (download) */
    public function read(NodeKey $file, FileRevision|int|string|null $revision = null): FileStream;
}

interface TrashOperations
{
    public function list(?ListOptions $options = null): NodePage;

    /** @throws NodeNameConflictException (если RestoreOptions::onConflict = Fail), InvalidDestinationException */
    public function restore(NodeKey $node, ?RestoreOptions $options = null): Node;

    /** Только для узла в корзине. @throws NodeNotTrashedException */
    public function purge(NodeKey $node): void;

    /** Purge всех узлов корзины, к которым у actor'а есть Delete. Возвращает число корней. */
    public function empty(): int;
}
```

Способности, проверяемые `OperationGuard` для каждой операции:

| Операция | Проверка |
|---|---|
| `find`, `children` (папка), `revisions`, `abilities` | `View` |
| `read` | `Download` (+ scan status) |
| `rename`, `update` | `Edit` |
| `move` | `Move` на узле + `Create` на назначении |
| `copy` | `Copy` на узле + `Create` на назначении |
| `folders()->create`, `files()->upload` | `canCreateIn(drive)` + `Create` на родителе |
| `addRevision`, `restoreRevision` | `Edit` на файле |
| `trash` | `Trash` |
| `trash()->restore` | `Restore` + `Create` на назначении, если оно не исходный родитель |
| `trash()->purge`, `empty` | `Delete` |

## 5. Sub-API ядра: доступ, шаринг, activity

```php
interface AccessOperations
{
    /** @return Collection<int, NodeGrant> прямые grants узла. Требует Share. */
    public function grants(NodeKey $node): Collection;

    /** @throws PermissionDeniedException (нет Share), UnenumerableGrantSubjectException */
    public function grant(NodeKey $node, GrantInput $grant): NodeGrant;

    public function revoke(NodeKey $node, NodeGrant|int|string $grant): void;

    /** Диагностика: почему $subject (по умолчанию actor) имеет/не имеет способность. Требует Manage. */
    public function explain(NodeKey $node, Ability $ability, ?Authorizable $subject = null): AccessExplanation;
}

interface ShareOperations
{
    /** @return Collection<int, NodeShare> */
    public function list(NodeKey $node): Collection;

    /** Сырой токен возвращается один раз в MintedShareLink. Требует Share. */
    public function create(NodeKey $node, ShareLinkInput $input): MintedShareLink;

    public function revoke(NodeKey $node, NodeShare|int|string $share): void;
}

interface ActivityOperations
{
    /** @return CursorPaginator<int, Activity> события drive, видимые actor'у. */
    public function forDrive(?ListOptions $options = null): CursorPaginator;

    /** @return CursorPaginator<int, Activity> требует View на узле. */
    public function forNode(NodeKey $node, ?ListOptions $options = null): CursorPaginator;
}
```

## 6. Входные и выходные типы (core `Vaulter\Data\Drive\*`)

```php
final readonly class UploadSource
{
    public static function fromUploadedFile(UploadedFile $file): self;              // имя и заявленный MIME клиента
    public static function fromPath(string $path, ?string $name = null): self;      // пакет открывает и закрывает
    /** @param resource $stream  пакет читает до конца, НЕ закрывает; non-seekable допустим */
    public static function fromStream($stream, string $name, ?int $size = null): self;
    public static function fromString(string $bytes, string $name): self;
    public static function fromPsrStream(StreamInterface $stream, string $name): self; // чтение чанками

    public function name(): string;
    public function declaredMimeType(): ?string;
    public function declaredSize(): ?int;   // подсказка; реальный размер считается при staging
    /** @internal вызывается только IngestFileAction/StagedUpload */
    public function openForReading(): mixed;
    /** @internal */
    public function releaseIfOwned(mixed $handle): void;
}

final readonly class UploadOptions   // fluent-with-клоны: make()->parent()->name()->onConflict()->declaredMimeType()
{
    public function __construct(
        public Node|int|string|null $parent = null,
        public ?string $name = null,                       // null → UploadSource::name()
        public OnNameConflict $onConflict = OnNameConflict::Fail,
        public ?string $declaredMimeType = null,
    ) {}
}

final readonly class RevisionOptions { public function __construct(public ?int $expectedSequence = null, public ?string $declaredMimeType = null) {} }
final readonly class CreateFolderOptions { public function __construct(public OnNameConflict $onConflict = OnNameConflict::Fail) {} }
final readonly class MoveOptions { public function __construct(public ?int $expectedSequence = null, public OnNameConflict $onConflict = OnNameConflict::Fail) {} }
final readonly class CopyOptions { public function __construct(public ?string $name = null, public OnNameConflict $onConflict = OnNameConflict::Rename) {} }
final readonly class RestoreOptions { public function __construct(public Node|int|string|null $destination = null, public OnNameConflict $onConflict = OnNameConflict::Rename) {} }
final readonly class UpdateNodeOptions { public function __construct(public ?bool $pinned = null, public ?int $position = null, public ?int $expectedSequence = null) {} }

final readonly class ListOptions
{
    public function __construct(
        public int $perPage = 50,                 // 1..200, иначе InvalidArgumentException
        public ?string $cursor = null,
        public NodeSort $sort = NodeSort::Default, // position ASC, name ASC, id ASC
        public bool $descending = false,
        /** @var list<NodeKind> */ public array $kinds = [],
    ) {}
    public static function make(): self;          // + fluent perPage()/cursor()/sort()/kinds()
}

final readonly class SearchOptions { public function __construct(public int $perPage = 50, public ?string $cursor = null, public NodeKey|null $within = null) {} }

/** @implements IteratorAggregate<int, Node> */
final readonly class NodePage implements IteratorAggregate, Countable, JsonSerializable
{
    /** @param list<Node> $items */
    public function __construct(public array $items, public ?string $nextCursor, public int $scanned) {}
    public function hasMore(): bool;               // nextCursor !== null
}

final readonly class FileStream
{
    /** @param resource $stream */
    public function __construct(public mixed $stream, public string $filename, public string $mimeType, public int $size, public string $sha256, public FileRevision $revision) {}
}

enum OnNameConflict: string { case Fail = 'fail'; case Rename = 'rename'; case AddRevision = 'add_revision'; }
enum NodeSort: string { case Default = 'default'; case Name = 'name'; case UpdatedAt = 'updated_at'; case Size = 'size'; }
```

`OnNameConflict::AddRevision` допустим только в `UploadOptions` и только если существующий узел — файл;
иначе `InvalidArgumentException`. `Rename` генерирует `name (2).ext`, `name (3).ext`, … в той же транзакции
(повтор при гонке — ретрай по unique-нарушению до 5 раз).

## 7. Контекст операции и actor

```php
namespace Vaulter\Operations;

final readonly class OperationContext
{
    public function __construct(
        public Drive $drive,
        public Actor $actor,
        public string $correlationId,
        public ?string $idempotencyKey = null,
    ) {}

    /** Для jobs/команд без handle. */
    public static function system(Drive $drive, string $reason, ?string $correlationId = null): self;
}

namespace Vaulter\ValueObjects\Access;

final readonly class Actor
{
    public static function user(Authorizable $user): self;
    public static function system(string $reason): self;

    public function isSystem(): bool;
    public function authorizable(): ?Authorizable;
    public function ref(): ActorRef;              // для записи в БД/события
}

final readonly class ActorRef                   // сериализуемое представление
{
    public function __construct(public ?string $type, public int|string|null $id, public ?string $reason = null) {}
    public const string SYSTEM_TYPE = 'vaulter:system';
}
```

Правило jobs: job сериализует `ActorRef` и `drive_id`; при исполнении восстанавливает пользователя через
`Auth::getProvider()->retrieveById()` (или system) и строит handle заново; права проверяются **на момент
исполнения**, а не переносятся из момента dispatch.

## 8. Профили

```php
namespace Vaulter\Profiles;

abstract class ProfileProvider
{
    abstract public function profile(Profile $profile): Profile;
}

final class Profile   // fluent builder; ProfileRegistry замораживает (clone + readonly-снимок) после boot
{
    public function key(string $key): static;                         // ^[a-z0-9][a-z0-9-]{0,63}$
    public function owners(string ...$modelClasses): static;          // пусто = любой Model
    public function disk(string $disk): static;
    public function defaultQuota(?int $bytes): static;
    public function uploads(UploadPolicy $policy): static;
    public function permissionDriver(string $driverKey): static;      // ключ из access.permission_drivers
    public function documentTypes(string ...$typeKeys): static;       // пусто = все зарегистрированные
    public function grants(bool $enabled = true): static;
    public function shareLinks(bool $enabled = true): static;
    public function comments(bool $enabled = true): static;
    public function revisionLimit(?int $max): static;                 // null = без лимита, keep_forever не считается
    public function trashRetentionDays(?int $days): static;

    // readers: getKey(), getOwners(), getDisk(), getDefaultQuota(), getUploadPolicy(), getPermissionDriver(),
    //          getDocumentTypes(), grantsEnabled(), shareLinksEnabled(), commentsEnabled(), getRevisionLimit(), getTrashRetentionDays()
}

final readonly class UploadPolicy
{
    public function __construct(
        public ?int $maxFileSize = null,                   // байты; null = глобальный лимит
        /** @var list<string> */ public array $allowedMimeTypes = [],   // пусто = все, кроме blocked; поддерживает 'image/*'
        /** @var list<string> */ public array $blockedExtensions = [],  // добавляется к глобальному списку
        public ?bool $requireScan = null,                  // null = глобальная настройка
    ) {}
}

final class ProfileRegistry
{
    public function register(ProfileProvider|Profile|string $profile): void;   // FQCN из конфига
    public function get(string $key): Profile;                                  // @throws UnknownProfileException
    public function default(): Profile;
    /** @return array<string, Profile> */
    public function all(): array;
}
```

Эффективное значение фичи = глобальный флаг (`vaulter.features.*`) ∧ флаг профиля. Профиль не может включить
то, что выключено глобально (ошибка валидации при boot).

## 9. Trait для моделей хоста

```php
namespace Vaulter\Concerns;

trait HasDrives
{
    public function drive(?string $profile = null): DriveHandle;       // Vaulter::drive($this, $profile)
    /** @return MorphMany<Drive, $this> */
    public function drives(): MorphMany;
    public static function bootHasDrives(): void;                      // при vaulter.drives.delete_with_owner — DeleteDriveAction на deleted
}
```

## 10. Documents API (пакет documents)

```php
namespace Vaulter\Documents\Operations;

final readonly class DocumentOperations
{
    public function __construct(DriveHandle $handle, /* зависимости из контейнера */);

    /** @throws UnknownDocumentTypeException, ProfileRestrictionException, InvalidDocumentContentException, … */
    public function create(string $name, string $type = 'vaulter/rich-text', ?array $content = null, NodeKey|null $parent = null, ?CreateFolderOptions $options = null): Node;

    /** Голова или указанная ревизия. Требует View. */
    public function content(NodeKey $document, DocumentRevision|int|string|null $revision = null): DocumentSnapshot;

    /** Автосохранение: обновляет автосейв-голову в окне debounce или создаёт ревизию. Требует Edit. */
    public function update(NodeKey $document, array $content, ?int $expectedSequence = null): DocumentRevision;

    /** Именованная ревизия. Требует Edit. */
    public function snapshot(NodeKey $document, ?string $label = null, bool $keepForever = false): DocumentRevision;

    /** @return CursorPaginator<int, DocumentRevision> */
    public function revisions(NodeKey $document, ?ListOptions $options = null): CursorPaginator;

    /** Новая голова = копия ревизии; увеличивает collab_epoch. Требует Edit. */
    public function restore(NodeKey $document, DocumentRevision|int|string $revision): DocumentRevision;

    public function setRetention(NodeKey $document, DocumentRevision|int|string $revision, bool $keepForever): DocumentRevision;

    /** @throws UnknownExportFormatException, DocumentTypeUnavailableException. Требует Download. */
    public function export(NodeKey $document, string $format): DocumentExport;

    /** Токен для хаба (Edit → read-write, View → read-only). */
    public function collaborationSession(NodeKey $document): CollaborationSession;
}

final readonly class CommentOperations
{
    public function list(NodeKey $node, ?ListOptions $options = null): CursorPaginator;          // View
    public function add(NodeKey $node, AddCommentInput $input): NodeComment;                      // Comment
    public function resolve(NodeKey $node, NodeComment|int|string $comment): NodeComment;         // Comment (автор) или Edit
    public function delete(NodeKey $node, NodeComment|int|string $comment): void;                 // автор или Manage
}

final readonly class DocumentSnapshot
{
    public function __construct(
        public Document $document,
        public DocumentRevision $revision,
        public string $type,
        public int $schemaVersion,
        public DocumentContent $content,
        public bool $typeAvailable,     // false → тип не зарегистрирован, контент непрозрачный
    ) {}
}
```

## 11. Исключения публичного API

Все наследуют `Vaulter\Exceptions\VaulterException` и реализуют `ProblemException` (D29).

| Исключение | Пакет | Код | Когда |
|---|---|---|---|
| `MissingActorException` | core | `actor_required` | нет пользователя и не выбран `asSystem` |
| `NodeNotFoundException` | core | `node_not_found` | нет узла, другой drive/tenant, нет `View` |
| `PermissionDeniedException` | core | `permission_denied` | есть `View`, нет способности |
| `NodeNameConflictException` | core | `node_name_conflict` | конфликт имени при `Fail` |
| `StaleNodeException` | core | `node_sequence_mismatch` | `expectedSequence` не совпал |
| `InvalidDestinationException` | core | `invalid_destination` | не папка, в корзине, корень как объект операции, другой drive |
| `UnsupportedNodeKindException` | core | `unsupported_node_kind` | kind без lifecycle / shortcut |
| `NodeNotTrashedException` | core | `node_not_trashed` | purge не из корзины |
| `ContentUnavailableException` | core | `content_unavailable` | scan pending/infected/failed |
| `UnknownProfileException` | core | `unknown_profile` | |
| `ProfileRestrictionException` | core | `profile_restriction` | владелец/тип/фича запрещены профилем |
| `NodeCycleException` | drive | `node_move_cycle` | |
| `CrossDriveOperationException` | drive | `cross_drive_operation` | |
| `QuotaExceededException` | drive | `quota_exceeded` | |
| `UploadRejectedException` | drive | `upload_rejected` (+ `reason`: `too_large`, `mime_not_allowed`, `mime_mismatch`, `extension_blocked`, `name_invalid`, `infected`) | |
| `NotAFileException` | drive | `not_a_file` | |
| `NotADocumentException` | documents | `not_a_document` | |
| `UnknownDocumentTypeException` | documents | `unknown_document_type` | |
| `DocumentTypeUnavailableException` | documents | `document_type_unavailable` | тип удалён из установки |
| `InvalidDocumentContentException` | documents | `invalid_document_content` (+ `reason`) | |
| `DocumentRevisionNotFoundException` | documents | `document_revision_not_found` | |
| `UnknownExportFormatException` | documents | `unknown_export_format` | |
| `CollaborationEpochMismatchException` | documents | `collaboration_epoch_mismatch` | store со старым epoch |
