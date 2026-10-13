<?php

declare(strict_types=1);

use Vaulter\ValueObjects\Cas\BlobScope;

it('round trips lossless CAS scope identifiers', function (string $id): void {
    foreach ([BlobScope::drive($id), BlobScope::tenant($id)] as $scope) {
        expect(BlobScope::fromSegment($scope->segment())->key())->toBe($scope->key());
    }
})->with(['001', '01ARZ3NDEKTSV4RRFFQ69G5FAV', '019a0000-1234-7000-8000-123456789abc', 'host/project', 'équipe']);

it('selects the host scope without coercing identifiers and never builds global', function (): void {
    expect(BlobScope::forUpload('tenant', null, 'drive')->key())->toBe('drive:drive')
        ->and(BlobScope::forUpload('drive', null, 'drive')->key())->toBe('drive:drive')
        ->and(BlobScope::forUpload('tenant', '001', 'drive')->key())->toBe('tenant:001')
        ->and(fn () => BlobScope::forUpload('global', 'tenant', 'drive'))->toThrow(InvalidArgumentException::class);
});

it('gives every upload its own scope when deduplication is off', function (): void {
    $a = BlobScope::forUpload('off', 'tenant', 'drive');
    $b = BlobScope::forUpload('off', 'tenant', 'drive');

    expect($a->key())->not->toBe($b->key())
        ->and($a->key())->toStartWith('off:')
        ->and($a->dedupScope())->toBe('off')
        ->and($a->segment())->toStartWith('o-')
        ->and(BlobScope::fromSegment($a->segment())->key())->toBe($a->key())
        ->and($a->tenantId())->toBeNull()->and($a->storageId())->toBeNull();
});

it('selects the drive scope for the drive dedup setting', function (): void {
    $scope = BlobScope::forUpload(dedupScope: 'drive', tenantId: 'tenant', storageId: '001');

    expect($scope->dedupScope())->toBe('drive')
        ->and($scope->key())->toBe('drive:001')
        ->and($scope->segment())->toBe('d-001')
        ->and($scope->storageId())->toBe('001')
        ->and($scope->tenantId())->toBeNull();
});

it('rejects invalid and noncanonical scope segments', function (string $segment): void {
    expect(fn () => BlobScope::fromSegment($segment))->toThrow(InvalidArgumentException::class);
})->with(['', '../g', 'd-', 'd-~', 'd-~YQ', 't-~bad=', 'unknown', 'g', 'global', 's001', 't12', 'snull', 'tnull']);
