<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * A module's contribution to the problem-code catalogue, declared with `ModuleBuilder::problems()`. The
 * catalogue lists the codes of active modules only; a code declared by two active modules makes the module
 * graph invalid. A source has no constructor arguments: the compiler reads its codes.
 */
#[Spi(SpiKind::Implement)]
interface ProblemCodeSource
{
    /** The translation namespace holding `problems.<code>.title|detail`. */
    public function translationNamespace(): string;

    /**
     * @return array<string, ProblemSpec> code => spec
     */
    public function codes(): array;
}
