<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Uploads;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The assembled resumable object's magic bytes contradict the client-declared mime
 * (§2.8 / F-P1.4-11 / U5.5): e.g. a file declared image/png whose leading bytes are
 * a ZIP archive. The server recomputes the type from the bytes it holds and refuses
 * to commit a type-confused object rather than trust the client's claim.
 */
final class UploadContentMismatchException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'upload_rejected';

    private const int HTTP_STATUS = 422;

    public static function forSession(string $uploadId, string $declaredMime): self
    {
        return new self(
            "[Vaulter] Upload session [{$uploadId}] declared mime [{$declaredMime}] but the ".
            'assembled object contents do not match it. '.
            'Fix: upload a file whose contents match the declared Content-Type. '.
            'Docs: '.self::docs('upload-content-mismatch'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['reason' => 'mime_mismatch'];
    }
}
