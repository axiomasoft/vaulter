<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Pest\Mixins\Expectation;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Webhooks\Schema\WebhooksSchema;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('The endpoint index migration runs only on MySQL.');
    }

    // MySQL DDL commits implicitly. Give the migration a connection outside RefreshDatabase's transaction.
    config()->set('database.connections.webhook_upgrade', DB::connection()->getConfig());
    $db = DB::connection('webhook_upgrade');
    $schema = $db->getSchemaBuilder();
    $options = new DatabaseOptions(connection: 'webhook_upgrade', tablePrefix: 'upgrade_');
    $this->endpoints = $options->table('webhook_endpoints');
    $this->deliveries = $options->table('webhook_deliveries');
    $this->beforeApplicationDestroyed(function () use ($schema): void {
        $schema->dropIfExists($this->deliveries);
        $schema->dropIfExists($this->endpoints);
        DB::purge('webhook_upgrade');
    });

    $schema->create($this->endpoints, static fn (Blueprint $table) => $table->unsignedBigInteger('id')->primary());
    $schema->create($this->deliveries, function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('endpoint_id');
        $table->timestamp('created_at')->nullable();
        $table->index(['endpoint_id', 'created_at', 'id'], $this->deliveries.'_endpoint_feed_idx');
        $table->foreign('endpoint_id')->references('id')->on($this->endpoints)->cascadeOnDelete();
    });
    $this->app->instance(WebhooksSchema::class, new WebhooksSchema(new SchemaHelpers($options, app(IdStrategyResolver::class))));
    $this->migration = require __DIR__.'/../../database/migrations/0001_01_01_000404_add_endpoint_foreign_key_index_to_v_webhook_deliveries_table.php';
});

it('keeps the endpoint FK enforceable across a feed rebuild and resumes either migration direction', function (): void {
    $db = DB::connection('webhook_upgrade');
    $schema = $db->getSchemaBuilder();
    $feed = $this->deliveries.'_endpoint_feed_idx';
    $db->table($this->endpoints)->insert(['id' => 1]);
    $db->table($this->deliveries)->insert(['id' => 1, 'endpoint_id' => 1]);
    $interrupt = false;
    $db->beforeExecuting(static function (string $sql) use (&$interrupt, $feed): void {
        if ($interrupt && str_contains(strtolower($sql), 'add index `'.$feed.'`')) {
            $interrupt = false;
            throw new LogicException('stop before restoring the feed');
        }
    });
    $assertKey = function () use ($db): void {
        expect(fn (): bool => $db->table($this->deliveries)->insert(['id' => 2, 'endpoint_id' => 999, 'created_at' => null]))
            ->toThrow(fn (QueryException $error): Expectation => expect($error->errorInfo[1])->toBe(1452));
    };
    foreach ([$this->migration->up(...), $this->migration->down(...)] as $migrate) {
        $interrupt = true;
        expect($migrate)->toThrow(LogicException::class, 'stop before restoring the feed');
        $assertKey();
        $migrate();
        $migrate();
        $assertKey();
        expect($schema->hasIndex($this->deliveries, $feed))->toBeTrue()
            ->and($db->table($this->deliveries)->where('endpoint_id', 1)->count())->toBe(1);
    }
    expect($schema->hasIndex($this->deliveries, $this->deliveries.'_endpoint_id_index'))->toBeTrue()
        ->and($schema->hasIndex($this->deliveries, $this->deliveries.'_endpoint_id_fk_idx'))->toBeFalse();
});

it('retains legacy orphans for repair and restores the missing constraint on retry', function (): void {
    $db = DB::connection('webhook_upgrade');
    $schema = $db->getSchemaBuilder();
    $table = $this->deliveries;
    $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropForeign(['endpoint_id']));
    $db->table($table)->insert(['id' => 3, 'endpoint_id' => 7]);

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'Delivery 3 references a missing endpoint')
        ->and($db->table($table)->where('id', 3)->value('endpoint_id'))->toEqual(7);

    $db->table($this->endpoints)->insert(['id' => 7]);
    $this->migration->up();
    expect(fn (): bool => $db->table($table)->insert(['id' => 4, 'endpoint_id' => 8]))
        ->toThrow(fn (QueryException $error): Expectation => expect($error->errorInfo[1])->toBe(1452));
    $db->table($this->endpoints)->where('id', 7)->delete();
    expect($db->table($table)->count())->toBe(0);
});
