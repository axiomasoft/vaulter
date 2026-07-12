---
title: Рецепты
description: Готовые, копируемые сценарии интеграции Vaulter — хранилище для мультитенантного SaaS, привязка Drive к CRM-задаче, свои драйверы прав, свои обработчики форматов, переключение диска на S3 и сборка мусора по блобам.
outline: deep
---

# Рецепты

Практичные сквозные рецепты для типичных способов встроить Vaulter. Каждый опирается на
реальный контракт или config-ключ — см. [Архитектуру](/ru/guide/architecture) для общей
модели и [Конфигурацию](/ru/guide/configuration) для каждого ключа в полном объёме.

[[toc]]

## Привязать Drive к CRM-задаче или проекту {#attach-drive}

Главный сценарий: дать любой сущности хоста собственный файлово-папочный Drive **без миграций**.
`Vaulter::for($entity)` находит-или-создаёт строку `v_storages` по полиморфной тройке
`(owner_type, owner_id, panel)` и подготавливает корневую папку — идемпотентно, поэтому двойной
вызов возвращает то же хранилище.

::: code-group

```php [Controller]
use Vaulter\Facades\Vaulter;

final class TaskDriveController
{
    public function index(\App\Models\Task $task)
    {
        // Authorize through your app, then open the Drive for this task.
        $drive = Vaulter::for($task);

        return response()->json([
            'storage' => $drive->storage()->id,
            'nodes'   => $drive->list(), // Collection<Node> of the root's children
        ]);
    }

    public function store(\App\Models\Task $task, \Illuminate\Http\Request $request)
    {
        $drive = Vaulter::for($task);

        $node = $drive->upload(
            $request->file('document'),
            actorId: $request->user()->getKey(),
        );

        return response()->json(['node' => $node->id], 201);
    }
}
```

```vue [Vue]
<script setup lang="ts">
import { VaulterDrive } from '@vaulter/vue'

// The storage id resolved server-side (e.g. from Vaulter::for($task)->storage()->id).
const props = defineProps<{ storageId: string }>()
</script>

<template>
  <VaulterDrive :storage-id="props.storageId" />
</template>
```

:::

Для **выделенного** Drive на конкретном типе сущности — со своими фича-флагами и Filament-ресурсом
— определите Storage Panel вместо панели `default`:

```bash
php artisan vaulter:make-panel task_drive --host="App\\Models\\Task"
```

Это создаст `app/Vaulter/Panels/TaskDriveStoragePanel.php`. Отредактируйте её фича-флаги, затем
зарегистрируйте FQCN провайдера в `config/vaulter.php`:

```php
// config/vaulter.php
'panels' => [
    \App\Vaulter\Panels\TaskDriveStoragePanel::class,
],
```

```php
// app/Vaulter/Panels/TaskDriveStoragePanel.php
use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

final class TaskDriveStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('task_drive')
            ->host(\App\Models\Task::class)
            ->storageType('team')
            ->feature('document_versions', true) // enable in-app rich-text editing
            ->feature('share_links', true);      // enable bearer share links
    }
}
```

Теперь смонтируйте его по id панели:

```php
$drive = Vaulter::for($task, panel: 'task_drive');
```

::: tip Без миграций
Панель — это конфиг-конверт поверх **общей** схемы `v_*`: `make-panel` **не** пишет
миграций и **не** трогает конфиг. Регистрация FQCN и (только для редкого режима собственных
таблиц) генерация миграций — намеренно отдельные шаги. См.
[ADR-0009](/adr/0009-storage-panels).
:::

## Хранилище для мультитенантного SaaS {#multi-tenant}

Vaulter **не** навязывает мультитенантность — так задумано ([ADR-0003](/adr/0003-embeddability-data-model)).
Границей тенанта владеет хост; Vaulter даёт швы, чтобы её выразить. Здесь три подвижные части.

### 1. Добавьте колонку тенанта

```php
// config/vaulter.php
'tenant' => [
    'enabled' => true,        // VAULTER_TENANT_ENABLED — adds a tenant_id column to the schema
    'column'  => 'tenant_id',
],
```

### 2. Подставьте тенант-осведомлённую модель

Vaulter резолвит каждую модель через `config('vaulter.models.*')`. Направьте одну (или все) на
собственный подкласс, который применяет ваш scope тенанта — сам Vaulter никогда не скоупит по
`tenant_id`:

```php
// app/Models/Vaulter/Node.php
namespace App\Models\Vaulter;

use Vaulter\Models\Drive\Node as BaseNode;
use App\Models\Concerns\BelongsToTenant; // your existing global-scope trait

final class Node extends BaseNode
{
    use BelongsToTenant;
}
```

```php
// config/vaulter.php
'models' => [
    // ...
    'node'    => \App\Models\Vaulter\Node::class,
    'storage' => \App\Models\Vaulter\Storage::class,
],
```

### 3. Согласуйте область дедупликации и идентификаторы

Дедупликация по тенанту уже стоит по умолчанию, так что глобальная область дедупликации не может
утечь *самим фактом существования* файла между тенантами ([ADR-0012](/adr/0012-dedup-scope)):

```php
// config/vaulter.php
'dedup' => ['scope' => 'tenant'], // the default; never use 'global' for untrusted tenants
'ids'   => ['default' => 'ulid'], // ULID aligns with CoreX / AzGuard morph keys (ADR-0011)
```

::: warning Область тенанта — ответственность хоста
Если вы выставите `dedup.scope = 'global'` при нескольких тенантах, вы соглашаетесь на побочный
канал существования между тенантами. Держите `tenant` (или `storage`), если тенанты не доверяют
друг другу. Если вы подставляете тенант-осведомлённую модель, именно она — а не Vaulter — обязана
применять scope на *каждом* запросе.
:::

## Написать свой драйвер прав {#permission-driver}

ACL для Drive комбинирует `v_node_grants` со сменной полосой `HostRoleDriver`. Чтобы связать
систему ролей вашего приложения, реализуйте
[`Vaulter\Contracts\Access\HostRoleDriver`](/ru/guide/architecture#authorization). Он
**слеп к узлам**: никогда не получает `Node` — только актора `Authorizable` и `AclScope`
(панель + координаты workspace + владелец узла).

Простейший реальный драйвер наследует автономную полосу `database` и просто расширяет
множество субъектов ролями/командами актора (чтобы применялись строки `v_node_grants`,
адресованные роли):

```php
namespace App\Vaulter;

use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Access\ValueObjects\Subject;
use Illuminate\Contracts\Auth\Access\Authorizable;

final class TeamRoleDriver extends DatabasePermissionResolver
{
    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array
    {
        $subjects = parent::subjectsFor($actor); // [Subject(actor)]

        foreach ($actor->teams as $team) {
            $subjects[] = new Subject(type: 'team', id: $team->getKey());
        }

        return $subjects;
    }
}
```

Более полный драйвер также отвечает за полосу ролей и переопределение супер-админа:

```php
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;

final class AppRoleDriver implements HostRoleDriver
{
    public function override(Authorizable $actor, AclScope $scope): ?Effect
    {
        return $actor->is_super_admin ? Effect::Allow : null; // absolute, step 1
    }

    public function resolve(Subject $subject, Authorizable $actor, AclScope $scope): ?Decision
    {
        // Raise access from a host role — folded as roleAllow into max(aclAllow, roleAllow).
        // It can RAISE access but never override a deny (the final veto still applies).
        if ($actor->canEditIn($scope->workspaceType, $scope->workspaceId)) {
            // Decision::allow(level) defaults bits to the level's bitmask;
            // pass explicit bits only to narrow them.
            return Decision::allow(GrantLevel::Writer);
        }

        return null; // nothing to say — the ACL is the sole authority
    }

    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array
    {
        return [Subject::ofModel($actor)];
    }

    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool
    {
        return $actor->canCreateIn($workspaceType, $workspaceId);
    }
}
```

Зарегистрируйте его как именованный драйвер и выберите:

```php
// config/vaulter.php
'drivers' => [
    'permission' => env('VAULTER_PERMISSION_DRIVER', 'app'),
],

'permission_drivers' => [
    'database' => \Vaulter\Drivers\Permission\DatabasePermissionResolver::class,
    'gate'     => \Vaulter\Drivers\Permission\GatePermissionResolver::class,
    'spatie'   => \Vaulter\Drivers\Permission\SpatiePermissionResolver::class,
    'azgard'   => \Vaulter\Drivers\Permission\AzgardPermissionResolver::class,
    'app'      => \App\Vaulter\AppRoleDriver::class, // your driver
],
```

::: tip Сначала возьмите готовую полосу
Для Laravel Gate, Spatie Permission v8 или AzGuard свой класс **не** нужен — выставьте
`drivers.permission` в `gate` / `spatie` / `azgard` и заполните соответствующий блок конфига
(`gate.*` / `spatie.*` / `azgard.*`). Полосе AzGuard нужен адаптер хоста для
`Contracts\Access\AzgardGuard` — см.
[Устранение неполадок](/ru/guide/troubleshooting#azgard-morphs).
:::

## Написать свой обработчик формата {#format-handler}

`FileHandler` заявляет права на mime-типы / расширения и говорит клиенту, как смонтировать
узел (редактор, превью или скачивание). `DocumentHandlerRegistry` подбирает лучший по
mime → расширение → возможность → приоритет, откатываясь к `DownloadOnlyHandler`. Добавить
формат — это **один класс + один вызов регистрации — без миграции, без правки ядра**
([H.5 / ADR-0004](/adr/0004-content-format)).

```php
namespace App\Vaulter\Handlers;

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\HandlerCapabilities;
use Vaulter\ValueObjects\Handlers\HandlerUi;

final class CadHandler implements FileHandler
{
    public function id(): string { return 'cad'; }

    /** @return list<string> */
    public function mimeTypes(): array { return ['application/acad', 'image/vnd.dwg']; }

    /** @return list<string> */
    public function extensions(): array { return ['dwg', 'dxf']; }

    // Higher wins among handlers matching the same file; DownloadOnly sits lowest.
    public function priority(): int { return 100; }

    public function capabilities(FileDescriptor $file): HandlerCapabilities
    {
        return new HandlerCapabilities(
            editable: false,
            previewable: true,
            downloadable: true,
            thumbnailable: true,
            collaborative: false,
        );
    }

    public function ui(FileDescriptor $file): HandlerUi
    {
        // The Vue component + open route + props the client routes the node to.
        return new HandlerUi(
            component: 'CadViewer',
            route: "nodes/{$file->nodeId}/download",
            props: ['mime' => $file->canonicalMime()],
        );
    }
}
```

Зарегистрируйте его любым из трёх способов — FQCN в конфиге (безопасно для кэша), вызовом в
рантайме или через теговую привязку:

::: code-group

```php [config]
// config/vaulter.php
'handlers' => [
    \Vaulter\Documents\Handlers\TipTapHandler::class,
    \App\Vaulter\Handlers\CadHandler::class, // before the fallbacks
    \Vaulter\Documents\Handlers\PreviewHandler::class,
    \Vaulter\Documents\Handlers\DownloadOnlyHandler::class,
],
```

```php [runtime]
// A service provider's boot()
use Vaulter\Facades\Vaulter;

Vaulter::registerHandler(\App\Vaulter\Handlers\CadHandler::class);
```

```php [tagged]
// A service provider's register()
$this->app->tag([\App\Vaulter\Handlers\CadHandler::class], 'vaulter.handlers');
```

:::

::: details Проверьте дескриптор, уходящий клиенту
`GET nodes/{node}/handler` возвращает `id` подобранного обработчика, его возможности и `HandlerUi`
для узла — ровно то, что читает Vue-клиент, чтобы выбрать реализацию редактора/превью. (Этот
маршрут завязан на фичу `document_versions`.)
:::

## Переключить диск хранилища на S3 {#s3-disk}

Байтовый план Vaulter — это контент-адресуемое хранилище поверх диска Flysystem. Чтобы
использовать S3, обычно достаточно поменять конфиг — свой класс не нужен.

### Направьте Vaulter на диск S3

```php
// config/filesystems.php — a standard Laravel S3 disk
'disks' => [
    's3-vaulter' => [
        'driver' => 's3',
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('VAULTER_S3_BUCKET'),
    ],
],
```

```dotenv
# .env
VAULTER_DISK=s3-vaulter
```

`config('vaulter.storage.default_disk')` — канонический CAS-диск, используемый, когда строка
хранилища не закрепляет собственный `disk`. Штатный `FlysystemStorageDriver` уже делает всё
правильно на S3: условное создание (`If-None-Match: *`), короткоживущие подписанные URL через
`temporaryUrl` и раскладку ключей с квалификацией по области.

::: danger Никогда не версионируйте CAS-бакет
CAS-бакет должен быть **без версионирования**. **Не** используйте lifecycle/versioning S3 как
механизм удаления блобов — удалением владеет Vaulter через свою двухфазную сборку мусора.
Единственное правило lifecycle S3, которое стоит добавить, — `AbortIncompleteMultipartUpload`
как страховка от брошенных резюмируемых загрузок ([ADR-0006](/adr/0006-content-addressed-blobs-gc)).
:::

### Подмените CAS-сервис под особенности объектного хранилища

Если нужно поведение, которого штатный драйвер не покрывает, реализуйте
`Vaulter\Contracts\Cas\StorageDriver` (или `ContentStore` / `BlobStore`) и привяжите:

```php
// config/vaulter.php
'cas' => [
    'content_store'  => \Vaulter\Media\Cas\Sha256ContentStore::class,
    'storage_driver' => \App\Vaulter\S3ChecksumDriver::class, // your driver
    'blob_store'     => \Vaulter\Media\Cas\BlobStore::class,
],
```

Поверхность `StorageDriver`: `disk()`, `put($key, $stream, $disk, $ifNoneMatch, $options)`,
`get()`, `exists()`, `delete()`, `temporaryUrl($key, $expiresAt, $disk)`.

## Свой формат экспорта {#custom-export}

Встроенные экспортёры `html` и `markdown` рендерят TipTap-содержимое документа. Добавьте свой
(PDF, DOCX, …), реализовав `Vaulter\Contracts\Handlers\DocumentExporter`:

```php
namespace App\Vaulter;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Exceptions\Documents\UnsupportedDocumentContentException;
use Vaulter\Documents\ValueObjects\TipTapContent;

final class PdfExporter implements DocumentExporter
{
    public function format(): string { return 'pdf'; }
    public function mimeType(): string { return 'application/pdf'; }
    public function extension(): string { return 'pdf'; }

    public function export(DocumentContent $content): string
    {
        // Контракт опаковый — сужаем до того, что понимает этот экспортёр.
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter(
                $this->format(), TipTapContent::class, $content::class,
            );
        }

        // Binary formats return an encoded string (e.g. base64) + the matching mime.
        return MyPdfLibrary::fromTiptap($content->toArray())->toBytes();
    }
}
```

Зарегистрируйте его через конфиг или в рантайме:

::: code-group

```php [config]
// config/vaulter.php
'exporters' => [
    'html'     => \Vaulter\Export\HtmlExporter::class,
    'markdown' => \Vaulter\Export\MarkdownExporter::class,
    'pdf'      => \App\Vaulter\PdfExporter::class,
],
```

```php [runtime]
use Vaulter\Facades\Vaulter;

Vaulter::registerExporter(new \App\Vaulter\PdfExporter());
```

:::

После этого он доступен по `GET nodes/{node}/export?format=pdf` и через Vue-composable
`useDocumentNode(nodeId).exportAs('pdf')`.

## Своя форма share-токена {#share-token}

По умолчанию share-токены — случайные строки. Чтобы выпускать короткие, хешированные или
префиксованные токены, реализуйте `Vaulter\Contracts\Sharing\ShareTokenGenerator` (токен должен
быть уникальным и умещаться в колонку токена, ≤ 64 символов):

```php
namespace App\Vaulter;

use Vaulter\Contracts\Sharing\ShareTokenGenerator;

final class ShortTokenGenerator implements ShareTokenGenerator
{
    public function generate(): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12));
    }
}
```

```php
// config/vaulter.php
'share' => [
    'token_generator' => \App\Vaulter\ShortTokenGenerator::class,
    'url_base'        => env('VAULTER_SHARE_URL_BASE', '/share'),
    'prefix_length'   => 8,
],
```

::: info Хранится только хеш
Хранится всегда лишь `sha256` токена (`token_hash`), плюс короткий `token_prefix` для поиска на
стороне владельца. Сырой токен возвращается создателю ровно один раз и больше никогда не
раскрывается ([ADR-0013](/adr/0013-two-sharing-channels)).
:::

## Сборка мусора по блобам по расписанию {#blob-gc}

Удаление в корзину или замена файлов оставляют блобы без ссылок. Двухфазная сборка мусора
помечает их надгробием (tombstone), затем сметает после окна отсрочки; отдельная задача
reconcile чинит дрейф `ref_count`.

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
use Illuminate\Support\Facades\Schedule;

// Trash retention → permanently force-deletes nodes past trash.retention_days.
Schedule::command('vaulter:purge-trash')->daily();

// Two-phase blob GC: tombstone unreferenced blobs, sweep tombstoned past grace.
Schedule::command('vaulter:gc-blobs')->hourly();

// Repair ref_count drift + flag dangling rows (run less often, off-peak).
// Add --orphans to ALSO disk-walk for orphan objects (report-only; see Storage & Blobs).
Schedule::command('vaulter:reconcile-blobs')->dailyAt('03:00');

// Reclaim resumable-upload sessions older than uploads.session_ttl_hours.
Schedule::command('vaulter:sweep-uploads')->hourly();
```

Окно отсрочки — `config('vaulter.gc.grace_hours')` (по умолчанию 24, с нижней границей 1ч). Оно
**обязано** быть ≥ вашей худшей задержки commit многочастевой загрузки, чтобы загрузка в полёте
успела воскресить помеченный надгробием блоб первой.

```bash
# Override the grace window ad hoc, and also clear crashed-staging PENDING rows.
php artisan vaulter:gc-blobs --grace=48 --pending
```

::: danger --pending сметает упавшие загрузки
`--pending` сметает блобы в статусе `PENDING`, оставленные упавшим шагом стейджинга. Запускайте
его только когда уверены, что ни одна загрузка не в полёте, либо держите окно отсрочки щедрым —
слишком короткая отсрочка может смести блоб, на который вот-вот сошлётся медленный commit
многочастевой загрузки.
:::

## Надёжность — outbox и вебхуки {#webhooks}

Фича `reliability` стейджит каждое доменное событие Drive в `v_outbox` (после коммита), затем
relay разносит его в подписанные строки `v_webhook_deliveries` по каждому активному подписанному
эндпоинту:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:relay-outbox')->everyMinute();    // outbox → deliveries → queue
Schedule::command('vaulter:retry-webhooks')->everyFiveMinutes(); // retry failed deliveries
Schedule::command('vaulter:prune-webhooks')->daily();        // reclaim settled audit history
```

Эндпоинтами подписчиков управляют через маршруты `webhook-endpoints` (завязаны на право manage
из `WebhookEndpointPolicy`). Каждый POST доставки несёт подпись HMAC-SHA256
(`X-Vaulter-Signature`), метку времени (`X-Vaulter-Timestamp`) и имя события
(`X-Vaulter-Event`); потребители **дедуплицируют по `event_id`** из полезной нагрузки, поскольку
доставка «хотя бы один раз». Настройте `config('vaulter.webhooks.*')` (`max_attempts`, `backoff`,
`disable_after_failures`).

### Обрезка урегулированной истории outbox и вебхуков

Разнесённые строки outbox и терминальные доставки вебхуков — это append-only аудит-след, который
иначе растёт бесконечно. `vaulter:prune-webhooks` освобождает **урегулированные** строки за
пределами окна хранения — и *только* урегулированные:

```bash
# Prune settled rows older than config('vaulter.webhooks.retention_days') (default 30):
php artisan vaulter:prune-webhooks

# Override the window, or preview the exact counts without deleting anything:
php artisan vaulter:prune-webhooks --days=7
php artisan vaulter:prune-webhooks --dry-run
```

Она удаляет ровно два вида строк, которые больше никогда не меняются:

- **разнесённые строки outbox** — статус `dispatched`, по `dispatched_at`;
- **терминальные доставки** — статус `delivered` или `dead` (DLQ), по `updated_at` (время
  терминализации).

Строки в полёте **никогда** не трогаются: строка outbox в статусе `pending` (ею всё ещё владеет
relay) и доставка `pending`/`failed` (ею всё ещё владеет sweep повторов по расписанию) остаются
нетронутыми независимо от возраста. `--dry-run` сообщает счётчики, которые он *удалил бы*, ничего
не удаляя. Команда завязана на фичу `reliability` — при выключенной фиче она ничего не делает и
печатает уведомление (таблиц `v_outbox` / `v_webhook_*` может не быть). Значение хранения по
умолчанию живёт в `config('vaulter.webhooks.retention_days')` (30, с нижней границей 0).
Планируйте её рядом с командами relay/retry выше.

## Тестирование со штатными дублями {#testing}

Vaulter поставляет тестовые дубли под `Vaulter\Testing\*`, чтобы потребитель мог подняться без
проводки реальной системы ролей:

- **`NullRoleDriver`** — открытый драйвер (каждый актор — супер-админ) для тестов / локальной
  разработки. В production он отклоняется, так что не может тихо открыть боевую установку.
- **`FakeAzgardGuard`** — дубль `Contracts\Access\AzgardGuard` для тестирования полосы `azgard`
  без установленного AzGuard.

```php
// In a Pest test, force the open driver for the duration of the test.
config()->set('vaulter.drivers.permission', 'null'); // non-production only

$drive = Vaulter::for($task);
$node  = $drive->upload('hello', name: 'note.txt');

expect($node->name)->toBe('note.txt');
```

## Дальше

- [Архитектура](/ru/guide/architecture) — слои и швы, в которые встраиваются эти рецепты.
- [Устранение неполадок](/ru/guide/troubleshooting) — что ломается и как чинить.
- [Конфигурация](/ru/guide/configuration) — каждый config-ключ в полном объёме.
