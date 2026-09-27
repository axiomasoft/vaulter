<?php

declare(strict_types=1);

namespace Vaulter\Load\Observability;

final class MetricFamilyCatalog
{
    /** @return array<string, array{type: string, label_keys: list<string>, registration: array<string, string>, bucket_set: ?string, gauge_mode: string}> */
    public static function families(): array
    {
        return [
            'vaulter_http_request_duration_seconds' => ['type' => 'histogram', 'label_keys' => ['operation', 'tier', 'outcome'], 'registration' => ['operation' => 'point_read', 'outcome' => 'success', 'tier' => 'S0'], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_tree_query_duration_seconds' => ['type' => 'histogram', 'label_keys' => ['query_kind', 'tier'], 'registration' => ['query_kind' => 'children', 'tier' => 'S0'], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_tree_cte_anchors' => ['type' => 'histogram', 'label_keys' => ['tier'], 'registration' => ['tier' => 'S0'], 'bucket_set' => 'rows', 'gauge_mode' => 'set'],
            'vaulter_subtree_nodes_touched' => ['type' => 'histogram', 'label_keys' => ['operation'], 'registration' => ['operation' => 'point_read'], 'bucket_set' => 'rows', 'gauge_mode' => 'set'],
            'vaulter_activity_feed_duration_seconds' => ['type' => 'histogram', 'label_keys' => ['filtered', 'tier'], 'registration' => ['filtered' => 'false', 'tier' => 'S0'], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_activity_write_total' => ['type' => 'counter', 'label_keys' => ['verb'], 'registration' => ['verb' => 'node.created'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_export_duration_seconds' => ['type' => 'histogram', 'label_keys' => ['format', 'tier'], 'registration' => ['format' => 'html', 'tier' => 'S0'], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_collab_session_issued_total' => ['type' => 'counter', 'label_keys' => ['driver', 'ability'], 'registration' => ['ability' => 'view', 'driver' => 'hocuspocus'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_collab_snapshot_duration_seconds' => ['type' => 'histogram', 'label_keys' => ['tier'], 'registration' => ['tier' => 'S0'], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_collab_room_active' => ['type' => 'gauge', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_broadcast_queue_wait_seconds' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_upload_session_active' => ['type' => 'gauge', 'label_keys' => ['protocol'], 'registration' => ['protocol' => 'tus'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_request_peak_memory_bytes' => ['type' => 'gauge', 'label_keys' => ['operation', 'tier'], 'registration' => ['operation' => 'point_read', 'tier' => 'S0'], 'bucket_set' => null, 'gauge_mode' => 'max'],
            'vaulter_upload_bytes_total' => ['type' => 'counter', 'label_keys' => ['protocol'], 'registration' => ['protocol' => 'tus'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_upload_chunk_duration_seconds' => ['type' => 'histogram', 'label_keys' => ['protocol', 'tier'], 'registration' => ['protocol' => 'tus', 'tier' => 'S0'], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_upload_conflict_total' => ['type' => 'counter', 'label_keys' => ['protocol'], 'registration' => ['protocol' => 'tus'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_assemble_bytes_total' => ['type' => 'counter', 'label_keys' => ['size_bucket'], 'registration' => ['size_bucket' => 'le_8mib'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_assemble_duration_seconds' => ['type' => 'histogram', 'label_keys' => ['size_bucket'], 'registration' => ['size_bucket' => 'le_8mib'], 'bucket_set' => 'batch_s', 'gauge_mode' => 'set'],
            'vaulter_download_bytes_total' => ['type' => 'counter', 'label_keys' => ['source'], 'registration' => ['source' => 'file'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_download_stream_active' => ['type' => 'gauge', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_download_ttfb_seconds' => ['type' => 'histogram', 'label_keys' => ['tier'], 'registration' => ['tier' => 'S0'], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_throttled_total' => ['type' => 'counter', 'label_keys' => ['route'], 'registration' => ['route' => 'share.show'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_outbox_backlog' => ['type' => 'gauge', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_outbox_stage_duration_seconds' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'stage_s', 'gauge_mode' => 'set'],
            'vaulter_outbox_relay_duration_seconds' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'batch_s', 'gauge_mode' => 'set'],
            'vaulter_outbox_relayed_total' => ['type' => 'counter', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_outbox_endpoints_scanned_total' => ['type' => 'counter', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_webhook_queue_wait_seconds' => ['type' => 'histogram', 'label_keys' => ['outcome'], 'registration' => ['outcome' => 'success'], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_webhook_delivery_duration_seconds' => ['type' => 'histogram', 'label_keys' => ['outcome'], 'registration' => ['outcome' => 'success'], 'bucket_set' => 'http_s', 'gauge_mode' => 'set'],
            'vaulter_webhook_delivered_total' => ['type' => 'counter', 'label_keys' => ['outcome'], 'registration' => ['outcome' => 'success'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_failed_jobs_total' => ['type' => 'counter', 'label_keys' => ['outcome'], 'registration' => ['outcome' => 'success'], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_webhook_retry_tick_seconds' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'batch_s', 'gauge_mode' => 'set'],
            'vaulter_webhook_retry_lockset_rows' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'rows', 'gauge_mode' => 'set'],
            'vaulter_sweep_tick_seconds' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'batch_s', 'gauge_mode' => 'set'],
            'vaulter_sweep_batch_rows' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'rows', 'gauge_mode' => 'set'],
            'vaulter_sweep_expired_total' => ['type' => 'counter', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_sweep_skipped_total' => ['type' => 'counter', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_gc_duration_seconds' => ['type' => 'histogram', 'label_keys' => ['phase'], 'registration' => ['phase' => 'mark'], 'bucket_set' => 'batch_s', 'gauge_mode' => 'set'],
            'vaulter_gc_batch_rows' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'rows', 'gauge_mode' => 'set'],
            'vaulter_gc_rows_scanned_total' => ['type' => 'counter', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_gc_objects_deleted_total' => ['type' => 'counter', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_purge_duration_seconds' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'batch_s', 'gauge_mode' => 'set'],
            'vaulter_purge_batch_nodes' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'rows', 'gauge_mode' => 'set'],
            'vaulter_purge_nodes_total' => ['type' => 'counter', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_prune_duration_seconds' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'batch_s', 'gauge_mode' => 'set'],
            'vaulter_prune_rows_total' => ['type' => 'counter', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_reconcile_duration_seconds' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'batch_s', 'gauge_mode' => 'set'],
            'vaulter_reconcile_objects_total' => ['type' => 'counter', 'label_keys' => [], 'registration' => [], 'bucket_set' => null, 'gauge_mode' => 'set'],
            'vaulter_command_peak_memory_bytes' => ['type' => 'gauge', 'label_keys' => ['command'], 'registration' => ['command' => 'vaulter:sweep-uploads'], 'bucket_set' => null, 'gauge_mode' => 'max'],
            'vaulter_pg_lock_wait_seconds' => ['type' => 'histogram', 'label_keys' => [], 'registration' => [], 'bucket_set' => 'lock_s', 'gauge_mode' => 'set'],
        ];
    }

    /** @return list<string> */
    public static function bucketBounds(string $set): array
    {
        return match ($set) {
            'http_s' => ['0.005', '0.01', '0.025', '0.05', '0.1', '0.12', '0.15', '0.18', '0.225', '0.25', '0.3', '0.375', '0.45', '0.5', '0.525', '0.6', '0.75', '0.9', '1', '1.2', '1.5', '1.8', '2', '2.25', '2.5', '3', '3.75', '4.5', '5', '6', '9', '10', '30', '45', '90', '+Inf'],
            'stage_s' => ['0.005', '0.008', '0.01', '0.012', '0.025', '0.05', '0.1', '0.25', '0.5', '1', '+Inf'],
            'batch_s' => ['0.05', '0.1', '0.25', '0.5', '1', '1.5', '2', '2.5', '5', '10', '15', '30', '60', '90', '120', '300', '600', '1800', '3600', '7200', '14400', '21600', '+Inf'],
            'lock_s' => ['0.001', '0.005', '0.01', '0.05', '0.1', '0.25', '0.5', '1', '2', '5', '10', '+Inf'],
            'rows' => ['1', '10', '50', '100', '500', '1000', '5000', '10000', '50000', '100000', '+Inf'],
            default => throw new \InvalidArgumentException('Unknown bucket set'),
        };
    }
}
