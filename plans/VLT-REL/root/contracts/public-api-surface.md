# VLT-REL — Public API Surface (SSOT-снапшот заморозки 0.1.0)

> Дизайн-артефакт фазы P2 (item **P2.1**). ЕДИНСТВЕННЫЙ человекочитаемый SSOT публичной
> composer-поверхности vaulter на момент тега `0.1.0`: каждый публичный интерфейс-контракт (26)
> с полной сигнатурой методов, единственный фасад + его accessor, host-facing config-ключи,
> публикуемые ресурсы и биндинги контрактов. Из этого дока **P2.2** генерирует per-package golden
> snapshot-регрессии — пропущенный метод здесь = дыра в заморозке.
>
> **Граница** взята из `.claude/rules/public-api.md` (`paths: **/src/Contracts/**`,
> `**/src/**/Facades/**`) + `root/cut-line.md` §2 — НЕ переизобретается. **Значения** config-дефолтов
> НЕ морозятся (только присутствие ключа + публичность; `cut-line.md` §2). Порядок детерминированный:
> пакет → namespace (alpha) → интерфейс (alpha по FQCN) → методы в порядке объявления.
>
> **Якорь**: разведка на `git rev-parse --short HEAD` = дерево коммита P2.1; `git tag | wc -l` = 0
> (первый тег). Каждый несущий тезис — `RAG:—` (repo-grounded, якорь `файл:строка`); внешних фактов нет.
>
> **Автоматический гейт морозит ТОЛЬКО раздел 1 (интерфейсы) + раздел 2 (фасад)** — D5. Разделы 3–5
> (config / публикуемые ресурсы / биндинги) держатся ЭТИМ доком + review-правилом `public-api.md` +
> §2 D#-дисциплиной плана, НЕ рефлексией (D5).

---

## 0. Сводка поверхности

| Категория | Кол-во | Замораживается |
|:--|:--:|:--|
| Интерфейсы-контракты (`**/src/Contracts/**`) | **26** | P2.2 рефлексией (сигнатуры) |
| Фасад (`Vaulter\Facades\Vaulter`) | **1** | P2.2 рефлексией (`@method` / резолвящийся менеджер) |
| Host-facing config-файлы | **6** (3 публикуемых + 3 merge-only) | доком + `public-api.md` (D5) |
| Публикуемые миграции (`v_*`) | **19** (только core) | доком + `public-api.md` (D5) |
| Биндинги контрактов в ServiceProvider | 6 провайдеров | доком + `public-api.md` (D5) |

Разбивка 26 контрактов по пакетам: **core 21** · **drive 1** · **documents 3** · **azgard 1** ·
media 0 · filament 0. `RAG:—` (`find packages/{core,drive,documents,media,filament,azgard}/src/Contracts
-name '*.php' | grep -v vendor | wc -l` = 26).

---

## 1. Интерфейсы-контракты (замораживаются P2.2)

### 1.1 `axioma-studio/vaulter-core` (21) — namespace-корень `Vaulter\Contracts\*`

#### `Vaulter\Contracts` (root)

##### `Vaulter\Contracts\IdStrategy`
```php
public function key(Blueprint $table, string $column = 'id'): void;
public function reference(Blueprint $table, string $column, bool $nullable = false): void;
public function actor(Blueprint $table, string $column, bool $nullable = false): void;
public function cast(): string;
public function modelConcern(): ?string;                 // @return class-string|null
public function newId(): string|int|null;
```

#### `Vaulter\Contracts\Access`

##### `Vaulter\Contracts\Access\AzgardGuard`
```php
public function hasScopedPermission(string $permission, object $entity): bool;
public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;
public function isSuperAdmin(): bool;
```

##### `Vaulter\Contracts\Access\EnumeratesRoleSubjects`
```php
// pure marker — no methods (capability signal F-P1.2-01)
```

##### `Vaulter\Contracts\Access\GrantAuthoritativeDriver`
```php
public function isGrantAuthoritative(): bool;
```

##### `Vaulter\Contracts\Access\HostRoleDriver`
```php
public function resolve(Subject $subject, Authorizable $actor, AclScope $scope): ?Decision;
public function override(Authorizable $actor, AclScope $scope): ?Effect;
public function subjectsFor(Authorizable $actor): array;   // @return list<Subject>
public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool;
```

#### `Vaulter\Contracts\Cas`

##### `Vaulter\Contracts\Cas\BlobStore`
```php
public function commit(mixed $stream, ?int $tenantId = null, ?int $storageId = null, ?string $disk = null): Blob; // @param resource $stream
public function attach(FileVersion $version, Blob $blob, array $attributes = []): void; // @param array{role?:string,ordinal?:int,offset_bytes?:int|null,length_bytes?:int|null} $attributes
public function detach(FileVersion $version, Blob $blob, string $role = 'data', int $ordinal = 0): void;
public function read(Blob $blob): mixed;                   // @return resource|null
```

##### `Vaulter\Contracts\Cas\ContentStore`
```php
public function stage(mixed $stream): StagedObject;        // @param resource $stream
public function promote(StagedObject $staged, string $key, ?string $disk = null): void;
public function get(string $key, ?string $disk = null): mixed; // @return resource|null
public function exists(string $key, ?string $disk = null): bool;
public function delete(string $key, ?string $disk = null): void;
public function keyFor(string $scope, string $sha256): string;
```

##### `Vaulter\Contracts\Cas\ListableContentStore`
```php
public function lastModified(string $key, ?string $disk = null): ?int;
public function listKeys(?string $disk = null): iterable;  // @return iterable<string>
```

##### `Vaulter\Contracts\Cas\ListableStorageDriver`
```php
public function lastModified(string $key, ?string $disk = null): ?int;
public function listContents(?string $disk = null): iterable; // @return iterable<string>
```

##### `Vaulter\Contracts\Cas\StagedObject`
```php
public function path(): string;
public function sha256(): string;
public function sizeBytes(): int;
public function readStream(): mixed;                       // @return resource
public function discard(): void;
```

##### `Vaulter\Contracts\Cas\StorageDriver`
```php
public function disk(?string $disk = null): Filesystem;    // Illuminate\Contracts\Filesystem\Filesystem
public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool; // @param resource $stream, @param array<string,mixed> $options
public function get(string $key, ?string $disk = null): mixed; // @return resource|null
public function exists(string $key, ?string $disk = null): bool;
public function delete(string $key, ?string $disk = null): void;
public function temporaryUrl(string $key, \DateTimeInterface $expiresAt, ?string $disk = null): ?string;
```

#### `Vaulter\Contracts\Drive`

##### `Vaulter\Contracts\Drive\DriveHandle`
```php
public function storage(): Storage;
public function panel(): string;
public function root(): Node;
public function createFolder(string $name, ?Node $parent = null, int|string|null $actorId = null): Node;
public function upload(mixed $source, ?Node $into = null, ?string $name = null, ?string $mimeType = null, ?string $originalName = null, int|string|null $actorId = null): Node;
public function uploadVersion(Node $target, mixed $source, ?string $mimeType = null, ?string $originalName = null, int|string|null $actorId = null): Node;
public function move(Node $node, ?Node $into = null, int|string|null $actorId = null): Node;
public function rename(Node $node, string $name, int|string|null $actorId = null): Node;
public function copy(Node $node, ?Node $into = null, ?string $name = null, int|string|null $actorId = null): Node;
public function trash(Node $node, int|string|null $actorId = null): Node;
public function restore(Node $node, int|string|null $actorId = null): Node;
public function forceDelete(Node $node, int|string|null $actorId = null): void;
public function list(?Node $folder = null): Collection;    // @return Collection<int, Node>
```

##### `Vaulter\Contracts\Drive\DriveHandleFactory`
```php
public function forOwner(Model $entity, string $panel): DriveHandle; // Illuminate\Database\Eloquent\Model
public function forStorage(Storage $storage): DriveHandle;
```

#### `Vaulter\Contracts\Handlers`

##### `Vaulter\Contracts\Handlers\DocumentContent`
```php
public function toArray(): array;                          // @return array<string, mixed>
public function contentHash(): string;
public function sizeInBytes(): int;
```

##### `Vaulter\Contracts\Handlers\DocumentExporter`
```php
public function format(): string;
public function mimeType(): string;
public function extension(): string;
public function export(DocumentContent $content): string;
```

##### `Vaulter\Contracts\Handlers\FileHandler`
```php
public function id(): string;
public function mimeTypes(): array;                        // @return list<string>
public function extensions(): array;                       // @return list<string>
public function priority(): int;
public function capabilities(FileDescriptor $file): HandlerCapabilities;  // Vaulter\ValueObjects\Handlers\*
public function ui(FileDescriptor $file): HandlerUi;                      // Vaulter\ValueObjects\Handlers\*
```

##### `Vaulter\Contracts\Handlers\HandlerRegistry`
```php
public function register(FileHandler|string $handler): void; // @param FileHandler|class-string<FileHandler> $handler
public function all(): array;                              // @return list<FileHandler>
```

##### `Vaulter\Contracts\Handlers\NodeHandlerResolver`
```php
public function describe(Node $node, ?string $detectedMime = null): HandlerDescriptor; // Vaulter\ValueObjects\Handlers\HandlerDescriptor
```

#### `Vaulter\Contracts\Sharing`

##### `Vaulter\Contracts\Sharing\ShareTokenGenerator`
```php
public function generate(): string;
```

#### `Vaulter\Contracts\Webhooks`

##### `Vaulter\Contracts\Webhooks\HostResolver`
```php
public function resolve(string $host): array;              // @return list<string>
```

##### `Vaulter\Contracts\Webhooks\OutboundUrlGuard`
```php
public function isAllowed(string $url): void;              // @throws BlockedOutboundUrlException
public function pin(string $url): PinnedOutboundEndpoint;  // Vaulter\ValueObjects\Webhooks\PinnedOutboundEndpoint; @throws BlockedOutboundUrlException
```

### 1.2 `axioma-studio/vaulter-drive` (1) — namespace-корень `Vaulter\Drive\Contracts\*`

##### `Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler`
```php
public function protocol(): UploadProtocol;                // Vaulter\Drive\Enums\Uploads\UploadProtocol
public function initialise(UploadSession $session): array; // Vaulter\Drive\Models\Uploads\UploadSession; @return array<string, mixed>
public function isComplete(UploadSession $session): bool;
public function abort(UploadSession $session): void;
```

### 1.3 `axioma-studio/vaulter-documents` (3) — namespace-корень `Vaulter\Documents\Contracts\*`

##### `Vaulter\Documents\Contracts\Collaboration\HostUserResolver`
```php
public function find(int|string $id): ?Authorizable;       // Illuminate\Contracts\Auth\Access\Authorizable
```

##### `Vaulter\Documents\Contracts\Collaboration\NodeCollaborationDriver`
```php
public function type(): CollaborationDriverType;           // Vaulter\Documents\Enums\Collaboration\CollaborationDriverType
public function issueSession(Node $node, Ability $ability, int|string $actorId): CollaborationSessionData; // Vaulter\Enums\Access\Ability; Vaulter\Documents\Data\Collaboration\CollaborationSessionData
```

##### `Vaulter\Documents\Contracts\Export\ExportFilenameResolver`
```php
public function resolve(Node $node, string $extension): string;
```

### 1.4 `axioma-studio/vaulter-azgard` (1) — namespace-корень `Vaulter\Azgard\Contracts\*`

##### `Vaulter\Azgard\Contracts\AzgardBackedUser`
```php
interface AzgardBackedUser extends AzGuard\Contracts\HasPermissions, AzGuard\Contracts\HasScopedRoles {}
// pure composition marker — declares NO own methods; inherited surface is AzGuard's OWN
// public contracts (0.2+): HasPermissions (hasPermission/hasPermissionIn/isSuperAdmin) +
// HasScopedRoles (hasScopedPermission). No re-declared signature to drift.
```

### 1.5 `vaulter-media` (0), `vaulter-filament` (0)

Публичной интерфейс-поверхности НЕТ — оба пакета не имеют `src/Contracts/`. P2.2 ставит на них
«empty-surface» гвард (ловит случайно добавленную поверхность). `RAG:—` (`find
packages/{media,filament}/src/Contracts` → пусто).

---

## 2. Фасад `Vaulter\Facades\Vaulter` (замораживается P2.2)

- **FQCN:** `Vaulter\Facades\Vaulter` (`final class ... extends Illuminate\Support\Facades\Facade`).
- **Accessor:** `getFacadeAccessor(): string` → `'vaulter'`.
- **Резолюция биндинга:** `'vaulter'` — контейнер-alias на `Vaulter\VaulterManager`
  (`VaulterServiceProvider:105` `$this->app->alias(VaulterManager::class, 'vaulter')`).
  Фасад стоит над **менеджером** (паттерн Laravel Manager — как `Cache`/`Filesystem`), не напрямую
  над интерфейсом `Contracts\*`. Это осознанный, задокументированный шов (handoff P2 «фасад над
  биндингом `'vaulter'`»), НЕ drift `public-api.md:23` — публичная поверхность фасада есть его
  `@method`-контракт над резолвящимся `VaulterManager`. `RAG:—` (`public-api.md:23`,
  `VaulterServiceProvider:104-105`).

**Публичная `@method`-поверхность** (что морозит P2.2 — статические методы фасада):
```php
public static function for(\Illuminate\Database\Eloquent\Model $entity, ?string $panel = null): \Vaulter\Contracts\Drive\DriveHandle;
public static function forStorage(\Vaulter\Models\Drive\Storage $storage): \Vaulter\Contracts\Drive\DriveHandle;
public static function panel(string $name = 'default'): \Vaulter\Panels\Panel;
public static function panels(): \Vaulter\Panels\PanelRegistry;
public static function registerPanel(\Vaulter\Panels\StoragePanelProvider|\Vaulter\Panels\Panel $panel): void;
public static function registerDocumentType(string $type, array $definition): void;               // array<string, mixed> $definition
public static function documentTypes(): array;                                                    // array<string, array<string, mixed>>
public static function registerHandler(\Vaulter\Contracts\Handlers\FileHandler|string $handler): void; // class-string<FileHandler>
public static function handlers(): \Vaulter\Contracts\Handlers\HandlerRegistry;
public static function registerExporter(\Vaulter\Contracts\Handlers\DocumentExporter|string $exporter): void; // class-string<DocumentExporter>
public static function exporter(string $format): \Vaulter\Contracts\Handlers\DocumentExporter;
public static function exporterFormats(): array;                                                  // list<string>
```
`RAG:—` (`packages/core/src/Facades/Vaulter.php:1148-1163` — `@method`-докблок над `VaulterManager`).

---

## 3. Host-facing config-ключи (доком + `public-api.md`, D5 — НЕ рефлексией)

Замораживается **присутствие ключа + публичность**, НЕ значения дефолтов (`cut-line.md` §2).
Весь опубликованный `config/vaulter*.php` host-facing по определению (потребитель может override любой
ключ). Ниже — ключ-в-ключ по top-level; маркер `внутр.` = wiring-карта (FQCN-реестр, меняется через
D#, не «host-facing knob»).

### 3.1 `config/vaulter.php` (core, `vaulter.*` — публикуемый, tag `vaulter-config`)

| Top-level ключ | Под-ключи | Публ. |
|:--|:--|:--|
| `models` | storage/node/file/file_version/blob/file_version_blob/grant/node_share/activity/outbox/webhook_endpoint/webhook_delivery (swappable-модели) | публ. |
| `table_names` | 16 per-entity имён | публ. |
| `id_type` | (legacy) | публ. |
| `ids` | `default`, `strategies` (карта bigint/ulid/uuid7 → FQCN) | публ. (`strategies` внутр.-карта) |
| `panels`, `default_panel` | FQCN-реестр StoragePanelProvider | публ. |
| `migrations` | `path` | публ. |
| `features` | blobs/versions/grants/activity/uploads/share_links/comments/document_versions/spreadsheets/collaboration/public_listing/reliability | публ. |
| `handlers` | FQCN-список FileHandler (baseline Preview/DownloadOnly) | публ. |
| `tiptap` | `extra_nodes`/`extra_marks`/`extra_attributes`/`url_attributes` (D27/D28 allow-list) | публ. |
| `tenant` | `enabled`, `column` | публ. |
| `table_prefix` | (`v_`) | публ. |
| `connection` | (named DB connection, F-P2.3-02) | публ. |
| `drivers` | `permission` | публ. |
| `permission_drivers` | database/gate/spatie/azgard/null → FQCN | публ. (внутр.-карта) |
| `gate` | `abilities`, `super_ability`, `create_ability` | публ. |
| `spatie` | `permissions`, `super_permission`, `create_permission`, `teams`, `role_subject_type`, `team_subject_type` | публ. |
| `azgard` | `permissions` (Ability→perm), `create_permission` | публ. |
| `dedup` | `scope` (global/tenant/storage, D18) | публ. |
| `gc` | `grace_hours`, `orphan_grace_hours` | публ. |
| `storage` | `default_disk` | публ. |
| `share_token_generator` | FQCN | публ. |
| `share` | `token_generator`, `url_base`, `prefix_length`, `default_ttl_hours`, `max_ttl_hours` | публ. |
| `downloads` | `inline_mime_whitelist` (F-P1.3-04) | публ. |
| `trash` | `retention_days` | публ. |
| `uploads` | `max_bytes`/`chunk_size`/`max_chunk_bytes`/`session_ttl_hours`/`max_active_sessions`/`tus.enabled`/`metadata.{allowed_keys,max_value_length}` | публ. |
| `webhooks` | `timeout`/`max_attempts`/`backoff`/`disable_after_failures`/`signature_header`/`timestamp_header`/`event_header`/`retention_days`/`retry_claim_seconds`/`manage_ability`/`delivery.{url_guard,allowed_schemes,blocked_ip_ranges,allowed_ip_ranges,connect_timeout,max_response_bytes}` (D5/N2 SSRF) | публ. |
| `route_prefix`, `middleware` | | публ. |
| `rate_limits` | `public.per_minute`, `uploads.per_minute` | публ. |
| `max_versions` | (legacy) | публ. |
| `versions` | `max`, `autosave_debounce_seconds` | публ. |
| `enabled_document_types`, `max_document_size_kb` | | публ. |
| `drive` | `max_upload_kb`, `allowed_mime`, `name_rules.{max_length,no_separators_pattern}` | публ. |
| `exporters` | format→FQCN (пусто в core; merge из documents) | публ. |
| `collaboration` | `driver`/`channel_prefix`/`webhook_secret`/`webhook_timestamp_tolerance`/`drivers.{reverb,hocuspocus}` | публ. |

### 3.2 `config/vaulter-cas.php` (media — **merge-only** в `vaulter.cas.*`, НЕ публикуемый standalone)

| Ключ | Публ. |
|:--|:--|
| `content_store`, `storage_driver`, `blob_store` (FQCN CAS-движка) | публ. (merge в `vaulter.cas.*`, `VaulterMediaServiceProvider:45`) |

### 3.3 `config/vaulter-drive.php` (drive — **merge-only** в `vaulter.models.*`)

| Ключ | Публ. |
|:--|:--|
| `upload_session`, `upload_part` (swappable-модели) | публ. (merge в `vaulter.models.*`, `VaulterDriveServiceProvider:48`) |

### 3.4 `config/vaulter-documents.php` (documents — **merge-only** в `vaulter.{models,handlers,exporters}.*`)

| Ключ | Публ. |
|:--|:--|
| `models.node_comment`, `models.node_document_version` | публ. (merge в `vaulter.models.*`) |
| `handlers` (TipTapHandler) | публ. (merge в `vaulter.handlers`) |
| `exporters.html`, `exporters.markdown` | публ. (merge в `vaulter.exporters`) |

### 3.5 `config/vaulter-azgard.php` (azgard — публикуемый, tag `vaulter-azgard-config`)

| Ключ | Публ. |
|:--|:--|
| `panel_id` | публ. |

### 3.6 `config/vaulter-filament.php` (filament — публикуемый, tag `vaulter-filament-config`)

| Ключ | Публ. |
|:--|:--|
| `navigation_group` | публ. |
| `resources.{storage,node,grant,share,activity}` | публ. |

---

## 4. Публикуемые ресурсы (доком + `public-api.md`, D5)

### 4.1 Публикуемые config-файлы

**Фактически публикуемых standalone — 3, не 6** (остальные 3 merge-only в `vaulter.*`; Scope P2.1 (4)
ожидал «6» — это аппроксимация, реальность энумерирована ниже; анти-подгонка `cut-line.md` §2):

| Файл | Механизм | Публикуется? | Tag |
|:--|:--|:--:|:--|
| `packages/core/config/vaulter.php` | `->hasConfigFile()` + install `publishConfigFile()` | **да** | `vaulter-config` |
| `packages/azgard/config/vaulter-azgard.php` | `boot()` `$this->publishes(...)` | **да** | `vaulter-azgard-config` |
| `packages/filament/config/vaulter-filament.php` | `->hasConfigFile()` | **да** | `vaulter-filament-config` |
| `packages/media/config/vaulter-cas.php` | `mergeConfigFrom → vaulter.cas` | нет (merge) | — |
| `packages/drive/config/vaulter-drive.php` | `mergeConfigFrom → vaulter.models` | нет (merge) | — |
| `packages/documents/config/vaulter-documents.php` | `mergeConfigFrom → vaulter.{models,handlers,exporters}` | нет (merge) | — |

`RAG:—` (`VaulterServiceProvider:54-71`, `VaulterAzgardServiceProvider:54-58`,
`VaulterFilamentServiceProvider:15-16`, `VaulterMediaServiceProvider:45`,
`VaulterDriveServiceProvider:48`, `VaulterDocumentsServiceProvider` merge).

### 4.2 Публикуемые миграции (`v_*`-схема)

**19 файлов, ВСЕ в core** (`packages/core/database/migrations`, `->discoversMigrations()` +
`->runsMigrations()` + install `publishMigrations()`). Прочие 5 пакетов миграций НЕ несут
(схема — core-owner, T2). `RAG:—` (`ls packages/core/database/migrations/*.php | wc -l` = 19;
`find packages/{drive,documents,media,filament,azgard}/database -name '*.php'` → пусто).

<details><summary>19 миграций (детерминированный порядок имён)</summary>

`000007_create_v_storages` · `000008_create_v_nodes` · `000009_create_v_blobs` ·
`000010_create_v_files` · `000011_create_v_file_versions` · `000012_create_v_file_version_blobs` ·
`000013_create_v_node_grants` · `000014_create_v_node_shares` · `000015_create_v_activity` ·
`000016_create_v_upload_sessions` · `000017_create_v_upload_parts` · `000018_create_v_outbox` ·
`000019_create_v_webhook_endpoints` · `000020_create_v_webhook_deliveries` ·
`000021_create_v_node_document_versions` · `000022_create_v_node_comments` ·
`000023_drop_legacy_docvault_tables` · `2026_07_10_000001_encrypt_webhook_signing_secret` ·
`2026_07_11_000001_optimize_hot_path_indexes`
</details>

### 4.3 Публикуемые ассеты / прочее

- **Translations:** core `->hasTranslations()` — публикуемые языковые файлы (tag `vaulter-translations`).
- **Публикуемые ассеты (JS/CSS):** НЕ декларируются ни одним провайдером (`hasAssets` отсутствует);
  фронт `@vaulter/vue` — отдельный npm-трек вне composer-канала (D3). `RAG:—` (grep `hasAssets`
  по 6 провайдерам → пусто).

---

## 5. Биндинги контрактов в ServiceProvider (доком + `public-api.md`, D5)

Контракт → как резолвится. Пустой биндинг = host обязан связать (или капабилити-маркер).

| Контракт | Провайдер | Биндинг / резолюция |
|:--|:--|:--|
| `Contracts\Sharing\ShareTokenGenerator` | core | `bind` → `Config::nodeShareTokenGeneratorClass()` (деф. `RandomShareTokenGenerator`) |
| `Contracts\Webhooks\HostResolver` | core | `bind` → `SystemHostResolver` |
| `Contracts\Webhooks\OutboundUrlGuard` | core | `bind` → `Config::webhookUrlGuardClass()` (деф. `DefaultOutboundUrlGuard`) |
| `Contracts\Handlers\HandlerRegistry` | core | `bind` → `DocumentHandlerRegistry` (singleton) |
| `Contracts\Handlers\NodeHandlerResolver` | core | `bind` → `Engines\NodeHandlerResolver` |
| `Contracts\Access\HostRoleDriver` | core | `bind` → активный `permission_drivers[config('vaulter.drivers.permission')]` (`VaulterServiceProvider:144`) |
| `Contracts\IdStrategy` | core | резолвится через `IdStrategyResolver` + карта `vaulter.ids.strategies` (не прямой `bind` контракта) |
| `Contracts\Handlers\FileHandler` | core | НЕ single-bind — tag `'vaulter.handlers'` + список `config('vaulter.handlers')` + `Vaulter::registerHandler()` |
| `Contracts\Handlers\DocumentExporter` | core/documents | НЕ single-bind — карта `config('vaulter.exporters')` + `Vaulter::registerExporter()` |
| `Contracts\Cas\StorageDriver` | media | `singleton` → `Config::storageDriverClass()` (деф. `FlysystemStorageDriver`) |
| `Contracts\Cas\ContentStore` | media | `singleton` → `Config::contentStoreClass()` (деф. `Sha256ContentStore`) |
| `Contracts\Cas\BlobStore` | media | `bind` → `Config::blobStoreClass()` (деф. media `BlobStore`) |
| `Contracts\Drive\DriveHandleFactory` | drive | `bind` → `DriveManager` |
| `Contracts\Drive\DriveHandle` | drive | НЕ bind — возвращается фабрикой `DriveHandleFactory` |
| `Documents\Contracts\Collaboration\HostUserResolver` | documents | `bind` → `DefaultHostUserResolver` |
| `Documents\Contracts\Export\ExportFilenameResolver` | documents | `bind` → `DefaultExportFilenameResolver` |
| `Documents\Contracts\Collaboration\NodeCollaborationDriver` | documents | резолвится driver-менеджером по `config('vaulter.collaboration.driver')` (reverb/hocuspocus) |
| `Contracts\Access\AzgardGuard` | azgard | `bind` → closure строит `AzgardGuardAdapter` из резолвнутого `AzgardBackedUser` (только при установленном пакете) |
| `Contracts\Access\EnumeratesRoleSubjects` | — | капабилити-маркер, реализуется драйвером, не биндится |
| `Contracts\Access\GrantAuthoritativeDriver` | — | капабилити-метод, реализуется драйвером, не биндится |
| `Contracts\Cas\ListableContentStore` / `ListableStorageDriver` | — | капабилити-маркеры, реализуются движком media, не биндятся |
| `Contracts\Cas\StagedObject` | — | возвращаемый тип `ContentStore::stage()`, не биндится |
| `Contracts\Handlers\DocumentContent` | — | payload-контракт версии, не биндится (shape решает handler) |
| `Drive\Contracts\Uploads\UploadProtocolHandler` | drive | резолвится `UploadSessionManager` по протоколу сессии (tus/s3), не single-bind |
| `Azgard\Contracts\AzgardBackedUser` | — | контракт host-user (host реализует на своей `User`-модели), не биндится |

`RAG:—` (`VaulterServiceProvider:116-176`, `VaulterMediaServiceProvider:51-53`,
`VaulterDriveServiceProvider:53`, `VaulterDocumentsServiceProvider:105-112`,
`VaulterAzgardServiceProvider:36-49`).

---

## 6. Drift-check против `.claude/rules/public-api.md` (вердикт)

Правило (`public-api.md:2-4,18-23`): публичное = `**/src/Contracts/**` ∪ `**/src/**/Facades/**`;
внутреннее помечено `@internal`(+`final`); фасад — над контейнер-биндингом.

| Ось drift | Результат | Якорь |
|:--|:--|:--|
| Публичный класс ВНЕ `Contracts/`/`Facades/` | не обнаружено — arch-инвариант `internal namespaces are not consumed outside the Vaulter package tree` (`Repositories`/`Support`) держится; поверхность = Contracts+Facades | `ArchTest.php:96-98` |
| `Contracts/`-интерфейс, помеченный `@internal` | **0** — `grep -rn '@internal' packages/*/src/Contracts/` пусто | drift-check греп |
| Все 26 `Contracts/`-файлов — интерфейсы | да — arch `contracts are interfaces` зелёный; ни одного не-interface файла в `Contracts/` | `ArchTest.php:182-184` |
| Фасад над биндингом→контракт | фасад над `'vaulter'`→`VaulterManager` (Manager-паттерн, не прямой интерфейс) — **осознанный шов**, задокументирован (§2), НЕ нарушение | `VaulterServiceProvider:104-105` |

**Вердикт: `clean` (no escalation).** Фактическая поверхность НЕ разошлась с `public-api.md`:
единственная «нестандартность» — фасад над менеджером, а не над интерфейсом-контрактом — идиоматична
для Laravel Manager (как `Cache`/`Filesystem`), пред-принята handoff'ом P2 и морозится по
`@method`-контракту (§2). Триггер эскалации «public contract/SemVer» НЕ сработал → P2.2 разблокирован.

---

## 7. Заметки для P2.2 (генерация golden)

- **Морозить (рефлексией):** раздел 1 (26 интерфейсов — полная сигнатура: типы параметров вкл.
  union/nullable/default/variadic, return-тип) + раздел 2 (фасад по `@method`).
- **НЕ морозить рефлексией (D5):** разделы 3–5 — держатся ЭТИМ доком + `public-api.md`.
- **`AzgardBackedUser`** — маркер `extends HasPermissions, HasScopedRoles` из azguard-пакета: golden
  фиксирует наследуемую поверхность через рефлексию (`getMethods` резолвит унаследованные AzGuard-методы).
- **Капабилити-маркеры без методов** (`EnumeratesRoleSubjects`) — golden фиксирует пустую сигнатуру;
  добавление метода = breaking, ловится.
- **Empty-surface гвард** media/filament — `Contracts/` пуст, любой добавленный интерфейс красит билд.
- **Amend-путь** (P2.2): `MetricsRecorder` (VLT-LOAD P8 / D31) добавится ПОСЛЕ снапшота как MINOR —
  регенерация golden + новый D# + `plan.md` §7 Contracts + CHANGELOG MINOR (`cut-line.md` §4).
