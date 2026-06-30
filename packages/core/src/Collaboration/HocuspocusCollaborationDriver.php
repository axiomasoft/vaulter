<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Auth\Authenticatable;
use Vaulter\Contracts\Collaboration\CollaborationDriver;
use Vaulter\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Models\Documents\Document;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;
use Vaulter\VaulterManager;

/**
 * Optional driver targeting an external Node Hocuspocus hub (see hocuspocus-server/).
 * Requires firebase/php-jwt to mint room tokens.
 */
final readonly class HocuspocusCollaborationDriver implements CollaborationDriver
{
    public function __construct(
        private VaulterManager $manager,
    ) {}

    public function type(): CollaborationDriverType
    {
        return CollaborationDriverType::Hocuspocus;
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
        $config = Config::driverConfig('hocuspocus');

        return [
            'driver' => $this->type()->value,
            'url' => $config['url'] ?? null,
            'room' => Identity::stringify($document->getKey()),
            'token' => $this->issueToken($document, $user),
        ];
    }

    public function issueToken(Document $document, Authenticatable $user): string
    {
        if (!class_exists(JWT::class)) {
            throw new VaulterException(
                '[Vaulter] The Hocuspocus driver requires firebase/php-jwt. '
                .'Fix: composer require firebase/php-jwt. '
                .'Docs: https://github.com/axioma-studio/vaulter/blob/main/docs/guide/errors.md#missing-jwt',
            );
        }

        $config = Config::driverConfig('hocuspocus');
        $secret = $config['secret'] ?? null;

        if (!is_string($secret) || $secret === '') {
            throw new VaulterException(
                '[Vaulter] Missing VAULTER_COLLAB_SECRET for the Hocuspocus driver. '
                .'Fix: set the shared secret in your environment. '
                .'Docs: https://github.com/axioma-studio/vaulter/blob/main/docs/guide/errors.md#missing-collab-secret',
            );
        }

        $ttlRaw = $config['token_ttl'] ?? 3600;
        $ttl = is_numeric($ttlRaw) ? (int) $ttlRaw : 3600;
        $issuedAt = time();

        return JWT::encode(
            payload: [
                'sub' => Identity::stringify($user->getAuthIdentifier()),
                'room' => Identity::stringify($document->getKey()),
                'iat' => $issuedAt,
                'exp' => $issuedAt + $ttl,
            ],
            key: $secret,
            alg: 'HS256',
        );
    }
}
