<?php

declare(strict_types=1);

use Vaulter\Integration\Tests\Schema\DriveSchemaAssertions;

it('applies the base Drive migrations under the ulid strategy', function (): void {
    DriveSchemaAssertions::assertAllTablesAndColumns();
});
