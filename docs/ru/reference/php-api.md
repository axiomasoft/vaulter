---
title: Фасад и Manager
description: Фасад Vaulter и VaulterManager — публичная точка входа для интеграции с хост-приложением.
outline: deep
---

# Фасад и Manager

`Vaulter\Facades\Vaulter` — публичная точка входа пакета. Это тонкий фасад Laravel
поверх `Vaulter\VaulterManager`, который регистрируется как синглтон контейнера
(алиас `'vaulter'`) в `VaulterServiceProvider`. Каждый метод ниже — это реальный
метод на `VaulterManager`.

```php
use Vaulter\Facades\Vaulter;

$drive = Vaulter::for($project);          // открыть Drive-хэндл
$panel = Vaulter::profiles()->get('project_drive'); // резолв Storage Panel
```

::: info Резолвьте как удобно
Фасад, `app(VaulterManager::class)` и алиас контейнера `'vaulter'` возвращают один и
тот же синглтон. Внедрите `VaulterManager` в конструктор, если предпочитаете DI
фасаду.
:::

## Сводка методов

| Метод | Возвращает | Назначение |
|---|---|---|
| `for(Model $entity, ?string $panel = null)` | `DriveHandle` | Открыть Drive-хэндл, привязанный к хранилищу сущности хоста в панели |
| `forStorage(Drive $storage)` | `DriveHandle` | Открыть Drive-хэндл, привязанный к уже разрешённой строке-хранилищу |
| `profiles()` | `ProfileRegistry` | Именованные профили Drive (`get`, `register`, `registerProvider`) |
| `handlers()` | `FileHandlerRegistry` | Реестр document handler'ов |

Фасад аннотирует все публичные методы менеджера. Для регистрации используйте `profiles()` и `handlers()`.

## Точки входа в Drive

### `for(Model $entity, ?string $panel = null): DriveHandle`

Основная точка входа для интеграции. Находит-или-создаёт строку `v_storages` для
сущности хоста в рамках панели (идемпотентно — корневая папка подготавливается
автоматически) и возвращает эргономичный `DriveHandle`, методы которого делегируют
Drive-Action'ам и репозиториям. По умолчанию панель — `'default'`.

```php
$drive = Vaulter::for($project);                 // панель по умолчанию
$drive = Vaulter::for($project, 'project_drive'); // именованная панель
```

### `forStorage(Drive $storage): DriveHandle`

Открыть Drive-хэндл, привязанный к уже разрешённой строке `Drive`. Это шов, который
использует HTTP-слой: `Drive`, связанный через route-model binding (разрешённый
через сменяемый `config('vaulter.models.drive')`), напрямую отображается в
`DriveHandle` без повторного вывода владельца. Панель хэндла отражает
`$storage->profile`, поэтому его Action'ы выполняются в правильном scope панели.

```php
$drive = Vaulter::forStorage($storage);
```

Полную поверхность методов хэндла см. в [`DriveHandle`](/ru/concepts/nodes-and-revisions).

## Профили

### `profiles(): ProfileRegistry`

Получите профиль через `Vaulter::profiles()->get('project')` или зарегистрируйте
провайдер через `Vaulter::profiles()->registerProvider(new ProjectProfile)`.
См. [Профили Drive](/ru/concepts/drives-and-profiles).

## Типы документов и handler'ы

### Типы документов

Типов документов на этом фасаде нет: они живут в `vaulter-documents` как `Documents::types()`
(`Vaulter\Documents\Types\DocumentTypeRegistry`). См. [Типы документов](/ru/extending/document-types).

### `handlers(): FileHandlerRegistry`

Шов расширяемости по форматам. Добавить новый формат — это один класс + один вызов:
`handlers()->register()` принимает FQCN или инстанс и делегирует привязанному к
контейнеру `Vaulter\Handlers\FileHandlerRegistry` (принадлежит core с P0.4/D10 —
единый источник истины для резолва mime → расширение → возможность → приоритет,
резолвится даже без установленного `vaulter-documents`). Ни миграций, ни правок
ядра, ни обязательного пакета.

```php
Vaulter::handlers()->register(\App\Vaulter\CadHandler::class);
```

Также можно перечислить FQCN handler'ов в `config('vaulter.handlers')` или привязать
их к тегированной привязке контейнера `'vaulter.file_handlers'`. См.
[Recipes → Custom handler](/ru/guides/recipes).

## Совместное редактирование

### `driver(?string $name = null): CollaborationDriver`

Резолв драйвера совместного редактирования. По умолчанию имя —
`config('vaulter-documents.collaboration.driver')` (`hocuspocus`); класс драйвера
читается из `config('vaulter-documents.collaboration.driver')` и резолвится через
контейнер. Неизвестный или несоответствующий драйвер бросает
`Vaulter\Exceptions\VaulterException`.

```php
$driver = Vaulter::driver();            // настроенный по умолчанию
$driver = Vaulter::driver('hocuspocus'); // явный драйвер
```

## См. также

- [Справочник конфигурации](/ru/reference/config) — все config-ключи
- [Справочник контрактов](/ru/reference/php-api) — интерфейсы, которые эти методы принимают и возвращают
- [Справочник событий](/ru/reference/events) — что диспатчится по мере работы с диском
- [Справочник команд](/ru/reference/commands) — поверхность Artisan

## Контракты

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

### Access

#### `Vaulter\Contracts\Access\PermissionResolver` {#access-permission-resolver}

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
системе ролей вашего приложения. См. [Рецепты → Свой резолвер](/ru/guides/recipes).

#### `Vaulter\Drive\Contracts\Access\PermissionDriver`

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
[Рецепты → Свой резолвер](/ru/guides/recipes).

#### `Vaulter\Azgard\Contracts\AzgardGuard`

Узкий принадлежащий Vaulter шов, который удовлетворяет актор хоста на базе AzGuard, так что
`vaulter-core` не зависит от AzGuard **никак**. Драйвер прав `azgard` общается только с этим
интерфейсом — никогда с классами AzGuard. Оба метода — решения AzGuard v1
(`hasPermission(on: $entity)` и `hasPermission(on: AssignmentScopeRef::of($type, $id))`);
метода super-admin нет — super-admin AzGuard получает роль owner через эти решения.

```php
interface AzgardGuard
{
    public function hasScopedPermission(string $permission, object $entity): bool;

    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;
}
```

Контракт поставляется `axioma-studio/vaulter-azgard`, вместе с реальным
`AzgardGuardAdapter` и тестовым `Vaulter\Azgard\Testing\FakeAzgardGuard`.
Provider регистрирует драйвер и адаптер автоматически.

**Используйте, когда** `config('vaulter.access.permission_driver') = 'azgard'`:
установите `composer require axioma-studio/vaulter-azgard`, добавьте
`Vaulter\Azgard\Contracts\AzgardBackedUser` на модель пользователя, зарегистрируйте
morph-алиасы моделей пользователя и workspace и объявите алиасы workspace типами assignment
scope панели AzGuard. Фейк также требует установленного моста и его зависимостей.
См. [Драйверы прав](/ru/extending/permission-drivers).

---

### Cas (контент-адресуемое хранилище)

#### `Vaulter\Media\Contracts\Cas\BlobStore`

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

#### `Vaulter\Media\Contracts\Cas\ContentStore`

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

#### `Vaulter\Media\Contracts\Cas\StagedObject`

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

#### `Vaulter\Media\Contracts\Cas\StorageDriver`

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

#### `Vaulter\Contracts\Cas\StorageAdapter` (легаси)

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
[Рецепты → Драйвер хранилища](/ru/guides/recipes).
:::

---

### Collaboration

#### `Vaulter\Documents\Contracts\CollaborationDriver`

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

#### `Vaulter\Documents\Contracts\CollaborationDriver` (легаси)

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

#### `Vaulter\Documents\Contracts\HostUserResolver`

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

См. [Совместное редактирование → Драйверы](/ru/guides/collaboration).

---

### Handlers

#### `Vaulter\Contracts\Handlers\FileHandler`

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

См. [Рецепты → Свой обработчик](/ru/guides/recipes).

#### `Vaulter\Documents\Contracts\DocumentExporter`

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

См. [Рецепты → Свой экспорт](/ru/guides/recipes).

#### `Vaulter\Contracts\Handlers\FileHandlerRegistry`

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

#### `Vaulter\Contracts\Handlers\NodePresenter`

Возвращает представление узла, содержащее `id`, `capabilities` и `ui`.
`ui` сериализует `ViewerDescriptor` с полями `key` и `props`.

```php
interface NodePresenter
{
    public function present(Node $node): NodePresentation;
}
```

### Sharing

#### `Vaulter\Drive\Contracts\Sharing\ShareTokenGenerator`

Генерирует непрозрачный токен для публичной ссылки (share). Токен должен быть уникальным и
помещаться в колонку токена (≤ 64 символов). Генератор задаётся в `config('vaulter.sharing.token_generator')`.

```php
interface ShareTokenGenerator
{
    public function generate(): string;
}
```

По умолчанию — `Vaulter\Drive\Support\RandomShareTokenGenerator`. См.
[Рецепты → Генератор токенов публичных ссылок](/ru/guides/recipes).

---

### Uploads

#### `Vaulter\Contracts\Uploads\UploadProtocolHandler`

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

См. [Использование → Загрузки](/ru/guides/uploads).

---

### Identity

#### `Vaulter\Contracts\IdStrategy`

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
См. [Авторизация → Мультитенантность](/ru/concepts/tenancy) для выравнивания морфов.
:::

### Смотрите также

- [Фасад и менеджер](/ru/reference/php-api) — методы, которые принимают и возвращают всё это
- [Справочник по конфигурации](/ru/reference/config) — ключи привязок
- [Справочник по событиям](/ru/reference/events) — что диспатчится через эти швы

## Поддерживаемая поверхность

То, что Vaulter обещает сохранять, размечено в коде, а не перечислено вручную (D27). Каждый тип `packages/*/src` несёт ровно один
атрибут из `Vaulter\Attributes`:

| Атрибут | Смысл |
|---|---|
| `#[Api]` | Поддерживается для кода, который использует Vaulter. Публичные и защищённые члены замораживаются вместе с типом, включая имена параметров (именованные аргументы — API). |
| `#[Spi(SpiKind::Implement)]` | Контракт, который реализует хост или модуль; ядро вызывает его позиционно. |
| `#[Spi(SpiKind::Call)]` | Сервис, который реализует Vaulter, а хост или модуль вызывает. |
| `#[Internal]` | Не поддерживается; может измениться в любом релизе. На члене убирает только этот член (технический конструктор, хук) из типа `#[Api]`. |

Манифест каждого пакета — типы `#[Api]` и `#[Spi]` с поддерживаемыми членами — генерируется в
`packages/<пакет>/tests/Arch/__snapshots__/surface.txt` командой `scripts/php84 php scripts/inventory/inventory.php surface --write`;
`--check` (и `SurfaceManifestTest`) краснеет, когда код и манифест расходятся, поэтому любое изменение поверхности — видимое изменение
закоммиченного файла. Поддерживаемая сигнатура, называющая тип `#[Internal]` или доменную Eloquent-модель, попадает в отчёт сканирования
I13 (`scan-i13`, режим report до заморозки 1.0).
