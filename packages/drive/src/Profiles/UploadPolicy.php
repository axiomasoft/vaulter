<?php

declare(strict_types=1);

namespace Vaulter\Drive\Profiles;

use Packman\Attributes\Api;
use Vaulter\Drive\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Media\Enums\Cas\ScanMode;

#[Api]
final readonly class UploadPolicy
{
    /** @param list<string> $allowedMimeTypes
     * @param list<string> $blockedExtensions
     */
    public function __construct(
        public ?int $maxFileSize = null,
        public array $allowedMimeTypes = [],
        public array $blockedExtensions = [],
        public ?bool $requireScan = null,
        public ?ScanMode $scanMode = null,
        public ?string $scanPolicyVersion = null,
    ) {
        if ($maxFileSize !== null && $maxFileSize < 0) {
            throw new InvalidProfileException('Upload size must be nonnegative.');
        }
        $this->validateList($allowedMimeTypes);
        $this->validateList($blockedExtensions);
        if ($requireScan === true && $scanMode !== null && $scanMode !== ScanMode::AllContent) {
            throw new InvalidProfileException('requireScan=true conflicts with the explicit scan mode.');
        }
        if ($scanPolicyVersion !== null && preg_match('/^[a-zA-Z0-9._:-]{1,64}$/D', $scanPolicyVersion) !== 1) {
            throw new InvalidProfileException('Invalid scan policy version.');
        }
    }

    /** @param array<array-key, mixed> $list */
    private function validateList(array $list): void
    {
        if (!array_is_list($list)) {
            throw new InvalidProfileException('Upload policy values must be string lists.');
        }
        foreach ($list as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidProfileException('Upload policy values must be nonempty strings.');
            }
        }
    }
}
