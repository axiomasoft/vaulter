<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Cursor;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\ValueObjects\GrantSet;
use Vaulter\Drive\Configuration\ListingConfig;
use Vaulter\Drive\Data\NodePage;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Nodes\InvalidCursorException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Support\Identity;
use WeakMap;

#[Internal]
final readonly class ExactListing
{
    private const PURPOSE = 'node-list';

    private const MAX_CURSOR_BYTES = 2048;

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

    /**
     * Seals a continuation position into an authenticated, encrypted cursor bound to a typed scope. The scope
     * (drive, tenant, query, share, actor ...) enters only as a canonical SHA-256 digest, never as plaintext;
     * the position is the raw seek keys or Laravel cursor parameters. Wire size is bounded at 2048 bytes.
     *
     * @param list<mixed>             $scope
     * @param array<array-key, mixed> $position
     */
    public function seal(string $purpose, array $scope, array $position): string
    {
        $json = json_encode(['v' => 1, 'p' => $purpose, 'b' => $this->binding($purpose, $scope), 'k' => $position], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cursor = $this->encrypter->encrypt($json, false);
        if (strlen($cursor) > self::MAX_CURSOR_BYTES) {
            throw new \LogicException('Listing cursor exceeds the wire size limit.');
        }

        return $cursor;
    }

    /**
     * @param list<mixed> $scope
     *
     * @throws InvalidCursorException when the cursor is malformed, tampered, legacy, oversized or bound to another scope
     *
     * @return array<array-key, mixed>
     */
    public function open(string $cursor, string $purpose, array $scope): array
    {
        if ($cursor === '' || strlen($cursor) > self::MAX_CURSOR_BYTES) {
            throw InvalidCursorException::invalid();
        }
        // Laravel's decrypt ignores base64 padding noise; one canonical wire form per payload.
        $outer = base64_decode($cursor, true);
        if ($outer === false || base64_encode($outer) !== $cursor) {
            throw InvalidCursorException::invalid();
        }
        try {
            $json = $this->encrypter->decrypt($cursor, false);
            $data = is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (DecryptException|\JsonException) {
            throw InvalidCursorException::invalid();
        }
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || ($data['p'] ?? null) !== $purpose || !is_string($data['b'] ?? null) || !hash_equals($this->binding($purpose, $scope), $data['b']) || !isset($data['k']) || !is_array($data['k'])) {
            throw InvalidCursorException::invalid();
        }

        return $data['k'];
    }

    /**
     * Opens a sealed cursor into the raw Laravel cursor (parameters + direction) a cursor paginator seeks from.
     *
     * @param list<mixed>  $scope
     * @param list<string> $columns the exact ordered cursor columns of the query
     */
    public function openCursor(?string $cursor, string $purpose, array $scope, array $columns): ?Cursor
    {
        if ($cursor === null) {
            return null;
        }
        $position = $this->open($cursor, $purpose, $scope);
        $parameters = $position['parameters'] ?? null;
        if (!is_array($parameters) || !is_bool($position['next'] ?? null) || array_keys($parameters) !== $columns) {
            throw InvalidCursorException::invalid();
        }
        foreach ($parameters as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw InvalidCursorException::invalid();
            }
        }

        return new Cursor($parameters, $position['next']);
    }

    /** @param list<mixed> $scope */
    public function sealCursor(?Cursor $next, string $purpose, array $scope): ?string
    {
        if ($next === null) {
            return null;
        }
        $parameters = $next->toArray();
        unset($parameters['_pointsToNextItems']);

        return $this->seal($purpose, $scope, ['parameters' => $parameters, 'next' => $next->pointsToNextItems()]);
    }

    /** @param list<mixed> $keys
     * @param list<array{column:string, direction:'asc'|'desc'}> $order
     */
    private function encode(array $keys, string $context, array $order): string
    {
        return $this->seal(self::PURPOSE, [$context, $order], $keys);
    }

    /** @param list<array{column:string, direction:'asc'|'desc'}> $order
     * @return list<int|string>
     */
    private function decode(string $cursor, string $context, array $order): array
    {
        $data = $this->open($cursor, self::PURPOSE, [$context, $order]);
        if (!array_is_list($data) || count($data) !== count($order)) {
            throw InvalidCursorException::invalid();
        }
        $keys = [];
        foreach ($data as $key) {
            if (!is_int($key) && !is_string($key)) {
                throw InvalidCursorException::invalid();
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Canonical digest of the server-computed scope; the scope itself never enters the cursor.
     *
     * @param list<mixed> $scope
     */
    private function binding(string $purpose, array $scope): string
    {
        return hash('sha256', json_encode([$purpose, ...$scope], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
