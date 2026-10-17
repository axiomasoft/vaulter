<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
#[Api]
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

    #[Internal]
    public function __construct(private Repository $config) {}

    public function deliveryMode(): string
    {
        $value = $this->config->get('vaulter-drive.downloads.delivery_mode', 'strict');
        if (!in_array($value, ['strict', 'direct_signed'], true)) {
            throw new InvalidConfigurationException('Invalid content delivery mode.');
        }

        return $value;
    }

    public function directTtlSeconds(): int
    {
        $value = $this->config->get('vaulter-drive.downloads.direct_ttl_seconds', 60);
        if (!is_int($value) || $value < 1 || $value > 300) {
            throw new InvalidConfigurationException('Direct URL TTL must be an integer from 1 to 300.');
        }

        return $value;
    }

    /** @return list<string> */
    public function inlineMimeTypes(): array
    {
        $default = self::DEFAULT_DOWNLOAD_INLINE_MIME_WHITELIST;
        $configured = $this->stringList($this->config->get('vaulter-drive.downloads.inline_mime_types', $default), $default);

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
