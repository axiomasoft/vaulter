<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * Realtime collaboration cannot be offered for this document: the feature is switched
 * off, or the document's type cannot be edited by several clients at once. The
 * document itself stays readable and, with Edit, saves through the ordinary update.
 */
#[Api]
final class CollaborationUnavailableException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(
        public readonly string $reason,
        public readonly ?string $type = null,
    ) {
        parent::__construct(sprintf(
            '[Vaulter] Collaboration is unavailable (%s). Fix: %s Docs: %s',
            $reason,
            $reason === 'disabled'
                ? 'enable vaulter-documents.collaboration.enabled, or edit through DocumentOperations::update().'
                : 'edit this document through DocumentOperations::update(); its type does not implement SupportsCollaboration.',
            self::docs('collaboration-unavailable'),
        ));
    }

    public static function disabled(): self
    {
        return new self('disabled');
    }

    public static function unsupportedType(string $type): self
    {
        return new self('unsupported_type', $type);
    }

    public function problemCode(): string
    {
        return 'collaboration_unavailable';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['context' => array_filter(['reason' => $this->reason, 'type' => $this->type], static fn (?string $value): bool => $value !== null)];
    }
}
