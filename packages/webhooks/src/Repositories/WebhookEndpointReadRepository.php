<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/**
 * The read side of webhook-endpoint queries — the
 * admin listing + single lookup for the management surface. Authorization is the
 * controller's job (a global manage ability); this repository only shapes queries.
 */
#[Internal]
final readonly class WebhookEndpointReadRepository
{
    public function __construct(
        private readonly WebhooksConfig $webhooksConfig,
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
        return $this->query()->whereKey($id)->first();
    }

    /**
     * @return Builder<WebhookEndpoint>
     */
    private function query(): Builder
    {
        /** @var class-string<WebhookEndpoint> $model */
        $model = $this->webhooksConfig->model('webhook_endpoint');

        return $model::query();
    }
}
