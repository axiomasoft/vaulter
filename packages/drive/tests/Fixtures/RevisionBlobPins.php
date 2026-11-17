<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Fixtures;

use Illuminate\Database\Query\Builder;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\RevisionBlobLinks;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Media\Models\Cas\Blob;

/** Reads of the drive's revision links of a blob: its edges and their media pins. */
final class RevisionBlobPins
{
    /** The revision edges that reference $blob. */
    public static function edges(Blob $blob): int
    {
        return FileRevisionBlob::query()->where('blob_id', $blob->getKey())->count();
    }

    /** The drive's pins of $blob. */
    public static function pins(Blob $blob): int
    {
        return self::query()->where('blob_id', $blob->getKey())->count();
    }

    /** Every pin the drive holds. */
    public static function all(): int
    {
        return self::query()->count();
    }

    /**
     * The reference keys of the drive's pins of $blob, sorted.
     *
     * @return list<string>
     */
    public static function keys(Blob $blob): array
    {
        /** @var list<string> $keys */
        $keys = self::query()->where('blob_id', $blob->getKey())->orderBy('reference_key')->pluck('reference_key')->all();

        return $keys;
    }

    private static function query(): Builder
    {
        return app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table('blob_references'))
            ->where('owner_type', RevisionBlobLinks::OWNER_TYPE);
    }
}
