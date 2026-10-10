<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Support\Identity;

/**
 * The drive change journal (PLAN2.D50, D59): v_drive_change_heads holds each drive's
 * counter, v_drive_changes the entries. Every existing drive gets its head row with
 * last_seq 0, so a client's first cursor starts at the journal's beginning; the
 * journal itself starts empty (the changes made before it existed are only in a full
 * listing). Re-running up() adds only the missing pieces and heads.
 */
return new class extends Migration
{
    private const int CHUNK = 500;

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $drives = app(DatabaseConfig::class)->table('drives');
        $heads = app(DatabaseConfig::class)->table('drive_change_heads');

        if (!$db->getSchemaBuilder()->hasTable($drives)) {
            return;
        }

        app(SchemaBuilder::class)->addDriveChanges();

        $after = null;
        do {
            $batch = $db->table($drives)
                ->when($after !== null, static fn ($query) => $query->where('id', '>', $after))
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'tenant_id']);
            if ($batch->isEmpty()) {
                break;
            }
            $existing = $db->table($heads)->whereIn('drive_id', $batch->pluck('id')->all())->pluck('drive_id')
                ->map(static fn (mixed $id): string => Identity::stringify($id))->all();
            $missing = $batch->reject(static fn (object $drive): bool => in_array(Identity::stringify($drive->id), $existing, true));
            if ($missing->isNotEmpty()) {
                $db->table($heads)->insert($missing->map(static fn (object $drive): array => [
                    'drive_id' => $drive->id,
                    'tenant_id' => $drive->tenant_id,
                    'last_seq' => 0,
                    'pruned_through' => 0,
                    'epoch' => 1,
                    'updated_at' => now(),
                ])->values()->all());
            }
            $after = $batch->last()->id;
        } while ($batch->count() === self::CHUNK);
    }

    public function down(): void
    {
        app(SchemaBuilder::class)->removeDriveChanges();
    }
};
