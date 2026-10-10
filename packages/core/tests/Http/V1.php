<?php

declare(strict_types=1);

namespace Vaulter\Tests\Http;

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/** Fixtures of the REST v1 suite: a drive under real grants and its URLs. */
final class V1
{
    public static function url(string $path): string
    {
        return 'api/vaulter/v1/'.ltrim($path, '/');
    }

    /** A fresh drive (system handle) whose root grants $role to user $userId. */
    public static function drive(int $userId = 1, string $role = RoleRegistry::OWNER): DriveHandle
    {
        config()->set('vaulter.access.permission_driver', 'database');
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
}
