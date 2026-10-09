---
title: Контракты
description: Каждый интерфейс Vaulter\Contracts по доменам — сигнатуры методов и когда его реализовывать.
outline: deep
---

# Контракты

Швы вариативности Vaulter живут в пространстве имён `Vaulter\Contracts\*`. Каждый из
них — публичный, замороженный по SemVer интерфейс, который вы можете реализовать, чтобы
подменить поведение: авторизацию, хранилище, транспорт совместного редактирования,
форматы документов, токены публичных ссылок, стратегию идентификаторов или протоколы
загрузки. На этой странице перечислен каждый контракт по доменам — с точными сигнатурами
методов и точкой привязки, через которую вы его подменяете.

| Домен | Контракт | Подмена через |
|---|---|---|
| Access | `PermissionResolver` | `config('vaulter.permission_resolver')` / `Vaulter::resolvePermissionsWith()` |
| Access | `PermissionDriver` | `config('vaulter.access.permission_driver')` + карта `access.permission_drivers` |
| Access | `AzgardGuard` (azgard) | привязка из `vaulter-azgard` |
| Cas | `BlobStore` | `config('vaulter-media.blob_store')` |
| Cas | `ContentStore` | `config('vaulter-media.content_store')` |
| Cas | `StorageDriver` | `config('vaulter-media.storage_driver')` |
| Cas | `StagedObject` | возвращается из `ContentStore::stage()` |
| Cas | `StorageAdapter` | `config('vaulter.storage_adapter')` (легаси) |
| Collaboration | `CollaborationDriver` | `config('vaulter-documents.collaboration.driver')` (легаси-путь документов) |
| Collaboration | `CollaborationDriver` | привязка в контейнере (путь узлов) |
| Collaboration | `HostUserResolver` | привязка в контейнере |
| Handlers | `FileHandler` | `config('vaulter.handlers')` / `Vaulter::handlers()->register()` |
| Handlers | `DocumentExporter` | `config('vaulter-documents.exporters')` / `Documents::exporters()->register()` |
| Sharing | `ShareTokenGenerator` | `config('vaulter.sharing.token_generator')` / `config('vaulter.sharing.token_generator')` |
| Uploads | `UploadProtocolHandler` | в комплекте (tus / S3-multipart) |
| — | `IdStrategy` | `config('vaulter.ids.strategies')` |

---

## Access

### `Vaulter\Contracts\Access\PermissionResolver` {#access-permission-resolver}

Хук, реализуемый хостом, для **легаси-пути документов DocVault**. Привяжите его через
`config('vaulter.permission_resolver')` (FQCN) или
`Vaulter::resolvePermissionsWith()`. Drive ACL вместо этого использует `PermissionDriver` —
данный резолвер относится к более старой, документо-центричной полосе.

```php
interface PermissionResolver
{
    /** @param string $action view|edit|comment|manage|delete */
    public function can(Authenticatable $user, string $action, Document $document): bool;

    public function canCreate(Authenticatable $user, string $workspaceType, int|string $workspaceId): bool;
}
```

**Реализуйте, когда** хотите, чтобы легаси-поверхность `v_documents` делегировала решения
системе ролей вашего приложения. См. [Рецепты → Свой резолвер](/ru/guide/recipes).

### `Vaulter\Contracts\Access\PermissionDriver`

Подключаемая, панель-специфичная **полоса ролей**, которую принадлежащий Vaulter
(несменяемый) `AccessResolver` комбинирует с ACL `v_node_grants`. Она мостит вашу систему
идентичности и ролей в Vaulter, не протекая доменом Vaulter: она никогда не получает `Node`
и никогда не оперирует действиями-магическими-строками — только актором `Authorizable` и
`AccessScope` (координаты панели + workspace + владелец узла). Подмените её через
`config('vaulter.access.permission_driver')` по карте `access.permission_drivers`; по умолчанию — автономный
`DatabasePermissionDriver`.

```php
interface PermissionDriver
{
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision;

    public function override(Authorizable $actor, AccessScope $scope): ?Effect;

    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array;

    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool;
}
```

- `resolve()` — решение хоста по роли, либо `null`, когда системе ролей хоста нечего
  сказать. Сворачивается как `roleAllow` в `granted = max(aclAllow, roleAllow)` — оно может
  **повысить** доступ, но никогда не перекрывает запрет (финальное вето всё равно
  применяется).
- `override()` — абсолютное переопределение хоста (супер-админ / `Gate::before`):
  `Effect::Allow` замыкает на полный доступ, `Effect::Deny` — на его отсутствие, оба —
  до любого прохода по ACL.
- `subjectsFor()` — развернуть актора в набор `Subject`, чьи гранты применимы
  (пользователь плюс его роли/команды на стороне хоста). Vaulter строит из этого списка
  `WHERE (subject_type, subject_id) IN (...)`.
- `canCreateInWorkspace()` — гейт создания папки/загрузки, где ещё нет существующего узла,
  относительно которого резолвить.

::: info Заморожено с M2a
Каждый драйвер из комплекта (`database`, `gate`, `spatie`, `azgard`, `null`) реализует ровно
эту поверхность, а контрактный тест утверждает, что все они выдают идентичные решения
`AccessResolver` на общей фикстуре.
:::

**Реализуйте, когда** у вас система ролей, которую драйверы из комплекта не покрывают. См.
[Рецепты → Свой резолвер](/ru/guide/recipes).

### `Vaulter\Azgard\Contracts\AzgardGuard`

Узкий принадлежащий Vaulter шов, который удовлетворяет актор хоста на базе AzGuard, так что
`vaulter-core` не несёт **никакой** жёсткой зависимости от `azguard`/`corex`. Драйвер прав
`azgard` общается только с этим интерфейсом — никогда с классами AzGuard.

```php
interface AzgardGuard
{
    public function hasScopedPermission(string $permission, object $entity): bool;

    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;

    public function isSuperAdmin(): bool;
}
```

Контракт поставляется `axioma-studio/vaulter-azgard`, вместе с реальным
`AzgardGuardAdapter` и тестовым `Vaulter\Azgard\Testing\FakeAzgardGuard`.
Provider регистрирует драйвер и адаптер автоматически.

**Используйте, когда** `config('vaulter.access.permission_driver') = 'azgard'`:
установите `composer require axioma-studio/vaulter-azgard`, добавьте
`Vaulter\Azgard\Contracts\AzgardBackedUser` на модель пользователя и согласуйте
ULID-morph (`vaulter.ids.strategy=ulid` + `corex.ids.strategy=Ulid` +
`AZ_GUARD_MORPH_TYPE=ulid`). Фейк также требует установленного моста и его зависимостей.
См. [Драйверы прав](/ru/guide/permission-drivers).

---

## Cas (контент-адресуемое хранилище)

### `Vaulter\Contracts\Cas\BlobStore`

Высокоуровневый, свободный от гонок жизненный цикл CAS-блоба. `commit()` — единственная
точка входа: stage → хэш → найти-или-создать блоб по его ключу дедупликации в области,
внутри одной залоченной транзакции, воскрешая «надгробленное» совпадение и продвигая байты
только тогда, когда строка создаётся заново. Истина живости — это ребро в
`v_file_version_blobs`, а не синхронный `ref_count`. Подмена через
`config('vaulter-media.blob_store')`.

```php
interface BlobStore
{
    /** @param resource $stream */
    public function commit(mixed $stream, ?int $tenantId = null, ?int $storageId = null, ?string $disk = null): Blob;

    /** @param array{role?: string, ordinal?: int, offset_bytes?: int|null, length_bytes?: int|null} $attributes */
    public function attach(FileRevision $version, Blob $blob, array $attributes = []): void;

    public function detach(FileRevision $version, Blob $blob, string $role = 'data', int $ordinal = 0): void;

    /** @return resource|null */
    public function read(Blob $blob): mixed;
}
```

### `Vaulter\Contracts\Cas\ContentStore`

Низкоуровневая байтовая сторона CAS: сложить входящий поток во временный локальный файл с
произвольным доступом (хэшируя эти надёжные байты на стороне сервера), затем продвинуть
сложенные байты к их каноническому, квалифицированному областью ключу. Знает только байты,
хэши и ключи — никогда строку `v_blobs`. Подмена через `config('vaulter-media.content_store')`.

```php
interface ContentStore
{
    /** @param resource $stream */
    public function stage(mixed $stream): StagedObject;

    public function promote(StagedObject $staged, string $key, ?string $disk = null): void;

    /** @return resource|null */
    public function get(string $key, ?string $disk = null): mixed;

    public function exists(string $key, ?string $disk = null): bool;

    public function delete(string $key, ?string $disk = null): void;

    /** Build the scope-qualified, sharded key "<scope>/ab/cd/<sha256>". */
    public function keyFor(string $scope, string $sha256): string;
}
```

### `Vaulter\Contracts\Cas\StagedObject`

Результат складывания загрузки в локальный временный файл с произвольным доступом. sha256
пересчитывается из этих надёжных байтов — никогда из живого потока, клиентского хэша или
ETag S3-multipart. Только для чтения и самоочищающийся. Возвращается из
`ContentStore::stage()`.

```php
interface StagedObject
{
    public function path(): string;

    public function sha256(): string;

    public function sizeBytes(): int;

    /** @return resource */
    public function readStream(): mixed;

    public function discard(): void;
}
```

### `Vaulter\Contracts\Cas\StorageDriver`

Тонкая абстракция над Flysystem-диском — байтовая плоскость под CAS. Подмените её, чтобы
добавить специфику объектного хранилища (`x-amz-checksum-sha256`, `If-None-Match: *`),
сохранив рабочим локальный/sqlite тестовый диск. Подмена через
`config('vaulter-media.storage_driver')`.

```php
interface StorageDriver
{
    public function disk(?string $disk = null): Filesystem;

    /**
     * @param resource             $stream
     * @param array<string, mixed> $options
     */
    public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool;

    /** @return resource|null */
    public function get(string $key, ?string $disk = null): mixed;

    public function exists(string $key, ?string $disk = null): bool;

    public function delete(string $key, ?string $disk = null): void;

    public function temporaryUrl(string $key, \DateTimeInterface $expiresAt, ?string $disk = null): ?string;
}
```

Когда `$ifNoneMatch` равен true, драйвер пытается сделать условное создание; 412/409
означает, что нас обогнали и нужно повторно выполнить SELECT + переиспользовать — это
проявляется как возврат `put()` значения `false`, а не как исключение. На локальном диске
условие деградирует до best-effort проверки через `exists()`.

### `Vaulter\Contracts\Cas\StorageAdapter` (легаси)

Подключаемое хранилище для **легаси**-бинарных вложений. Сам Vaulter хранит JSON-контент;
бинарные файлы в легаси-пути документов делегируются сюда. Подмена через
`config('vaulter.storage_adapter')`.

```php
interface StorageAdapter
{
    /** @param string|resource $contents */
    public function store(string $filename, mixed $contents): string;

    public function delete(string $url): void;

    public function url(string $path): string;
}
```

::: tip Какой CAS-контракт мне реализовывать?
Для собственного байтового бэкенда (S3 / локальный) реализуйте `StorageDriver` и привяжите
его — `ContentStore` и `BlobStore` остаются как есть. См.
[Рецепты → Драйвер хранилища](/ru/guide/recipes).
:::

---

## Collaboration

### `Vaulter\Documents\Contracts\CollaborationDriver`

Транспорт совместного редактирования в реальном времени по **дереву узлов** (текущий путь).
Абстрагирует то, как узел-документ (`kind=document`, контент в агрегате `v_documents`)
присоединяется для редактирования в реальном времени. Решение об авторизации — **не** задача
драйвера: контроллер резолвит его через `AccessResolver` и передаёт выданное право внутрь;
драйвер лишь кодирует его в дескриптор сессии (`readOnly = право не Edit`).

```php
interface CollaborationDriver
{
    public function type(): CollaborationDriverType;

    /** @param int|string $actorId the connecting host user's id (the JWT `sub`) */
    public function issueSession(Node $node, Ability $ability, int|string $actorId): CollaborationSession;
}
```

### `Vaulter\Documents\Contracts\CollaborationDriver` (легаси)

**Легаси**-транспорт совместного редактирования для документов, привязанный к модели
`Document`, прикреплённой через `document_id`. Сосуществует с `CollaborationDriver`;
хост выбирает один и тот же транспорт для обоих через
`config('vaulter-documents.collaboration.driver')`. Класс драйвера берётся из
`config('vaulter-documents.collaboration.driver')`.

```php
interface CollaborationDriver
{
    public function type(): CollaborationDriverType;

    public function authorizeChannel(Authenticatable $user, Document $document): bool;

    /** @return array<string, mixed> */
    public function connectionConfig(Document $document, Authenticatable $user): array;

    public function issueToken(Document $document, Authenticatable $user): string;
}
```

### `Vaulter\Documents\Contracts\HostUserResolver`

Материализует пользователя хоста (`Authorizable`) по id, чтобы серверный вебхук совместного
редактирования (server-to-server) — который несёт лишь id пользователя, без сессии — мог
прогнать `AccessResolver` относительно реального актора. Возвращает `null`, когда id ни во
что не резолвится (тогда вебхук отвечает `allowed=false`, а не открывается наружу). По
умолчанию `Vaulter\Documents\Collaboration\HostUserResolver` делегирует настроенному auth user-provider;
привяжите свой, чтобы переопределить.

```php
interface HostUserResolver
{
    public function find(int|string $id): ?Authorizable;
}
```

См. [Совместное редактирование → Драйверы](/ru/guide/collaboration).

---

## Handlers

### `Vaulter\Contracts\Handlers\FileHandler`

Подключаемый обработчик обработки документов — заголовочная фича расширяемости по форматам.
Обработчик заявляет набор mime-типов (точных или вайлдкардов вроде `"image/*"`) и/или
расширений, декларирует свои возможности и фронтенд-дескриптор монтирования на каждый файл,
а `Vaulter\Handlers\FileHandlerRegistry` резолвит лучший из них для `FileDescriptor`. Регистрируйте через
`config('vaulter.handlers')` (список FQCN), тегированную привязку `'vaulter.file_handlers'` или
`Vaulter::handlers()->register()`.

```php
interface FileHandler
{
    public function key(): string;

    /** @return list<string> */
    public function mimeTypes(): array;

    /** @return list<string> */
    public function extensions(): array;

    public function priority(): int;

    public function capabilities(FileDescriptor $file): ContentCapabilities;

    public function viewer(FileDescriptor $file): ViewerDescriptor;
}
```

- `key()` — стабильный уникальный ключ формата `vendor/name` (`'vaulter/tiptap'`, `'vaulter/preview'`,
  `'vaulter/download'`, `'vaulter/univer'`, …). Отдаётся клиенту, чтобы тот выбрал реализацию редактора/превью.
- `mimeTypes()` — точный (`"application/pdf"`), вайлдкард (`"image/*"`) или всеохватный
  (`"*"`). При маршрутизации предпочтение отдаётся вынюханному mime перед заявленным.
- `extensions()` — в нижнем регистре, без точки; запасной вариант по расширению,
  используемый, когда mime не совпал.
- `priority()` — при равенстве побеждает больший среди обработчиков, совпадающих по одному
  файлу + возможности; null-объект `DownloadOnlyHandler` сидит ниже всех.
- `capabilities()` — редактируемый / просматриваемый / скачиваемый / с превью /
  совместный, вычисляется на каждый файл.
- `viewer()` — ключ viewer и сериализуемые props.

См. [Рецепты → Свой обработчик](/ru/guide/recipes).

### `Vaulter\Documents\Contracts\DocumentExporter`

Рендерит декодированное содержимое документа (`DocumentContent`) в формат файла. Регистрируется в
`Documents::exporters()` и разрешается по паре (тип документа, формат). Видит только декодированное
inline-содержимое — никогда storage, blob'ы и URL. В комплекте экспортёры HTML и Markdown;
зарегистрируйте свой (PDF, DOCX, …) через `config('vaulter-documents.exporters')` или
`Documents::exporters()->register()`. Бинарные форматы возвращают закодированные байты файла и
объявляют соответствующий mime-тип.

```php
interface DocumentExporter
{
    public function key(): string;          // 'vaulter/html'
    public function format(): string;       // значение ?format=
    public function mimeType(): string;
    public function extension(): string;    // без точки
    /** @return list<string> ключи типов документов, которые он умеет отдавать */
    public function documentTypes(): array;
    public function export(DocumentContent $content, DocumentType $type): string;
}
```

См. [Рецепты → Свой экспорт](/ru/guide/recipes).

### `Vaulter\Contracts\Handlers\FileHandlerRegistry`

Общий реестр core принимает обработчики из конфига, контейнерного тега
`vaulter.file_handlers` и регистрации через `Vaulter::handlers()->register()`.

```php
interface FileHandlerRegistry
{
    /** @param FileHandler|class-string<FileHandler> $handler */
    public function register(FileHandler|string $handler): void;

    public function resolve(FileDescriptor $file, Capability $capability = Capability::Download): FileHandler;

    /** @return list<FileHandler> */
    public function all(): array;
}
```

### `Vaulter\Contracts\Handlers\NodePresenter`

Возвращает представление узла, содержащее `id`, `capabilities` и `ui`.
`ui` сериализует `ViewerDescriptor` с полями `key` и `props`.

```php
interface NodePresenter
{
    public function present(Node $node): NodePresentation;
}
```

## Sharing

### `Vaulter\Contracts\Sharing\ShareTokenGenerator`

Генерирует непрозрачный токен для публичной ссылки (share). Токен должен быть уникальным и
помещаться в колонку токена (≤ 64 символов). Генератор задаётся в `config('vaulter.sharing.token_generator')`.

```php
interface ShareTokenGenerator
{
    public function generate(): string;
}
```

По умолчанию — `Vaulter\Support\RandomShareTokenGenerator`. См.
[Рецепты → Генератор токенов публичных ссылок](/ru/guide/recipes).

---

## Uploads

### `Vaulter\Contracts\Uploads\UploadProtocolHandler`

Стратегия на каждый протокол, стоящая за сессией резюмируемой загрузки. В комплекте
поставляются две реализации — `TusUploadHandler` (tus.io CORE: Creation + HEAD + PATCH,
любой Flysystem-диск) и `S3MultipartHandler` (multipart объектного хранилища, только
S3-совместимые диски). Каждый обработчик резюмируемо накапливает байты в сложенный временный
файл, продвигая авторитетный серверный `received_bytes`, и сообщает, когда сессия полностью
собрана. Шаг «собрать → зафиксировать» находится **вне** обработчика (`CompleteUploadAction`
→ `BlobStore::commit`), так что инвариант «собранные байты всегда проходят через единый
свободный от гонок CAS» держится для обоих протоколов.

```php
interface UploadProtocolHandler
{
    public function protocol(): UploadProtocol;

    /** @return array<string, mixed> */
    public function initialise(UploadSession $session): array;

    public function isComplete(UploadSession $session): bool;

    public function abort(UploadSession $session): void;
}
```

См. [Использование → Загрузки](/ru/guide/uploads).

---

## Identity

### `Vaulter\Contracts\IdStrategy`

Стратегия первичного ключа: задаёт типы колонок в миграциях плюс соответствующий model
concern, так что «ulid против uuid7 против bigint» решается из
`config('vaulter.ids.strategy')`, а не зашивается в код. Реализации живут в
`Vaulter\Schema\*` (`AutoIncrementIds`, `UlidIds`, `Uuid7Ids`); хост может зарегистрировать
свою под `config('vaulter.ids.strategies')`.

```php
interface IdStrategy
{
    public function key(Blueprint $table, string $column = 'id'): void;

    public function reference(Blueprint $table, string $column, bool $nullable = false): void;

    public function actor(Blueprint $table, string $column, bool $nullable = false): void;

    /** Eloquent cast for the key type. */
    public function cast(): string;

    /** @return class-string|null Model concern (trait FQCN), or null for bigint. */
    public function modelConcern(): ?string;

    /** Mint a fresh key, or null for auto-increment keys. */
    public function newId(): string|int|null;
}
```

- `key()` — определить колонку первичного ключа.
- `reference()` — определить FK на другую сущность Vaulter (тот же тип ключа).
- `actor()` — определить ссылку на id пользователя хоста (без FK на уровне БД; таблицей
  пользователей владеет хост).

::: info По умолчанию — `ulid`
ULID стоит по умолчанию, чтобы морф-ключи совпадали с экосистемой Corex/Azgard; `uuid7`
ложится на нативные Postgres-колонки `uuid`; `bigint` — легаси-путь с автоинкрементом.
См. [Авторизация → Мультитенантность](/ru/guide/multi-tenancy) для выравнивания морфов.
:::

## Смотрите также

- [Фасад и менеджер](/ru/reference/facade) — методы, которые принимают и возвращают всё это
- [Справочник по конфигурации](/ru/reference/configuration) — ключи привязок
- [Справочник по событиям](/ru/reference/events) — что диспатчится через эти швы
