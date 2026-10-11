<?php

declare(strict_types=1);

namespace Vaulter\Load\Observability;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Run-scoped Redis registry and Prometheus 0.0.4 exposition for the load SUT.
 */
final class RedisMetricsRegistry
{
    public const int DEFAULT_TTL_SECONDS = 7_200;

    private const string ACTIVE_RUN_KEY = 'vaulter:metrics:active-run';

    private const array HTTP_BUCKETS = [
        '0.005', '0.01', '0.025', '0.05', '0.1', '0.12', '0.15', '0.18', '0.225', '0.25',
        '0.3', '0.375', '0.45', '0.5', '0.525', '0.6', '0.75', '0.9', '1', '1.2', '1.5',
        '1.8', '2', '2.25', '2.5', '3', '3.75', '4.5', '5', '6', '9', '10', '30', '45', '90',
        '+Inf',
    ];

    private const array STAGE_BUCKETS = [
        '0.005', '0.008', '0.01', '0.012', '0.025', '0.05', '0.1', '0.25', '0.5', '1', '+Inf',
    ];

    private const array BATCH_BUCKETS = [
        '0.05', '0.1', '0.25', '0.5', '1', '1.5', '2', '2.5', '5', '10', '15', '30',
        '60', '90', '120', '300', '600', '1800', '3600', '7200', '14400', '21600', '+Inf',
    ];

    private const array LOCK_BUCKETS = [
        '0.001', '0.005', '0.01', '0.05', '0.1', '0.25', '0.5', '1', '2', '5', '10', '+Inf',
    ];

    private const array ROW_BUCKETS = [
        '1', '10', '50', '100', '500', '1000', '5000', '10000', '50000', '100000', '+Inf',
    ];

    private const array TIERS = ['S0', 'S', 'M', 'L'];

    private const array HTTP_OUTCOMES = ['success', 'client_error', 'server_error', 'exception'];

    private const array JOB_OUTCOMES = ['success', 'failure', 'retry', 'timeout'];

    private const array OPERATIONS = [
        'point_read', 'tree_list', 'search', 'tree_mutate', 'subtree_mutate', 'activity_feed',
        'doc_read', 'doc_write', 'doc_export', 'collab_issue', 'collab_persist', 'comment',
        'upload_session', 'tus_patch', 's3_putpart', 's3_complete', 'single_shot', 'download',
    ];

    private const array VERBS = [
        'storage.created', 'node.created', 'file.uploaded', 'version.created',
        'upload_session.initiated', 'upload_session.aborted', 'upload_session.expired',
        'node.moved', 'node.renamed', 'node.copied', 'node.trashed', 'node.restored',
        'node.force_deleted', 'grant.created', 'grant.revoked', 'share.created', 'share.revoked',
    ];

    private const array ROUTES = [
        'share.show', 'share.nodes', 'share.download', 'collab.authenticate', 'collab.store',
        'uploads.tus_create', 'uploads.tus_patch', 'uploads.tus_status', 'uploads.s3_parts',
        'uploads.s3_complete', 'uploads.single',
    ];

    private const array COMMANDS = [
        'vaulter:uploads:sweep', 'vaulter:blobs:gc', 'vaulter:blobs:reconcile',
        'vaulter:trash:purge', 'vaulter:webhooks:prune', 'vaulter:webhooks:retry',
        'vaulter:webhooks:relay',
    ];

    /**
     * @var array<string, array{
     *     type: 'counter'|'gauge'|'histogram',
     *     help: string,
     *     labels: array<string, list<string>>,
     *     buckets?: list<string>,
     *     aggregation?: 'max'|'set'
     * }>
     */
    private const array FAMILIES = [
        'vaulter_http_request_duration_seconds' => ['type' => 'histogram', 'help' => 'Vaulter HTTP request duration.', 'labels' => ['operation' => self::OPERATIONS, 'tier' => self::TIERS, 'outcome' => self::HTTP_OUTCOMES], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_tree_query_duration_seconds' => ['type' => 'histogram', 'help' => 'Vaulter tree query duration.', 'labels' => ['query_kind' => ['children', 'subtree', 'breadcrumb', 'search'], 'tier' => self::TIERS], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_tree_cte_anchors' => ['type' => 'histogram', 'help' => 'Recursive CTE anchors per tree query.', 'labels' => ['tier' => self::TIERS], 'buckets' => self::ROW_BUCKETS],
        'vaulter_subtree_nodes_touched' => ['type' => 'histogram', 'help' => 'Subtree nodes touched by a mutation.', 'labels' => ['operation' => self::OPERATIONS], 'buckets' => self::ROW_BUCKETS],
        'vaulter_activity_feed_duration_seconds' => ['type' => 'histogram', 'help' => 'Activity feed query duration.', 'labels' => ['filtered' => ['true', 'false'], 'tier' => self::TIERS], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_activity_write_total' => ['type' => 'counter', 'help' => 'Activity records written.', 'labels' => ['verb' => self::VERBS]],
        'vaulter_export_duration_seconds' => ['type' => 'histogram', 'help' => 'Document export duration.', 'labels' => ['format' => ['html', 'markdown'], 'tier' => self::TIERS], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_collab_session_issued_total' => ['type' => 'counter', 'help' => 'Collaboration sessions issued.', 'labels' => ['driver' => ['reverb', 'hocuspocus'], 'ability' => ['view', 'download', 'comment', 'edit']]],
        'vaulter_collab_snapshot_duration_seconds' => ['type' => 'histogram', 'help' => 'Collaboration snapshot persistence duration.', 'labels' => ['tier' => self::TIERS], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_collab_room_active' => ['type' => 'gauge', 'help' => 'Active collaboration rooms.', 'labels' => [], 'aggregation' => 'set'],
        'vaulter_broadcast_queue_wait_seconds' => ['type' => 'histogram', 'help' => 'Broadcast queue wait duration.', 'labels' => [], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_upload_session_active' => ['type' => 'gauge', 'help' => 'Active upload sessions.', 'labels' => ['protocol' => ['tus', 's3', 'single']], 'aggregation' => 'set'],
        'vaulter_request_peak_memory_bytes' => ['type' => 'gauge', 'help' => 'Peak request memory usage.', 'labels' => ['operation' => self::OPERATIONS, 'tier' => self::TIERS], 'aggregation' => 'max'],
        'vaulter_upload_bytes_total' => ['type' => 'counter', 'help' => 'Accepted upload bytes.', 'labels' => ['protocol' => ['tus', 's3', 'single']]],
        'vaulter_upload_chunk_duration_seconds' => ['type' => 'histogram', 'help' => 'Upload chunk duration.', 'labels' => ['protocol' => ['tus', 's3', 'single'], 'tier' => self::TIERS], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_upload_conflict_total' => ['type' => 'counter', 'help' => 'Upload conflicts.', 'labels' => ['protocol' => ['tus', 's3', 'single']]],
        'vaulter_assemble_bytes_total' => ['type' => 'counter', 'help' => 'Multipart bytes assembled.', 'labels' => ['size_bucket' => ['le_8mib', 'le_64mib', 'le_512mib', 'le_5gib']]],
        'vaulter_assemble_duration_seconds' => ['type' => 'histogram', 'help' => 'Multipart assembly duration.', 'labels' => ['size_bucket' => ['le_8mib', 'le_64mib', 'le_512mib', 'le_5gib']], 'buckets' => self::BATCH_BUCKETS],
        'vaulter_download_bytes_total' => ['type' => 'counter', 'help' => 'Bytes read for downloads.', 'labels' => ['source' => ['file', 'version', 'share']]],
        'vaulter_download_stream_active' => ['type' => 'gauge', 'help' => 'Active download streams.', 'labels' => [], 'aggregation' => 'set'],
        'vaulter_download_ttfb_seconds' => ['type' => 'histogram', 'help' => 'Download server first-write duration.', 'labels' => ['tier' => self::TIERS], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_throttled_total' => ['type' => 'counter', 'help' => 'Throttled Vaulter requests.', 'labels' => ['route' => self::ROUTES]],
        'vaulter_outbox_backlog' => ['type' => 'gauge', 'help' => 'Pending outbox rows.', 'labels' => [], 'aggregation' => 'set'],
        'vaulter_outbox_stage_duration_seconds' => ['type' => 'histogram', 'help' => 'Outbox staging duration.', 'labels' => [], 'buckets' => self::STAGE_BUCKETS],
        'vaulter_outbox_relay_duration_seconds' => ['type' => 'histogram', 'help' => 'Outbox relay invocation duration.', 'labels' => [], 'buckets' => self::BATCH_BUCKETS],
        'vaulter_outbox_relayed_total' => ['type' => 'counter', 'help' => 'Outbox rows relayed.', 'labels' => []],
        'vaulter_outbox_endpoints_scanned_total' => ['type' => 'counter', 'help' => 'Webhook endpoints scanned by relay.', 'labels' => []],
        'vaulter_webhook_queue_wait_seconds' => ['type' => 'histogram', 'help' => 'Webhook delivery queue wait duration.', 'labels' => ['outcome' => self::JOB_OUTCOMES], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_webhook_delivery_duration_seconds' => ['type' => 'histogram', 'help' => 'Webhook delivery attempt duration.', 'labels' => ['outcome' => self::JOB_OUTCOMES], 'buckets' => self::HTTP_BUCKETS],
        'vaulter_webhook_delivered_total' => ['type' => 'counter', 'help' => 'Webhook delivery outcomes.', 'labels' => ['outcome' => self::JOB_OUTCOMES]],
        'vaulter_failed_jobs_total' => ['type' => 'counter', 'help' => 'Failed queue jobs.', 'labels' => ['outcome' => self::JOB_OUTCOMES]],
        'vaulter_webhook_retry_tick_seconds' => ['type' => 'histogram', 'help' => 'Webhook retry dispatcher duration.', 'labels' => [], 'buckets' => self::BATCH_BUCKETS],
        'vaulter_webhook_retry_lockset_rows' => ['type' => 'histogram', 'help' => 'Rows locked by a webhook retry tick.', 'labels' => [], 'buckets' => self::ROW_BUCKETS],
        'vaulter_sweep_tick_seconds' => ['type' => 'histogram', 'help' => 'Upload sweep tick duration.', 'labels' => [], 'buckets' => self::BATCH_BUCKETS],
        'vaulter_sweep_batch_rows' => ['type' => 'histogram', 'help' => 'Upload rows processed by a sweep tick.', 'labels' => [], 'buckets' => self::ROW_BUCKETS],
        'vaulter_sweep_expired_total' => ['type' => 'counter', 'help' => 'Upload sessions expired by sweeper.', 'labels' => []],
        'vaulter_sweep_skipped_total' => ['type' => 'counter', 'help' => 'Upload sessions skipped by sweeper.', 'labels' => []],
        'vaulter_gc_duration_seconds' => ['type' => 'histogram', 'help' => 'Blob garbage collection phase duration.', 'labels' => ['phase' => ['mark', 'sweep']], 'buckets' => self::BATCH_BUCKETS],
        'vaulter_gc_batch_rows' => ['type' => 'histogram', 'help' => 'Blob rows processed by a GC batch.', 'labels' => [], 'buckets' => self::ROW_BUCKETS],
        'vaulter_gc_rows_scanned_total' => ['type' => 'counter', 'help' => 'Blob rows scanned by GC.', 'labels' => []],
        'vaulter_gc_objects_deleted_total' => ['type' => 'counter', 'help' => 'Blob objects deleted by GC.', 'labels' => []],
        'vaulter_purge_duration_seconds' => ['type' => 'histogram', 'help' => 'Trash purge duration.', 'labels' => [], 'buckets' => self::BATCH_BUCKETS],
        'vaulter_purge_batch_nodes' => ['type' => 'histogram', 'help' => 'Nodes processed by a purge batch.', 'labels' => [], 'buckets' => self::ROW_BUCKETS],
        'vaulter_purge_nodes_total' => ['type' => 'counter', 'help' => 'Nodes purged.', 'labels' => []],
        'vaulter_prune_duration_seconds' => ['type' => 'histogram', 'help' => 'Webhook prune duration.', 'labels' => [], 'buckets' => self::BATCH_BUCKETS],
        'vaulter_prune_rows_total' => ['type' => 'counter', 'help' => 'Webhook rows pruned.', 'labels' => []],
        'vaulter_reconcile_duration_seconds' => ['type' => 'histogram', 'help' => 'Blob reconciliation duration.', 'labels' => [], 'buckets' => self::BATCH_BUCKETS],
        'vaulter_reconcile_objects_total' => ['type' => 'counter', 'help' => 'Blob objects reconciled.', 'labels' => []],
        'vaulter_command_peak_memory_bytes' => ['type' => 'gauge', 'help' => 'Peak command memory usage.', 'labels' => ['command' => self::COMMANDS], 'aggregation' => 'max'],
        'vaulter_pg_lock_wait_seconds' => ['type' => 'histogram', 'help' => 'PostgreSQL lock wait duration.', 'labels' => [], 'buckets' => self::LOCK_BUCKETS],
    ];

    private const string GAUGE_MAX_LUA = <<<'LUA'
local cur = tonumber(redis.call("GET", KEYS[1]) or "0")
local new = tonumber(ARGV[1])
if not new or new ~= new or new == math.huge or new == -math.huge then
  return redis.error_reply("ERR gauge must be finite")
end
if new > cur then redis.call("SET", KEYS[1], new) end
return redis.call("GET", KEYS[1])
LUA;

    private readonly string $histogramLua;

    public function __construct(
        private readonly Factory $redis,
    ) {
        $script = file_get_contents(__DIR__.'/lua/histogram_observe.lua');

        if ($script === false || $script === '') {
            throw new RuntimeException('Unable to load the histogram observation script.');
        }

        $this->histogramLua = $script;
    }

    /** @return array<string, array{type: 'counter'|'gauge'|'histogram', help: string, labels: array<string, list<string>>, buckets?: list<string>, aggregation?: 'max'|'set'}> */
    public function definitions(): array
    {
        return self::FAMILIES;
    }

    /** @return list<string> */
    public function familyNames(): array
    {
        return array_keys(self::FAMILIES);
    }

    /** @return array{type: 'counter'|'gauge'|'histogram', help: string, labels: array<string, list<string>>, buckets?: list<string>, aggregation?: 'max'|'set'}|null */
    public function definition(string $name): ?array
    {
        return self::FAMILIES[$name] ?? null;
    }

    public function openRun(string $runId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): void
    {
        $this->assertRunId($runId);
        $ttlSeconds = max(60, $ttlSeconds);
        $connection = $this->connection();
        $manifestKey = $this->manifestKey($runId);

        if ((int) $connection->exists($manifestKey) > 0) {
            throw new InvalidArgumentException("Metrics run {$runId} already exists.");
        }

        $createdAt = now()->toIso8601String();

        foreach (self::FAMILIES as $name => $definition) {
            $familyKey = $this->familyKey($runId, $name);
            $connection->sadd($manifestKey, $name);
            $connection->hmset($familyKey, [
                'help' => $definition['help'],
                'type' => $definition['type'],
                'labels' => json_encode(array_keys($definition['labels']), JSON_THROW_ON_ERROR),
                'buckets' => json_encode($definition['buckets'] ?? [], JSON_THROW_ON_ERROR),
                'aggregation' => $definition['aggregation'] ?? 'set',
                'created_at' => $createdAt,
            ]);

            foreach ($this->labelSets($definition['labels']) as $labels) {
                $this->registerSeries($connection, $runId, $name, $definition, $labels, $ttlSeconds);
            }

            $connection->expire($familyKey, $ttlSeconds);
        }

        $connection->hmset($this->metaKey($runId), [
            'created_at' => $createdAt,
            'state' => 'open',
            'ttl_seconds' => (string) $ttlSeconds,
        ]);
        $connection->set($this->openKey($runId), '1');
        $connection->set(self::ACTIVE_RUN_KEY, $runId);

        foreach ([$manifestKey, $this->metaKey($runId), $this->openKey($runId), self::ACTIVE_RUN_KEY] as $key) {
            $connection->expire($key, $ttlSeconds);
        }
    }

    public function finalizeRun(string $runId): bool
    {
        $this->assertRunId($runId);
        $connection = $this->connection();

        if ((int) $connection->exists($this->manifestKey($runId)) === 0) {
            return false;
        }

        $connection->set($this->openKey($runId), '0');
        $connection->hset($this->metaKey($runId), 'state', 'finalized');

        return true;
    }

    public function isOpen(string $runId): bool
    {
        return $this->isRunId($runId) && $this->connection()->get($this->openKey($runId)) === '1';
    }

    public function activeRunId(): ?string
    {
        $runId = $this->connection()->get(self::ACTIVE_RUN_KEY);

        return is_string($runId) && $this->isRunId($runId) ? $runId : null;
    }

    /** @param array<string, string> $labels */
    public function incrementCounter(string $runId, string $name, int|float $amount, array $labels): void
    {
        $definition = $this->requiredDefinition($name, 'counter');
        $connection = $this->connection();
        $this->indexSeries($connection, $runId, $name, $labels);
        $connection->incrbyfloat($this->seriesKey($runId, $name, $labels), $this->number($amount));
        $this->touchSeries($connection, $runId, $name, $labels, $definition);
    }

    /** @param array<string, string> $labels */
    public function setGauge(string $runId, string $name, int|float $value, array $labels): void
    {
        $definition = $this->requiredDefinition($name, 'gauge');
        $connection = $this->connection();
        $seriesKey = $this->seriesKey($runId, $name, $labels);
        $this->indexSeries($connection, $runId, $name, $labels);

        if (($definition['aggregation'] ?? 'set') === 'max') {
            $connection->eval(self::GAUGE_MAX_LUA, 1, $seriesKey, $this->number($value));
        } else {
            $connection->set($seriesKey, $this->number($value));
        }

        $this->touchSeries($connection, $runId, $name, $labels, $definition);
    }

    /** @param array<string, string> $labels */
    public function observeHistogram(string $runId, string $name, int|float $value, array $labels): void
    {
        $definition = $this->requiredDefinition($name, 'histogram');
        $connection = $this->connection();
        $seriesKey = $this->seriesKey($runId, $name, $labels);
        $this->indexSeries($connection, $runId, $name, $labels);
        $connection->eval($this->histogramLua, 1, $seriesKey, $this->number($value), ...$definition['buckets']);
        $this->touchSeries($connection, $runId, $name, $labels, $definition);
    }

    public function exposition(string $runId): ?string
    {
        $this->assertRunId($runId);
        $connection = $this->connection();
        $families = $connection->smembers($this->manifestKey($runId));

        if (!is_array($families) || $families === []) {
            return null;
        }

        sort($families, SORT_STRING);
        $lines = [];

        foreach ($families as $name) {
            if (!is_string($name) || !isset(self::FAMILIES[$name])) {
                continue;
            }

            $definition = self::FAMILIES[$name];
            $lines[] = '# HELP '.$name.' '.$this->escapeHelp($definition['help']);
            $lines[] = '# TYPE '.$name.' '.$definition['type'];
            $family = $connection->hgetall($this->familyKey($runId, $name));
            $series = [];

            foreach (is_array($family) ? $family : [] as $field => $encodedLabels) {
                if (!is_string($field) || !str_starts_with($field, 'series:') || !is_string($encodedLabels)) {
                    continue;
                }

                $labels = json_decode($encodedLabels, true, flags: JSON_THROW_ON_ERROR);

                if (is_array($labels)) {
                    $series[substr($field, 7)] = $labels;
                }
            }

            ksort($series, SORT_STRING);

            foreach ($series as $labels) {
                /** @var array<string, string> $labels */
                $this->appendSeries($lines, $connection, $runId, $name, $definition, $labels);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @param list<string> $lines
     *  @param array{type: 'counter'|'gauge'|'histogram', help: string, labels: array<string, list<string>>, buckets?: list<string>, aggregation?: 'max'|'set'} $definition
     *  @param array<string, string> $labels */
    private function appendSeries(array &$lines, Connection $connection, string $runId, string $name, array $definition, array $labels): void
    {
        $seriesKey = $this->seriesKey($runId, $name, $labels);

        if ($definition['type'] !== 'histogram') {
            $value = $connection->get($seriesKey);
            $lines[] = $name.$this->formatLabels($labels).' '.$this->sample($value);

            return;
        }

        $values = $connection->hgetall($seriesKey);
        $values = is_array($values) ? $values : [];

        foreach ($definition['buckets'] as $bucket) {
            $bucketLabels = [...$labels, 'le' => $bucket];
            $lines[] = $name.'_bucket'.$this->formatLabels($bucketLabels).' '.$this->sample($values['le:'.$bucket] ?? 0);
        }

        $lines[] = $name.'_sum'.$this->formatLabels($labels).' '.$this->sample($values['_sum'] ?? 0);
        $lines[] = $name.'_count'.$this->formatLabels($labels).' '.$this->sample($values['_count'] ?? 0);
    }

    /** @param array<string, list<string>> $vocabulary
     *  @return list<array<string, string>> */
    private function labelSets(array $vocabulary): array
    {
        $sets = [[]];

        foreach ($vocabulary as $key => $values) {
            $expanded = [];

            foreach ($sets as $labels) {
                foreach ($values as $value) {
                    $expanded[] = [...$labels, $key => $value];
                }
            }

            $sets = $expanded;
        }

        return $sets;
    }

    /** @param array{type: 'counter'|'gauge'|'histogram', help: string, labels: array<string, list<string>>, buckets?: list<string>, aggregation?: 'max'|'set'} $definition
     *  @param array<string, string> $labels */
    private function registerSeries(Connection $connection, string $runId, string $name, array $definition, array $labels, int $ttlSeconds): void
    {
        $this->indexSeries($connection, $runId, $name, $labels);
        $seriesKey = $this->seriesKey($runId, $name, $labels);

        if ($definition['type'] === 'histogram') {
            $values = ['_sum' => '0', '_count' => '0'];

            foreach ($definition['buckets'] as $bucket) {
                $values['le:'.$bucket] = '0';
            }

            $connection->hmset($seriesKey, $values);
        } else {
            $connection->set($seriesKey, '0');
        }

        $connection->expire($seriesKey, $ttlSeconds);
    }

    /** @param array<string, string> $labels */
    private function indexSeries(Connection $connection, string $runId, string $name, array $labels): void
    {
        $connection->hset(
            $this->familyKey($runId, $name),
            'series:'.$this->canonicalLabels($labels),
            json_encode($labels, JSON_THROW_ON_ERROR),
        );
    }

    /** @param array{type: 'counter'|'gauge'|'histogram', help: string, labels: array<string, list<string>>, buckets?: list<string>, aggregation?: 'max'|'set'} $definition
     *  @param array<string, string> $labels */
    private function touchSeries(Connection $connection, string $runId, string $name, array $labels, array $definition): void
    {
        $ttl = (int) $connection->ttl($this->openKey($runId));

        if ($ttl <= 0) {
            return;
        }

        $connection->expire($this->seriesKey($runId, $name, $labels), $ttl);
        $connection->expire($this->familyKey($runId, $name), $ttl);
    }

    /** @return array{type: 'counter'|'gauge'|'histogram', help: string, labels: array<string, list<string>>, buckets?: list<string>, aggregation?: 'max'|'set'} */
    private function requiredDefinition(string $name, string $type): array
    {
        $definition = self::FAMILIES[$name] ?? null;

        if ($definition === null || $definition['type'] !== $type) {
            throw new InvalidArgumentException("Unknown {$type} metric family {$name}.");
        }

        return $definition;
    }

    private function connection(): Connection
    {
        return $this->redis->connection('default');
    }

    /** @param array<string, string> $labels */
    private function seriesKey(string $runId, string $name, array $labels): string
    {
        return $this->baseKey($runId).':series:'.$name.':'.$this->canonicalLabels($labels);
    }

    private function familyKey(string $runId, string $name): string
    {
        return $this->baseKey($runId).':family:'.$name;
    }

    private function manifestKey(string $runId): string
    {
        return $this->baseKey($runId).':manifest';
    }

    private function metaKey(string $runId): string
    {
        return $this->baseKey($runId).':meta';
    }

    private function openKey(string $runId): string
    {
        return $this->baseKey($runId).':open';
    }

    private function baseKey(string $runId): string
    {
        return 'vaulter:metrics:{'.$runId.'}';
    }

    /** @param array<string, string> $labels */
    private function canonicalLabels(array $labels): string
    {
        if ($labels === []) {
            return '_';
        }

        ksort($labels, SORT_STRING);
        $parts = [];

        foreach ($labels as $key => $value) {
            $parts[] = rawurlencode($key).'='.rawurlencode($value);
        }

        return implode(',', $parts);
    }

    /** @param array<string, string> $labels */
    private function formatLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        ksort($labels, SORT_STRING);
        $pairs = [];

        foreach ($labels as $key => $value) {
            $escaped = str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
            $pairs[] = $key.'="'.$escaped.'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
    }

    private function sample(mixed $value): string
    {
        return is_int($value) || is_float($value) || is_string($value) ? (string) $value : '0';
    }

    private function escapeHelp(string $help): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $help);
    }

    private function assertRunId(string $runId): void
    {
        if (!$this->isRunId($runId)) {
            throw new InvalidArgumentException('Metrics run_id must be a UUID.');
        }
    }

    private function isRunId(string $runId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $runId) === 1;
    }
}
