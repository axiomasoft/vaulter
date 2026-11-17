<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled resumable upload metadata options for `vaulter-drive.uploads.resumable.metadata.*`.
 */
#[Api(since: '1.0')]
final readonly class ResumableMetadataOptions
{
    /** @var list<string> */
    private const array DEFAULT_ALLOWED_KEYS = ['filename', 'filetype', 'name', 'mime_type'];

    /**
     * @param list<string> $allowedKeys
     */
    public function __construct(
        public array $allowedKeys = self::DEFAULT_ALLOWED_KEYS,
        public int $maxValueLength = 512,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['allowed_keys', 'max_value_length'], $path);

        $keysRaw = $raw['allowed_keys'] ?? self::DEFAULT_ALLOWED_KEYS;
        $allowedKeys = [];
        if (is_array($keysRaw)) {
            foreach ($keysRaw as $key) {
                if (is_string($key) && $key !== '') {
                    $allowedKeys[] = $key;
                }
            }
        }

        $maxLength = $raw['max_value_length'] ?? 512;

        return new self(
            allowedKeys: $allowedKeys,
            maxValueLength: max(is_numeric($maxLength) ? (int) $maxLength : 512, 1),
        );
    }
}
