<?php

declare(strict_types=1);

namespace Vaulter\Drive\Queries\Activity;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Configuration\ListingConfig;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\NodeSort;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Events\EventTypeContract;

/**
 * Bounded exact scan; cursors advance over every examined candidate.
 */
#[Internal]
final readonly class VisibleActivityQuery
{
    /** Context keys that describe who holds access; only a Share-capable reader sees them. */
    private const array PRIVILEGED_CONTEXT_KEYS = ['subject_type', 'subject_id', 'permissions', 'token_prefix'];

    public function __construct(private VaulterConfig $config, private ListingConfig $listing, private OperationGuard $guard, private Repository $repository) {}

    /** @return VisibleActivityPaginator<Activity> */
    public function execute(DriveContext $context, ListOptions $options, ?Node $node = null, ?EventTypeContract $verb = null): VisibleActivityPaginator
    {
        if ($options->sort !== NodeSort::Default || $options->descending || $options->kinds !== []) {
            throw new InvalidArgumentException('Activity supports only perPage and forward cursor options.');
        }
        $scope = hash('sha256', json_encode([(string) $context->driveId(), $context->drive->getRawOriginal('tenant_id'), $node === null ? null : (string) $node->id, $verb?->value], JSON_THROW_ON_ERROR));
        $cursor = $this->decode($options->cursor, $scope);
        $query = $this->base($context, $node, $verb);
        $last = $cursor === null ? null : ['created_at' => $this->scalar($cursor->parameter('created_at')), 'id' => $this->scalar($cursor->parameter('id'))];
        $visible = [];
        $remaining = $this->listing->scanBudget();
        while ($remaining > 0 && count($visible) < $options->perPage) {
            $batchQuery = clone $query;
            $this->after($batchQuery, $last);
            $batch = $batchQuery->limit(min(100, $remaining))->get();
            if ($batch->isEmpty()) {
                break;
            }
            $keys = $batch->where('target_type', (new ($this->config->model('node')))->getMorphClass())->pluck('target_id')->filter()->unique()->all();
            $class = $this->config->model('node');
            $nodes = $class::query()->where('drive_id', $context->driveId())->whereNull('trashed_at')->whereKey($keys)->get();
            // Visible target key => whether the actor may also Share it (and so see who did what).
            $allowed = $this->guard->visibleWith(context: $context, nodes: $nodes, ability: Ability::Share);
            foreach ($batch as $event) {
                $last = ['created_at' => $this->scalar($event->getRawOriginal('created_at')), 'id' => (string) $event->id];
                $remaining--;
                if ($context->actor->isSystem()) {
                    $visible[] = $event;
                } elseif ($this->visible($context, $event, $allowed)) {
                    $visible[] = $this->project($event, $allowed);
                }
                if (count($visible) === $options->perPage || $remaining === 0) {
                    break;
                }
            }
        }
        $more = clone $query;
        $this->after($more, $last);
        $hasMore = $last !== null && $more->exists();
        $continuation = $last === null ? null : $this->encode($last, $scope);

        return new VisibleActivityPaginator(items: $visible, perPage: $options->perPage, continuation: $continuation, hasMore: $hasMore, cursor: $cursor);
    }

    /** @return Builder<Activity> */
    private function base(DriveContext $context, ?Node $node, ?EventTypeContract $verb): Builder
    {
        $class = $this->config->model('activity');
        $query = $class::query()->where('drive_id', $context->driveId())->newestFirst();
        if ($node !== null) {
            $query->where('target_type', $node->getMorphClass())->where('target_id', $node->id);
        }
        if ($verb !== null) {
            $query->where('verb', $verb->value);
        }

        return $query;
    }

    /** @param array<string, bool> $allowed */
    private function visible(DriveContext $context, Activity $event, array $allowed): bool
    {
        if ($event->target_type === $context->drive->getMorphClass()) {
            return (string) $event->target_id === (string) $context->driveId();
        }

        return $event->target_type === (new ($this->config->model('node')))->getMorphClass() && isset($allowed[(string) $event->target_id]);
    }

    /**
     * A reader without Share on the target sees WHAT happened, not who did it from where
     * or to whom access was granted: the request fingerprint, the actor key and the
     * grant/share subject, permission and token details are withheld. The row is a read
     * projection (original attributes synced), so it can never write the redaction back.
     *
     * @param array<string, bool> $allowed
     */
    private function project(Activity $event, array $allowed): Activity
    {
        $target = $event->target_type === (new ($this->config->model('node')))->getMorphClass() ? (string) $event->target_id : null;
        if ($target !== null && ($allowed[$target] ?? false)) {
            return $event;
        }
        foreach (['ip', 'user_agent', 'request_id', 'actor_id'] as $attribute) {
            $event->setAttribute($attribute, null);
        }
        $context = $event->context;
        if (is_array($context)) {
            $event->setAttribute('context', array_diff_key($context, array_flip(self::PRIVILEGED_CONTEXT_KEYS)));
        }
        $event->syncOriginal();

        return $event;
    }

    /** @param Builder<Activity> $query
     * @param array{created_at: mixed, id: mixed}|null $last
     */
    private function after(Builder $query, ?array $last): void
    {
        if ($last !== null) {
            $query->where(fn (Builder $q) => $q->where('created_at', '<', $last['created_at'])->orWhere(fn (Builder $q) => $q->where('created_at', $last['created_at'])->where('id', '<', $last['id'])));
        }
    }

    /** @param array{created_at: string, id: string} $last */
    private function encode(array $last, string $scope): Cursor
    {
        return new Cursor([...$last, 'scope' => $scope, 'signature' => $this->signature($last['created_at'], $last['id'], $scope)]);
    }

    private function decode(?string $encoded, string $scope): ?Cursor
    {
        if ($encoded === null) {
            return null;
        }
        $cursor = Cursor::fromEncoded($encoded);
        $params = $cursor?->toArray() ?? [];
        if ($cursor === null || $cursor->pointsToNextItems() !== true || !is_string($params['created_at'] ?? null) || !is_string($params['id'] ?? null) || $params['id'] === '' || !is_string($params['signature'] ?? null) || ($params['scope'] ?? null) !== $scope || !hash_equals($this->signature($params['created_at'], $params['id'], $scope), $params['signature'])) {
            throw new InvalidArgumentException('Invalid activity cursor or query scope.');
        }

        return $cursor;
    }

    private function scalar(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('Invalid activity tuple.');
        }

        return (string) $value;
    }

    private function signature(string $createdAt, string $id, string $scope): string
    {
        $key = $this->repository->get('app.key');
        if (!is_string($key) || $key === '') {
            throw new InvalidArgumentException('Activity cursors require an application signing key.');
        }

        return hash_hmac('sha256', json_encode([$createdAt, $id, $scope], JSON_THROW_ON_ERROR), $key);
    }
}
