<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Illuminate\Support\Facades\Log;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Models\Drive\Node;

/**
 * Fail-loud boot-time footgun guard (F-P1.9-01, ADR-0003 Amendment): the package
 * does NOT scope queries by tenant_id — a host that flips `vaulter.tenancy.enabled`
 * without ALSO swapping `configuration key vaulter.models.node` for a tenant-scoping
 * subclass gets a populated `tenant_id` column with ZERO enforced isolation,
 * silently. This NEVER adds a global scope itself (that would break
 * GC/reconcile/sweeper, which must see every tenant) — it only makes the
 * misconfiguration loud: a warning always, plus a throw outside production so a
 * developer notices immediately (the same fail-loud shape as F-P1.2-01's
 * {@see GrantNodeAccessAction::guardEnumerableSubject()}).
 *
 * A standalone class (not inlined in the ServiceProvider) so it is directly
 * unit-testable without re-running the whole package boot sequence.
 */
final class TenantFootgunGuard
{
    public function __construct(
        private readonly TenancyConfig $tenancyConfig,
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    /**
     * @param bool $isProduction whether to degrade the misconfiguration to a
     *                           log-only warning (production) or also throw
     *                           (non-production — the loud path)
     */
    public function check(bool $isProduction): void
    {
        if (!$this->tenancyConfig->enabled()) {
            return;
        }

        if ($this->vaulterConfig->model('node') !== Node::class) {
            // A host subclass is assumed to carry its own TenantScope — the
            // documented escape hatch (ADR-0003 "the host's swapped model applies
            // its own TenantScope").
            return;
        }

        Log::warning(
            '[Vaulter] vaulter.tenancy.enabled is true but config(\'vaulter.models.node\') is '
            .'still the default Node model, which carries NO tenant scope. The tenant_id '
            .'column is populated with ZERO query-level isolation — every actor with any '
            .'grant reaches every tenant\'s nodes. Swap in a tenant-aware Node subclass '
            .'(config(\'vaulter.models.node\')) with its own TenantScope, or this stays open.',
        );

        if (!$isProduction) {
            throw new TenancyMisconfiguredException(
                'vaulter.tenancy.enabled=true but config(\'vaulter.models.node\') is the default '
                .Node::class.', which does not scope by tenant_id (ADR-0003: Vaulter does not '
                .'enforce tenancy — the host\'s swapped model applies its own TenantScope). '
                .'Either swap config(\'vaulter.models.node\') for a tenant-scoping subclass, or '
                .'disable vaulter.tenancy.enabled. This throw is non-production-only (production '
                .'degrades to a log warning — an unenforced tenant_id column is a silent, not a '
                .'crashing, misconfiguration).',
            );
        }
    }
}
