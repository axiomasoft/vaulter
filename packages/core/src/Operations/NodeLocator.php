<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tenancy\TenancyBoundary;

final readonly class NodeLocator
{
    public function __construct(
        private VaulterConfig $config,
        private VaulterDatabase $database,
        private TenancyConfig $tenancy,
        private TenancyBoundary $boundary,
    ) {}

    public function find(DriveContext $context, Node|NodeSnapshot|int|string $key, bool $includeTrashed = false): Node
    {
        $id = $this->key($key);
        if ($id === null) {
            throw NodeNotFoundException::forId('unpersisted');
        }
        $query = $this->query($context);
        if (!$this->plausibleKey($query, $id)) {
            // A key the id column cannot hold names no node; PostgreSQL would fail the cast instead.
            throw NodeNotFoundException::forId($id);
        }
        if (!$includeTrashed) {
            $query->whereNull('trashed_at');
        }
        $node = $query->whereKey($id)->first();

        return $node ?? throw NodeNotFoundException::forId($id);
    }

    /**
     * @internal Rebind untrusted listing candidates (models, snapshots or ids) in one scoped query.
     *
     * @param  iterable<Node|NodeSnapshot|int|string> $nodes
     * @return list<Node>
     */
    public function findMany(DriveContext $context, iterable $nodes): array
    {
        $keys = [];
        foreach ($nodes as $node) {
            $key = $this->key($node);
            if ($key !== null) {
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            return [];
        }
        $query = $this->query($context);
        $keys = array_values(array_filter($keys, fn (int|string $key): bool => $this->plausibleKey($query, $key)));
        if ($keys === []) {
            return [];
        }
        $found = $query->whereKey($keys)->get()->keyBy(fn (Node $node): string => (string) $node->id);
        $result = [];
        foreach ($keys as $key) {
            $node = $found->get((string) $key);
            if ($node !== null) {
                $result[] = $node;
            }
        }

        return $result;
    }

    /** @return Builder<Node> */
    private function query(DriveContext $context): Builder
    {
        $class = $this->config->model('node');
        $model = new $class;
        $model->setConnection($this->database->connection()->getName());

        if ($this->tenancy->enabled()) {
            $this->boundary->assertDrive($context->drive);
        }

        return $this->boundary->constrain($model->newQuery())->where(column: 'drive_id', operator: '=', value: $context->driveId());
    }

    /** @param Builder<Node> $query */
    private function plausibleKey(Builder $query, int|string $key): bool
    {
        return $query->getModel()->getKeyType() !== 'int' || preg_match('/^[0-9]{1,19}$/', (string) $key) === 1;
    }

    private function key(Node|NodeSnapshot|int|string $key): int|string|null
    {
        if ($key instanceof NodeSnapshot) {
            return $key->id === '' ? null : $key->id;
        }
        if (!$key instanceof Node) {
            return $key === '' ? null : $key;
        }
        $id = $key->getRawOriginal($key->getKeyName());

        return $key->exists && (is_int($id) || is_string($id)) && $id !== '' ? $id : null;
    }
}
