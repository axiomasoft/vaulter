<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

use Illuminate\Support\Carbon;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;

final class AcmeDriver implements CollaborationProtocolDriver
{
    /** @var array<string, CollaborationGrant> */
    private array $credentials = [];

    public ?string $invalidVersion = null;

    public bool $emptyOptions = false;

    public function protocol(): string
    {
        return 'acme-ot';
    }

    public function version(): string
    {
        return 'acme-ot/1';
    }

    public function supportsState(string $documentType, string $stateFormat, int $schemaVersion, bool $hasCheckpoint, CollaborationMode $mode): bool
    {
        return $documentType === 'acme/ot' && $stateFormat === 'acme-ot-v1' && $schemaVersion === 1;
    }

    public function issueSession(CollaborationGrant $grant): CollaborationSession
    {
        $token = bin2hex(random_bytes(24));
        $this->credentials[$token] = $grant;

        return new CollaborationSession(
            protocol: $this->protocol(), version: $this->invalidVersion ?? $this->version(), endpoint: null,
            token: $token, expiresAt: $grant->notAfter, mode: $grant->mode, nodeId: $grant->nodeId,
            generation: $grant->generation, schemaVersion: $grant->schemaVersion,
            options: $this->emptyOptions ? [] : ['document' => $grant->nodeId],
        );
    }

    public function verify(string $token): CollaborationGrant
    {
        $grant = $this->credentials[$token] ?? throw new \InvalidArgumentException('Invalid opaque credential.');
        if ($grant->notAfter <= Carbon::now()->getTimestamp()) {
            throw new \InvalidArgumentException('Expired opaque credential.');
        }

        return $grant;
    }
}
