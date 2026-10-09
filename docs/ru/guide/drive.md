---
title: Drive — хранилища, папки, файлы и узлы
description: Vaulter Drive — одно хранилище на сущность хоста, единое дерево v_nodes и хэндл DriveHandle (createFolder / upload / move / copy / rename / trash / restore / forceDelete / list).
outline: deep
---

# Drive

**Drive** — это ядро предметной области Vaulter: каждая сущность хоста (проект,
команда, пользователь, …) получает **контейнер-хранилище**, а каждое хранилище
держит единое дерево смежности из **узлов** — папок, файлов и документов в одной
таблице `v_nodes`. Ко всему этому вы обращаетесь через один эргономичный хэндл:
`Vaulter::for($entity)`, который возвращает [`DriveHandle`](/ru/reference/facade).

::: tip Один хэндл, одно хранилище
`DriveHandle` привязан ровно к одной паре `(Drive, profile)`. Откройте его один
раз на запрос и переиспользуйте — каждый метод ниже делегирует небольшому Action
(на запись) или read-репозиторию; сам хэндл не добавляет бизнес-логики и не делает
проверок доступа (авторизация обеспечивается на [границе REST](/ru/reference/rest-api)).
:::

## Хранилища

**Хранилище** — это владеемый полиморфный контейнер-диск (`v_storages`). Оно
монтируется на сущность хоста через morph `owner_type` / `owner_id`, ограничено
рамками [панели](/ru/concepts/storage-panels) и несёт узел корневой папки плюс учёт
использованного/квоты, который показывает UI.

### Открытие Drive

`Vaulter::for()` — основная точка входа для интеграции. Она **находит-или-создаёт**
строку хранилища для сущности (идемпотентно — второй вызов вернёт то же самое
хранилище), автоматически провижинит корневую папку и отдаёт `DriveHandle`:

```php
use Vaulter\Facades\Vaulter;

$project = Project::find(1);

// Панель по умолчанию.
$drive = Vaulter::for($project);

// Именованная панель (см. Storage Panels).
$drive = Vaulter::for($project, panel: 'attachments');
```

Тройка владельца `(owner_type, owner_id, panel)` — это ключ идемпотентности,
защищённый частичным UNIQUE-индексом: конкурентные первые обращения сходятся на
одной строке.

::: details Уже есть модель Drive? Используйте `forStorage()`
Когда строка хранилища уже разрешена (например, `Drive` привязан через
route-model-binding), пропустите поиск по владельцу с помощью
`Vaulter::forStorage($storage)`. Это тот шов, который использует HTTP-слой; панель
хэндла отражает собственную `panel` хранилища.

```php
$drive = Vaulter::forStorage($storage);
```
:::

### Корневой узел

Каждое хранилище открывается в корневой узел `kind=folder` (его `name` — пустая
строка). Достаньте его через `root()`; передавайте его — или любой узел-папку — как
назначение для методов записи ниже. Если назначение не указано, используется корень.

```php
$root = $drive->root();          // Vaulter\Models\Drive\Node
$storage = $drive->model();    // Vaulter\Models\Drive\Drive
$profile = $drive->profile();  // Vaulter\Profiles\Profile
$profileId = $profile->getId(); // string
```

### Квота и использование

Хранилище отслеживает `used_bytes` и опциональную `quota_bytes` (nullable = без
квоты). Оба поля приводятся к integer на модели и отдаются как есть через
[`DriveResource`](/ru/reference/rest-api#storages), чтобы UI мог отрисовать шкалу
использования:

```php
$storage = $drive->model();

$storage->used_bytes;   // int — сейчас потреблённые байты
$storage->quota_bytes;  // ?int — null означает без ограничений
```

::: info `used_bytes` ведёт сам Drive
`used_bytes` продвигается/освобождается Action-ами загрузки и жёсткого удаления
(purge отцепляет рёбра версия↔блоб и освобождает квоту). У хэндла нет публичного
метода «установить квоту» — задайте `quota_bytes` прямо на модели хранилища или
через default в [Storage Panel](/ru/concepts/storage-panels).
:::

## Узлы

Каждая папка, файл и документ — это строка в `v_nodes`, различаемая по своему
[`NodeKind`](/ru/reference/contracts):

| Kind | Значение | Полезная нагрузка лежит в |
|---|---|---|
| `NodeKind::Folder` | `folder` | — (виртуальный каталог, держит потомков) |
| `NodeKind::File` | `file` | `v_files` / `v_file_versions` / `v_blobs` |
| `NodeKind::Document` | `document` | `v_documents` + `v_document_revisions` (TipTap/Yjs) |
| `NodeKind::Shortcut` | `shortcut` | указатель на другой узел (`shortcut_target_id`) |

Эта страница покрывает папки и файлы через `DriveHandle`. У узлов-документов
(rich-text/Yjs) свой поток создания/контента/версий — см.
[Редактирование](/ru/guide/documents) и [Версии](/ru/guide/documents).

## Методы DriveHandle

Все методы записи принимают опциональный `$actorId` (id пользователя хоста, которому
атрибутируется операция) и возвращают затронутый `Node`; `forceDelete` возвращает
`void`.

### `createFolder()`

```php
public function createFolder(
    string $name,
    ?Node $parent = null,        // null = корень хранилища
    int|string|null $actorId = null,
): Node
```

```php
$folder = $drive->createFolder('Invoices');

// Вложенно в существующую папку.
$sub = $drive->createFolder('2026', parent: $folder, actorId: $user->id);
```

Имя — это один **сегмент** дерева; оно не может содержать разделителей пути
(`/` или `\`). Путь строится созданием папок уровень за уровнем.

### Имена, лимиты, избранное и свойства

**Имена.** Каждая запись нового имени (создание, загрузка, переименование, копия с именем,
переименование при конфликте в move или restore) приводит его к Unicode NFC и проверяет
политикой имён профиля. Ничего не обрезается: `" a.txt"` отклоняется, а не меняется. Есть две
политики, профиль выбирает одну:

- `portable-v1` (по умолчанию) — имена, которые примет любая настольная ОС и ZIP: без
  `< > : " | ? *`, без пробелов по краям, без точки в конце, без имён устройств Windows (`CON`,
  `PRN`, `AUX`, `NUL`, `COM0`–`COM9`, `LPT0`–`LPT9`, с любым расширением), без `desktop.ini` и
  `.DS_Store`, не длиннее 255 байт UTF-8 и 255 единиц UTF-16;
- `permissive-v1` — только базовые правила: не пустое, не `.`/`..`, без `/`, `\` и управляющих
  символов, не длиннее 255 байт UTF-8.

Отклонённое имя — `InvalidNodeNameException` (422 `invalid_node_name` с `reason`). Уже
сохранённые имена не меняются; переименование в них обязано пройти политику.

Соседи уникальны по `name_key` = NFC(полное свёртывание регистра(NFC(name))), сравнение
побайтовое на любой СУБД: `Report.pdf`/`report.pdf`, `Résumé.pdf` в NFC/NFD и
`STRASSE`/`straße` — одно имя, а узел может сменить регистр собственного имени. Узлы в корзине
не считаются.

**Лимиты.** Политика диска профиля задаёт лимиты дерева и содержимого; Actions проверяют их под
блокировками изменяемых строк на всех путях:

```php
$profile->drivePolicy(fn (DrivePolicyBuilder $p) => $p
    ->namePolicy('portable-v1')
    ->maxFolderDepth(100)        // уровней ниже корня
    ->maxFolderChildren(10_000)  // дети в корзине считаются до очистки
    ->maxFileBytes(null)         // null = только лимит загрузки установки
    ->maxPropertiesBytes(16_384)
    ->maxPropertyCount(100));
```

Превышение — `FolderChildrenLimitExceededException` (409), `TreeDepthLimitExceededException`
(409) или `FileSizeLimitExceededException` (413). Снижение лимита ничего не удаляет: отклоняются
только операции, ухудшающие превышение.

`maxFileBytes` проверяется для каждого нового файла копии, в том числе внутри
копируемой папки. Восстановление ревизии должно уложиться в текущий лимит, если
увеличивает размер файла; уменьшение размера допустимо после снижения лимита.
Отказ не меняет ревизии, квоту и сохранённые байты.

**Избранное** персонально: `nodes()->star($node)`, `unstar($node)` и `starred(?ListOptions)`
(видимые узлы актора со звездой, новые сначала). Звезда не меняет ни sequence узла, ни его
времена; ответы REST несут `viewer.starred` для пользователя запроса. Ручного порядка детей нет;
сортировка — через `ListOptions`.

**Свойства** — строки по пространствам имён: `nodes()->properties($node)` возвращает
`[namespace => [key => value]]`, а `setProperties($node, 'acme.crm', set: ['deal' => '42'],
unset: ['old'], expectedSequence: …)` атомарно патчит одно пространство и увеличивает sequence
узла. Пространство — `^[a-z0-9][a-z0-9.-]{0,127}$`, ключ — `^[A-Za-z0-9_.-]{1,64}$`, значение —
строка UTF-8 до 1024 байт; `vaulter` и `vaulter.*` пишет только система. Нарушение —
`InvalidPropertiesException` (422 `invalid_properties`).

**Времена.** `content_modified_at`/`content_modified_by` — последнее изменение содержимого
(ревизия файла, запись состояния документа); `updated_at` меняется при любом изменении.
`UploadOptions::make()->clientModifiedAt($time)` сохраняет время изменения у загружающего на
ревизии — только как информацию.

### `upload()`

Застейджить байты и создать новый узел-**файл**. `$source` гибок — Vaulter приводит
любой из этих вариантов к перематываемому потоку, прежде чем Action застейджит и
дедуплицирует его:

- PSR-7 `StreamInterface`
- `SplFileInfo` / Laravel `UploadedFile`
- открытый **resource** потока
- «сырую» **строку** байтов

```php
public function upload(
    mixed $source,
    ?Node $into = null,          // null = корень хранилища
    ?string $name = null,        // переопределяет сохранённое имя
    ?string $mimeType = null,
    ?string $originalName = null,
    int|string|null $actorId = null,
): Node
```

::: code-group
```php [Из UploadedFile]
// например, внутри контроллера
$node = $drive->upload(
    source: $request->file('document'),
    into: $folder,
    actorId: $request->user()->id,
);
```

```php [Из строки]
$node = $drive->upload(
    source: "id,name\n1,Ada\n",
    name: 'people.csv',
    mimeType: 'text/csv',
);
```

```php [Из пути]
$node = $drive->upload(
    source: new SplFileInfo('/tmp/report.pdf'),
    into: $folder,
);
```
:::

Когда `$name` не указан, Vaulter выводит сохранённое имя из источника (client
original name для `UploadedFile`, basename для `SplFileInfo`, иначе `upload.bin`).

::: tip Резюмируемые / большие загрузки
`DriveHandle::upload()` — это внутрипроцессный путь: он читает весь источник в
поток. Для браузерных загрузок больших файлов используйте вместо этого эндпоинты
**резюмируемой загрузки** (tus / S3-multipart), которые собирают байты через *тот
же* безгоночный CAS-коммит. См. [Загрузки](/ru/guide/uploads).
:::

### `uploadVersion()`

Закоммитить **новую версию** на существующий узел-файл — `version_no` инкрементится,
head перенаправляется, предыдущая версия вытесняется:

```php
public function uploadVersion(
    Node $target,
    mixed $source,
    ?string $mimeType = null,
    ?string $originalName = null,
    int|string|null $actorId = null,
): Node
```

```php
$node = $drive->uploadVersion($existingFile, $request->file('document'));
```

### `move()`

Переместить узел под новую родительскую папку (null = корень):

```php
public function move(Node $node, ?Node $into = null, int|string|null $actorId = null): Node
```

```php
$drive->move($node, into: $archiveFolder);
$drive->move($node);  // обратно в корень
```

### `rename()`

```php
public function rename(Node $node, string $name, int|string|null $actorId = null): Node
```

```php
$drive->rename($node, 'Q4 Report (final).pdf');
```

### `copy()`

Глубоко скопировать узел (и поддерево папки) в назначение; `$name` переопределяет имя
копии (null сохраняет имя источника):

```php
public function copy(Node $node, ?Node $into = null, ?string $name = null, int|string|null $actorId = null): Node
```

```php
$copy = $drive->copy($node, into: $templatesFolder, name: 'Template');
```

### `trash()`

Мягко удалить узел (выставляет `trashed_at`). Поддерево можно восстановить, пока его
не восстановят или не вычистят. См. [Корзину](/ru/guide/trash).

```php
public function trash(Node $node, int|string|null $actorId = null): Node
```

```php
$drive->trash($node);
```

### `restore()`

Вернуть корень корзины (и удалённые вместе с ним узлы) в исходного родителя. Если родитель исчез
или сам в корзине, без явного назначения (`RestoreOptions`) будет `RestoreDestinationRequiredException`;
узел, удалённый вместе с папкой, даёт `NodeTrashedByAncestorException`. См. [Корзина](/ru/guide/trash):

```php
public function restore(Node $node, int|string|null $actorId = null): Node
```

```php
$drive->restore($node);
```

### `forceDelete()`

Навсегда удалить узел и его поддерево — это отцепляет рёбра версия↔блоб (байты
становятся кандидатами на сборку мусора) и освобождает квоту. Возвращает `void`.

```php
public function forceDelete(Node $node, int|string|null $actorId = null): void
```

```php
$drive->forceDelete($node);
```

::: danger Необратимо
`forceDelete()` нельзя отменить. Для восстановимого удаления используйте `trash()`;
пусть вашу политику хранения применяет команда
[`vaulter:purge-trash`](/ru/guide/trash#scheduled-purge), а не жёсткое удаление в
коде приложения.
:::

### `list()`

Перечислить **непосредственных потомков** папки (или корня, если null) — горячий
листинг:

```php
/** @return \Illuminate\Database\Eloquent\Collection<int, Node> */
public function list(?Node $folder = null): Collection
```

```php
foreach ($drive->list() as $node) {        // содержимое корня
    echo $node->name;
}

$children = $drive->list($folder);          // содержимое папки
```

::: info Только живые узлы
`list()` возвращает живых (не в корзине) непосредственных потомков. Для
пагинированных листингов, поиска и вида корзины используйте
[REST-эндпоинты](/ru/reference/rest-api#drive)
(`storages/{storage}/nodes`, `…/search`, `…/trash`), которые пагинируют курсором.
:::

## Жизненный цикл диска

У диска есть `status` — `Vaulter\Enums\Drive\DriveStatus`: `active`, `archived` или `deleting`
(закрытый enum: клиент обязан считать неизвестное значение недоступным). Меняет его handle:

```php
$snapshot = $drive->archive();     // active → archived: только чтение
$snapshot = $drive->unarchive();   // archived → active
$snapshot = $drive->delete();      // active|archived → deleting, затем асинхронная чистка
```

Все три требуют `Manage` на корне (или system-актора) и возвращают `DriveSnapshot` с новым
`status`; переход из неверного состояния даёт `InvalidDriveStateException`.

- **archived** — любое изменение, чистка и создание от не-system актора дают
  `DriveArchivedException` (409 `drive_archived`), включая правки грантов и share-ссылок и
  удаление собственного комментария. Чтение и скачивание работают, существующие share-ссылки
  отдают только чтение. Проверка повторяется под tree-lock, поэтому запись, гонящаяся с
  архивацией, либо успевает до неё, либо отклоняется.
- **deleting** — диска для всех уже нет: `drive_not_found` / `node_not_found`; видит его только
  system-актор `drive-purge`. `PurgeDriveJob` (unique, пять попыток) чистит по 100 детей корня за
  шаг, каждого в своей транзакции через тот же путь жизненного цикла, CAS и квоты, что и
  `forceDelete`, затем удаляет корень и строку диска. Activity и outbox остаются историей, байты
  освобождает GC блобов. Прерванную чистку продолжает `vaulter:drives:purge`.

`DriveArchived`, `DriveUnarchived` и `DriveDeleted` диспатчатся после коммита (`DriveDeleted`
несёт только идентификаторы — строки уже нет).

### Удаление вместе с владельцем — `HasDrives`

```php
use Vaulter\Concerns\HasDrives;

class Project extends Model
{
    use HasDrives;
}

$project->drive();          // DriveHandle (как Vaulter::drive($project))
$project->drives;           // диски, смонтированные на нём (morphMany)
```

При `vaulter.drives.delete_with_owner = true` (по умолчанию `false`) удаление владельца удаляет его
диски от system-актора `owner-deleted`. Модель с `SoftDeletes` реагирует на `forceDeleted`, а не на
`deleted`, поэтому мягко удалённый владелец сохраняет диски.

## События

Записи Drive диспетчат доменные события, которые вы можете слушать:

| Событие | Диспетчится |
|---|---|
| `Vaulter\Drive\Events\Drives\DriveCreated` | первый провижининг хранилища |
| `Vaulter\Drive\Events\Nodes\FolderCreated` | `createFolder()` |
| `Vaulter\Drive\Events\Files\FileUploaded` | `upload()` (новый файл) |
| `Vaulter\Drive\Events\Files\FileRevisionAdded` | `uploadVersion()` / резюмируемый коммит |
| `Vaulter\Drive\Events\Nodes\NodeMoved` | `move()` |
| `Vaulter\Drive\Events\Nodes\NodeRenamed` | `rename()` |
| `Vaulter\Drive\Events\Nodes\NodeCopied` | `copy()` |
| `Vaulter\Drive\Events\Nodes\NodeTrashed` | `trash()` |
| `Vaulter\Drive\Events\Nodes\NodeRestored` | `restore()` |
| `Vaulter\Drive\Events\Nodes\NodePurged` | `forceDelete()` |

Полные полезные нагрузки — в [справочнике событий](/ru/reference/events).

## В браузере

Те же операции доступны через REST и обёрнуты composable-функцией `useNodes` из
`@vaulter/vue`, к которой привязывается UI `<VaulterDrive>`:

```ts
import { useNodes } from '@vaulter/vue'

const { list, createFolder, upload, move, rename, copy, trash, restore } = useNodes(storageId)

await createFolder({ name: 'Invoices' })
await list({ parentId: folderId })
```

## См. также

- [Корзина и восстановление](/ru/guide/trash) — жизненный цикл корзины и плановая вычистка
- [Справочник REST API](/ru/reference/rest-api) — каждый маршрут Drive с параметрами и ответами
- [Загрузки](/ru/guide/uploads) — резюмируемые (tus / S3-multipart) загрузки
- [Storage Panels](/ru/concepts/storage-panels) — настройка изолированных дисков на сущность
- [API `DriveHandle`](/ru/reference/facade) — полные сигнатуры методов
