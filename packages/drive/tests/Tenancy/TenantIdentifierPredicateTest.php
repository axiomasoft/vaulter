<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

uses(TestCase::class);

it('SEC-TENANT-IDENTITY keeps numeric and case-sensitive tenant identity on the actual database', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    app()->instance(TenantResolver::class, $resolver);
    $connection = app(VaulterDatabase::class)->connection();
    $schema = $connection->getSchemaBuilder();
    $table = 'p38_identity_'.bin2hex(random_bytes(4));
    $model = new class extends Drive
    {
        public function getTable(): string
        {
            return $this->table;
        }
    };
    $model->setTable($table);
    $schema->create($table, function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->bigInteger('tenant_id')->nullable()->index();
    });
    try {
        config()->set('vaulter.ids.host_keys', 'bigint');
        $connection->table($table)->insert(['tenant_id' => 1]);
        $resolver->tenant = '001';
        expect(app(TenancyBoundary::class)->constrain($model->newQuery())->count())->toBe(0);
        $resolver->tenant = 1;
        expect(app(TenancyBoundary::class)->constrain($model->newQuery())->count())->toBe(1);
        $resolver->tenant = 'not-numeric';
        expect(app(TenancyBoundary::class)->constrain($model->newQuery())->count())->toBe(0);
    } finally {
        $schema->drop($table);
    }
    $schema->create($table, function (Blueprint $blueprint) use ($connection): void {
        $blueprint->id();
        $column = $blueprint->string('tenant_id')->nullable()->index();
        if ($connection->getDriverName() === 'mysql') {
            $column->collation('utf8mb4_unicode_ci');
        } elseif ($connection->getDriverName() === 'sqlite') {
            $column->collation('NOCASE');
        }
    });
    try {
        config()->set('vaulter.ids.host_keys', 'string');
        $connection->table($table)->insert(['tenant_id' => 'A']);
        $resolver->tenant = 'a';
        if (in_array($connection->getDriverName(), ['mysql', 'sqlite'], true)) {
            expect($connection->table($table)->where('tenant_id', 'a')->exists())->toBeTrue();
        }
        expect(app(TenancyBoundary::class)->constrain($model->newQuery())->count())->toBe(0);
        $resolver->tenant = 'A';
        expect(app(TenancyBoundary::class)->constrain($model->newQuery())->count())->toBe(1);
        fwrite(STDOUT, 'P38_TENANT_IDENTITY '.json_encode(['driver' => $connection->getDriverName(), 'database' => $connection->getDatabaseName(), 'numeric_leading_zero_denied' => true, 'text_case_denied' => true, 'canonical_allowed' => true]).PHP_EOL);
    } finally {
        $schema->drop($table);
    }
});
