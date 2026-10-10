<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

/**
 * A package's contribution to the problem-code catalogue. A source is tagged
 * `vaulter.problem_codes` in the container, so core lists the codes of drive and
 * documents without importing their classes.
 */
interface ProblemCodeSource
{
    /** The package the codes belong to, as shown in the reference docs. */
    public function package(): string;

    /** The translation namespace holding `problems.<code>.title|detail`. */
    public function translationNamespace(): string;

    /**
     * @return array<string, array{status: int, exception: class-string|null}>
     */
    public function codes(): array;
}
