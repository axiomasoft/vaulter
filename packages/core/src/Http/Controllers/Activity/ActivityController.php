<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Activity;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Enums\Events\EventType;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Activity\ActivityResource;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\ActivityOperations;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\Identity;
use Vaulter\Support\LoadTier;
use Vaulter\ValueObjects\Access\Actor;

final class ActivityController extends Controller
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function forStorage(Request $request, Drive $storage): AnonymousResourceCollection
    {
        return $this->feed($request, $storage);
    }

    public function forNode(Request $request, Node $node): AnonymousResourceCollection
    {
        return $this->feed($request, $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id), $node);
    }

    private function feed(Request $request, Drive $drive, ?Node $node = null): AnonymousResourceCollection
    {
        $user = $request->user();
        if (!$user instanceof Authorizable) {
            throw MissingActorException::required();
        }
        $operations = app()->makeWith(ActivityOperations::class, ['context' => new OperationContext(drive: $drive, actor: Actor::user($user), correlationId: (string) Str::ulid())]);
        $verb = EventType::tryFrom($request->string('verb')->toString());
        $cursor = $request->query('cursor');
        $options = new ListOptions(perPage: max(1, min($request->integer('per_page', 50), 200)), cursor: is_string($cursor) ? $cursor : null);

        return $this->metrics->timing(name: 'vaulter_activity_feed_duration_seconds', operation: fn (): AnonymousResourceCollection => ActivityResource::collection($operations->filtered(node: $node === null ? null : Identity::stringify($node->getKey()), options: $options, verb: $verb)->withQueryString()), labels: ['filtered' => $verb !== null, 'tier' => LoadTier::label()]);
    }
}
