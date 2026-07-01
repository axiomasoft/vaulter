<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Vaulter\Support\Config;

/**
 * ADR-0014 шаг 2 — drop the legacy document_id-bound DocVault tables.
 *
 * The six create migrations (000001..000006) were removed in the same excision, so a
 * FRESH install never builds these tables. This migration exists only for dev/staging
 * databases that already ran the old creates: it drops the tables idempotently
 * (dropIfExists — a no-op when they were never created) in child→parent FK order so
 * the drop is safe on every driver (sqlite/pgsql/mysql) without toggling FK checks.
 *
 * Irreversible by design: there is no legacy schema to restore (the data is gone and
 * the canonical path is the v_nodes tree). Rollback of step 2 is a VCS revert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Children first (they carry the FK to v_documents / v_document_folders), then
        // the parents. Config::table() applies the configured prefix and falls back to
        // the historical base name now that the table_names entries are gone.
        foreach ([
            'document_shares',
            'document_comments',
            'document_permissions',
            'document_versions',
            'documents',
            'document_folders',
        ] as $entity) {
            Schema::dropIfExists(Config::table($entity));
        }
    }

    public function down(): void
    {
        // Irreversible: the legacy DocVault schema was excised (ADR-0014 шаг 2).
    }
};
