<?php

declare(strict_types=1);

namespace Vaulter\Media\ValueObjects\Cas;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * The deduplication scope of a CAS blob: identical bytes share one blob only inside
 * one tenant or one drive. 'off' gives every upload a scope of its own.
 * There is no install-wide scope: dedup never crosses a tenant or a drive.
 */
#[Api]
final readonly class BlobScope
{
    private function __construct(private string $kind, private string $id, private ?string $uploadTenantId = null) {}

    public static function tenant(string $id): self
    {
        return self::identified('tenant', $id);
    }

    /**
     * The scope of one storage (dedup_scope 'drive'): an opaque identifier of the owner's storage, stored as
     * v_blobs.storage_id. The stored scope value, key prefix and key segment keep their 'drive' spelling.
     */
    public static function storage(string $id): self
    {
        return self::identified('drive', $id);
    }

    /** A scope of its own for one upload: no other commit can deduplicate into it. */
    public static function unshared(): self
    {
        return self::identified('off', (string) Str::ulid());
    }

    /**
     * The scope a new upload commits into. A drive without a tenant deduplicates
     * within itself under 'tenant': there is no tenant boundary to share.
     */
    public static function forUpload(string $dedupScope, ?string $tenantId, string $storageId): self
    {
        $scope = match ($dedupScope) {
            'tenant' => $tenantId === null ? self::storage($storageId) : self::tenant($tenantId),
            'drive' => self::storage($storageId),
            'off' => self::unshared(),
            default => throw new InvalidArgumentException('Unknown CAS dedup scope "'.$dedupScope.'"; use tenant, drive or off.'),
        };

        return new self($scope->kind, $scope->id, $tenantId);
    }

    public function key(): string
    {
        return $this->kind.':'.$this->id;
    }

    public function segment(): string
    {
        $encoded = preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $this->id) === 1
            ? $this->id : '~'.rtrim(strtr(base64_encode($this->id), '+/', '-_'), '=');

        return match ($this->kind) {
            'drive' => 'd-',
            'off' => 'o-',
            default => 't-',
        }.$encoded;
    }

    public static function fromSegment(string $segment): self
    {
        if (preg_match('/^([tdo])-(~?[A-Za-z0-9_-]{1,128})$/D', $segment, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid CAS scope segment.');
        }

        $encoded = $matches[2];
        $id = str_starts_with($encoded, '~') ? base64_decode(strtr(substr($encoded, 1), '-_', '+/'), true) : $encoded;
        if ($id === false) {
            throw new InvalidArgumentException('Invalid encoded CAS scope identifier.');
        }

        $scope = self::identified(match ($matches[1]) {
            'd' => 'drive',
            'o' => 'off',
            default => 'tenant',
        }, $id);
        if ($scope->segment() !== $segment) {
            throw new InvalidArgumentException('Noncanonical CAS scope segment.');
        }

        return $scope;
    }

    public function dedupScope(): string
    {
        return $this->kind;
    }

    public function tenantId(): ?string
    {
        return $this->kind === 'tenant' ? $this->id : null;
    }

    /** @internal */
    public function encryptionTenantId(): ?string
    {
        return $this->uploadTenantId ?? $this->tenantId();
    }

    public function storageId(): ?string
    {
        return $this->kind === 'drive' ? $this->id : null;
    }

    private static function identified(string $kind, string $id): self
    {
        // Keep the canonical path within the reconciler's bounded segment grammar.
        if ($id === '' || strlen($id) > 96) {
            throw new InvalidArgumentException('CAS scope identifiers must contain 1–96 bytes.');
        }

        return new self($kind, $id);
    }
}
