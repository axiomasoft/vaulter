---
title: Мультитенантность
description: Как Vaulter вписывается в мультитенантное Laravel-приложение — колонка tenant_id, которой владеет хост, подмена на tenant-aware модель и согласование ulid-морфов с экосистемой Corex / AzGuard.
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
'tenant' => [
    'enabled' => env('VAULTER_TENANT_ENABLED', false),
    'column'  => 'tenant_id',
],
```

- `tenant.enabled` — глобальный флаг, читается через `Config::tenantEnabled()`.
- `tenant.column` — имя колонки, читается через `Config::tenantColumn()` (по умолчанию
  `tenant_id`).

### Пара слов о колонке `tenant_id`

Таблицы Drive (`v_storages`, `v_nodes`, `v_blobs`, `v_files`, …) **всегда** несут
nullable-колонку `tenant_id`, независимо от `tenant.enabled`. Это сделано намеренно:
UNIQUE-индекс дедупликации блобов ключуется на `tenant_id` при `dedup.scope = tenant`,
поэтому колонка обязана существовать, чтобы индекс можно было определить. Когда тенантность
выключена, индекс просто охватывает `NULL`-колонку.

```php
// Vaulter\Schema\PanelTablesBuilder::tenant() — выполняется для каждой таблицы Drive
private function tenant(Blueprint $table, IdStrategy $ids): void
{
    $ids->reference(table: $table, column: 'tenant_id', nullable: true);
}
```

Свойство модели объявлено на каждой Drive-модели
(`Vaulter\Models\Drive\Storage`, `Node`, `File`, `FileVersion`,
`Vaulter\Models\Cas\Blob`, `Vaulter\Models\Access\NodeGrant`,
`Vaulter\Models\Sharing\NodeShare`, …) как `int|string|null $tenant_id`.

::: tip
Флаг `tenant.enabled` — это то, на что должны ориентироваться *ваши* tenant-aware модели и
миграции. Сам Vaulter читает его лишь для того, чтобы решить, применять ли опциональную
колонку на таблицах **легаси-DocVault** (`Vaulter\Support\VaulterSchema::tenant()` условный);
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
        │  UploadFileAction читает $storage->tenant_id
        ▼
   BlobStore::commit(tenantId: …)
        │  запекает его в сегмент области + ключ дедупликации
        ▼
   v_blobs.tenant_id  +  storage_key "t<id>/ab/cd/<sha256>"
```

Путь провижининга подтверждает мысль — `StorageManager::provision()` создаёт хранилище
**без** `tenant_id`:

```php
// Vaulter\Drive\StorageManager::provision() — здесь tenant_id не ставится
$storage = $this->store->create([
    'panel'        => $panel,
    'owner_type'   => $owner->getMorphClass(),
    'owner_id'     => $owner->getKey(),
    'name'         => $this->defaultName($owner),
    'disk'         => Config::defaultDisk(),
    'storage_type' => $this->storageType($panel),
    'used_bytes'   => 0,
]);
```

Если хотите заполнять `tenant_id`, ваша подставленная модель `Storage` должна его ставить.
Дальше он распространяется автоматически: `Vaulter\Drive\Actions\UploadFileAction` читает его
прямо со строки хранилища и передаёт в blob store:

```php
// Vaulter\Drive\Actions\UploadFileAction
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
    'storage' => \App\Models\Vaulter\TenantStorage::class,
    'node'    => \App\Models\Vaulter\TenantNode::class,
    'blob'    => \App\Models\Vaulter\TenantBlob::class,
    // …
],
```

Минимальное tenant-aware хранилище, которое *штампует и скоупит* по текущему тенанту:

```php
namespace App\Models\Vaulter;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Models\Drive\Storage as BaseStorage;

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
[дизайна сменяемых моделей](/ru/guide/configuration#models).

## Взаимодействие с областью дедупликации

Тенантность и [контент-адресуемое хранилище](/ru/guide/storage-and-blobs) встречаются на одной
настройке: `dedup.scope`.

```php
// config/vaulter.php
'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'), // global|tenant|storage
],
```

Для мультитенантной установки значение по умолчанию `tenant` почти всегда — то, что вам нужно:

| `dedup.scope` | Поведение в мультитенантном приложении |
|---|---|
| `tenant` *(по умолчанию)* | `UNIQUE (tenant_id, sha256)`. Одинаковые байты хранятся **один раз на тенанта**. Нет межтенантной **утечки существования** — тенант A никогда не узнает, что тенант B держит некий файл. `storage_key` префиксуется `t<id>/…`, так что сборка мусора одного тенанта никогда не тронет байты другого. |
| `global` | `UNIQUE (sha256)`. Байты дедуплицируются **между тенантами** — дешевле по хранению, но тенант может вывести, что другой тенант держит идентичные байты. Выбирайте, только когда это допустимо. |
| `storage` | `UNIQUE (storage_id, sha256)`. Дедупликация per storage container — граница ещё уже, чем per tenant. |

::: warning Выбирайте область на этапе установки
`dedup.scope` запекается в `storage_key` каждого блоба **и** в уникальный индекс. Это по сути
дверь в одну сторону — сменить позже значит переписывать ключи и индекс. Для изоляции по
тенантам без утечки существования держите `tenant`.
:::

::: details Почему `tenant` предотвращает утечку существования
При дедупликации `global` первый, кто загрузил некие байты, создаёт объект; второй тенант,
загружающий *те же* байты, получает попадание в дедупликацию и не сохраняет ничего — что
наблюдаемо через тайминги/учёт и выдаёт, что *кто-то* уже держит эти байты. При дедупликации
`tenant` второй тенант получает свой собственный физический объект, так что наблюдать нечего.
Сегмент области в ключе (`t<id>/…`) делает это осязаемым на диске.
:::

## Согласование ulid-морфов с Corex / AzGuard

Vaulter по умолчанию выбирает стратегию первичного ключа **ulid** именно затем, чтобы его
морф-ключи стыковались с экосистемой Corex / AzGuard:

```php
// config/vaulter.php
'ids' => [
    'default' => env('VAULTER_ID_STRATEGY', 'ulid'), // ulid|uuid7|bigint (D9)
    // …
],
```

> По умолчанию ulid, чтобы морф-ключи стыковались с экосистемой Corex/Azgard.
> — `config/vaulter.php`, блок `ids`

Это важно, когда вы гоняете **драйвер ролей хоста AzGuard** для авторизации в приложении со
скоупом по тенантам. Этот драйвер
(`Vaulter\Drivers\Permission\AzgardPermissionResolver`) общается только со швом
`Vaulter\Contracts\Access\AzgardGuard`, которым владеет Vaulter — так что vaulter-core не несёт
**никакой** жёсткой зависимости от `azguard` / `corex` — и маршрутизирует свои проверки ролей
со скоупом по workspace через `hasPermissionIn($contextType, $contextId, $permission)`, что
является контекстной проверкой Corex `TenantContextGuard` (полоса тенанта/workspace).

Чтобы эти морф-типы корректно резолвились между пакетами, хост должен согласовать конфигурацию
ulid-морфов от края до края:

```dotenv
# Vaulter
VAULTER_ID_STRATEGY=ulid

# AzGuard
AZ_GUARD_MORPH_TYPE=ulid
```

```php
// Corex
'ids' => ['strategy' => \Corex\…\Ulid::class],   // corex.ids.strategy = Ulid
```

…и зарегистрировать морф-типы workspace / owner как **морф-алиасы Corex**
(`EntityRegistry` / Corex ADR-003), как задокументировано на контракте `AzgardGuard`:

```php
// Vaulter\Contracts\Access\AzgardGuard (docblock)
// The $contextType / $entity morph types MUST be registered Corex morph aliases
// aligned with vaulter.ids.default=ulid + corex.ids.strategy=Ulid + AZ_GUARD_MORPH_TYPE=ulid.
```

::: info Вы обязаны привязать реальный `AzgardGuard`
По умолчанию реальная привязка `AzgardGuard` не поставляется — тесты привязывают
`Vaulter\Testing\FakeAzgardGuard`. Продакшн-хост привязывает реальный адаптер, делегирующий
в AzGuard `hasScopedPermission` / Corex `ContextGuard` `hasPermissionIn`. См.
[Авторизация › Драйвер ролей хоста](/ru/guide/permission-drivers) — там полная разводка
драйвера и конфиг-блок `azgard`.
:::

## Собираем всё вместе

Типичная установка Vaulter со скоупом по тенантам:

1. Поставьте `tenant.enabled = true` и оставьте `tenant.column = 'tenant_id'`.
2. Оставьте `dedup.scope = tenant`, чтобы блобы были изолированы по тенантам без утечки
   существования.
3. Отнаследуйте те модели Vaulter, что нужно скоупить (как минимум `storage`; обычно `node`,
   `file`, `blob`, `activity`), и направьте на них карту `models`. Каждый подкласс штампует
   `tenant_id` при создании и применяет ваш глобальный скоуп.
4. Если используете AzGuard для авторизации, оставьте `ids.default = ulid` и согласуйте
   конфигурацию морфов Corex / AzGuard, как выше.

Дальше каждая загрузка автоматически протягивает тенант к своему блобу, а ваш слой моделей —
не Vaulter — обеспечивает, что каждый тенант видит.

## См. также

- [Хранилище и блобы](/ru/guide/storage-and-blobs) — контент-адресуемое хранилище, в которое
  впадают `tenant_id` и `dedup.scope`.
- [Конфигурация](/ru/guide/configuration) — полные ключи `tenant`, `dedup`, `ids` и `models`.
- [Авторизация › Драйвер ролей хоста](/ru/guide/permission-drivers) — драйвер AzGuard и
  конфиг-блок `azgard`.
