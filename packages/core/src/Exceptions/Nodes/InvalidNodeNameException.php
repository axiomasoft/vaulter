<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A node name broke the base rules or the drive's name policy. `reason` is a
 * stable machine code from the closed list below; the message explains it.
 * Rendered as a validation error on `name` so API clients handle it like a
 * form-request failure.
 */
#[Api]
final class InvalidNodeNameException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public const string EMPTY = 'empty';

    public const string PATH_ALIAS = 'path_alias';

    public const string SEPARATOR = 'separator';

    public const string CONTROL_CHAR = 'control_char';

    public const string RESERVED_CHAR = 'reserved_char';

    public const string TRAILING_DOT_OR_SPACE = 'trailing_dot_or_space';

    public const string LEADING_OR_TRAILING_SPACE = 'leading_or_trailing_space';

    public const string RESERVED_NAME = 'reserved_name';

    public const string TOO_LONG = 'too_long';

    private const array EXPLANATIONS = [
        self::EMPTY => 'it is empty',
        self::PATH_ALIAS => 'it is a path alias',
        self::SEPARATOR => 'it contains a path separator',
        self::CONTROL_CHAR => 'it contains a control character',
        self::RESERVED_CHAR => 'it contains a character the name policy reserves, or is not valid UTF-8',
        self::TRAILING_DOT_OR_SPACE => 'it ends with a dot',
        self::LEADING_OR_TRAILING_SPACE => 'it starts or ends with whitespace',
        self::RESERVED_NAME => 'it is a name the operating systems reserve',
        self::TOO_LONG => 'it is longer than 255 UTF-8 bytes or 255 UTF-16 code units',
    ];

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function forName(string $name, string $reason): self
    {
        $printable = json_encode($name, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $why = self::EXPLANATIONS[$reason] ?? throw new \InvalidArgumentException('Unknown node name reason '.$reason.'.');

        return new self(
            "[Vaulter] The node name {$printable} is invalid: {$why}. "
            .'Fix: use a single non-empty segment the drive name policy accepts. '
            .'Docs: '.self::docs('invalid-node-name'),
            $reason,
        );
    }

    public function problemCode(): string
    {
        return 'invalid_node_name';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['reason' => $this->reason, 'errors' => ['name' => [$this->getMessage()]]];
    }
}
