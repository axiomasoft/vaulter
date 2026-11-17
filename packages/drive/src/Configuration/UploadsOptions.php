<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Configuration\StringList;

/**
 * Immutable compiled upload options for `vaulter-drive.uploads.*`.
 */
#[Api(since: '1.0')]
final readonly class UploadsOptions
{
    /**
     * @param list<string> $allowedMimeTypes
     * @param list<string> $blockedExtensions
     */
    public function __construct(
        public UploadsScanOptions $scan = new UploadsScanOptions,
        public int $maxFileSize = 5_368_709_120,
        public array $allowedMimeTypes = [],
        public array $blockedExtensions = [],
        public bool $rejectMimeMismatch = true,
        public ResumableUploadsOptions $resumable = new ResumableUploadsOptions,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, [
            'scan',
            'max_file_size',
            'allowed_mime_types',
            'blocked_extensions',
            'reject_mime_mismatch',
            'resumable',
        ], $path);

        $maxFileSize = $raw['max_file_size'] ?? 5_368_709_120;
        $allowedMimeTypes = StringList::from($raw['allowed_mime_types'] ?? [], []);
        $blockedExtensions = StringList::from($raw['blocked_extensions'] ?? [], []);
        $rejectMimeMismatch = (bool) ($raw['reject_mime_mismatch'] ?? true);

        return new self(
            scan: UploadsScanOptions::fromArray($raw['scan'] ?? [], $path.'.scan', $validator),
            maxFileSize: max(is_numeric($maxFileSize) ? (int) $maxFileSize : 5_368_709_120, 0),
            allowedMimeTypes: $allowedMimeTypes,
            blockedExtensions: $blockedExtensions,
            rejectMimeMismatch: $rejectMimeMismatch,
            resumable: ResumableUploadsOptions::fromArray($raw['resumable'] ?? [], $path.'.resumable', $validator),
        );
    }
}
