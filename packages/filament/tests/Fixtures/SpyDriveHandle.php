<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests\Fixtures;

use ArrayObject;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Contracts\Access\AccessOperations;
use Vaulter\Contracts\Activity\ActivityOperations;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Drive\FileOperations;
use Vaulter\Contracts\Drive\FolderOperations;
use Vaulter\Contracts\Drive\NodeOperations;
use Vaulter\Contracts\Drive\TrashOperations;
use Vaulter\Contracts\Sharing\ShareOperations;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\DriveSnapshot;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Data\Snapshots\QuotaSnapshot;
use Vaulter\Models\Drive\Drive;
use Vaulter\Operations\ChangeOperations;
use Vaulter\Profiles\Profile;
use Vaulter\ValueObjects\Access\Actor;

/**
 * A real handle that records the node operations the panel calls on it, so a test can
 * prove a panel action went through the handle (and with which arguments) instead of
 * a core Action. Everything delegates to the inner handle; nothing is faked.
 */
final class SpyDriveHandle implements DriveHandle
{
    /** @param ArrayObject<int, array{string, array<int, mixed>}> $calls */
    public function __construct(private readonly DriveHandle $inner, private readonly ArrayObject $calls) {}

    public function model(): Drive
    {
        return $this->inner->model();
    }

    public function snapshot(): DriveSnapshot
    {
        return $this->inner->snapshot();
    }

    public function quota(): QuotaSnapshot
    {
        return $this->inner->quota();
    }

    public function archive(): DriveSnapshot
    {
        return $this->inner->archive();
    }

    public function unarchive(): DriveSnapshot
    {
        return $this->inner->unarchive();
    }

    public function delete(): DriveSnapshot
    {
        return $this->inner->delete();
    }

    public function profile(): Profile
    {
        return $this->inner->profile();
    }

    public function actor(): Actor
    {
        return $this->inner->actor();
    }

    public function actingAs(Authorizable $user): static
    {
        return new self($this->inner->actingAs($user), $this->calls);
    }

    public function asSystem(string $reason): static
    {
        return new self($this->inner->asSystem($reason), $this->calls);
    }

    public function withCorrelationId(string $correlationId): static
    {
        return new self($this->inner->withCorrelationId($correlationId), $this->calls);
    }

    public function withIdempotencyKey(string $key, ?string $requestFingerprint = null): static
    {
        return new self($this->inner->withIdempotencyKey($key, $requestFingerprint), $this->calls);
    }

    public function root(): NodeSnapshot
    {
        return $this->inner->root();
    }

    public function nodes(): NodeOperations
    {
        $inner = $this->inner->nodes();
        $calls = $this->calls;

        return new class($inner, $calls) implements NodeOperations
        {
            /** @param ArrayObject<int, array{string, array<int, mixed>}> $calls */
            public function __construct(private readonly NodeOperations $inner, private readonly ArrayObject $calls) {}

            public function find(NodeSnapshot|string|int $node): NodeSnapshot
            {
                return $this->inner->find($node);
            }

            public function children(NodeSnapshot|string|int|null $folder = null, ?ListOptions $options = null): Page
            {
                return $this->inner->children($folder, $options);
            }

            public function search(string $term, ?SearchOptions $options = null): Page
            {
                return $this->inner->search($term, $options);
            }

            public function rename(NodeSnapshot|string|int $node, string $name, ?int $expectedSequence = null): NodeSnapshot
            {
                $this->calls->append(['nodes.rename', [$node, $name]]);

                return $this->inner->rename($node, $name, $expectedSequence);
            }

            public function move(NodeSnapshot|string|int $node, NodeSnapshot|string|int|null $destination, ?MoveOptions $options = null): NodeSnapshot
            {
                $this->calls->append(['nodes.move', [$node, $destination]]);

                return $this->inner->move($node, $destination, $options);
            }

            public function copy(NodeSnapshot|string|int $node, NodeSnapshot|string|int|null $destination = null, ?CopyOptions $options = null): NodeSnapshot
            {
                return $this->inner->copy($node, $destination, $options);
            }

            public function star(NodeSnapshot|string|int $node): NodeSnapshot
            {
                return $this->inner->star($node);
            }

            public function unstar(NodeSnapshot|string|int $node): NodeSnapshot
            {
                return $this->inner->unstar($node);
            }

            public function starred(?ListOptions $options = null): Page
            {
                return $this->inner->starred($options);
            }

            public function properties(NodeSnapshot|string|int $node): array
            {
                return $this->inner->properties($node);
            }

            public function setProperties(NodeSnapshot|string|int $node, string $namespace, array $set, array $unset = [], ?int $expectedSequence = null): NodeSnapshot
            {
                return $this->inner->setProperties($node, $namespace, $set, $unset, $expectedSequence);
            }

            public function trash(NodeSnapshot|string|int $node, ?int $expectedSequence = null): NodeSnapshot
            {
                $this->calls->append(['nodes.trash', [$node]]);

                return $this->inner->trash($node, $expectedSequence);
            }

            public function abilities(NodeSnapshot|string|int $node): NodeAbilities
            {
                return $this->inner->abilities($node);
            }

            public function abilitiesMany(array $nodes): array
            {
                return $this->inner->abilitiesMany($nodes);
            }
        };
    }

    public function folders(): FolderOperations
    {
        return $this->inner->folders();
    }

    public function files(): FileOperations
    {
        return $this->inner->files();
    }

    public function trash(): TrashOperations
    {
        return $this->inner->trash();
    }

    public function access(): AccessOperations
    {
        return $this->inner->access();
    }

    public function shares(): ShareOperations
    {
        return $this->inner->shares();
    }

    public function activity(): ActivityOperations
    {
        return $this->inner->activity();
    }

    public function changes(): ChangeOperations
    {
        return $this->inner->changes();
    }

    public function extension(string $operations): object
    {
        return $this->inner->extension($operations);
    }
}
