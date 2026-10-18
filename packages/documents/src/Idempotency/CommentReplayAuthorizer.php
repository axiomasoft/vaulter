<?php

declare(strict_types=1);

namespace Vaulter\Documents\Idempotency;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;
use Vaulter\Support\Identity;

/**
 * The current authorization of a replayed comment write (D25, ID2), with the same guard as the write itself, on the
 * comment's node (a node that is trashed, gone or invisible now, a revoked ability or a disabled comments feature:
 * the replay is refused exactly as a new request would be).
 *
 * Add needs Comment. Resolve needs Comment and either authorship or Edit; delete needs View and either authorship or
 * Manage. The receipt records the basis the right was proven on as its scope: a `node` is the Edit or Manage proof, a
 * `node-author` the authorship — which a later change of roles does not take away from the author.
 */
#[Internal]
final readonly class CommentReplayAuthorizer implements ReplayAuthorizer
{
    public const string AUTHORED = 'node-author';

    private const string ADD = 'add';

    private const string RESOLVE = 'resolve';

    private const string DELETE = 'delete';

    private function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private ProfilePolicy $profiles, private string $verb, private string $node) {}

    public static function forAdd(DriveContext $context, NodeLocator $locator, OperationGuard $guard, ProfilePolicy $profiles, string $node): self
    {
        return new self($context, $locator, $guard, $profiles, self::ADD, $node);
    }

    public static function forResolve(DriveContext $context, NodeLocator $locator, OperationGuard $guard, ProfilePolicy $profiles, string $node): self
    {
        return new self($context, $locator, $guard, $profiles, self::RESOLVE, $node);
    }

    public static function forDelete(DriveContext $context, NodeLocator $locator, OperationGuard $guard, ProfilePolicy $profiles, string $node): self
    {
        return new self($context, $locator, $guard, $profiles, self::DELETE, $node);
    }

    /** Whether the context's actor wrote the comment of `$authorType` / `$authorId`. */
    public static function isAuthor(DriveContext $context, string $authorType, int|string|null $authorId): bool
    {
        $actor = $context->actor->ref();

        return !$context->actor->isSystem()
            && $actor->id !== null
            && $authorId !== null
            && $actor->type === $authorType
            && Identity::stringify($actor->id) === Identity::stringify($authorId);
    }

    public function scope(mixed $result): ResourceRef
    {
        return match (true) {
            $result instanceof CommentSnapshot => new ResourceRef($this->verb === self::ADD || !self::isAuthor($this->context, $result->authorType, $result->authorId) ? 'node' : self::AUTHORED, $result->nodeId),
            $result instanceof CommentDeleteReceipt => new ResourceRef($result->byAuthor ? self::AUTHORED : 'node', $result->nodeId),
            default => new ResourceRef('node', $this->node),
        };
    }

    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
    {
        $id = $resource?->kind === 'node' ? $resource->id : ($scope !== null && in_array($scope->kind, ['node', self::AUTHORED], true) ? $scope->id : $this->node);
        $node = $this->locator->find($this->context, $id);
        $authored = $scope?->kind === self::AUTHORED;

        match ($this->verb) {
            self::ADD => $this->guard->authorize($this->context, Ability::Comment, $node, OperationKind::Mutation),
            self::RESOLVE => $this->guard->authorize($this->context, $authored ? Ability::Comment : Ability::Edit, $node, OperationKind::Mutation),
            default => $this->guard->authorize($this->context, $authored ? Ability::View : Ability::Manage, $node, OperationKind::Mutation),
        };
        if ($this->verb === self::RESOLVE && !$authored) {
            $this->guard->authorize($this->context, Ability::Comment, $node, OperationKind::Mutation);
        }
        $this->profiles->assertComments($this->context->drive);
    }
}
