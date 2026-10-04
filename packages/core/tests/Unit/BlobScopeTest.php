<?php

declare(strict_types=1);

use Vaulter\ValueObjects\Cas\BlobScope;

it('round trips lossless CAS scope identifiers', function (string $id): void {
    foreach ([BlobScope::drive($id), BlobScope::tenant($id)] as $scope) {
        expect(BlobScope::fromSegment($scope->segment())->key())->toBe($scope->key());
    }
})->with(['001', '01ARZ3NDEKTSV4RRFFQ69G5FAV', '019a0000-1234-7000-8000-123456789abc', 'host/project', 'équipe']);

it('retains legacy paths while sharing their numeric scope keys', function (string $segment, string $key): void {
    $scope = BlobScope::fromSegment($segment);
    expect($scope->key())->toBe($key)->and($scope->segment())->toBe($segment);
})->with([
    ['global', 'global'], ['s001', 'drive:001'], ['t12', 'tenant:12'],
    ['snull', 'legacy:snull'], ['tnull', 'legacy:tnull'],
]);

it('selects the host scope without coercing identifiers', function (): void {
    expect(BlobScope::forUpload('tenant', null, 'drive')->key())->toBe('global')
        ->and(BlobScope::forUpload('storage', null, 'drive')->key())->toBe('drive:drive')
        ->and(BlobScope::forUpload('tenant', '001', 'drive')->key())->toBe('tenant:001')
        ->and(BlobScope::forUpload('global', 'tenant', 'drive')->segment())->toBe('g');
});

it('rejects invalid and noncanonical scope segments', function (string $segment): void {
    expect(fn () => BlobScope::fromSegment($segment))->toThrow(InvalidArgumentException::class);
})->with(['', '../g', 'd-', 'd-~', 'd-~YQ', 't-~bad=', 'unknown']);
