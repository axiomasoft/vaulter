---
title: Мультитенантность
description: Как Vaulter вписывается в мультитенантное Laravel-приложение — колонка tenant_id, которой владеет хост, подмена на tenant-aware модель и согласование id и morph-алиасов с AzGuard.
outline: deep
---

# Мультитенантность

Vaulter спроектирован так, чтобы встраиваться в мультитенантное приложение, но занимает
осознанную позицию: **тенантностью владеет хост, сам Vaulter по ней никогда не скоупит.**
Vaulter даёт вам колонку `tenant_id` на своих таблицах и протягивает её значение сквозь
контент-адресуемое хранилище; а *применение* скоупа тенанта — чтобы запросы видели строки
только одного тенанта — задача вашего приложения, решаемая подменой на tenant-aware модель.

::: info Единственное правило
> Vaulter не скоупит по `tenant_id`. Подставьте tenant-aware модель (через конфиг
> `models`), которая применит ваш скоуп.
>
> — `config/vaulter.php`, блок `tenant`

Это сохраняет Vaulter совместимым с *любой* стратегией тенантности — одна база с
колонкой-дискриминатором, пакет для тенантности или ваши собственные глобальные скоупы —
вместо того чтобы жёстко зашить одну.
:::

## Включение тенантности

```php
// config/vaulter.php
'tenancy' => [
    'enabled' => env('VAULTER_TENANT_ENABLED', false),
],
```

- `tenancy.enabled` — глобальный флаг, читается через `TenancyConfig::enabled()`.
- Колонка всегда называется `tenant_id` (`TenancyConfig::COLUMN`).

### Пара слов о колонке `tenant_id`

Таблицы Drive (`v_storages`, `v_nodes`, `v_blobs`, `v_files`, …) **всегда** несут
nullable-колонку `tenant_id`, независимо от `tenancy.enabled`. Это сделано намеренно:
UNIQUE-индекс дедупликации блобов ключуется на `tenant_id` при `vaulter-media.dedup.scope = tenant`,
поэтому колонка обязана существовать, чтобы индекс можно было определить. Когда тенантность
выключена, индекс просто охватывает `NULL`-колонку.

```php
// Vaulter\Schema\SchemaBuilder::tenant() — выполняется для каждой таблицы Drive
private function tenant(Blueprint $table, IdStrategy $ids): void
{
    $ids->reference(table: $table, column: 'tenant_id', nullable: true);
}
```

Свойство модели объявлено на каждой Drive-модели
(`Vaulter\Models\Drive\Drive`, `Node`, `File`, `FileRevision`,
`Vaulter\Models\Cas\Blob`, `Vaulter\Models\Access\NodeGrant`,
`Vaulter\Models\Sharing\NodeShare`, …) как `int|string|null $tenant_id`.

::: tip
Флаг `tenancy.enabled` — это то, на что должны ориентироваться *ваши* tenant-aware модели и
миграции. Сам Vaulter читает его лишь для того, чтобы решить, применять ли опциональную
колонку на таблицах **легаси-DocVault** (`Vaulter\Schema\VaulterSchema::tenant()` условный);
современные таблицы Drive выпускают колонку безусловно, как объяснено выше.
:::

## Как `tenant_id` на самом деле течёт

Поскольку Vaulter никогда не скоупит, он также никогда и не *придумывает* идентификатор
тенанта. Проследите, откуда берётся значение:

```
ваша tenant-aware модель Storage
        │  ставит tenant_id при создании (observer `creating`, глобальный скоуп, …)
        ▼
   v_storages.tenant_id
        │  IngestFileAction читает $storage->tenant_id
        ▼
   BlobStore::commit(tenantId: …)
        │  запекает его в сегмент области + ключ дедупликации
        ▼
   v_blobs.tenant_id  +  storage_key "t<id>/ab/cd/<sha256>"
```

Путь провижининга подтверждает мысль — `DriveProvisioner::provision()` создаёт хранилище
**без** `tenant_id`:

```php
// Vaulter\Drive\DriveProvisioner::provision() — здесь tenant_id не ставится
$storage = $this->store->create([
    'panel'        => $panel,
    'owner_type'   => $owner->getMorphClass(),
    'owner_id'     => $owner->getKey(),
    'name'         => $this->defaultName($owner),
    'disk'         => app(\Vaulter\Media\Configuration\MediaConfig::class)->disk(),
    'storage_type' => $this->storageType($panel),
    'used_bytes'   => 0,
]);
```

Если хотите заполнять `tenant_id`, ваша подставленная модель `Storage` должна его ставить.
Дальше он распространяется автоматически: `Vaulter\Drive\Actions\Files\IngestFileAction` читает его
прямо со строки хранилища и передаёт в blob store:

```php
// Vaulter\Drive\Actions\Files\IngestFileAction
$blob = $this->blobs->commit(
    stream:    $data->stream,
    tenantId:  $data->storage->tenant_id,   // ← со строки хранилища, заполнено хостом
    storageId: $data->storage->id,
    disk:      $data->storage->disk,
);
```

Тот же приём работает и в других местах: `Vaulter\Activity\ActivityRecorder` и
`Vaulter\Uploads\UploadSessionManager` оба копируют `tenant_id` с владеющего
хранилища/узла, а не из глобальной настройки Vaulter.

## Подмена на tenant-aware модель

Механизм — это карта `models`. Направьте любую запись на ваш собственный подкласс, и Vaulter
резолвит модель через эту карту повсюду:

```php
// config/vaulter.php
'models' => [
    // …
    'drive' => \App\Models\Vaulter\TenantStorage::class,
    'node'    => \App\Models\Vaulter\TenantNode::class,
    'blob'    => \App\Models\Vaulter\TenantBlob::class,
    // …
],
```

Минимальное tenant-aware хранилище, которое *штампует и скоупит* по текущему тенанту:

```php
namespace App\Models\Vaulter;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Models\Drive\Drive as BaseStorage;

class TenantStorage extends BaseStorage
{
    protected static function booted(): void
    {
        // Штампуем тенант при создании — это значение и потечёт в блобы.
        static::creating(function (self $storage): void {
            $storage->tenant_id ??= tenant()?->getKey();
        });

        // Скоупим чтения текущим тенантом — часть, которую Vaulter намеренно опускает.
        static::addGlobalScope('tenant', function (Builder $query): void {
            if ($tenantId = tenant()?->getKey()) {
                $query->where('tenant_id', $tenantId);
            }
        });
    }
}
```

::: tip Возьмите готовый пакет для тенантности
`tenant()` выше — это заглушка. Если вы используете пакет для тенантности (например, тот, что
уже предоставляет трейт `BelongsToTenant` и резолвер текущего тенанта), примените к своему
подклассу *его* трейт вместо самодельного скоупа. Vaulter всё равно, *как* применён скоуп —
важно лишь, что модель его применяет.
:::

Поскольку модели сменяемы, а колонка `tenant_id` уже существует, вы добавляете изоляцию по
тенантам **без миграции Vaulter или правки ядра** — зеркало слоя
[дизайна сменяемых моделей](/ru/guides/configuration#models).

## Взаимодействие с областью дедупликации

Тенантность и [контент-адресуемое хранилище](/ru/concepts/storage-and-blobs) встречаются на одной
настройке: `vaulter-media.dedup.scope`.

```php
// config/vaulter-media.php
'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'), // global|tenant|drive
],
```

Для мультитенантной установки значение по умолчанию `tenant` почти всегда — то, что вам нужно:

| `vaulter-media.dedup.scope` | Поведение в мультитенантном приложении |
|---|---|
| `tenant` *(по умолчанию)* | Одинаковые байты хранятся **один раз на тенанта**. Нет межтенантной **утечки существования** — тенант A никогда не узнает, что тенант B держит некий файл. `storage_key` префиксуется `t<id>/…`, так что сборка мусора одного тенанта никогда не тронет байты другого. |
| `global` | Байты дедуплицируются **между тенантами** — дешевле по хранению, но тенант может вывести, что другой тенант держит идентичные байты. Выбирайте, только когда это допустимо. |
| `drive` | Дедупликация per storage container — граница ещё уже, чем per tenant. |

::: tip Смена области по умолчанию
Старые блобы сохраняют `scope_key`; новые загрузки используют текущую область.
Для изоляции тенантов без утечки наличия файлов оставьте `tenant`.
:::

::: details Почему `tenant` предотвращает утечку существования
При дедупликации `global` первый, кто загрузил некие байты, создаёт объект; второй тенант,
загружающий *те же* байты, получает попадание в дедупликацию и не сохраняет ничего — что
наблюдаемо через тайминги/учёт и выдаёт, что *кто-то* уже держит эти байты. При дедупликации
`tenant` второй тенант получает свой собственный физический объект, так что наблюдать нечего.
Сегмент области в ключе (`t<id>/…`) делает это осязаемым на диске.
:::

## Согласование id и морфов с AzGuard

Vaulter по умолчанию выбирает стратегию первичного ключа **ulid**, чтобы его ключи стыковались
с моделями хоста в экосистеме:

```php
// config/vaulter.php
'ids' => [
    'default' => env('VAULTER_ID_STRATEGY', 'ulid'), // ulid|uuid7|bigint (D9)
    // …
],
```

Это важно, когда вы используете **драйвер ролей хоста AzGuard** для авторизации в приложении со
скоупом по тенантам. Этот драйвер (`Vaulter\Azgard\AzgardPermissionDriver`) общается только со
швом `Vaulter\Azgard\Contracts\AzgardGuard`, которым владеет Vaulter, — так что vaulter-core от
AzGuard **не зависит** — и задаёт вопросы в области workspace как решения AzGuard v1 в
**assignment scope**: `hasPermission($permission, on: AssignmentScopeRef::of($type, $id))`, где
`$type`/`$id` — morph-алиас и ключ владельца диска.

Чтобы идентичности совпали, хост согласует три вещи:

1. **Morph-алиасы.** Идентичности AzGuard — алиасы типов (`[a-z0-9][a-z0-9_.-]*`), а не имена
   классов. Зарегистрируйте пользователя и каждую модель workspace:

   ```php
   Relation::enforceMorphMap([
       'user' => User::class,
       'team' => Team::class,
   ]);
   ```

   Владелец диска с типом-именем класса вызывает `InvalidAssignmentScopeException`.

2. **Типы assignment scope.** Объявите каждый алиас workspace типом assignment scope панели
   AzGuard (`PanelBuilder::scopes(AssignmentScopePolicy::inherit(...))`); алиас, не объявленный
   панелью, получает отказ. **Тенанты** AzGuard (`TenantPolicy`) — отдельная граница: панели с
   тенантами нужен текущий тенант, без него AzGuard отказывает.

3. **Колонки ключей хоста.** Если AzGuard хранит гранты для ulid-ключей, согласуйте его тип
   ключей хоста (`azguard.ids.host_keys` / `azguard.storages.*.host_keys`) со стратегией
   ключей ваших моделей.

::: info Установите мост AzGuard
`composer require axioma-studio/vaulter-azgard` устанавливает драйвер, реальный
адаптер и `Vaulter\Azgard\Testing\FakeAzgardGuard`. Provider сам регистрирует
драйвер и привязывает guard; модель пользователя должна реализовать
`Vaulter\Azgard\Contracts\AzgardBackedUser`. Для тестов с фейком нужны установленные
зависимости моста. См. [Драйверы прав](/ru/extending/permission-drivers) и конфиг `vaulter-azgard.*`.
:::

## Собираем всё вместе

Типичная установка Vaulter со скоупом по тенантам:

1. Поставьте `tenancy.enabled = true`.
2. Оставьте `vaulter-media.dedup.scope = tenant`, чтобы блобы были изолированы по тенантам без утечки
   существования.
3. Отнаследуйте те модели Vaulter, что нужно скоупить (как минимум `storage`; обычно `node`,
   `file`, `blob`, `activity`), и направьте на них карту `models`. Каждый подкласс штампует
   `tenant_id` при создании и применяет ваш глобальный скоуп.
4. Если используете AzGuard для авторизации, зарегистрируйте morph-алиасы, объявите алиасы
   workspace типами assignment scope панели AzGuard и согласуйте тип ключей хоста, как выше.

Дальше каждая загрузка автоматически протягивает тенант к своему блобу, а ваш слой моделей —
не Vaulter — обеспечивает, что каждый тенант видит.

## См. также

- [Хранилище и блобы](/ru/concepts/storage-and-blobs) — контент-адресуемое хранилище, в которое
  впадают `tenant_id` и `vaulter-media.dedup.scope`.
- [Конфигурация](/ru/guides/configuration) — полные ключи `tenant`, `dedup`, `ids` и `models`.
- [Авторизация › Драйвер ролей хоста](/ru/extending/permission-drivers) — драйвер AzGuard и
  конфиг `vaulter-azgard.*`.
