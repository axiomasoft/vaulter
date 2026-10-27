<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * Сквозной GC-конвейер БЕЗ ручного стампинга delete_after_at (H.3). Раньше mark-фаза
 * сканировала по `delete_after_at < now`, который ни один путь коммита/детача не
 * взводит на committed-блобе, — значит реально осиротевший блоб не попадал в скан
 * и байты текли навсегда. Эти тесты гоняют настоящую цепочку
 * commit → attach → detach → reconcile → gc и доказывают, что осиротевший блоб
 * собирается БЕЗ единого forceFill в тесте.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('реальный конвейер: detach→reconcile→mark томбствляет осиротевший committed-блоб (без ручного стампинга)', function (): void {
    $store = app(BlobStore::class);
    $reconciler = app(BlobReconciler::class);
    $gc = app(BlobGarbageCollector::class);

    // Коммитим блоб и навешиваем настоящее ребро — блоб живой.
    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('orphan-me'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $version = CasTestHelpers::reference();
    CasTestHelpers::pin($blob, $version);

    // Снимаем единственное ребро: блоб осиротел, но ref_count-КЭШ ещё врёт.
    CasTestHelpers::unpin($blob, $version);

    // Реконсиляция перестраивает кэш из числа рёбер → ref_count = 0.
    $reconciler->reconcile();
    expect($blob->fresh()->ref_count)->toBe(0)
        ->and($blob->fresh()->delete_after_at)->toBeNull();

    // Mark обязан подобрать committed ∧ ref_count<=0 и затомбствить с grace-дедлайном —
    // НИКАКОГО ручного delete_after_at до этого не было.
    $result = $gc->mark(graceHours: 24);

    expect($result['tombstoned'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned)
        ->and($blob->fresh()->delete_after_at)->not->toBeNull()
        ->and($blob->fresh()->delete_after_at->isFuture())->toBeTrue();
});

it('реальный конвейер: за время grace sweep не трогает свежий tombstone, после grace — удаляет байты', function (): void {
    $store = app(BlobStore::class);
    $reconciler = app(BlobReconciler::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('full-pipeline'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $version = CasTestHelpers::reference();
    CasTestHelpers::pin($blob, $version);
    CasTestHelpers::unpin($blob, $version);

    $reconciler->reconcile();

    // Mark с маленьким (но >=1ч после флора) grace — байты ещё на диске, sweep ждёт.
    $gc->mark(graceHours: 1);
    $key = $blob->storage_key;
    Storage::disk('local')->assertExists($key);

    expect($gc->sweep()['swept'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned);
    Storage::disk('local')->assertExists($key);

    // Перематываем время за grace-окно — теперь sweep вправе физически удалить.
    $this->travel(2)->hours();

    $result = $gc->sweep();

    expect($result['swept'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Deleted)
        ->and($blob->fresh()->delete_after_at)->toBeNull();
    Storage::disk('local')->assertMissing($key);
});

it('реальный конвейер: всё ещё ссылающийся блоб mark пропускает (liveness = ребро)', function (): void {
    $store = app(BlobStore::class);
    $reconciler = app(BlobReconciler::class);
    $gc = app(BlobGarbageCollector::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('still-referenced'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $version = CasTestHelpers::reference();
    CasTestHelpers::pin($blob, $version);

    // Реконсиляция выставит ref_count=1 — в скан кандидатов он не попадёт вовсе.
    $reconciler->reconcile();
    expect($blob->fresh()->ref_count)->toBe(1);

    $result = $gc->mark(graceHours: 24);

    expect($result['tombstoned'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);
});
