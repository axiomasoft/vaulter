<?php

declare(strict_types=1);

namespace Vaulter\Media\Http\Problems;

use Vaulter\Attributes\Internal;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;

/**
 * The problem codes of the media package: a stable public contract, so a code is added here, in
 * resources/lang/{en,ru}/problems.php and in the reference docs together.
 */
#[Internal]
final class MediaProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter-media';
    }

    public function codes(): array
    {
        return [
            // The bytes went with a concurrent sweep: the answer depends on another transaction, never cached (D23, D25).
            'blob_unavailable' => new ProblemSpec(409, BlobUnavailableException::class),
        ];
    }
}
