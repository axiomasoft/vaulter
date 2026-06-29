<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Illuminate\Contracts\Auth\Authenticatable;
use Vaulter\Contracts\CollaborationDriver;
use Vaulter\Enums\CollaborationDriverType;
use Vaulter\Models\Document;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;
use Vaulter\VaulterManager;

/**
 * Default driver: Yjs over Laravel Reverb. The front-end joins a private channel
 * authorized by the host PermissionResolver — no extra service required.
 */
final readonly class ReverbCollaborationDriver implements CollaborationDriver
{
    public function __construct(
        private VaulterManager $manager,
    ) {}

    public function type(): CollaborationDriverType
    {
        return CollaborationDriverType::Reverb;
    }

    public function authorizeChannel(Authenticatable $user, Document $document): bool
    {
        $resolver = $this->manager->permissionResolver();

        return $resolver === null
            ? true
            : $resolver->can($user, 'edit', $document);
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionConfig(Document $document, Authenticatable $user): array
    {
        return [
            'driver' => $this->type()->value,
            'channel' => $this->channelName($document),
            'document_id' => $document->getKey(),
            'token' => $this->issueToken($document, $user),
        ];
    }

    public function issueToken(Document $document, Authenticatable $user): string
    {
        // Reverb authorizes via the private-channel auth endpoint; no room token needed.
        return '';
    }

    private function channelName(Document $document): string
    {
        return Config::channelPrefix().'.'.Identity::stringify($document->getKey());
    }
}
