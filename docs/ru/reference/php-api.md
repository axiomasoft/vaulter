---
title: Фасад
description: Фасад Vaulter (Vaulter\Drive\Vaulter) — публичная точка входа drive для интеграции с хост-приложением.
outline: deep
---

# Фасад

`Vaulter\Drive\Facades\Vaulter` — публичная точка входа drive. Это тонкий фасад Laravel
поверх `Vaulter\Drive\Vaulter`, который `vaulter-drive` регистрирует как синглтон контейнера (алиас `'vaulter'`)
и заодно объявляет для хоста алиас класса `Vaulter`. Фасаду нужен `axioma-studio/vaulter-drive`; без него
`Vaulter::drive()` бросает `MissingPackageException` с именем пакета. Каждый метод ниже — реальный метод
`Vaulter\Drive\Vaulter`.

```php
use Vaulter\Drive\Facades\Vaulter;

$drive = Vaulter::drive($project);                       // открыть Drive-хэндл
$profile = Vaulter::profiles()->get('project_drive');    // резолв профиля drive
```

::: info Резолвьте как удобно
Фасад, `app(\Vaulter\Drive\Vaulter::class)` и алиас контейнера `'vaulter'` возвращают один и
тот же синглтон. Внедрите `Vaulter\Drive\Vaulter` в конструктор, если предпочитаете DI фасаду.
Прежних `Vaulter\VaulterManager` и `Vaulter\Facades\Vaulter` больше нет.
:::

## Сводка методов

| Метод | Возвращает | Назначение |
|---|---|---|
| `drive(Model $ownerOrDrive, ?string $profile = null)` | `DriveHandle` | Найти-или-создать drive сущности хоста в профиле (или открыть уже разрешённую строку `Drive`) |
| `findDrive(Model $owner, ?string $profile = null)` | `?DriveHandle` | Существующий drive владельца или `null`; ничего не создаёт |
| `findDriveById(string\|int $id)` | `?DriveHandle` | Drive с этим ключом в текущем тенанте или `null` |
| `drives(Authorizable $actor, ?ListOptions $options = null)` | `Page<DriveSnapshot>` | Drive, которые актор может открыть (те, чей корень ему виден) |
| `profiles()` | `ProfileRegistry` | Именованные профили Drive (`get`, `register`, `registerProvider`) |
| `handlers()` | `FileHandlerRegistry` | Реестр файловых обработчиков |
| `permissions()` | `PermissionDriverManager` | Используемый драйвер прав |

Тенантность — забота ядра: код вне скоупа тенанта выполняйте через `Vaulter\Tenancy\Tenancy::without(...)` (core).

## Точки входа в Drive

### `drive(Model $ownerOrDrive, ?string $profile = null): DriveHandle`

Основная точка входа для интеграции. Находит-или-создаёт строку `v_drives` для сущности хоста в рамках
профиля (идемпотентно — корневая папка подготавливается автоматически) и возвращает эргономичный
`DriveHandle`, методы которого делегируют Operations drive. По умолчанию берётся профиль по умолчанию из
конфига. Если передать готовую модель `Drive`, вернётся хэндл этой строки (другой `$profile` — это
`InvalidArgumentException`).

```php
$drive = Vaulter::drive($project);                  // профиль по умолчанию
$drive = Vaulter::drive($project, 'project_drive'); // именованный профиль
```

### `findDrive()`, `findDriveById()`, `drives()`

`findDrive()` и `findDriveById()` возвращают хэндл уже существующего drive, иначе `null`. `drives()` перечисляет
drive, которые актор может открыть, как снимки в порядке ключа с непрозрачным курсором; решение о видимости
принимается до нарезки страницы. Так хост и перечисляет свои drive: связь `HasDrives::drives()` у модели-владельца
помечена `#[Internal]` — она остаётся для учёта владельцев внутри drive и отдаёт доменную модель `Drive`, поэтому в
поддерживаемую поверхность не входит.

`Vaulter\Drive\Contracts\NodeSnapshotContributor` — слот `drive` (Contributor), который модуль documents заполняет
`documents.heads`, чтобы снимок узла получил `NodeSnapshot::$document`. `contribute(array $nodes)` получает страницу как
`list<NodeSnapshot>` — факты каждого узла до добавления фрагмента, состояния зрителя и `syncKey` читателя, никогда не
строку узла — и отвечает `id узла => фрагмент`; свои агрегаты контрибьютор читает по id узлов.

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
контейнеру `Vaulter\Drive\Handlers\FileHandlerRegistry` (принадлежит drive —
единый источник истины для резолва mime → расширение → возможность → приоритет,
резолвится даже без установленного `vaulter-documents`). Ни миграций, ни правок
ядра, ни обязательного пакета.

```php
Vaulter::handlers()->register(\App\Vaulter\CadHandler::class);
```

Также можно перечислить FQCN handler'ов в `config('vaulter-drive.handlers')` или привязать
их к тегированной привязке контейнера `'vaulter.file_handlers'`. См.
[Recipes → Custom handler](/ru/guides/recipes).

## См. также

- [Справочник конфигурации](/ru/reference/config) — все config-ключи
- [Справочник контрактов](/ru/reference/php-api) — интерфейсы, которые эти методы принимают и возвращают
- [Справочник событий](/ru/reference/events) — что диспатчится по мере работы с диском
- [Справочник команд](/ru/reference/commands) — поверхность Artisan

## Контракты

Швы вариативности Vaulter живут в пространстве имён `Contracts` пакета, которому принадлежит понятие
(`Vaulter\Contracts\*` — ядро, `Vaulter\Media\Contracts\*`, `Vaulter\Drive\Contracts\*`,
`Vaulter\Documents\Contracts\*`). Каждый из
них — публичный, замороженный по SemVer интерфейс, который вы можете реализовать, чтобы
подменить поведение: авторизацию, хранилище, транспорт совместного редактирования,
форматы документов, токены публичных ссылок, стратегию идентификаторов или протоколы
загрузки. На этой странице перечислен каждый контракт по доменам — с точными сигнатурами
методов и точкой привязки, через которую вы его подменяете.

| Домен | Контракт | Подмена через |
|---|---|---|
| Access | `PermissionDriver` | `config('vaulter-drive.access.permission_driver')` + карта `vaulter-drive.access.permission_drivers` |
| Access | `AzGuardGuard` (azguard) | привязка из `vaulter-azguard` |
| Cas | `BlobStore` | `config('vaulter-media.blob_store')` |
| Cas | `ContentStore` | `config('vaulter-media.content_store')` |
| Cas | `StorageDriver` | `config('vaulter-media.storage_driver')` |
| Cas | `StagedObject` | возвращается из `ContentStore::stage()` |
| Cas | `BlobPins` | контракт media, через который владелец связи с блобом (drive) ставит и снимает пины |
| Collaboration | `HostUserResolver` (collaboration) | привязка в контейнере |
| Collaboration | `CollaborativeState` (documents) | публичный контракт состояния, через который пишет журнал коллаборации (API, не точка расширения) |
| Collaboration | `PendingStateDrainer` (documents) | реализуется модулем коллаборации; documents вызывает его перед выдачей или заменой pending-документа |
| Handlers | `FileHandler` | `config('vaulter-drive.handlers')` / `Vaulter::handlers()->register()` |
| Handlers | `DocumentExporter` | `config('vaulter-documents.exporters')` / `Documents::exporters()->register()` |
| Sharing | `ShareTokenGenerator` | `config('vaulter-drive.sharing.token_generator')` / `config('vaulter-drive.sharing.token_generator')` |
| Uploads | `UploadProtocolHandler` | в комплекте (tus / S3-multipart) |
| — | `IdStrategy` | `config('vaulter.ids.strategies')` |

---

### Access

#### `Vaulter\Drive\Contracts\Access\PermissionDriver`

Подключаемая, панель-специфичная **полоса ролей**, которую принадлежащий Vaulter
(несменяемый) `AccessResolver` комбинирует с ACL `v_node_grants`. Она мостит вашу систему
идентичности и ролей в Vaulter, не протекая доменом Vaulter: она никогда не получает `Node`
и никогда не оперирует действиями-магическими-строками — только актором `Authorizable` и
`AccessScope` (координаты панели + workspace + владелец узла). Подмените её через
`config('vaulter-drive.access.permission_driver')` по карте `vaulter-drive.access.permission_drivers`; по умолчанию — автономный
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
Каждый драйвер из комплекта (`database`, `gate`, `spatie`, `azguard`, `null`) реализует ровно
эту поверхность, а контрактный тест утверждает, что все они выдают идентичные решения
`AccessResolver` на общей фикстуре.
:::

**Реализуйте, когда** у вас система ролей, которую драйверы из комплекта не покрывают. См.
[Рецепты → Свой резолвер](/ru/guides/recipes).

#### `Vaulter\AzGuard\Contracts\AzGuardGuard`

Узкий принадлежащий Vaulter шов, который удовлетворяет актор хоста на базе AzGuard, так что
`vaulter-core` не зависит от AzGuard **никак**. Драйвер прав `azguard` общается только с этим
интерфейсом — никогда с классами AzGuard. Оба метода — решения AzGuard v1
(`hasPermission(on: $entity)` и `hasPermission(on: AssignmentScopeRef::of($type, $id))`);
метода super-admin нет — super-admin AzGuard получает роль owner через эти решения.

```php
interface AzGuardGuard
{
    public function hasScopedPermission(string $permission, object $entity): bool;

    public function hasPermissionIn(string $contextType, int|string $contextId, string $permission): bool;
}
```

Контракт поставляется `axioma-studio/vaulter-azguard`, вместе с реальным
`AzGuardGuardAdapter` и тестовым `Vaulter\AzGuard\Testing\FakeAzGuardGuard`.
Provider регистрирует драйвер и адаптер автоматически.

**Используйте, когда** `config('vaulter-drive.access.permission_driver') = 'azguard'`:
установите `composer require axioma-studio/vaulter-azguard`, добавьте
`Vaulter\AzGuard\Contracts\AzGuardBackedUser` на модель пользователя, зарегистрируйте
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

#### `Vaulter\Media\Contracts\Cas\BlobPins`

Истина о живости блоба. Владелец, который держит байты живыми (drive со своими связями ревизий), ставит пин на
блоб под непрозрачным `owner_type` (`<module>.<what>`) и `reference_key`, выведенным из его собственной строки,
**в той же транзакции, что записывает эту строку**, и снимает пин в транзакции, удаляющей строку. Сборка мусора
и сверка `ref_count` читают только пины в `v_blob_references`; `ref_count` — кэш. Каждый метод выполняется в
транзакции вызывающего (вызов вне транзакции бросает `LogicException`).

```php
interface BlobPins
{
    public function pin(Blob $blob, string $ownerType, string $referenceKey): void;

    public function unpin(Blob|int|string $blob, string $ownerType, string $referenceKey): void;

    /** Только для собственного purge или отката владельца; возвращает число снятых пинов. */
    public function releaseOwner(string $ownerType, ?Closure $keys = null): int;

    /** @return array<string, int> */
    public function countByOwner(): array;
}
```

`pin()` бросает `BlobUnavailableException` (`blob_unavailable`), когда байтов блоба уже нет: закоммитьте их заново
через `BlobStore::commitStaged()` и пините возвращённый блоб. Блоб в состоянии deleting или deleted не пинится
никогда. Пины остаются, когда модуль-владелец выключен или удалён; снимает их только purge владельца.

**Реализуйте, когда** добавляете модуль, который держит блобы живыми.

::: tip Какой CAS-контракт мне реализовывать?
Для собственного байтового бэкенда (S3 / локальный) реализуйте `StorageDriver` и привяжите
его — `ContentStore` и `BlobStore` остаются как есть. См.
[Рецепты → Драйвер хранилища](/ru/guides/recipes).
:::

---

### Collaboration

#### Протокол коллаборации

Публичный `CollaborationProtocolDriver` получает проверенный `CollaborationGrant` и возвращает нейтральную сессию; доступ проверяет общая policy. См. [протоколы коллаборации](/ru/extending/collaboration-protocols).

`Vaulter\Documents\Access\CollaborationAccessPolicy::evaluateAuthenticationReference()` принимает те же actor,
drive, node, tenant, correlation id, потолок режима и необязательную версию схемы клиента, что `evaluateReference()`.
Результат — `?CollaborationAccessDecision`: `null` означает живой документ в указанной области, на который актор
потерял View; грант не выдаётся. Невалидная ссылка по-прежнему даёт `NodeNotFoundException`. Разрешённое решение
проходит те же проверки policy. Для доступа к содержимому остаётся строгий `evaluateReference()`.

#### `Vaulter\Documents\State\CollaborativeState` и `PendingStateDrainer`

Нейтральный контракт состояния, который documents предлагает транспорту (API): `lock($nodeId)` берёт блокировки дерева,
узла и документа в фиксированном порядке и читает состояние, `markPending()` ставит pending-маркер
`(поколение, max(seq))` в транзакции append, `publishCheckpoint()` заменяет рабочее состояние одним compare-and-set по
поколению и версии состояния и снимает маркер, только если чекпоинт его покрывает, а `pendingDocuments()` перечисляет
документы с маркером. Каждый метод выполняется внутри открытой транзакции Vaulter.
`PendingStateDrainer::drain(PendingMarker $marker)` (SPI) реализует модуль коллаборации: documents вызывает его вне
транзакции перед restore, REST replace, чтением контента или экспортом pending-документа, затем сам перечитывает маркер и
отвечает `409 document_state_pending`, если он всё ещё стоит.

См. [Совместное редактирование](/ru/guides/collaboration).

---

### Handlers

#### `Vaulter\Drive\Contracts\Handlers\FileHandler`

Подключаемый обработчик обработки документов — заголовочная фича расширяемости по форматам.
Обработчик заявляет набор mime-типов (точных или вайлдкардов вроде `"image/*"`) и/или
расширений, декларирует свои возможности и фронтенд-дескриптор монтирования на каждый файл,
а `Vaulter\Drive\Handlers\FileHandlerRegistry` резолвит лучший из них для `FileDescriptor`. Регистрируйте через
`config('vaulter-drive.handlers')` (список FQCN), тегированную привязку `'vaulter.file_handlers'` или
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

#### `Vaulter\Drive\Contracts\Handlers\FileHandlerRegistry`

Общий реестр drive принимает обработчики из конфига, контейнерного тега
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

#### `Vaulter\Drive\Contracts\Handlers\NodePresenter`

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
помещаться в колонку токена (≤ 64 символов). Генератор задаётся в `config('vaulter-drive.sharing.token_generator')`.

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

#### `Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler`

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
ULID стоит по умолчанию, чтобы морф-ключи совпадали с экосистемой Corex/AzGuard; `uuid7`
ложится на нативные Postgres-колонки `uuid`; `bigint` — легаси-путь с автоинкрементом.
См. [Авторизация → Мультитенантность](/ru/concepts/tenancy) для выравнивания морфов.
:::

### Смотрите также

- [Фасад](/ru/reference/php-api) — методы, которые принимают и возвращают всё это
- [Справочник по конфигурации](/ru/reference/config) — ключи привязок
- [Справочник по событиям](/ru/reference/events) — что диспатчится через эти швы

## Поддерживаемая поверхность

То, что Vaulter обещает сохранять, размечено в коде, а не перечислено вручную. Каждый тип `packages/*/src` несёт ровно один
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
границ (`scan-i13`, режим report до заморозки 1.0).


### Идентичность читателя синхронизации

`NodeSnapshot::$syncKey` имеет тип `?string` и проецируется для текущего читателя;
сохраняйте его с локальным деревом. `NodeChange` содержит `operation`, `syncKey`,
`nodeId`, `subtree`, `node`, `nodeSequence`; remove не раскрывает raw id, причину или снимок.
`ChangesConfig::keyGeneration()` и `cursorMaxAgeSeconds()` возвращают проверенные настройки.
`ChangeOperations::startCursor()` вызывается до первого обхода и вне внешней транзакции;
`list()` сохраняет чтение внутри транзакции. Внутренний метод получения ключа —
`ReaderSyncIdentity::cursorSubkey()`; новый публичный API `cursorKey` не вводится.
Общие receipts хранят нейтральные снимки; ключ проецируется после текущей проверки прав
replay, включая document content. См. [Журнал изменений](/ru/concepts/events).
