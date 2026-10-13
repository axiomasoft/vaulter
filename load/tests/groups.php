<?php

declare(strict_types=1);

/*
 * Test groups of the load suite (PLAN3 P1.5). The whole suite is functional coverage of the load harness (readiness, queue and
 * chaos fixtures, Redis metrics, traces): it needs external services or only checks the stand itself, so it runs through
 * `scripts/t stand` and never on a PR. k6 scripts and benchmarks are not tests of this suite and are not run by any command.
 */
return [
    'database' => [],
    'serial' => [],
    'extended' => [],
    'stand' => [
        'Feature',
        'Health',
        'Observability',
    ],
];
