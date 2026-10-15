<?php

declare(strict_types=1);

namespace Vaulter\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Vaulter\Access\ValueObjects\GrantSet;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ListingConfig;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Nodes\InvalidCursorException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Support\Identity;
use WeakMap;

#[Internal]
final readonly class ExactListing
{
    /** @var WeakMap<Node, GrantSet> */
    private WeakMap $grants;

    public function __construct(
        private readonly ListingConfig $listingConfig,
        private AccessResolver $access, private VaulterDatabase $database, private Encrypter $encrypter,
    ) {
        $this->grants = new WeakMap;
    }

    public function grantSetFor(Node $node): GrantSet
    {
        return $this->grants[$node] ?? GrantSet::none();
    }

    /**
     * @param Builder<Node>                                      $candidates
     * @param list<array{column:string, direction:'asc'|'desc'}> $order
     */
    public function page(Authorizable|DriveContext|null $actor, Builder $candidates, array $order, int $perPage, ?string $cursor, string $context): NodePage
    {
        $operation = $actor instanceof DriveContext ? $actor : null;
        if ($operation !== null) {
            $candidates = (clone $candidates)->where('drive_id', $operation->driveId());
            $actor = $operation->actor->authorizable();
        }
        if ($actor === null && !($operation?->actor->isSystem() ?? false)) {
            return new NodePage(items: [], nextCursor: null, scanned: 0);
        }
        if ($candidates->getConnection() !== $this->database->connection()) {
            throw new \LogicException('Listing candidates must use the Vaulter connection.');
        }
        $last = $cursor === null ? null : $this->decode($cursor, $context, $order);
        $visible = [];
        $scanned = 0;
        $budget = $this->listingConfig->scanBudget();
        while (count($visible) < $perPage && $scanned < $budget) {
            $query = (clone $candidates)->reorder();
            foreach ($order as $sort) {
                $query->orderBy($sort['column'], $sort['direction']);
            }
            if ($last !== null) {
                $query->where(function (Builder $outer) use ($order, $last): void {
                    foreach ($order as $i => $sort) {
                        $outer->orWhere(function (Builder $branch) use ($order, $last, $i, $sort): void {
                            for ($j = 0; $j < $i; $j++) {
                                $branch->where($order[$j]['column'], '=', $last[$j]);
                            }
                            $branch->where($sort['column'], $sort['direction'] === 'asc' ? '>' : '<', $last[$i]);
                        });
                    }
                });
            }
            $batch = $query->limit(min($perPage * 2, $budget - $scanned))->get();
            if ($batch->isEmpty()) {
                $last = null;
                break;
            }
            $sets = $actor === null ? [] : $this->access->evaluateMany($actor, $batch);
            if ($actor instanceof Model) {
                // The listing actor's own stars, one query per batch (viewer.starred).
                $batch->load(['userStates' => static function (Relation $states) use ($actor): void {
                    $states->where('actor_type', $actor->getMorphClass())->where('actor_id', $actor->getKey());
                }]);
            }
            $allowed = $operation === null ? null : array_fill_keys(array_map(static fn (Node $node): string => (string) $node->id, app(OperationGuard::class)->visible($operation, $batch)), true);
            foreach ($batch as $node) {
                $scanned++;
                $last = array_map(static fn (array $sort) => $node->getRawOriginal($sort['column']), $order);
                $set = $operation?->actor->isSystem() ? GrantSet::superAllowed() : $sets[Identity::stringify($node->id)];
                if ($allowed === null ? $set->allows(Ability::View) : isset($allowed[(string) $node->id])) {
                    $visible[] = $node;
                    $this->grants[$node] = $set;
                    if (count($visible) === $perPage) {
                        break;
                    }
                }
            }
        }

        return new NodePage(items: $visible, nextCursor: $last === null ? null : $this->encode($last, $context, $order), scanned: $scanned);
    }

    /** @param list<mixed> $keys
     * @param list<array{column:string, direction:'asc'|'desc'}> $order
     */
    private function encode(array $keys, string $context, array $order): string
    {
        $payload = $this->base64url(json_encode(['c' => $context, 'k' => $keys, 'o' => $order], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->base64url(hash_hmac('sha256', $payload, $this->encrypter->getKey(), true));
    }

    /** @param list<array{column:string, direction:'asc'|'desc'}> $order
     * @return list<int|string>
     */
    private function decode(string $cursor, string $context, array $order): array
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || !hash_equals($this->base64url(hash_hmac('sha256', $parts[0], $this->encrypter->getKey(), true)), $parts[1])) {
            throw InvalidCursorException::invalid();
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || ($data['c'] ?? null) !== $context || ($data['o'] ?? null) !== $order || !isset($data['k']) || !is_array($data['k']) || !array_is_list($data['k']) || count($data['k']) !== count($order)) {
            throw InvalidCursorException::invalid();
        }
        $keys = [];
        foreach ($data['k'] as $key) {
            if (!is_int($key) && !is_string($key)) {
                throw InvalidCursorException::invalid();
            }
            $keys[] = $key;
        }

        return $keys;
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
