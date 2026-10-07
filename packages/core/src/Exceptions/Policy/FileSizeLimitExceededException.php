<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Policy;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A file is larger than the drive policy's maxFileBytes or the installation-wide
 * upload limit. Checked against the declared size before bytes are written, while
 * they stream and against the actual size at finalization.
 */
final class FileSizeLimitExceededException extends VaulterException implements ProblemException
{
    private function __construct(string $message, public readonly int $limit)
    {
        parent::__construct($message);
    }

    public static function forLimit(int $limit): self
    {
        return new self('[Vaulter] The file is larger than '.$limit.' bytes. '
            .'Why: the drive policy or the installation limits the size of one file. '
            .'Fix: upload a smaller file. Docs: '.self::docs('file-size-limit-exceeded'), $limit);
    }

    public function problemCode(): string
    {
        return 'file_size_limit_exceeded';
    }

    public function httpStatus(): int
    {
        return 413;
    }

    /**
     * @return array<string, mixed>
     */
    protected function renderExtras(): array
    {
        return ['limit' => $this->limit];
    }
}
