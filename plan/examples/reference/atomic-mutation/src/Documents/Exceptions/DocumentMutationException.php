<?php

declare(strict_types=1);

namespace VaulterRef\Documents\Exceptions;

use RuntimeException;

/** Target: domain exceptions of packages/documents/src/Exceptions/ mapped by the AI adapter to SDK codes. */
final class DocumentMutationException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Missing, foreign, trashed, not a document, drive not writable or no Edit: indistinguishable on purpose. */
    public static function unavailable(): self
    {
        return new self('resource_unavailable', 'The document is not available to this subject.');
    }

    public static function commandRequired(): self
    {
        return new self('document_command_required', 'The journal owns this document; a full replace is refused.');
    }

    public static function pending(): self
    {
        return new self('pending_state', 'Saved collaborative updates are not in the working state yet.');
    }

    public static function stale(int $expected, int $actual): self
    {
        return new self('stale_state', "Expected position {$expected}, locked position {$actual}.");
    }

    public static function scopeClosed(): self
    {
        return new self('scope_closed', 'The locked mutation is used outside its callback.');
    }

    public static function replacedTwice(): self
    {
        return new self('replace_already_called', 'replace() runs at most once per transaction attempt.');
    }

    public static function counterOverflow(): self
    {
        return new self('counter_overflow', 'A generation or position exceeds 2^53-1 and cannot be put on the wire.');
    }

    public static function invalidContent(string $why): self
    {
        return new self('invalid_content', $why);
    }
}
