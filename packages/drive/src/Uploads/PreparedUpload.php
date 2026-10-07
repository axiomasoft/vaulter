<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Vaulter\Contracts\Cas\StagedObject;
use Vaulter\Drive\Data\Files\UploadFacts;

/** @internal The caller owns this preparation until its last SQL attempt returns. */
final readonly class PreparedUpload
{
    public function __construct(public StagedObject $staged, public UploadFacts $facts) {}

    public function discard(): void
    {
        $this->staged->discard();
    }
}
