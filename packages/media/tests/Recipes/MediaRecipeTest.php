<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

// The fragments below are copied into the guides byte for byte.
it('keeps tenants apart under the documented dedup scope (troubleshooting guide)', function (): void {
    Storage::fake('local');

    $config = [
        // [recipe:media-dedup-scope]
        // config/vaulter-media.php
        'dedup' => [
            'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'),
        ],
        // [/recipe]
    ];
    config()->set('vaulter-media.dedup', $config['dedup']);

    $store = app(BlobStore::class);
    $scope = (string) config('vaulter-media.dedup.scope');

    $first = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload($scope, '1', '10'));
    $again = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload($scope, '1', '11'));
    $other = $store->commit(CasTestHelpers::streamFor('shared'), scope: BlobScope::forUpload($scope, '2', '20'));

    expect($scope)->toBe('tenant')
        ->and($again->getKey())->toBe($first->getKey())
        ->and($other->getKey())->not->toBe($first->getKey());
});
