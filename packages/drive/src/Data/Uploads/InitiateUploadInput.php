<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Uploads;

use InvalidArgumentException;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Models\Drive\Node;

final readonly class InitiateUploadInput
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public UploadProtocol $protocol,
        public Node|int|string|null $targetParent = null,
        public ?string $targetName = null,
        public Node|int|string|null $targetNode = null,
        public ?string $expectedSha256 = null,
        public ?int $totalSizeBytes = null,
        public ?string $mimeType = null,
        public array $metadata = [],
        public ?int $expectedSequence = null,
    ) {
        if ($totalSizeBytes !== null && $totalSizeBytes < 0) {
            throw new InvalidArgumentException('Upload size must be nonnegative.');
        }
        if ($expectedSequence !== null && $expectedSequence < 0) {
            throw new InvalidArgumentException('Expected sequence must be nonnegative.');
        }
        if ($expectedSha256 !== null && preg_match('/^[a-f0-9]{64}$/i', $expectedSha256) !== 1) {
            throw new InvalidArgumentException('Expected SHA-256 must be a hexadecimal content digest.');
        }
    }

    public function isNewVersion(): bool
    {
        return $this->targetNode !== null;
    }
}
