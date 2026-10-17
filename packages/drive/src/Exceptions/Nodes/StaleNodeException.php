<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class StaleNodeException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    /** @param string|null $nodeId the node whose expectation failed, reported to the client as meta.node_id */
    public function __construct(private readonly ?string $nodeId = null)
    {
        parent::__construct('[Vaulter] The node changed; reload it before retrying.');
    }

    public static function forNode(int|string $nodeId): self
    {
        return new self((string) $nodeId);
    }

    /** @return array<string, mixed> */
    public function problemDetails(): array
    {
        return $this->nodeId === null ? [] : ['node_id' => $this->nodeId];
    }

    public function problemCode(): string
    {
        return 'node_sequence_mismatch';
    }

    public function httpStatus(): int
    {
        return 412;
    }
}
