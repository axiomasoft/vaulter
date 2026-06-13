<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\DocVaultManager;
use DocVault\Exceptions\PermissionDeniedException;
use DocVault\Models\Document;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class IssueCollaborationTokenAction
{
    public function __construct(
        private DocVaultManager $manager,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(Document $document, Authenticatable $user): array
    {
        $driver = $this->manager->driver();

        if (!$driver->authorizeChannel($user, $document)) {
            throw PermissionDeniedException::forAction('collaborate');
        }

        return $driver->connectionConfig($document, $user);
    }
}
