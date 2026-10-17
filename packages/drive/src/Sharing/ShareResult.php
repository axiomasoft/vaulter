<?php

declare(strict_types=1);

namespace Vaulter\Drive\Sharing;

use Vaulter\Attributes\Internal;

/**
 * The resolver's result envelope: a {@see ShareResolution} status plus
 * the {@see ShareContext} when (and only when) the status is Ok. Immutable — the
 * public HTTP layer reads the status to choose 404 / 401 / proceed.
 */
#[Internal]
final readonly class ShareResult
{
    private function __construct(
        public ShareResolution $resolution,
        public ?ShareContext $context,
        public bool $inSession = false,
    ) {}

    /**
     * @param bool $inSession the request carried a valid link session, so this visit
     *                        was already redeemed and costs no further use
     */
    public static function ok(ShareContext $context, bool $inSession = false): self
    {
        return new self(resolution: ShareResolution::Ok, context: $context, inSession: $inSession);
    }

    public static function notFound(): self
    {
        return new self(resolution: ShareResolution::NotFound, context: null);
    }

    public static function passwordRequired(): self
    {
        return new self(resolution: ShareResolution::PasswordRequired, context: null);
    }

    public static function loginRequired(): self
    {
        return new self(resolution: ShareResolution::LoginRequired, context: null);
    }

    public function isOk(): bool
    {
        return $this->resolution->isOk();
    }
}
