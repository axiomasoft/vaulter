<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Sharing;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** The share link would outlive the drive policy's maxShareLinkTtlDays. */
final class ShareLinkTtlExceededException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private function __construct(string $message, public readonly int $limitDays)
    {
        parent::__construct($message);
    }

    public static function forLimit(int $limitDays): self
    {
        return new self('[Vaulter] The share link would stay valid longer than '.$limitDays.' days. '
            .'Why: the drive policy limits how long a link may live, and the limit applies at creation and on every visit. '
            .'Fix: choose an earlier expiry. '
            .'Docs: '.self::docs('share-link-ttl-exceeded'), $limitDays);
    }

    public function problemCode(): string
    {
        return 'share_link_ttl_exceeded';
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
        return ['limit_days' => $this->limitDays];
    }
}
