<?php

declare(strict_types=1);

use Vaulter\Drive\Testing\Concerns\ExercisesWideNodeListing;

uses(ExercisesWideNodeListing::class);

it('lists and cursor-paginates a ULID subtree above the PostgreSQL bind limit (R27)', function (): void {
    $this->assertWideNodeListing();
});
