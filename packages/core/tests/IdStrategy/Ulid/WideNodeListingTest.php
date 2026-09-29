<?php

declare(strict_types=1);

use Vaulter\Tests\Concerns\ExercisesWideNodeListing;

uses(ExercisesWideNodeListing::class);

it('lists and cursor-paginates a ULID subtree above the PostgreSQL bind limit (R27)', function (): void {
    $this->assertWideNodeListing();
});
