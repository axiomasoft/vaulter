<?php

declare(strict_types=1);

namespace Vaulter\Permissions\Azgard\Tests\RealAzgard\Stubs\Roles;

use AzGuard\Roles\BaseRole;

/**
 * A real AzGuard role logic class granting the Vaulter documents-view permission — the
 * scoped role assigned on a workspace entity in the real e2e.
 */
final class DocsViewerRole extends BaseRole
{
    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return ['documents.view'];
    }
}
