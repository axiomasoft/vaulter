<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\ExactListing;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\NodePage;
use Vaulter\ValueObjects\Access\Actor;

/** @internal The current global shared listing; each page evaluates exact View. */
#[Internal]
final readonly class SharedWithActorQuery
{
    public function __construct(private AccessResolver $access, private ExactListing $listing, private VaulterConfig $config) {}

    public function execute(Authorizable $actor, ListOptions $options): NodePage
    {
        $model = $this->config->model('node');
        $query = $model::query()->whereNull('trashed_at')->whereKey($this->access->sharedNodeIds($actor));

        return $this->listing->page(
            actor: $actor, candidates: $query,
            order: [['column' => 'name', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
            perPage: $options->perPage, cursor: $options->cursor, context: json_encode(['list' => 'shared', 'actor' => Actor::user($actor)->ref()], JSON_THROW_ON_ERROR),
        );
    }
}
