<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures;

use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;

/** The problem codes of a fixture module. */
final class FixtureProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'fixture';
    }

    public function codes(): array
    {
        return [
            'fixture_conflict' => new ProblemSpec(409, receipt: ReceiptPolicy::Cacheable),
            'fixture_missing' => new ProblemSpec(404),
        ];
    }
}
