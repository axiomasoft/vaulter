<?php

declare(strict_types=1);

use Vaulter\Integration\Tests\Schema\DriveSchemaAssertions;

it('применяет базовые Drive-миграции под ulid-стратегией', function (): void {
    DriveSchemaAssertions::assertAllTablesAndColumns();
});
