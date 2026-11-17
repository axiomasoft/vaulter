<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Http;

use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Testing\Http\V1;

/** Fixtures of the drive REST v1 suite on top of the core V1 helpers. */
final class DriveV1
{
    /** The caller's current tag of a live node, read the way a client reads it. */
    public static function etag(TestCase $test, string $nodeId): string
    {
        $etag = $test->getJson(V1::url('nodes/'.$nodeId))->assertOk()->headers->get('ETag');

        return is_string($etag) ? $etag : throw new RuntimeException('No ETag on nodes.show');
    }

    /** The caller's tag of a trashed node, as the trash listing shows it. */
    public static function trashedEtag(TestCase $test, string $driveId, string $nodeId): string
    {
        foreach ($test->getJson(V1::url('drives/'.$driveId.'/trash'))->assertOk()->json('data') as $item) {
            if ($item['id'] === $nodeId) {
                return $item['etag'];
            }
        }

        throw new RuntimeException('Node '.$nodeId.' is not a visible trash root');
    }

    public static function tagFor(DriveHandle $drive, string $nodeId): string
    {
        return V1::tagFor($drive, $nodeId);
    }

    /** @return array{If-Match: string} */
    public static function ifMatch(int|string $nodeId): array
    {
        return V1::ifMatch($nodeId);
    }

    /** @return array<string, mixed> the problem body without its per-request id */
    public static function problem(TestResponse $response): array
    {
        $body = $response->json();
        unset($body['request_id']);

        return $body;
    }
}
