<?php

declare(strict_types=1);

// S0 volume profile (CI-профиль, НЕ тир развёртывания).
//
// Source: reference-env.md §4.1 (numbers already fixed in load/env/S0.env —
// SEED_NODES/SEED_ACTIVITY/SEED_EXPIRED_SESSIONS/SEED_ORPHAN_BLOBS/MAX_FIXTURE_BYTES
// are REUSED here verbatim, not re-derived) + reference-env.md §2.1 order-of-magnitude
// for everything §4.1 doesn't name explicitly. §4.1 gives no independent formula for
// those extra knobs, so each is either a straight ~1/50 of the S-tier row or,
// where 1/50 would round to something too small to exercise the mechanism it targets
// (grant-anchor fan-out, endpoint-subscription filtering), a documented minimum-useful
// floor instead — never a shrink of the four numbers §4.1 already committed to.
return [
    'tier' => 'S0',

    'nodes' => [
        'total' => 20_000,          // load/env/S0.env SEED_NODES
        'typical_depth' => 4,        // S=8, halved — CI doesn't need the full depth,
        // the fixed backbone below already exercises the
        // DEFAULT_MAX_DEPTH boundary on its own.
        'typical_width' => 8,        // S=20, reduced for the smaller node budget.
        'backbone_depth' => 64,      // FIXED across every tier incl. CI — this is
        // NodeReadRepository::DEFAULT_MAX_DEPTH, a
        // structural boundary, not a volume knob.
        'wide_node_children' => 300, // S=5000 (~1/17) — enough to exercise the
        // partial-UNIQUE(parent_id, lower(name)) scan and
        // children pagination without blowing the CI budget.
    ],

    'blobs' => [
        // "Orphan" == BOTH GC-candidate scopes L-03 targets (BlobGarbageCollector.php
        // :81/:115): half seeded as mark-phase candidates (committed, ref_count=0, no
        // edge — Blob::scopeGarbageCollectable()), half as sweep-phase candidates
        // (tombstoned, delete_after_at in the past — Blob::scopeSweepable()). Their SUM
        // is the "orphan-строк профиля" the Validation count checks against.
        'orphan' => 2_000, // load/env/S0.env SEED_ORPHAN_BLOBS
        // Byte-backed ("hot") blobs — real bytes land in MinIO via BlobStore::commit(),
        // the rest of the live blob population is metadata-only.
        'hot_set' => 400,
        'hot_set_avg_bytes' => 65_536, // 64 KiB avg => ~25 MiB hot-set total on disk,
        // nowhere near MAX_FIXTURE_BYTES (this is the
        // per-object hot set, not the single big
        // multipart fixture).
    ],

    'file_versions' => [
        // ~40% of files get a second version (own fresh blob, no forced same-content
        // dedup) — reference-env.md §2.1 "≈1.5-2 версии на файл", scaled down.
        'extra_version_share' => 0.4,
    ],

    'upload_sessions' => [
        'expired' => 5_000, // load/env/S0.env SEED_EXPIRED_SESSIONS — sweepable
        // (UploadSession::scopeSweepable(): status in
        // initiated|uploading|assembled AND expires_at <= now).
        'healthy' => 500,    // NOT swept — expires_at in the future.
        'multipart_share' => 0.5,       // fraction of sessions that carry v_upload_parts
        // rows (protocol=s3-multipart); the rest are
        // tus (progress tracked via received_bytes, no
        // parts table — Required Reads #5).
        'avg_parts_per_multipart_session' => 4, // reference-env.md §2.1 "≈4 парта на сессию".
    ],

    'outbox' => [
        'backlog' => 200, // pending, available_at in the past (OutboxRelay::process
        // limit=100 — a 200-row backlog needs >1 tick to drain).
    ],

    'webhook_endpoints' => [
        // S=20 (~1/50=0.4) — floored to the minimum that still gives
        // OutboxRelay::subscribedEndpoints() (L-05, O(endpoints)-in-PHP filter) more
        // than one candidate to filter per outbox row.
        'active' => 5,
    ],

    'webhook_deliveries' => [
        'due' => 100, // status in pending|failed, next_retry_at <= now
        // (WebhookDelivery::scopeDueForRetry() — L-04 unbounded lock-set).
    ],

    'node_grants' => [
        // S=20 (~1/50=0.4) — floored: NodeReadRepository::candidateNodeIds() (L-06)
        // does one recursive-CTE descent PER subtree anchor, so the many-grant actor
        // needs more than one anchor to exercise the fan-out at all.
        'many_grant_anchors' => 5,
    ],

    'node_shares' => [
        'active' => 20, // S=1000 (~1/50)
        'revoked_share' => 0.1, // fraction revoked — exercises the partial
        // (expires_at) WHERE revoked_at IS NULL index by
        // having rows on BOTH sides of the predicate.
    ],

    'node_comments' => [
        'total' => 400, // S=20000 (~1/50)
    ],

    'node_document_versions' => [
        'nodes' => 20,          // document-kind nodes carrying a version history
        'versions_per_node' => 2, // S=10000 total / (S nodes≈500-ish) ≈ ~2; kept flat
        // across tiers as the structural shape, node COUNT
        // carries the volume scaling instead.
    ],

    'activity' => [
        'total' => 200_000, // load/env/S0.env SEED_ACTIVITY
        'sustained_write_rps' => 5, // S=20/с (~1/4 — a CI lane runs minutes, not the
        // full duration a lab-box tier does; this is a
        // floor that still exercises the GIN-under-write
        // path in load:activity-write, not a literal 1/50).
    ],

    'max_fixture_bytes' => 268_435_456, // load/env/S0.env MAX_FIXTURE_BYTES (256 MiB)
];
