<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A properties patch broke the contract of or the drive policy limits.
 * `reason` is one of the stable codes below.
 */
#[Api]
final class InvalidPropertiesException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public const string NAMESPACE = 'namespace';

    public const string KEY = 'key';

    public const string VALUE_TOO_LONG = 'value_too_long';

    public const string TOO_MANY = 'too_many';

    public const string TOO_LARGE = 'too_large';

    public const string RESERVED_NAMESPACE = 'reserved_namespace';

    private const array EXPLANATIONS = [
        self::NAMESPACE => 'a namespace must match ^[a-z0-9][a-z0-9.-]{0,127}$',
        self::KEY => 'a key must be 1–64 ASCII characters of A-Z a-z 0-9 _ . -',
        self::VALUE_TOO_LONG => 'a value must be a UTF-8 string of at most 1024 bytes',
        self::TOO_MANY => 'the node would hold more properties than the drive policy allows',
        self::TOO_LARGE => 'the properties would exceed the drive policy size in canonical JSON',
        self::RESERVED_NAMESPACE => 'the vaulter and vaulter.* namespaces are written by the system only',
    ];

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $subject = ''): self
    {
        $why = self::EXPLANATIONS[$reason] ?? throw new \InvalidArgumentException('Unknown properties reason '.$reason.'.');
        $printable = $subject === '' ? '' : ' '.json_encode($subject, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new self('[Vaulter] Invalid node properties'.$printable.': '.$why.'. '
            .'Fix: send string values under a namespaced key within the drive policy limits. '
            .'Docs: '.self::docs('invalid-properties'), $reason);
    }

    public function problemCode(): string
    {
        return 'invalid_properties';
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
        return ['reason' => $this->reason];
    }
}
