<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Jobs;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Jobs\JobRequest;
use Vaulter\Jobs\PreparedJob;
use Vaulter\Jobs\PublicationContext;

#[Spi(SpiKind::Implement)]
interface JobPublisher
{
    public function prepare(JobRequest $request, ?PublicationContext $origin = null): PreparedJob;

    public function publish(PreparedJob $job): void;
}
