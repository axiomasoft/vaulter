<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Support\Config;

/**
 * The read side of webhook-endpoint queries (master plan §2.11 / D14 / M3b) — the
 * admin listing + single lookup for the management surface. Authorization is the
 * controller's job (a global manage ability); this repository only shapes queries.
 */
final readonly class WebhookEndpointReadRepository
{
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
        return $this->query()->whereKey($id)->first();
    }

    /**
     * @return Builder<WebhookEndpoint>
     */
    private function query(): Builder
    {
        /** @var class-string<WebhookEndpoint> $model */
        $model = Config::webhookEndpointModel();

        return $model::query();
    }
}
