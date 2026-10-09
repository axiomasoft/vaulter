<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\RealAzgard\Stubs;

use AzGuard\Permissions\RequiresGrant;

/**
 * The permissions of the host's `app` panel under the exact local keys the default
 * `vaulter-azgard.permissions` map names (`documents.view` → `app:documents.view`).
 */
#[RequiresGrant]
enum DocumentsPermission: string
{
    case View = 'documents.view';
    case Download = 'documents.download';
    case Comment = 'documents.comment';
    case Update = 'documents.update';
    case Create = 'documents.create';
    case Delete = 'documents.delete';
    case Manage = 'documents.manage';
}
