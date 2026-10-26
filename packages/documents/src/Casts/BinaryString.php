<?php

declare(strict_types=1);

namespace Vaulter\Documents\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;

/**
 * Round-trip an arbitrary binary blob through a database column as a plain PHP string
 * on EVERY driver — including binaries that contain NUL bytes.
 *
 * The attribute is the raw bytes in PHP; at rest it is stored BASE64-encoded. That
 * encoding is what makes it portable:
 *
 *   - PostgreSQL `bytea` via PDO's default string binding (PARAM_STR) silently
 *     TRUNCATES a binary at its first NUL byte — a raw Yjs update (which routinely
 *     starts with NUL) round-trips to an EMPTY column, destroying the snapshot. PDO
 *     also hands `bytea` back as a STREAM RESOURCE, not a string. Base64 (NUL-free
 *     ASCII) sidesteps both: it binds losslessly as a string and the get() drains any
 *     stream before decoding.
 *   - SQLite (BLOB) and MySQL (longblob) keep raw binary fine, but storing base64
 *     uniformly across drivers keeps the on-disk format identical everywhere — a row
 *     is driver-portable, and there is no per-driver branch to drift.
 *
 * The cached byte length (collab_state_size) is computed by DocumentStateWriter from
 * the RAW value before this cast encodes it, so it stays the
 * true payload size regardless of the at-rest base64 representation.
 *
 * Used for v_documents.collab_state and v_document_revisions.collab_state — the binary Yjs
 * CRDT state that is the collaboration source of truth, read back through
 * Eloquent by the restore path and checkpoint capture. No code reads the column out of band (the hub persists it
 * via the collab/store webhook and never queries it directly).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
#[Internal]
final class BinaryString implements CastsAttributes
{
    /**
     * Reading: drain a Postgres bytea stream to its bytes, then base64-decode back to
     * the raw payload. A value that is not valid base64 (e.g. a legacy raw-stored row)
     * is returned as-is rather than nulled.
     *
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $stored = $this->toStringOrNull($value);

        if ($stored === null || $stored === '') {
            return $stored;
        }

        $decoded = base64_decode($stored, true);

        return $decoded === false ? $stored : $decoded;
    }

    /**
     * Writing: base64-encode the raw bytes so they bind losslessly (NUL-safe) on every
     * driver. A stream is drained first; null passes through.
     *
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $raw = $this->toStringOrNull($value);

        return $raw === null ? null : base64_encode($raw);
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
            $contents = stream_get_contents($value);

            return $contents === false ? null : $contents;
        }

        /** @var string $value */
        return (string) $value;
    }
}
