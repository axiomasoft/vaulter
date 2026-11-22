<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Exceptions;

use Override;
use Packman\Attributes\Internal;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Webhooks\Errors\WebhooksErrorCode;

/**
 * A webhook delivery (or endpoint registration) targeted a URL the outbound guard
 * refused: a non-http(s) scheme, an unresolvable host, or a host
 * that resolves — on ANY A/AAAA record — into a private, loopback, link-local or
 * otherwise reserved range (cloud metadata at 169.254.169.254 included).
 *
 * The barrier lives on the DELIVERY path (DeliverWebhook), not just registration, so a
 * DNS record that flips to an internal address between the two is still refused.
 */
#[Internal]
final class BlockedOutboundUrlException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'validation_failed';

    private const int HTTP_STATUS = 422;

    public static function scheme(string $scheme): self
    {
        return new self(
            sprintf('[Vaulter] Webhook delivery refused: URL scheme [%s] is not allowed. ', $scheme === '' ? '(none)' : $scheme)
            .'Why: only the configured outbound schemes (default http/https) may be dialled. '
            .'Fix: register the endpoint with an http(s) URL. '
            .'Docs: '.self::docs('webhook-ssrf'),
        );
    }

    public static function malformed(): self
    {
        return new self(
            '[Vaulter] Webhook delivery refused: malformed or disallowed URL authority. '
            .'Why: an outbound target must carry a resolvable host. '
            .'Fix: register a well-formed absolute http(s) URL. '
            .'Docs: '.self::docs('webhook-ssrf'),
        );
    }

    public static function unresolvable(string $host): self
    {
        return new self(
            sprintf('[Vaulter] Webhook delivery refused: host [%s] does not resolve to any IP. ', $host)
            .'Why: a host with no A/AAAA record cannot be safely dialled. '
            .'Fix: point the endpoint at a publicly resolvable host. '
            .'Docs: '.self::docs('webhook-ssrf'),
        );
    }

    public static function blockedAddress(string $host, string $ip): self
    {
        return new self(
            sprintf('[Vaulter] Webhook delivery refused: host [%s] resolves to the blocked address [%s]. ', $host, $ip)
            .'Why: the address falls in a private/loopback/link-local/reserved range (SSRF guard). '
            .'Fix: use a public endpoint, or widen vaulter-webhooks.delivery.allowed_ip_ranges for a trusted internal receiver. '
            .'Docs: '.self::docs('webhook-ssrf'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['errors' => ['url' => [$this->getMessage()]]];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: WebhooksErrorCode::BlockedOutboundUrl, category: ErrorCategory::Internal);
    }
}
