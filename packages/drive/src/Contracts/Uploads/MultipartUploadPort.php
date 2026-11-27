<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Uploads;

use DateTimeInterface;
use Packman\Attributes\Internal;

/** The direct multipart transport shared by upload, scan, completion and cleanup. */
#[Internal]
interface MultipartUploadPort
{
    public function enabled(?string $disk): bool;

    public function initiate(?string $disk, string $key): string;

    public function presignPart(?string $disk, string $key, string $uploadId, int $part, int $size, DateTimeInterface $expires): string;

    /** @return array{version:string,etag:string} */
    public function complete(?string $disk, string $key, string $uploadId, int $total, int $partSize): array;

    /** @param array{version:string,etag:string} $identity @return resource|null */
    public function open(?string $disk, string $key, array $identity): mixed;

    /** @param array{version:string,etag:string}|null $identity */
    public function discard(?string $disk, string $key, ?string $uploadId, ?array $identity): void;
}
