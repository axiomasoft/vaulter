<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Vaulter\Drive\Configuration\DownloadsOptions;

/**
 * The ONE shared response-header builder for a file download, used by BOTH PHP-stream download surfaces — the identity
 * DownloadController and the PublicShareController.
 * The declared `mime_type` is attacker-controlled (set by whoever uploaded the
 * file), so it is NEVER trusted directly: `X-Content-Type-Options: nosniff` is
 * set unconditionally, and `Content-Type` is only ever the declared mime when it
 * sits on the closed inline-safe whitelist — anything else (including a mime
 * that merely LOOKS safe, e.g. `text/html; charset=utf-8`) downgrades to
 * `application/octet-stream`.
 */
#[Internal]
final class DownloadHeaders
{
    /**
     * Build the download response headers for a declared mime-type. Does not
     * touch `Content-Length` — the caller merges these in alongside it.
     *
     * @return array{'Content-Type': string, 'X-Content-Type-Options': string, 'Cache-Control': string}
     */
    public static function forDeclaredMime(?string $declaredMime): array
    {
        return [
            'Content-Type' => self::safeContentType($declaredMime),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];
    }

    /**
     * Every header of a byte response of the versioned API: the mime rules above, an
     * RFC 6266 disposition, a sandbox CSP so a document opened from the API origin cannot
     * run script, no byte ranges (not supported in 1.0) and no storing.
     *
     * @param  'attachment'|'inline' $disposition
     * @return array<string, string>
     */
    public static function forContent(?string $declaredMime, string $filename, string $disposition = 'attachment', ?int $size = null): array
    {
        $headers = [
            ...self::forDeclaredMime($declaredMime),
            'Content-Disposition' => self::disposition($disposition, $filename),
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Accept-Ranges' => 'none',
        ];
        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }

        return $headers;
    }

    /**
     * `attachment; filename="<ascii>"; filename*=UTF-8''<encoded>`. The stored name is
     * user input: control characters (a CR/LF would split the header) and path
     * separators are removed before it reaches the header.
     */
    public static function disposition(string $type, string $filename): string
    {
        if ($type !== 'attachment' && $type !== 'inline') {
            throw new InvalidArgumentException('Content-Disposition must be attachment or inline.');
        }
        $name = trim(str_replace(['/', '\\'], '_', (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename)));
        // An invalid UTF-8 name makes the replace fail; it is served under a neutral name.
        if ($name === '') {
            $name = 'download';
        }
        $fallback = trim((string) preg_replace('/[^\x20-\x7E]|["%\\\\\/]/', '_', Str::ascii($name)));

        return HeaderUtils::makeDisposition($type, $name, $fallback === '' ? 'download' : $fallback);
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

        if (!in_array($normalized, app(DownloadsOptions::class)->inlineMimeTypes, true)) {
            return 'application/octet-stream';
        }

        return $declaredMime;
    }
}
