<?php

declare(strict_types=1);

namespace Vaulter\Media\Content;

use Vaulter\Attributes\Api;

#[Api]
final readonly class ImmutableContentIdentity
{
    public function __construct(public int|string $blobId, public string $scopeKey, public string $disk, public string $storageKey, public ?string $storageVersion, public string $sha256, public int $size)
    {
        if ($size < 0 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1 || $scopeKey === '' || $storageKey === '') {
            throw new \InvalidArgumentException('Invalid immutable content identity.');
        }
    }

    public function fingerprint(): string
    {
        $values = ['blob_id' => (string) $this->blobId, 'scope_key' => $this->scopeKey, 'disk' => $this->disk, 'storage_key' => $this->storageKey, 'storage_version' => $this->storageVersion, 'sha256' => $this->sha256, 'size' => $this->size, 'manifest' => null];
        ksort($values);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
