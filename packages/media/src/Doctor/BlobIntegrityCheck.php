<?php

declare(strict_types=1);

namespace Vaulter\Media\Doctor;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Support\Identity;

/**
 * The blobs the integrity scrub marked as damaged: their object is missing or has another size or sha256, and
 * no download hands them out. Only an ingest of the same bytes into the same scope rewrites the object and lifts the
 * mark, so the finding is a warning that names the blobs and the way to heal them. Reads the rows only.
 */
#[Internal]
final readonly class BlobIntegrityCheck implements DoctorCheck
{
    public function __construct(private MediaConfig $mediaConfig) {}

    public function key(): string
    {
        return 'media.blobs.integrity';
    }

    public function title(): string
    {
        return 'Blob integrity';
    }

    public function run(DoctorContext $context): array
    {
        $model = $this->mediaConfig->blobModel();
        $failed = $model::query()->whereNotNull('integrity_failed_at');
        $count = (clone $failed)->count();

        if ($count === 0) {
            return [DoctorFinding::ok(code: 'media.blobs.integrity', message: 'No blob is marked as damaged by the integrity scrub.')];
        }

        $key = (new $model)->getKeyName();
        $ids = (clone $failed)->orderBy('integrity_failed_at')->orderBy($key)->limit(max($context->sampleLimit, 1))->pluck($key)
            ->map(static fn (mixed $id): string => Identity::stringify($id))->all();

        return [DoctorFinding::warning(
            code: 'media.blobs.integrity',
            message: sprintf('%d blob(s) failed the integrity scrub (missing object, wrong size or wrong sha256); their content is not handed out.', $count),
            hint: 'upload the same bytes again into the same scope: the ingest rewrites the object and lifts the mark',
            evidence: ['failed' => $count, 'blobs' => implode(',', $ids)],
        )];
    }
}
