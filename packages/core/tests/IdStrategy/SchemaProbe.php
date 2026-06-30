<?php

declare(strict_types=1);

namespace Vaulter\Tests\IdStrategy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Support\VaulterSchema;

/**
 * Shared probe for the VaulterSchema convention helpers. Builds one table using
 * every helper and reports the resulting column shape, so each strategy test
 * asserts the same surface under its own id strategy.
 */
final class SchemaProbe
{
    public const TABLE = 'v_schema_probe';

    /**
     * Create the probe table through the convention helpers under the active
     * id strategy (tenancy is forced on so tenant() emits its column).
     */
    public static function build(): void
    {
        config()->set('vaulter.tenant.enabled', true);

        Schema::create(self::TABLE, function (Blueprint $table): void {
            VaulterSchema::key($table);
            VaulterSchema::panel($table);
            VaulterSchema::tenant($table);
            VaulterSchema::reference($table, 'storage_id');
            VaulterSchema::reference($table, 'parent_id', nullable: true);
            VaulterSchema::morphs($table, 'owner');
            VaulterSchema::actor($table, 'created_by');
            VaulterSchema::bits($table, 'permissions');
            VaulterSchema::extraData($table);
            $table->string('name');
        });
    }

    public static function drop(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * Introspect the exact emitted column type for a single helper call,
     * independent of the database grammar (reads the Blueprint definition).
     */
    public static function emittedType(string $column, \Closure $define): ?string
    {
        $table = new Blueprint(DB::connection(), self::TABLE);
        $define($table);

        foreach ($table->getColumns() as $definition) {
            if ($definition->get('name') === $column) {
                return $definition->get('type');
            }
        }

        return null;
    }
}
