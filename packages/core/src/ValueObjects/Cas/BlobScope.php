<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Cas;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The deduplication scope of a CAS blob: identical bytes share one blob only inside
 * one tenant or one drive (PLAN2.D49). 'off' gives every upload a scope of its own.
 * Uploads never build the retired install-wide 'global' scope; it is still parsed so
 * existing objects stay readable until vaulter:blobs:repair-scopes moves them.
 */
final readonly class BlobScope
{
    private function __construct(private string $kind, private ?string $id = null, private ?string $legacySegment = null) {}

    public static function tenant(string $id): self
    {
        return self::identified('tenant', $id);
    }

    public static function drive(string $id): self
    {
        return self::identified('drive', $id);
    }

    /** A scope of its own for one upload: no other commit can deduplicate into it. */
    public static function unshared(): self
    {
        return self::identified('off', (string) Str::ulid());
    }

    public static function legacy(string $segment): self
    {
        if ($segment === 'global') {
            return new self('global', legacySegment: $segment);
        }

        // Historical releases also emitted ULID segments; preserve their layout.
        if (preg_match('/^([st])(null|\d+|[0-9A-HJKMNP-TV-Z]{26})$/D', $segment, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid legacy CAS scope segment.');
        }

        return new self($matches[1] === 's' ? 'drive' : 'tenant', $matches[2] === 'null' ? null : $matches[2], $segment);
    }

    /**
     * The scope a new upload commits into. A drive without a tenant deduplicates
     * within itself under 'tenant': there is no tenant boundary to share.
     */
    public static function forUpload(string $dedupScope, ?string $tenantId, string $storageId): self
    {
        return match ($dedupScope) {
            'tenant' => $tenantId === null ? self::drive($storageId) : self::tenant($tenantId),
            'drive', 'storage' => self::drive($storageId),
            'off' => self::unshared(),
            default => throw new InvalidArgumentException('Unknown CAS dedup scope "'.$dedupScope.'"; use tenant, drive or off.'),
        };
    }

    public function key(): string
    {
        if ($this->kind === 'global') {
            return 'global';
        }

        return $this->id === null ? 'legacy:'.$this->legacySegment : $this->kind.':'.$this->id;
    }

    public function segment(): string
    {
        if ($this->legacySegment !== null) {
            return $this->legacySegment;
        }

        if ($this->kind === 'global') {
            return 'g';
        }

        $id = $this->id ?? throw new InvalidArgumentException('Missing CAS scope identifier.');
        $encoded = preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) === 1
            ? $id : '~'.rtrim(strtr(base64_encode($id), '+/', '-_'), '=');

        return match ($this->kind) {
            'drive' => 'd-',
            'off' => 'o-',
            default => 't-',
        }.$encoded;
    }

    public static function fromSegment(string $segment): self
    {
        if ($segment === 'g') {
            // Objects of the retired install-wide scope, readable until repaired.
            return new self('global');
        }

        if (preg_match('/^([tdo])-(~?[A-Za-z0-9_-]{1,128})$/D', $segment, $matches) !== 1) {
            return self::legacy($segment);
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

    public function isLegacy(): bool
    {
        return $this->legacySegment !== null;
    }

    public function dedupScope(): string
    {
        return $this->kind;
    }

    public function tenantId(): ?string
    {
        return $this->kind === 'tenant' ? $this->id : null;
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
