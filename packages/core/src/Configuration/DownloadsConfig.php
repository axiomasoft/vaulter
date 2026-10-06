<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class DownloadsConfig
{
    private const array DEFAULT_DOWNLOAD_INLINE_MIME_WHITELIST = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
    ];

    public function __construct(private Repository $config) {}

    /** @return list<string> */
    public function inlineMimeTypes(): array
    {
        $default = self::DEFAULT_DOWNLOAD_INLINE_MIME_WHITELIST;
        $configured = $this->stringList($this->config->get('vaulter.downloads.inline_mime_types', $default), $default);

        $mimes = [];

        foreach ($configured as $mime) {
            if ($mime !== '') {
                $mimes[] = strtolower($mime);
            }
        }

        return $mimes === [] ? $default : array_values(array_unique($mimes));
    }

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    private function stringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
