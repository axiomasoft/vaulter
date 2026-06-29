<?php

declare(strict_types=1);

namespace Vaulter\Facades;

use Illuminate\Support\Facades\Facade;
use Vaulter\VaulterManager;

/**
 * @method static \Vaulter\Drive\DriveContext                for(\Illuminate\Database\Eloquent\Model $entity, ?string $panel = null)
 * @method static \Vaulter\Panels\Panel                      panel(string $name = 'default')
 * @method static \Vaulter\Panels\PanelRegistry              panels()
 * @method static void                                       registerPanel(\Vaulter\Panels\StoragePanelProvider|\Vaulter\Panels\Panel $panel)
 * @method static void                                       resolvePermissionsWith(\Vaulter\Contracts\PermissionResolver $resolver)
 * @method static \Vaulter\Contracts\PermissionResolver|null permissionResolver()
 * @method static void                                       registerDocumentType(string $type, array<string, mixed> $definition)
 * @method static array<string, array<string, mixed>>        documentTypes()
 * @method static \Vaulter\Contracts\CollaborationDriver     driver(?string $name = null)
 *
 * @see VaulterManager
 */
final class Vaulter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'vaulter';
    }
}
