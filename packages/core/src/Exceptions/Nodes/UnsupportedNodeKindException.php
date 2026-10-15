<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class UnsupportedNodeKindException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] This node kind does not support this operation.');
    }

    public function problemCode(): string
    {
        return 'unsupported_node_kind';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function forKind(NodeKind $kind): self
    {
        return new self;
    }
}
