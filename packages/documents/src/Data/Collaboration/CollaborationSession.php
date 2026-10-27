<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Vaulter\Attributes\Api;
use Vaulter\Documents\Exceptions\CollaborationProtocolContractException;

#[Api]
final readonly class CollaborationSession
{
    /** @param array<string, string|int|bool|null> $options Nonsecret protocol options. */
    public function __construct(
        public string $protocol,
        public string $version,
        public ?string $endpoint,
        public string $token,
        public int $expiresAt,
        public CollaborationMode $mode,
        public string $nodeId,
        public int $generation,
        public int $schemaVersion,
        public array $options = [],
    ) {}

    public function assertGrant(CollaborationGrant $grant, string $version): void
    {
        if ($this->protocol !== $grant->protocol || preg_match('/^[a-z][a-z0-9-]*$/', $this->protocol) !== 1
            || $this->version !== $version || preg_match('/^'.preg_quote($this->protocol, '/').'\/[1-9][0-9]*$/', $version) !== 1
            || $this->nodeId !== $grant->nodeId || $this->generation !== $grant->generation
            || $this->schemaVersion !== $grant->schemaVersion || ($grant->mode === CollaborationMode::Read && $this->mode !== CollaborationMode::Read)
            || $this->expiresAt > $grant->notAfter || $this->expiresAt <= time() || trim($this->token) === '') {
            throw new CollaborationProtocolContractException;
        }
        $this->assertOptions($this->options);
    }

    /** @param array<array-key, mixed> $options Values crossing an adapter boundary need runtime validation. */
    private function assertOptions(array $options): void
    {
        foreach ($options as $key => $value) {
            if (!is_string($key) || (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null)) {
                throw new CollaborationProtocolContractException;
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['protocol' => $this->protocol, 'version' => $this->version, 'endpoint' => $this->endpoint,
            'token' => $this->token, 'expires_at' => $this->expiresAt, 'mode' => $this->mode->value,
            'node_id' => $this->nodeId, 'generation' => $this->generation, 'schema_version' => $this->schemaVersion, 'options' => $this->options];
    }
}
