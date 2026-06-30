<?php

declare(strict_types=1);

namespace Vaulter\Tests\Cas;

use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;

/**
 * Shared builders for the CAS slice tests (M1b): a real Storage→Node→File→Version
 * chain so attach()/detach() can hang real edges off a committed blob, plus a
 * helper to turn a string into a stream the ContentStore can stage.
 */
final class CasTestHelpers
{
    /**
     * Build a committed FileVersion (with its File + Node + Storage) to attach a
     * blob edge to.
     */
    public static function makeVersion(int $versionNo = 1, string $name = 'a.bin'): FileVersion
    {
        // One storage per host owner (the M1c owner-unique index): mint a fresh
        // owner_id per call so repeated makeVersion()s in one test don't collide.
        static $owner = 0;
        $owner++;

        $storage = Storage::query()->create([
            'owner_type' => 'team',
            'owner_id' => $owner,
            'name' => 'Disk',
        ]);

        $node = Node::query()->create([
            'storage_id' => $storage->getKey(),
            'kind' => NodeKind::File,
            'name' => $name,
        ]);

        $file = File::query()->create([
            'node_id' => $node->getKey(),
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 0,
        ]);

        return FileVersion::query()->create([
            'file_id' => $file->getKey(),
            'version_no' => $versionNo,
            'content_sha256' => str_repeat('0', 64),
            'size_bytes' => 0,
            'original_name' => $name,
            'status' => 'committed',
        ]);
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
