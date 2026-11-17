<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Http;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use RuntimeException;
use Throwable;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Vaulter;
use Vaulter\Testing\Fixtures\TestUser;

/** Fixtures of the REST v1 suite: a drive under real grants and its URLs. */
#[Internal]
final class V1
{
    public static function url(string $path): string
    {
        return 'api/vaulter/v1/'.ltrim($path, '/');
    }

    /** A fresh drive (system handle) whose root grants $role to user $userId. */
    public static function drive(int $userId = 1, string $role = RoleRegistry::OWNER): DriveHandle
    {
        config()->set('vaulter-drive.access.permission_driver', 'database');
        $drive = DriveTestHelpers::drive();
        AccessTestHelpers::grant($drive->root(), $role, scope: GrantScope::Subtree, subjectId: $userId);

        return $drive;
    }

    public static function user(int $id = 1): TestUser
    {
        return AccessTestHelpers::user($id);
    }

    public static function file(DriveHandle $drive, string $name = 'report.txt', string $bytes = 'hello v1', ?string $mime = 'text/plain', ?string $parent = null): NodeSnapshot
    {
        return $drive->files()->upload(
            UploadSource::fromStream(DriveTestHelpers::streamFor($bytes), $name),
            new UploadOptions(parent: $parent, declaredMimeType: $mime),
        );
    }

    public static function folder(DriveHandle $drive, string $name, ?string $parent = null): NodeSnapshot
    {
        return $drive->folders()->create(name: $name, parent: $parent);
    }

    /**
     * The tag $drive's actor would see for a node, trashed members included — for nodes a
     * client has no listing to read the tag from (a member inside a trashed folder).
     */
    public static function tagFor(DriveHandle $drive, string $nodeId): string
    {
        return app(NodeResponses::class)->current(Request::create('/'), $drive, $nodeId, includeTrashed: true)->etag();
    }

    /**
     * The If-Match header the authenticated user would send for a node (live or trashed).
     *
     * @return array{If-Match: string}
     */
    public static function ifMatch(int|string $nodeId): array
    {
        $user = auth()->user();
        try {
            if (!$user instanceof Authorizable) {
                throw new RuntimeException('ifMatch() needs an authenticated user');
            }
            $drive = app(Vaulter::class)->drive(app(HttpResourceResolver::class)->driveOfNode((string) $nodeId))->actingAs($user);

            return ['If-Match' => self::tagFor($drive, (string) $nodeId)];
        } catch (Throwable) {
            // A node the user cannot see has no tag for it; the request then answers
            // whatever hides the node, before any precondition.
            return ['If-Match' => '"unseen"'];
        }
    }
}
