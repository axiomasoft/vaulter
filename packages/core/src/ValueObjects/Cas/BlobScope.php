<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Cas;

use InvalidArgumentException;

final readonly class BlobScope
{
    private function __construct(private string $kind, private ?string $id = null, private ?string $legacySegment = null) {}

    public static function global(): self
    {
        return new self('global');
    }

    public static function tenant(string $id): self
    {
        return self::identified('tenant', $id);
    }

    public static function drive(string $id): self
    {
        return self::identified('drive', $id);
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

    public static function forUpload(string $dedupScope, ?string $tenantId, string $storageId): self
    {
        return match ($dedupScope) {
            'global' => self::global(),
            'tenant' => $tenantId === null ? self::global() : self::tenant($tenantId),
            'drive', 'storage' => self::drive($storageId),
            default => throw new InvalidArgumentException('Unknown CAS dedup scope.'),
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

        return ($this->kind === 'drive' ? 'd-' : 't-').$encoded;
    }

    public static function fromSegment(string $segment): self
    {
        if ($segment === 'g') {
            return self::global();
        }

        if (preg_match('/^([td])-(~?[A-Za-z0-9_-]{1,128})$/D', $segment, $matches) !== 1) {
            return self::legacy($segment);
        }

        $encoded = $matches[2];
        $id = str_starts_with($encoded, '~') ? base64_decode(strtr(substr($encoded, 1), '-_', '+/'), true) : $encoded;
        if ($id === false) {
            throw new InvalidArgumentException('Invalid encoded CAS scope identifier.');
        }

        $scope = $matches[1] === 'd' ? self::drive($id) : self::tenant($id);
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
