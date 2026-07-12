<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Cas\BlobLock;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Models\Cas\Blob;
use Vaulter\Support\Config;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return resource
 */
function p23c10Stream(string $bytes)
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/**
 * P2.3 / C10 — при tenant.enabled=false схема остаётся валидной (создаётся,
 * FK/constraint'ы не падают — подтверждено: базовый TestCase уже гоняет со
 * ВЫКЛЮЧЕННЫМ tenant.enabled по умолчанию, и весь набор repro стартует зелёным
 * на этой конфигурации).
 *
 * dedup-uniq — сначала проверяется путь ПРИЛОЖЕНИЯ (BlobStore::commit(), НЕ сырой
 * INSERT): BlobStoreRepository::applyScope() строит `->where('tenant_id',
 * $tenantId)`, и Eloquent САМ переводит `where(col, null)` в `whereNull(col)`
 * (Illuminate\Database\Query\Builder::where()) — так что ПОСЛЕДОВАТЕЛЬНЫЙ повторный
 * коммит одинакового содержимого с tenantId=null корректно находит существующую
 * строку и дедуплицирует. Дефект НЕ здесь.
 *
 * Дефект — в КОНКУРЕНТНОМ коммите на MySQL/MariaDB конкретно: BlobLock::acquire()
 * на MySQL — no-op (полагается на SELECT...FOR UPDATE внутри lockedLookup()), а
 * FOR UPDATE не может залочить ЕЩЁ НЕ СУЩЕСТВУЮЩУЮ строку — то есть для ДВУХ
 * гонящихся транзакций обе видят "строки ещё нет" и обе идут в INSERT. Backbone
 * по докстрингу — "UNIQUE (scope, sha256) constraint... holds on ALL three
 * drivers even when this advisory lock is a no-op": но UNIQUE(tenant_id, sha256)
 * на MySQL InnoDB (и по стандарту SQL — как на Postgres/SQLite тоже) НЕ считает
 * два NULL дубликатом, так что при tenant.enabled=false обе гонящиеся транзакции
 * ПРОЙДУТ constraint без UniqueConstraintViolationException. На Postgres гонку
 * всё равно ловит pg_advisory_xact_lock (реальный мьютекс по строке 'tnull|sha',
 * НЕ зависит от NULL); на SQLite ловит single-writer. На MySQL — ничего не ловит.
 * Это MySQL-специфичный race (нет живого MySQL в этом окружении — repro ниже
 * подтверждает то, что МОЖНО подтвердить без MySQL: (1) сиквеншиал-путь дедупит
 * корректно (не баг), и (2) сырой UNIQUE(tenant_id, sha256) индекс в СХЕМЕ
 * действительно допускает два NULL-дубля — предпосылка гонки; полный вывод — в
 * находке ниже).
 */
it('сиквеншиал: BlobStore::commit() дважды с tenantId=null (default dedup.scope=tenant) корректно дедуплицирует', function (): void {
    Storage::fake('local');
    expect(Config::tenantEnabled())->toBeFalse();
    expect(Config::dedupScope())->toBe('tenant');

    $store = app(BlobStore::class);

    $a = $store->commit(p23c10Stream('same-bytes'));
    $b = $store->commit(p23c10Stream('same-bytes'));

    expect($b->getKey())->toBe($a->getKey())
        ->and(Blob::query()->count())->toBe(1)
        ->and($a->storage_key)->toStartWith('tnull/')
        ->and($a->tenant_id)->toBeNull();
});

it('схема: UNIQUE(tenant_id, sha256) допускает два NULL-дубля в обход дедупа (предпосылка race на MySQL)', function (): void {
    expect(BlobLock::class)->toBeString(); // документирует контекст (см. докблок выше)

    $table = Config::table('blobs');
    $sha = str_repeat('b', 64);

    $row = [
        'hash_algo' => 'sha256',
        'sha256' => $sha,
        'size_bytes' => 10,
        'disk' => 'default',
        'status' => 'committed',
        'ref_count' => 0,
        'dedup_scope' => 'tenant',
        'chunked' => false,
        'first_seen_at' => now(),
        'tenant_id' => null,
    ];

    // Эмулирует ДВЕ гонящиеся транзакции, УЖЕ прошедшие мимо lockedLookup() (обе
    // не увидели строк друг друга — ровно то, что происходит на MySQL, когда
    // FOR UPDATE не блокирует несуществующую строку) и одновременно достигшие
    // INSERT. Единственное, что могло бы их остановить на этом этапе — сам
    // UNIQUE(tenant_id, sha256) индекс.
    DB::table($table)->insert([...$row, 'storage_key' => 'tnull/bb/bb/'.$sha.'-A']);

    $threw = false;

    try {
        DB::table($table)->insert([...$row, 'storage_key' => 'tnull/bb/bb/'.$sha.'-B']);
    } catch (\Illuminate\Database\QueryException) {
        $threw = true;
    }

    expect($threw)->toBeFalse('UNIQUE(tenant_id, sha256) НЕ поймал дубль при NULL tenant_id — race backbone не держит на этом уровне.');
    expect(DB::table($table)->where('sha256', $sha)->count())->toBe(2);
});
