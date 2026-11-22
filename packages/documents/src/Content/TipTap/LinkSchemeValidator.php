<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content\TipTap;

use Packman\Attributes\Api;

/**
 * Strict allow-list guard for `href` URL schemes in TipTap content. Shared by the store-time
 * validator ({@see TipTapContent}) and the render-time Markdown path (the HTML path
 * gets its second echelon from `symfony/html-sanitizer` instead).
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
#[Api]
final readonly class LinkSchemeValidator
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * The schemes that are an XSS primitive wherever a browser resolves them — whatever the
     * attribute they sit in is called.
     *
     * @var list<string>
     */
    private const array DANGEROUS_SCHEMES = ['javascript', 'data', 'vbscript'];

    public static function isAllowed(string $href): bool
    {
        return self::sanitize($href) !== null;
    }

    /**
     * Does this VALUE read as a URL with an executable scheme?
     *
     * The allow-list above answers "may this URL be emitted"; this answers a different,
     * weaker question — "would a browser execute this string if it ever resolved it as a
     * URL" — and it is the one an attribute the package knows NOTHING about can be asked
     * . The store-time allow-list only knows which attributes are
     * URL-BEARING by name ({@see TipTapContent::urlAttributes()}), so a host extension whose
     * URL lives in `source` / `dataSrc` / any name outside that list slipped a
     * `javascript:` past the barrier at write AND at read. Guarding by VALUE closes the seam
     * for good: an attribute name is host taste, an executable scheme never is.
     *
     * Deliberately NOT the negation of {@see isAllowed()}: a relative `/pricing`, an anchor
     * `#top` or a bare `foo` are not allowed as a link href, but they are perfectly ordinary
     * values for a title/alt/class attribute and must not blow a document up.
     */
    public static function isDangerous(string $value): bool
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        if (!is_string($stripped)) {
            return true;
        }

        $normalized = mb_strtolower(trim($stripped));

        return array_any(self::DANGEROUS_SCHEMES, fn (string $scheme): bool => str_starts_with($normalized, $scheme.':'));
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
        preg_match('/\A([a-z][a-z0-9+.-]*):/i', $normalized, $match);
        $scheme = strtolower($match[1] ?? '');

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        return $normalized;
    }
}
