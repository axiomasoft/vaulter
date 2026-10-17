<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\Access\GrantStoreRepository;
use Vaulter\Facades\Vaulter;
use Vaulter\Filament\Support\PanelNodes;

/**
 * Builders for the Filament resource tests: a fresh storage + a folder under its
 * root, a known acting user, and a one-liner grant writer (through the core store
 * repository, the same row the AccessResolver reads).
 */
final class DriveBuilder
{
    /**
     * A fresh storage (and its auto-provisioned root) for a unique host entity.
     */
    public static function storage(): Drive
    {
        static $next = 0;
        $next++;

        $host = new TestHost;
        $host->forceFill(['id' => $next]);
        $host->exists = true;

        return Vaulter::drive($host)->model();
    }

    /**
     * A folder node directly under the storage root (via the core Drive Action).
     */
    public static function folder(Drive $storage, string $name = 'Docs'): Node
    {
        $snapshot = Vaulter::drive($storage)->asSystem('test fixture')->folders()->create(name: $name);

        return Node::query()->findOrFail($snapshot->id);
    }

    /**
     * The panel table row of a node as the acting admin sees it; a node the panel does
     * not list (no View) yields a bare row, so action callbacks can still be forged.
     *
     * @return array<string, mixed>
     */
    public static function row(Node $node): array
    {
        foreach (PanelNodes::rows() as $row) {
            if ($row['id'] === (string) $node->getKey()) {
                return $row;
            }
        }

        return [
            'id' => (string) $node->getKey(),
            'drive_id' => (string) $node->getRawOriginal('drive_id'),
            'parent_id' => $node->parent_id === null ? null : (string) $node->parent_id,
            'kind' => $node->kind,
            'name' => $node->name,
            'size_bytes' => 0,
            'trashed_at' => null,
            'created_at' => CarbonImmutable::now(),
            'abilities' => [],
        ];
    }

    /**
     * The acting panel admin (the grant subject / actor).
     */
    public static function user(int $id = 1): TestUser
    {
        $user = new TestUser;
        $user->forceFill(['id' => $id]);
        $user->exists = true;

        return $user;
    }

    /**
     * Grant a user a role on a node (the row the AccessResolver reads), written via
     * the core store repository so the test does not hand-roll the schema.
     */
    public static function grant(
        Node $node,
        TestUser $user,
        string $role = RoleRegistry::OWNER,
        GrantScope $scope = GrantScope::Subtree,
    ): NodeGrant {
        return app(GrantStoreRepository::class)->grant(
            node: $node,
            data: new GrantInput(
                subject: Subject::ofModel($user),
                role: $role,
                scope: $scope,
            ),
        );
    }
}
