<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Configuration\StringList;

/**
 * Immutable compiled download-delivery options for `vaulter-drive.downloads.*`.
 */
#[Api(since: '1.0')]
final readonly class DownloadsOptions
{
    /** @var list<string> */
    private const array DEFAULT_INLINE_MIME_WHITELIST = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
        'application/pdf',
        'text/plain',
    ];

    /**
     * @param list<string> $inlineMimeTypes
     */
    public function __construct(
        public string $deliveryMode = 'strict',
        public int $directTtlSeconds = 60,
        public array $inlineMimeTypes = self::DEFAULT_INLINE_MIME_WHITELIST,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['delivery_mode', 'direct_ttl_seconds', 'inline_mime_types'], $path);

        $mode = $raw['delivery_mode'] ?? 'strict';
        if (!in_array($mode, ['strict', 'direct_signed'], true)) {
            $validator->add($path.'.delivery_mode', 'Invalid content delivery mode.');
            $mode = 'strict';
        }

        $ttl = $raw['direct_ttl_seconds'] ?? 60;
        if (!is_int($ttl) || $ttl < 1 || $ttl > 300) {
            $validator->add($path.'.direct_ttl_seconds', 'Direct URL TTL must be an integer from 1 to 300.');
            $ttl = 60;
        }

        $inline = StringList::from($raw['inline_mime_types'] ?? self::DEFAULT_INLINE_MIME_WHITELIST, self::DEFAULT_INLINE_MIME_WHITELIST);

        return new self(
            deliveryMode: $mode,
            directTtlSeconds: $ttl,
            inlineMimeTypes: $inline,
        );
    }
}
