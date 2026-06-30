<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Illuminate\Contracts\Auth\Authenticatable;
use Vaulter\Contracts\Collaboration\CollaborationDriver;
use Vaulter\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Models\Documents\Document;
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
            'connection' => Config::reverbConnection(),
            'document_id' => $document->getKey(),
            'token' => $this->issueToken($document, $user),
        ];
    }

    /**
     * Reverb authenticates out-of-band: the front-end joins the PRIVATE broadcast
     * channel for the room and Laravel's channel-auth endpoint authorizes it (the
     * registered channel callback delegates to authorizeChannel → the host
     * PermissionResolver). There is no separate room JWT to mint — the connection
     * descriptor the client uses IS the channel name it subscribes to, which the
     * auth endpoint then gates. We return that channel name so a single accessor
     * yields a usable handle for either transport instead of an opaque empty string.
     */
    public function issueToken(Document $document, Authenticatable $user): string
    {
        return $this->channelName($document);
    }

    private function channelName(Document $document): string
    {
        return Config::channelPrefix().'.'.Identity::stringify($document->getKey());
    }
}
