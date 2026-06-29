<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Vaulter\Exceptions\PermissionDeniedException;
use Vaulter\Models\Document;
use Vaulter\VaulterManager;

final readonly class IssueCollaborationTokenAction
{
    public function __construct(
        private VaulterManager $manager,
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
