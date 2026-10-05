<?php

declare(strict_types=1);

namespace Vaulter\Tests\Drive;

use Vaulter\Drive\DriveHandle;
use Vaulter\Facades\Vaulter;
use Vaulter\Tests\Fixtures\Panels\FakeProject;

/**
 * Shared builders for the Drive slice tests (M1c): a host entity with an id (not
 * persisted — the storage only stores its morph) and a quick Vaulter::for handle.
 */
final class DriveTestHelpers
{
    /**
     * A host entity with a fixed id, distinct per call so each test gets its own
     * storage (the owner-unique index forbids two storages for one owner+panel).
     */
    public static function host(?int $id = null): FakeProject
    {
        static $next = 0;
        $next++;

        $project = new FakeProject;
        $project->forceFill(['id' => $id ?? $next]);
        $project->exists = true;

        return $project;
    }

    /**
     * Open a Drive handle for a fresh host entity.
     */
    public static function drive(?int $id = null): DriveHandle
    {
        return Vaulter::for(self::host($id));
    }

    /**
     * An in-memory read stream over the given bytes.
     *
     * @return resource
     */
    public static function streamFor(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
