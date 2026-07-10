<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Documents;

/**
 * Strict allow-list guard for `href` URL schemes in TipTap content (F-P1.11-01,
 * plans/VLT-REMED/findings/P0-remediation-norms.md N1). Shared by the store-time
 * validator ({@see TipTapContent}) and the render-time Markdown path (the HTML path
 * gets its second echelon from `symfony/html-sanitizer` instead, per D4).
 *
 * Only `http`, `https`, `mailto` are allowed — everything else (`javascript:`,
 * `data:`, `vbscript:`, schema-less paths) is rejected. The scheme is extracted AFTER
 * normalisation, never by matching the raw string: browsers strip ASCII control
 * characters (tab/CR/LF) anywhere in a URL before parsing it, so a check like
 * `str_starts_with($href, 'javascript:')` is defeated by `java\tscript:alert(1)`.
 * BiDi control characters (U+202A–U+202E, U+2066–U+2069) are rejected outright: they
 * let an attacker visually reorder the scheme in a code review / preview UI while the
 * browser resolves the real one underneath.
 */
final readonly class LinkSchemeValidator
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    public static function isAllowed(string $href): bool
    {
        return self::sanitize($href) !== null;
    }

    /**
     * Returns the control-character-stripped, trimmed href when its scheme is on the
     * allow-list, or null when it must be rejected.
     */
    public static function sanitize(string $href): ?string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $href);

        if (!is_string($stripped)) {
            return null;
        }

        if (preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $stripped) === 1) {
            return null;
        }

        $normalized = trim($stripped);
        $scheme = parse_url(mb_strtolower($normalized), PHP_URL_SCHEME);

        if (!is_string($scheme) || !in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        return $normalized;
    }
}
