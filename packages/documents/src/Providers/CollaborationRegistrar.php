<?php

declare(strict_types=1);

namespace Vaulter\Documents\Providers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Vaulter\Access\AccessResolver;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Documents\Collaboration\HostUserResolver as DefaultHostUserResolver;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Contracts\HostUserResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;

final class CollaborationRegistrar extends ServiceProvider
{
    public function register(): void
    {
        // The host-user resolver seam: the collaboration webhook resolves the
        // acting user by id through the host's auth user-provider. Bound to the
        // default DefaultHostUserResolver; a host (or a test) may swap it. Purely
        // documents machinery — core neither defines nor binds it (finding).
        $this->app->bind(HostUserResolver::class, DefaultHostUserResolver::class);
    }

    public function boot(): void
    {
        $this->registerBroadcastChannel();
    }

    /**
     * Authorize the private collaboration room channel (channel_prefix.{id}). The
     * room id is a NODE id: a kind=document node authorizes through the
     * Vaulter-owned AccessResolver (Ability::View to connect at all; read-only vs
     * writable is encoded in the issued session, not the channel). Gated on BOTH the
     * 'document_versions' feature AND 'collaboration' — the realtime channel is a separate opt-in over base document
     * editing. The channel preserves the broadcast authorization seam
     * independently of the Hocuspocus room token.
     */
    private function registerBroadcastChannel(): void
    {
        Broadcast::channel(
            app(DocumentsConfig::class)->channelPrefix().'.{roomId}',
            static fn (Authenticatable $user, int|string $roomId): bool => app(DocumentsConfig::class)->collaborationEnabled()
                && self::authorizeNodeRoom($user, $roomId),
        );
    }

    /**
     * Authorize a node room: the id resolves to a kind=document node the actor may at
     * least View through the AccessResolver.
     */
    private static function authorizeNodeRoom(Authenticatable $user, int|string $roomId): bool
    {
        if (!$user instanceof Authorizable) {
            return false;
        }

        $model = app(VaulterConfig::class)->model('node');
        $node = $model::query()->find($roomId);

        if (!$node instanceof Node || !$node->kind->isDocument()) {
            return false;
        }

        return app(AccessResolver::class)->can($user, Ability::View, $node);
    }
}
