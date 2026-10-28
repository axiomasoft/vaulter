<?php

declare(strict_types=1);

namespace Vaulter\Jobs;

use Vaulter\Attributes\Api;

#[Api]
final readonly class PreparedJob
{
    public function __construct(public JobRequest $request, public ?PublicationContext $context = null) {}
}
