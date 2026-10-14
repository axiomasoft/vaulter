<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;

/** A problem source with a malformed code and an entry that is not a spec. */
final class MalformedProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'fixture';
    }

    public function codes(): array
    {
        /** @phpstan-ignore return.type (the compiler must refuse what static analysis would) */
        return [
            'Not-A-Code' => new ProblemSpec(409),
            'fixture_raw' => ['status' => 409, 'exception' => null],
        ];
    }
}
