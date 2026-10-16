<?php

declare(strict_types=1);

namespace Vaulter\Media\Schema;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\ModuleSchema;
use Vaulter\Schema\SchemaHelpers;

/** The tables the media module owns: the content-addressed blobs and the pins that keep them alive (D23). */
#[Internal]
final class MediaSchema implements ModuleSchema
{
    public function __construct(private readonly SchemaHelpers $helpers) {}

    /** @return list<string> the entity keys of the tables this schema owns, in dependency order */
    public function entities(): array
    {
        return [
            'blobs',
            'blob_references',
        ];
    }

    public function create(string $entity): void
    {
        $ids = $this->helpers->ids();

        $this->helpers->schemaConnection()->create($this->helpers->table($entity), function (Blueprint $table) use ($ids, $entity): void {
            match ($entity) {
                'blobs' => $this->defineBlobs(table: $table, ids: $ids),
                'blob_references' => $this->defineBlobReferences(table: $table, ids: $ids),
                default => throw new InvalidArgumentException(sprintf('Unknown media entity [%s].', $entity)),
            };
        });
    }

    public function drop(string $entity): void
    {
        $this->helpers->schemaConnection()->dropIfExists($this->helpers->table($entity));
    }

    /**
     * v_blobs — the content-addressable, immutable payload (dedup + GC).
     * Gated on 'blobs'. storage_key is scope-qualified and the dedup-unique
     * index spans the scope column so two scopes never share one physical row.
     */
    private function defineBlobs(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $this->helpers->tenant(table: $table);
        // The opaque storage the blob deduplicates within under dedup_scope 'drive' (D23): no foreign key, media
        // knows no drive table.
        $ids->reference(table: $table, column: 'storage_id', nullable: true);

        $table->string('hash_algo', 16)->default('sha256');
        $table->char('sha256', 64);
        $table->bigInteger('size_bytes')->index();
        $table->string('disk', 64)->default('default');

        // Scope-qualified storage_key: "<scope>/ab/cd/<sha256>". A
        // scope-free path under per-scope dedup would let one GC delete another
        // scope's live bytes. BlobScope bounds canonical keys below 255 bytes;
        // VARCHAR keeps the full-value UNIQUE portable to MySQL without a lossy
        // prefix index or a hash surrogate.
        $table->string('storage_key');

        // Blob state machine (logic is; the columns exist now): pending →
        // committed → tombstoned → deleting → deleted. No standalone index on
        // status: it is a strict column-prefix of the
        // (status, ref_count, delete_after_at) GC index below, so it never wins
        // over it and only pays extra write cost.
        $table->string('status', 12)->default('pending');
        $table->bigInteger('ref_count')->default(0);
        $table->string('dedup_scope', 8)->default('tenant');
        $table->timestamp('quarantined_at')->nullable();
        $table->string('storage_version', 255)->nullable();
        $table->string('etag', 64)->nullable();
        $table->string('md5', 64)->nullable();
        $table->string('crc32c', 64)->nullable();
        $table->boolean('chunked')->default(false);
        $ids->reference(table: $table, column: 'encryption_key_id', nullable: true);
        $table->timestamp('first_seen_at')->useCurrent();
        $table->timestamp('committed_at')->nullable();
        $table->timestamp('delete_after_at')->nullable();

        $table->string('scope_key', 191);
        $name = $table->getTable();
        $table->unique(['scope_key', 'sha256'], $name.'_scope_sha_uniq');
        $table->unique('storage_key', $name.'_storage_key_uniq');

        // GC scan support: committed orphans past their grace deadline.
        $table->index(['status', 'ref_count', 'delete_after_at'], $name.'_gc_idx');
    }

    /**
     * v_blob_references — the pins (D23): the liveness truth of a blob. Every row is one opaque owner reference
     * (owner_type, e.g. `drive.revision_blob`, and a reference_key derived from the owner's row), written only
     * through BlobPins. The foreign key points from the pin to the blob and restricts its deletion; media never
     * references a table of an owner.
     */
    private function defineBlobReferences(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $ids->reference(table: $table, column: 'blob_id');
        $table->string('owner_type', 64);
        $table->string('reference_key', 191);
        $table->timestamp('created_at')->useCurrent();

        $name = $table->getTable();
        $table->unique(['blob_id', 'owner_type', 'reference_key'], $name.'_pin_uniq');
        $table->index(['owner_type', 'reference_key'], $name.'_owner_idx');

        $table->foreign('blob_id', $name.'_blob_fk')
            ->references('id')
            ->on($this->helpers->table('blobs'))
            ->restrictOnDelete();
    }
}
