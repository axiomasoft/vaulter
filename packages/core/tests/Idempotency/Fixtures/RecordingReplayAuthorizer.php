<?php

declare(strict_types=1);

namespace Vaulter\Tests\Idempotency\Fixtures;

use Throwable;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;

/** Records every replay authorization; refuses with `$deny` when set. */
final class RecordingReplayAuthorizer implements ReplayAuthorizer
{
    /** @var list<array{?ResourceRef, ?ResourceRef}> */
    public array $calls = [];

    public function __construct(public ?Throwable $deny = null) {}

    public function scope(mixed $result): ResourceRef
    {
        return new ResourceRef('drive', 'scope-of-test');
    }

    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
    {
        $this->calls[] = [$resource, $scope];
        if ($this->deny !== null) {
            throw $this->deny;
        }
    }
}
