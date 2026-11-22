<?php

declare(strict_types=1);

namespace Vaulter\Documents\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Store raw binary in the working and revision sync rows. Laravel binds streams
 * as PDO::PARAM_LOB, preserving embedded NUL bytes on PostgreSQL and matching
 * the database check that measures the stored bytes.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
#[Internal]
final class BinaryString implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $this->toStringOrNull($value);
    }

    /**
     * @param  array<string, mixed> $attributes
     * @return resource|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $raw = $this->toStringOrNull($value);
        if ($raw === null) {
            return null;
        }

        $stream = fopen('php://memory', 'w+b');
        if ($stream === false || fwrite($stream, $raw) !== strlen($raw) || !rewind($stream)) {
            throw new RuntimeException('[Vaulter] Could not bind the document checkpoint as binary.');
        }

        return $stream;
    }

    /**
     * Coerce a column value (string, null, or a PDO bytea stream resource) to a string
     * or null.
     */
    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            // Eloquent may cast the same stream on multiple attribute reads.
            // Read the entire value without consuming it for the next getter or checkpoint.
            $position = ftell($value);
            $seekable = stream_get_meta_data($value)['seekable'];
            try {
                $contents = stream_get_contents($value, offset: $seekable ? 0 : -1);
            } finally {
                if ($seekable && $position !== false) {
                    fseek($value, $position);
                }
            }

            return $contents === false ? null : $contents;
        }

        /** @var string $value */
        return $value;
    }
}
