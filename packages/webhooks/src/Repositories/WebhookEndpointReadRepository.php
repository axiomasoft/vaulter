<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Packman\Attributes\Internal;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\TenantIdentityPredicate;
use Vaulter\Webhooks\Configuration\WebhooksOptions;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/**
 * The read side of webhook-endpoint queries — the
 * admin listing + single lookup for the management surface. Authorization is the
 * controller's job (a global manage ability); this repository only shapes queries.
 *
 * Under strict tenancy a caller sees only the endpoints of the current tenant. An endpoint without a tenant,
 * or of another tenant, is as unknown as a missing one.
 */
#[Internal]
final readonly class WebhookEndpointReadRepository
{
    public function __construct(
        private WebhooksOptions $webhooksConfig,
        private TenancyBoundary $tenancy,
    ) {}

    /**
     * The newest-first listing of every registered endpoint. Returns a Builder so the
     * controller composes the cursor pagination (the house pattern).
     *
     * @return Builder<WebhookEndpoint>
     */
    public function listing(): Builder
    {
        return $this->query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Find an endpoint by id, or null when it does not exist.
     */
    public function find(int|string $id): ?WebhookEndpoint
    {
        $query = $this->query();

        return Identity::fitsKey($query->getModel(), $id) ? $query->whereKey($id)->first() : null;
    }

    /**
     * @return Builder<WebhookEndpoint>
     */
    private function query(): Builder
    {
        /** @var class-string<WebhookEndpoint> $model */
        $model = $this->webhooksConfig->model('webhook_endpoint');
        $query = $model::query();
        if (!$this->tenancy->enforced()) {
            return $query;
        }
        $tenant = $this->tenancy->current();
        $column = $query->getModel()->qualifyColumn('tenant_id');
        $driver = $query->getModel()->getConnection()->getDriverName();

        return $query->where($column, $tenant)
            ->whereRaw(new TenantIdentityPredicate($column, $driver), [$tenant]);
    }
}
