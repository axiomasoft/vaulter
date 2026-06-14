<?php

declare(strict_types=1);

namespace DocVault\Repositories;

use DocVault\DocVaultManager;
use DocVault\Models\Document;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Read-side authorization gate. Delegates to the host {@see PermissionResolver}.
 * When no resolver is configured, access is open (intended for trusted/dev setups —
 * bind a resolver in production).
 */
final readonly class DocumentVisibilityService
{
    public function __construct(
        private DocVaultManager $manager,
    ) {}

    public function canView(?Authenticatable $user, Document $document): bool
    {
        return $this->can($user, 'view', $document);
    }

    public function can(?Authenticatable $user, string $action, Document $document): bool
    {
        $resolver = $this->manager->permissionResolver();

        if ($resolver === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $resolver->can($user, $action, $document);
    }

    public function canCreate(?Authenticatable $user, string $workspaceType, int|string $workspaceId): bool
    {
        $resolver = $this->manager->permissionResolver();

        if ($resolver === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $resolver->canCreate($user, $workspaceType, $workspaceId);
    }

    /**
     * @param  Collection<int, Document> $documents
     * @return Collection<int, Document>
     */
    public function filterViewable(?Authenticatable $user, Collection $documents): Collection
    {
        return $documents
            ->filter(fn (Document $document): bool => $this->canView($user, $document))
            ->values();
    }
}
