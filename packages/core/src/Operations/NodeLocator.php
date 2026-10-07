<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tenancy\TenancyBoundary;

final readonly class NodeLocator
{
    public function __construct(
        private VaulterConfig $config,
        private VaulterDatabase $database,
    ) {}

    public function find(OperationContext $context, Node|int|string $key, bool $includeTrashed = false): Node
    {
        $id = $this->key($key);
        if ($id === null) {
            throw NodeNotFoundException::forId('unpersisted');
        }
        $query = $this->query($context);
        if ($includeTrashed) {
            $query->withTrashed();
        } else {
            $query->whereNull('trashed_at');
        }
        $node = $query->whereKey($id)->first();

        return $node ?? throw NodeNotFoundException::forId($id);
    }

    /**
     * @internal Rebind untrusted listing candidates in one scoped query.
     *
     * @param  iterable<Node> $nodes
     * @return list<Node>
     */
    public function findMany(OperationContext $context, iterable $nodes): array
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
        $found = $this->query($context)->whereKey($keys)->get()->keyBy(fn (Node $node): string => (string) $node->id);
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
    private function query(OperationContext $context): Builder
    {
        $class = $this->config->model('node');
        $model = new $class;
        $model->setConnection($this->database->connection()->getName());

        if (app(TenancyConfig::class)->enabled()) {
            app(TenancyBoundary::class)->assertDrive($context->drive);
        }

        return app(TenancyBoundary::class)->constrain($model->newQuery())->where(column: 'drive_id', operator: '=', value: $context->driveId());
    }

    private function key(Node|int|string $key): int|string|null
    {
        if (!$key instanceof Node) {
            return $key === '' ? null : $key;
        }
        $id = $key->getRawOriginal($key->getKeyName());

        return $key->exists && (is_int($id) || is_string($id)) && $id !== '' ? $id : null;
    }
}
