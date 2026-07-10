<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\StorageAdapter;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Contracts\Documents\DocumentExporter;
use Vaulter\Contracts\Documents\DocumentHandler;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\FileVersionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Panels\DefaultStoragePanel;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Panels\StoragePanelProvider;
use Vaulter\Webhooks\DefaultOutboundUrlGuard;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final class Config
{
    /**
     * The default closed set of ranges an outbound webhook must not resolve into
     * (§2.11 / N2): loopback, RFC-1918 private, link-local (incl. cloud metadata at
     * 169.254.169.254), CGNAT, this-network, multicast, reserved/future-use (incl. the
     * 255.255.255.255 limited-broadcast address, which lives inside 240.0.0.0/4), and
     * their IPv6 equivalents — including the unspecified address `::`, which the kernel
     * routes to loopback on Linux. IPv4-mapped IPv6 is folded to IPv4 before the check,
     * so the IPv4 entries cover it.
     *
     * @var list<string>
     */
    private const array DEFAULT_BLOCKED_IP_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * @return class-string<Storage>
     */
    public static function storageModel(): string
    {
        /** @var class-string<Storage> $class */
        $class = config('vaulter.models.storage', Storage::class);

        return $class;
    }

    /**
     * @return class-string<Node>
     */
    public static function nodeModel(): string
    {
        /** @var class-string<Node> $class */
        $class = config('vaulter.models.node', Node::class);

        return $class;
    }

    /**
     * @return class-string<File>
     */
    public static function fileModel(): string
    {
        /** @var class-string<File> $class */
        $class = config('vaulter.models.file', File::class);

        return $class;
    }

    /**
     * @return class-string<FileVersion>
     */
    public static function fileVersionModel(): string
    {
        /** @var class-string<FileVersion> $class */
        $class = config('vaulter.models.file_version', FileVersion::class);

        return $class;
    }

    /**
     * @return class-string<Blob>
     */
    public static function blobModel(): string
    {
        /** @var class-string<Blob> $class */
        $class = config('vaulter.models.blob', Blob::class);

        return $class;
    }

    /**
     * @return class-string<FileVersionBlob>
     */
    public static function fileVersionBlobModel(): string
    {
        /** @var class-string<FileVersionBlob> $class */
        $class = config('vaulter.models.file_version_blob', FileVersionBlob::class);

        return $class;
    }

    /**
     * The node-attached document-version model (§2.5 / M4a) — the canonical version
     * history on kind=document nodes. Owned by the vaulter-documents package
     * (P2 #5 / ADR-0015): core reads it config-only with NO hard class fallback, so
     * the model can move to that package without touching this class. Its default is
     * supplied by the owning package's provider (mergeConfig into
     * config('vaulter.models.node_document_version')).
     *
     * @return class-string<Model>
     */
    public static function nodeDocumentVersionModel(): string
    {
        $class = config('vaulter.models.node_document_version');

        if (!is_string($class) || $class === '') {
            throw new \RuntimeException(
                'Node-document-version model not configured — set vaulter.models.node_document_version (owned by axioma-studio/vaulter-documents).',
            );
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    /**
     * The max document-versions kept per node before pruning (§2.5), excluding
     * keep_forever versions. 0 = unlimited. Reads config('vaulter.versions.max')
     * with a fallback to the legacy max_versions key.
     */
    public static function maxDocumentVersions(): int
    {
        $value = config('vaulter.versions.max', config('vaulter.max_versions', 50));

        return is_numeric($value) ? (int) $value : 50;
    }

    /**
     * The autosave debounce window in seconds (§2.5 / M4a): an autosave within this
     * window of the head autosave version UPDATES it in place rather than creating a
     * new row, so a typing session does not flood the history. Floored at 0 (0 =
     * every autosave overwrites the head autosave version).
     */
    public static function autosaveDebounceSeconds(): int
    {
        $value = config('vaulter.versions.autosave_debounce_seconds', 30);

        $seconds = is_numeric($value) ? (int) $value : 30;

        return max($seconds, 0);
    }

    /**
     * @return class-string<NodeGrant>
     */
    public static function grantModel(): string
    {
        /** @var class-string<NodeGrant> $class */
        $class = config('vaulter.models.grant', NodeGrant::class);

        return $class;
    }

    /**
     * @return class-string<NodeShare>
     */
    public static function nodeShareModel(): string
    {
        /** @var class-string<NodeShare> $class */
        $class = config('vaulter.models.node_share', NodeShare::class);

        return $class;
    }

    /**
     * The NODE-attached comment model (ADR-0014) — the canonical comment surface
     * (v_node_comments) after the legacy document_id-bound path was excised. Owned by
     * the vaulter-documents package (P2 #5 / ADR-0015): read config-only, no hard
     * class fallback (default supplied by that package's provider).
     *
     * @return class-string<Model>
     */
    public static function nodeCommentModel(): string
    {
        $class = config('vaulter.models.node_comment');

        if (!is_string($class) || $class === '') {
            throw new \RuntimeException(
                'Node-comment model not configured — set vaulter.models.node_comment (owned by axioma-studio/vaulter-documents).',
            );
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    /**
     * @return class-string<Activity>
     */
    public static function activityModel(): string
    {
        /** @var class-string<Activity> $class */
        $class = config('vaulter.models.activity', Activity::class);

        return $class;
    }

    /**
     * The resumable-upload session model. Owned by the vaulter-drive package
     * (P2 #5 / ADR-0015): read config-only, no hard class fallback (default supplied
     * by that package's provider via config('vaulter.models.upload_session')). Core no
     * longer references the concrete class — the return is an opaque model class-string.
     *
     * @return class-string<Model>
     */
    public static function uploadSessionModel(): string
    {
        $class = config('vaulter.models.upload_session');

        if (!is_string($class) || $class === '') {
            throw new \RuntimeException(
                'Upload-session model not configured — set vaulter.models.upload_session (owned by axioma-studio/vaulter-drive).',
            );
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    /**
     * The resumable-upload part model. Owned by the vaulter-drive package
     * (P2 #5 / ADR-0015): read config-only, no hard class fallback. Core no longer
     * references the concrete class — the return is an opaque model class-string.
     *
     * @return class-string<Model>
     */
    public static function uploadPartModel(): string
    {
        $class = config('vaulter.models.upload_part');

        if (!is_string($class) || $class === '') {
            throw new \RuntimeException(
                'Upload-part model not configured — set vaulter.models.upload_part (owned by axioma-studio/vaulter-drive).',
            );
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    /**
     * @return class-string<Outbox>
     */
    public static function outboxModel(): string
    {
        /** @var class-string<Outbox> $class */
        $class = config('vaulter.models.outbox', Outbox::class);

        return $class;
    }

    /**
     * @return class-string<WebhookEndpoint>
     */
    public static function webhookEndpointModel(): string
    {
        /** @var class-string<WebhookEndpoint> $class */
        $class = config('vaulter.models.webhook_endpoint', WebhookEndpoint::class);

        return $class;
    }

    /**
     * @return class-string<WebhookDelivery>
     */
    public static function webhookDeliveryModel(): string
    {
        /** @var class-string<WebhookDelivery> $class */
        $class = config('vaulter.models.webhook_delivery', WebhookDelivery::class);

        return $class;
    }

    /**
     * The HTTP request timeout (seconds) for a single webhook delivery POST
     * (§2.11). Floored at 1.
     */
    public static function webhookTimeout(): int
    {
        $value = config('vaulter.webhooks.timeout', 5);

        $seconds = is_numeric($value) ? (int) $value : 5;

        return max($seconds, 1);
    }

    /**
     * The total number of delivery attempts before a webhook delivery is parked
     * dead (DLQ, §2.11). Floored at 1.
     */
    public static function webhookMaxAttempts(): int
    {
        $value = config('vaulter.webhooks.max_attempts', 5);

        $attempts = is_numeric($value) ? (int) $value : 5;

        return max($attempts, 1);
    }

    /**
     * The backoff delay (seconds) before retrying after the given 1-based attempt
     * number failed (§2.11). Past the configured tail the last entry caps further
     * retries; an empty schedule means retry immediately (0).
     */
    public static function webhookBackoff(int $attempt): int
    {
        $schedule = config('vaulter.webhooks.backoff', [10, 30, 60, 300, 1800]);

        if (!is_array($schedule) || $schedule === []) {
            return 0;
        }

        $values = array_values(array_map(static fn (mixed $seconds): int => is_numeric($seconds) ? (int) $seconds : 0, $schedule));

        $index = max(0, $attempt - 1);

        return $values[$index] ?? $values[array_key_last($values)];
    }

    /**
     * The number of consecutive endpoint failures that flips an endpoint's
     * is_active off (the §2.11 circuit breaker). Floored at 1.
     */
    public static function webhookDisableAfterFailures(): int
    {
        $value = config('vaulter.webhooks.disable_after_failures', 10);

        $failures = is_numeric($value) ? (int) $value : 10;

        return max($failures, 1);
    }

    /**
     * The HMAC-SHA256 signature header name the delivery POST carries (§2.11).
     */
    public static function webhookSignatureHeader(): string
    {
        $value = config('vaulter.webhooks.signature_header', 'X-Vaulter-Signature');

        return is_string($value) && $value !== '' ? $value : 'X-Vaulter-Signature';
    }

    /**
     * The replay-resistance timestamp header name the delivery POST carries
     * (§2.11) — the signed payload includes this timestamp.
     */
    public static function webhookTimestampHeader(): string
    {
        $value = config('vaulter.webhooks.timestamp_header', 'X-Vaulter-Timestamp');

        return is_string($value) && $value !== '' ? $value : 'X-Vaulter-Timestamp';
    }

    /**
     * The event-name header the delivery POST carries (§2.11).
     */
    public static function webhookEventHeader(): string
    {
        $value = config('vaulter.webhooks.event_header', 'X-Vaulter-Event');

        return is_string($value) && $value !== '' ? $value : 'X-Vaulter-Event';
    }

    /**
     * The age in days past which dispatched outbox rows and terminal webhook
     * deliveries are eligible for pruning (§2.11). Default 30, floored at 0.
     */
    public static function webhookRetentionDays(): int
    {
        $value = config('vaulter.webhooks.retention_days', 30);

        $days = is_numeric($value) ? (int) $value : 30;

        return max($days, 0);
    }

    /**
     * The Gate ability gating the webhook-endpoint management surface (§2.11). Null
     * means "no host ability defined" — the policy then allows any authenticated user
     * (the route still requires auth). A host MUST define this in production.
     */
    public static function webhookManageAbility(): ?string
    {
        $value = config('vaulter.webhooks.manage_ability');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The SWAPPABLE outbound SSRF guard (§2.11 / D5 / F-P1.7-01). A host may point
     * config('vaulter.webhooks.delivery.url_guard') at its own implementation (e.g. one
     * that defers to an egress proxy). Only a valid OutboundUrlGuard FQCN is returned.
     *
     * @return class-string<OutboundUrlGuard>
     */
    public static function webhookUrlGuardClass(): string
    {
        $class = config('vaulter.webhooks.delivery.url_guard', DefaultOutboundUrlGuard::class);

        if (is_string($class) && is_a($class, OutboundUrlGuard::class, allow_string: true)) {
            /** @var class-string<OutboundUrlGuard> $class */
            return $class;
        }

        return DefaultOutboundUrlGuard::class;
    }

    /**
     * The URL schemes an outbound webhook may dial (§2.11 / N2). Lower-cased; defaults
     * to http + https. Anything else (file/gopher/ftp/…) is refused by the guard.
     *
     * @return list<string>
     */
    public static function webhookAllowedSchemes(): array
    {
        $value = config('vaulter.webhooks.delivery.allowed_schemes', ['http', 'https']);

        if (!is_array($value)) {
            return ['http', 'https'];
        }

        $schemes = [];

        foreach ($value as $scheme) {
            if (is_string($scheme) && $scheme !== '') {
                $schemes[] = strtolower($scheme);
            }
        }

        return $schemes === [] ? ['http', 'https'] : array_values(array_unique($schemes));
    }

    /**
     * The private/loopback/link-local/reserved CIDR ranges an outbound webhook must
     * NOT resolve into (§2.11 / N2). Closed by default; a host tightens or replaces it.
     *
     * @return list<string>
     */
    public static function webhookBlockedIpRanges(): array
    {
        return self::ipRanges('vaulter.webhooks.delivery.blocked_ip_ranges', self::DEFAULT_BLOCKED_IP_RANGES);
    }

    /**
     * The CIDR ranges a host EXPLICITLY re-opens for a trusted internal receiver
     * (§2.11 / N2). Empty by default — the guard's default is closed.
     *
     * @return list<string>
     */
    public static function webhookAllowedIpRanges(): array
    {
        return self::ipRanges('vaulter.webhooks.delivery.allowed_ip_ranges', []);
    }

    /**
     * Whether an outbound webhook delivery follows HTTP redirects (§2.11 / N2). Default
     * FALSE: a public receiver must not 302 the request onto an internal address.
     */
    public static function webhookFollowRedirects(): bool
    {
        return config('vaulter.webhooks.delivery.follow_redirects', false) === true;
    }

    /**
     * The TCP connect timeout (seconds) for a delivery POST (§2.11 / N2). Floored at 1,
     * default 2 — a fast fail on an unreachable internal host.
     */
    public static function webhookConnectTimeout(): int
    {
        $value = config('vaulter.webhooks.delivery.connect_timeout', 2);

        $seconds = is_numeric($value) ? (int) $value : 2;

        return max($seconds, 1);
    }

    /**
     * The hard ceiling (bytes) on a receiver's response body (§2.11 / N2 / F-P1.7-06).
     * Delivery needs only the status, so a larger body is refused. Floored at 0
     * (0 disables the check); default 64 KiB.
     */
    public static function webhookMaxResponseBytes(): int
    {
        $value = config('vaulter.webhooks.delivery.max_response_bytes', 65536);

        $bytes = is_numeric($value) ? (int) $value : 65536;

        return max($bytes, 0);
    }

    /**
     * The claim window (seconds) a due retry's next_retry_at is bumped forward before
     * dispatch (§2.11 / F-P1.7-05) — the visibility timeout that keeps re-dispatch a
     * single owner. Floored at 1, default 60.
     */
    public static function webhookRetryClaimSeconds(): int
    {
        $value = config('vaulter.webhooks.retry_claim_seconds', 60);

        $seconds = is_numeric($value) ? (int) $value : 60;

        return max($seconds, 1);
    }

    /**
     * Read a config key of CIDR strings, falling back to $default when unset/!array.
     *
     * @param  list<string> $default
     * @return list<string>
     */
    private static function ipRanges(string $key, array $default): array
    {
        $value = config($key, $default);

        if (!is_array($value)) {
            return $default;
        }

        $ranges = [];

        foreach ($value as $range) {
            if (is_string($range) && $range !== '') {
                $ranges[] = $range;
            }
        }

        return $ranges;
    }

    /**
     * The hard ceiling (in bytes) on a resumable upload's declared total size
     * (§2.8 / M3a). A session whose total exceeds this is rejected at initiate; a
     * tus stream that overruns it is rejected at PATCH. 0 = unlimited.
     */
    public static function uploadMaxBytes(): int
    {
        $value = config('vaulter.uploads.max_bytes', 5_368_709_120); // 5 GiB

        $bytes = is_numeric($value) ? (int) $value : 5_368_709_120;

        return max($bytes, 0);
    }

    /**
     * The advertised default chunk size (in bytes) for a resumable upload (§2.8) —
     * a hint surfaced to clients; the server still accepts any PATCH/part size up to
     * the remaining bytes. Floored at 1.
     */
    public static function uploadChunkSize(): int
    {
        $value = config('vaulter.uploads.chunk_size', 8_388_608); // 8 MiB

        $bytes = is_numeric($value) ? (int) $value : 8_388_608;

        return max($bytes, 1);
    }

    /**
     * The session TTL in hours (§2.8): a session not committed within this window is
     * swept (its staged temp + S3 multipart reclaimed, status → expired). The
     * initiate path sets expires_at = now + this. Floored at 1.
     */
    public static function uploadSessionTtlHours(): int
    {
        $value = config('vaulter.uploads.session_ttl_hours', 24);

        $hours = is_numeric($value) ? (int) $value : 24;

        return max($hours, 1);
    }

    /**
     * Whether the tus.io resumable endpoints are enabled (§2.8). When false the tus
     * route group still mounts but the controller refuses with 403 (the feature is
     * config-gated independently of the 'uploads' schema flag).
     */
    public static function tusEnabled(): bool
    {
        return (bool) config('vaulter.uploads.tus.enabled', true);
    }

    /**
     * The selected host-role driver key (H.2), default 'database' (the standalone
     * zero-host-dependency lane).
     */
    public static function permissionDriver(): string
    {
        $value = config('vaulter.drivers.permission', 'database');

        return is_string($value) && $value !== '' ? $value : 'database';
    }

    /**
     * The driver-key → FQCN map for the host-role drivers (H.2). Only valid
     * HostRoleDriver subclasses are returned (cache-safe — FQCN only).
     *
     * @return array<string, class-string<HostRoleDriver>>
     */
    public static function permissionDrivers(): array
    {
        $value = config('vaulter.permission_drivers', []);

        if (!is_array($value)) {
            return [];
        }

        $drivers = [];

        foreach ($value as $key => $class) {
            if (is_string($key) && is_string($class) && is_a($class, HostRoleDriver::class, allow_string: true)) {
                $drivers[$key] = $class;
            }
        }

        /** @var array<string, class-string<HostRoleDriver>> $drivers */
        return $drivers;
    }

    public static function idType(): string
    {
        $value = config('vaulter.id_type', 'id');

        return is_string($value) ? $value : 'id';
    }

    /**
     * The active id-strategy key (ulid|uuid7|bigint), default ulid (D9).
     *
     * Backward-compat: when ids.default is not explicitly set, derive it from
     * the legacy id_type (id->bigint, ulid->ulid). When both are present,
     * ids.default wins.
     */
    public static function idStrategy(): string
    {
        $explicit = config('vaulter.ids.default');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return self::idStrategyFromLegacyType();
    }

    /**
     * @return array<string, class-string<IdStrategy>>
     */
    public static function idStrategies(): array
    {
        $value = config('vaulter.ids.strategies', []);

        if (!is_array($value)) {
            return [];
        }

        $strategies = [];

        foreach ($value as $key => $class) {
            if (is_string($key) && is_string($class) && is_a($class, IdStrategy::class, allow_string: true)) {
                $strategies[$key] = $class;
            }
        }

        /** @var array<string, class-string<IdStrategy>> $strategies */
        return $strategies;
    }

    /**
     * Legacy id_type values still drive published migrations and host models.
     * usesUlids() reflects the resolved strategy so it keeps working whether the
     * host configures id_type=ulid or the newer ids.default=ulid.
     */
    public static function usesUlids(): bool
    {
        return self::idStrategy() === 'ulid';
    }

    /**
     * Map the legacy id_type (id|ulid) onto an id-strategy key.
     */
    private static function idStrategyFromLegacyType(): string
    {
        return self::idType() === 'ulid' ? 'ulid' : 'bigint';
    }

    public static function extraDataEnabled(): bool
    {
        return (bool) config('vaulter.extra_data.enabled', true);
    }

    public static function tenantEnabled(): bool
    {
        return (bool) config('vaulter.tenant.enabled', false);
    }

    public static function tenantColumn(): string
    {
        $value = config('vaulter.tenant.column', 'tenant_id');

        return is_string($value) ? $value : 'tenant_id';
    }

    public static function tablePrefix(): string
    {
        $value = config('vaulter.table_prefix', 'v_');

        return is_string($value) ? $value : 'v_';
    }

    /**
     * Resolve a Vaulter table name: the configured prefix plus the base name, or
     * the base name mapped through config('vaulter.table_names') when present, so
     * an install may rename a single entity's table without a prefix change.
     */
    public static function table(string $name): string
    {
        $mapped = config("vaulter.table_names.{$name}");

        $base = is_string($mapped) && $mapped !== '' ? $mapped : $name;

        return self::tablePrefix().$base;
    }

    public static function routePrefix(): string
    {
        $value = config('vaulter.route_prefix', 'api/vaulter');

        return is_string($value) ? $value : 'api/vaulter';
    }

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return self::stringList(config('vaulter.middleware', ['api']), ['api']);
    }

    public static function maxVersions(): int
    {
        $value = config('vaulter.max_versions', 50);

        return is_numeric($value) ? (int) $value : 50;
    }

    /**
     * The dedup scope the content address is unique within (D18 / H.3):
     * global|tenant|storage. Drives both the dedup-UNIQUE index shape and the
     * scope-qualified storage_key prefix. Unknown values fall back to 'tenant'.
     */
    public static function dedupScope(): string
    {
        $value = config('vaulter.dedup.scope', 'tenant');

        return in_array($value, ['global', 'tenant', 'storage'], strict: true) ? $value : 'tenant';
    }

    /**
     * The two-phase GC grace window in hours (H.3), floored at 1h so a tombstone
     * can never be swept before an in-flight multipart upload could resurrect it.
     */
    public static function gcGraceHours(int $override = 0): int
    {
        $value = $override > 0 ? $override : config('vaulter.gc.grace_hours', 24);

        $hours = is_numeric($value) ? (int) $value : 24;

        return max($hours, 1);
    }

    /**
     * The orphan-object grace window in hours (H.3) — how old a bytes-without-a-row
     * object must be before the disk-walk treats it as a deletable orphan. An
     * in-flight commit promotes its bytes BEFORE its row is durable, so a fresh
     * object may be a commit-in-progress, not an orphan; this grace is deliberately
     * generous (a resumable upload can promote then commit much later). Defaults to
     * the GC grace, floored at 1h.
     */
    public static function orphanGraceHours(int $override = 0): int
    {
        $default = config('vaulter.gc.grace_hours', 24);

        $value = $override > 0 ? $override : config('vaulter.gc.orphan_grace_hours', $default);

        $hours = is_numeric($value) ? (int) $value : 24;

        return max($hours, 1);
    }

    /**
     * The default Flysystem disk for the canonical CAS objects when a blob's
     * storage row does not pin its own disk.
     */
    public static function defaultDisk(): string
    {
        $value = config('vaulter.storage.default_disk', 'local');

        return is_string($value) && $value !== '' ? $value : 'local';
    }

    public static function maxDocumentSizeKb(): int
    {
        $value = config('vaulter.max_document_size_kb', 5120);

        return is_numeric($value) ? (int) $value : 5120;
    }

    /**
     * The hard size ceiling (kilobytes) for a binary Drive upload (§2.4),
     * enforced by UploadFileRequest. Falls back to the document size limit when
     * unset, so the upload behaviour is unchanged until a host pins its own
     * value via config('vaulter.drive.max_upload_kb').
     */
    public static function maxUploadKb(): int
    {
        $value = config('vaulter.drive.max_upload_kb');

        return is_numeric($value) ? (int) $value : self::maxDocumentSizeKb();
    }

    /**
     * The OPTIONAL mime allowlist for binary Drive uploads. An empty list means
     * permissive (any mime accepted, the default); a non-empty list restricts
     * uploads to that set through Laravel's `mimetypes` rule. Patterns such as
     * 'image/*' are passed through verbatim — the rule expands them.
     *
     * @return list<string>
     */
    public static function allowedUploadMimes(): array
    {
        return self::stringList(config('vaulter.drive.allowed_mime', []), []);
    }

    /**
     * @return list<string>
     */
    public static function enabledDocumentTypes(): array
    {
        return self::stringList(config('vaulter.enabled_document_types', []), []);
    }

    public static function collaborationDriverName(): string
    {
        $value = config('vaulter.collaboration.driver', 'reverb');

        return is_string($value) ? $value : 'reverb';
    }

    public static function channelPrefix(): string
    {
        $value = config('vaulter.collaboration.channel_prefix', 'vaulter.documents');

        return is_string($value) ? $value : 'vaulter.documents';
    }

    /**
     * @return array<string, mixed>
     */
    public static function driverConfig(string $name): array
    {
        $value = config('vaulter.collaboration.drivers.'.$name);

        /** @var array<string, mixed> $config */
        $config = is_array($value) ? $value : [];

        return $config;
    }

    /**
     * The Laravel broadcast connection the Reverb collaboration transport uses
     * (config('vaulter.collaboration.drivers.reverb.connection'), default 'reverb').
     * The Vue client opens its Echo connection against this name; the room is the
     * private broadcast channel the auth endpoint gates.
     */
    public static function reverbConnection(): string
    {
        $value = config('vaulter.collaboration.drivers.reverb.connection', 'reverb');

        return is_string($value) && $value !== '' ? $value : 'reverb';
    }

    /**
     * The collaboration room name for a node (M4b) — channel_prefix.nodeId. This is
     * the single source of the room handle: the Reverb private broadcast channel, the
     * Hocuspocus JWT `room` claim and the webhook `room` body all derive from it, so a
     * token is bound to EXACTLY one node (no cross-node access, H-hardening). Accepts
     * the raw Eloquent key (typed mixed) and stringifies it.
     */
    public static function collaborationRoom(mixed $nodeId): string
    {
        return self::channelPrefix().'.'.Identity::stringify($nodeId);
    }

    /**
     * The node id a collaboration room name resolves back to — the inverse of
     * {@see collaborationRoom()}. The webhook endpoints accept either the bare node id
     * or the full prefixed room and normalise through here so the Node hub may send
     * whichever it holds. Returns the input unchanged when it carries no prefix.
     */
    public static function nodeIdFromRoom(string $room): string
    {
        $prefix = self::channelPrefix().'.';

        if (str_starts_with($room, $prefix)) {
            return substr($room, strlen($prefix));
        }

        return $room;
    }

    /**
     * The shared secret the Node Hocuspocus hub presents on the server-to-server
     * webhook endpoints (collab/authenticate, collab/store). Null/empty ⇒ the
     * webhook surface accepts ONLY a valid signed JWT (never open). Set via
     * VAULTER_COLLAB_WEBHOOK_SECRET.
     */
    public static function collaborationWebhookSecret(): ?string
    {
        $value = config('vaulter.collaboration.webhook_secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The HS256 signing secret the Hocuspocus driver mints room JWTs with, also the
     * key the webhook endpoints verify an incoming JWT against
     * (config('vaulter.collaboration.drivers.hocuspocus.secret')). Null/empty ⇒ no JWT
     * path is available.
     */
    public static function collaborationJwtSecret(): ?string
    {
        $value = config('vaulter.collaboration.drivers.hocuspocus.secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The TTL (seconds) a minted room JWT is valid for
     * (config('vaulter.collaboration.drivers.hocuspocus.token_ttl'), default 3600).
     */
    public static function collaborationTokenTtl(): int
    {
        $value = config('vaulter.collaboration.drivers.hocuspocus.token_ttl', 3600);

        return is_numeric($value) ? (int) $value : 3600;
    }

    /**
     * The Hocuspocus hub websocket URL the Vue client connects to
     * (config('vaulter.collaboration.drivers.hocuspocus.url')). Null when unset.
     */
    public static function collaborationHubUrl(): ?string
    {
        $value = config('vaulter.collaboration.drivers.hocuspocus.url');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return class-string<StorageAdapter>
     */
    public static function storageAdapterClass(): string
    {
        /** @var class-string<StorageAdapter> $class */
        $class = config('vaulter.storage_adapter', DefaultStorageAdapter::class);

        return $class;
    }

    /**
     * The CAS ContentStore implementation (H.3, swappable).
     *
     * @return class-string<ContentStore>
     */
    public static function contentStoreClass(): string
    {
        $class = config('vaulter.cas.content_store');

        if (is_string($class) && is_a($class, ContentStore::class, allow_string: true)) {
            /** @var class-string<ContentStore> $class */
            return $class;
        }

        throw new \RuntimeException(
            'CAS content store not configured — install axioma-studio/vaulter-media (it owns the vaulter.cas.content_store default).',
        );
    }

    /**
     * The CAS StorageDriver implementation (H.3, swappable).
     *
     * @return class-string<StorageDriver>
     */
    public static function storageDriverClass(): string
    {
        $class = config('vaulter.cas.storage_driver');

        if (is_string($class) && is_a($class, StorageDriver::class, allow_string: true)) {
            /** @var class-string<StorageDriver> $class */
            return $class;
        }

        throw new \RuntimeException(
            'CAS storage driver not configured — install axioma-studio/vaulter-media (it owns the vaulter.cas.storage_driver default).',
        );
    }

    /**
     * The high-level CAS BlobStore implementation (H.3, swappable).
     *
     * @return class-string<BlobStore>
     */
    public static function blobStoreClass(): string
    {
        $class = config('vaulter.cas.blob_store');

        if (is_string($class) && is_a($class, BlobStore::class, allow_string: true)) {
            /** @var class-string<BlobStore> $class */
            return $class;
        }

        throw new \RuntimeException(
            'CAS blob store not configured — install axioma-studio/vaulter-media (it owns the vaulter.cas.blob_store default).',
        );
    }

    /**
     * @return class-string<ShareTokenGenerator>
     */
    public static function shareTokenGeneratorClass(): string
    {
        /** @var class-string<ShareTokenGenerator> $class */
        $class = config('vaulter.share_token_generator', RandomShareTokenGenerator::class);

        return $class;
    }

    /**
     * The bearer share-link token generator (M2c / §2.7), read from
     * config('vaulter.share.token_generator') with a fallback to the legacy
     * top-level key so a host that only set the old key keeps working. Only a
     * valid ShareTokenGenerator FQCN is returned (cache-safe — FQCN only).
     *
     * @return class-string<ShareTokenGenerator>
     */
    public static function nodeShareTokenGeneratorClass(): string
    {
        $class = config('vaulter.share.token_generator', config('vaulter.share_token_generator'));

        if (is_string($class) && is_a($class, ShareTokenGenerator::class, allow_string: true)) {
            /** @var class-string<ShareTokenGenerator> $class */
            return $class;
        }

        return RandomShareTokenGenerator::class;
    }

    /**
     * The public base a bearer share URL is built from (NodeShareResource appends
     * the raw token). Default '/share'.
     */
    public static function shareUrlBase(): string
    {
        $value = config('vaulter.share.url_base', '/share');

        return is_string($value) && $value !== '' ? rtrim($value, '/') : '/share';
    }

    /**
     * How many leading characters of the raw bearer token are stored as the
     * non-secret token_prefix for owner-side UX lookup (§2.7). Floored at 1.
     */
    public static function sharePrefixLength(): int
    {
        $value = config('vaulter.share.prefix_length', 8);

        $length = is_numeric($value) ? (int) $value : 8;

        return max($length, 1);
    }

    /**
     * The trash retention window in days (D17 / §2.10) before a trashed node's
     * subtree is permanently purged. Default 30, floored at 0 (0 = purge as soon
     * as a node is trashed).
     */
    public static function trashRetentionDays(): int
    {
        $value = config('vaulter.trash.retention_days', 30);

        $days = is_numeric($value) ? (int) $value : 30;

        return max($days, 0);
    }

    /**
     * The registered document handler FQCNs (config('vaulter.handlers')) — the
     * format-extensibility registry source (§4.5 / H.5). Only valid DocumentHandler
     * subclasses are returned; the config stays FQCN-only (cache-safe — no closures),
     * so a runtime-registered format works with no migration and no core change.
     *
     * @return list<class-string<DocumentHandler>>
     */
    public static function documentHandlers(): array
    {
        $value = config('vaulter.handlers', []);

        if (!is_array($value)) {
            return [];
        }

        $handlers = [];

        foreach ($value as $class) {
            if (is_string($class) && is_a($class, DocumentHandler::class, allow_string: true)) {
                $handlers[] = $class;
            }
        }

        /** @var list<class-string<DocumentHandler>> $handlers */
        return $handlers;
    }

    /**
     * @return array<string, class-string<DocumentExporter>>
     */
    public static function exporters(): array
    {
        $value = config('vaulter.exporters', []);

        if (!is_array($value)) {
            return [];
        }

        $exporters = [];

        foreach ($value as $format => $class) {
            if (is_string($format) && is_string($class) && is_a($class, DocumentExporter::class, allow_string: true)) {
                $exporters[$format] = $class;
            }
        }

        /** @var array<string, class-string<DocumentExporter>> $exporters */
        return $exporters;
    }

    /**
     * The registered storage panel provider FQCNs (config('vaulter.panels')).
     * Only valid StoragePanelProvider subclasses are returned; the config stays
     * FQCN-only (cache-safe — no closures).
     *
     * @return list<class-string<StoragePanelProvider>>
     */
    public static function panels(): array
    {
        $value = config('vaulter.panels', []);

        if (!is_array($value)) {
            return [];
        }

        $providers = [];

        foreach ($value as $class) {
            if (is_string($class) && is_a($class, StoragePanelProvider::class, allow_string: true)) {
                $providers[] = $class;
            }
        }

        /** @var list<class-string<StoragePanelProvider>> $providers */
        return $providers;
    }

    /**
     * The default storage panel provider FQCN (config('vaulter.default_panel')),
     * falling back to the built-in DefaultStoragePanel.
     *
     * @return class-string<StoragePanelProvider>
     */
    public static function defaultPanel(): string
    {
        $class = config('vaulter.default_panel', DefaultStoragePanel::class);

        if (is_string($class) && is_a($class, StoragePanelProvider::class, allow_string: true)) {
            return $class;
        }

        return DefaultStoragePanel::class;
    }

    /**
     * Whether a global feature is enabled (config('vaulter.features.{name}')).
     * When a panel name is given, the panel's own resolution wins (panel override
     * → panel feature defaults), so a panel may enable/disable a feature locally.
     */
    public static function featureEnabled(string $feature, ?string $panel = null): bool
    {
        if ($panel !== null) {
            return app(PanelRegistry::class)->get($panel)->featureEnabled($feature);
        }

        $value = config("vaulter.features.{$feature}");

        return is_bool($value) ? $value : false;
    }

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    private static function stringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
