<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Vaulter\Configuration\DownloadsConfig;

/**
 * The ONE shared response-header builder for a file download (P1.1 / F-P1.3-04,
 * F-P1.11-03), used by BOTH PHP-stream download surfaces — the identity
 * DownloadController (vaulter-drive) and the public PublicShareController (this
 * package). Referenced from vaulter-drive, never the other way — core must not
 * depend on the extracted drive package (P2 #5 / D1), so this class stays
 * plain-prose about its drive-side caller rather than importing/`@see`-ing it.
 * The declared `mime_type` is attacker-controlled (set by whoever uploaded the
 * file), so it is NEVER trusted directly: `X-Content-Type-Options: nosniff` is
 * set unconditionally, and `Content-Type` is only ever the declared mime when it
 * sits on the closed inline-safe whitelist — anything else (including a mime
 * that merely LOOKS safe, e.g. `text/html; charset=utf-8`) downgrades to
 * `application/octet-stream`.
 */
final class DownloadHeaders
{
    /**
     * Build the download response headers for a declared mime-type. Does not
     * touch `Content-Length` — the caller merges these in alongside it.
     *
     * @return array{'Content-Type': string, 'X-Content-Type-Options': string}
     */
    public static function forDeclaredMime(?string $declaredMime): array
    {
        return [
            'Content-Type' => self::safeContentType($declaredMime),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * The declared mime, ONLY if it is exactly one of the whitelisted inline-safe
     * types (case-insensitive); otherwise the closed fallback. This is a strict
     * allow-list check, not a blocklist — a mime carrying parameters
     * (`text/html; charset=utf-8`) or an unlisted variant never matches and always
     * falls back, so there is no parameter/case obfuscation to sneak past it.
     */
    private static function safeContentType(?string $declaredMime): string
    {
        if ($declaredMime === null || $declaredMime === '') {
            return 'application/octet-stream';
        }

        $normalized = strtolower(trim($declaredMime));

        if (!in_array($normalized, app(DownloadsConfig::class)->inlineMimeTypes(), true)) {
            return 'application/octet-stream';
        }

        return $declaredMime;
    }
}
