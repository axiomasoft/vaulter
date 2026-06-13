<?php

declare(strict_types=1);

namespace DocVault\Facades;

use DocVault\DocVaultManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void                                        resolvePermissionsWith(\DocVault\Contracts\PermissionResolver $resolver)
 * @method static \DocVault\Contracts\PermissionResolver|null permissionResolver()
 * @method static void                                        registerDocumentType(string $type, array<string, mixed> $definition)
 * @method static array<string, array<string, mixed>>         documentTypes()
 *
 * @see DocVaultManager
 */
final class DocVault extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'docvault';
    }
}
