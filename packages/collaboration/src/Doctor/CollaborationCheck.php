<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Doctor;

use Illuminate\Contracts\Config\Repository;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Drain\DrainState;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

/**
 * The settings of an active collaboration module. Evidence names the settings and says whether they are set; the
 * secrets themselves never leave the config. (An inactive module registers no check: it is simply not collaborating.)
 *
 * The draining flag of a managed disable must live in a cache store every PHP process shares: an `array` or `null`
 * store is an error (C10). While the flag is set, appends are refused: a warning says so.
 */
#[Internal]
final readonly class CollaborationCheck implements DoctorCheck
{
    /** Cache drivers no other process sees. */
    private const array LOCAL_CACHE_DRIVERS = ['array', 'null'];

    public function __construct(private CollaborationConfig $config, private Repository $settings, private DrainState $draining) {}

    public function key(): string
    {
        return 'collaboration.hub';
    }

    public function title(): string
    {
        return 'Real-time collaboration';
    }

    public function run(DoctorContext $context): array
    {
        $jwt = $this->config->hubJwtSecret();
        $webhook = $this->config->hubWebhookSecret();
        $url = $this->config->hubUrl();
        $store = $this->config->drainCacheStore() ?? $this->string('cache.default');
        $driver = $store === null ? null : $this->string('cache.stores.'.$store.'.driver');
        $evidence = [
            'jwt_secret_set' => $jwt !== null,
            'webhook_secret_set' => $webhook !== null,
            'hub_url_set' => $url !== null,
            'drain_cache_store' => $store,
            'drain_cache_driver' => $driver,
        ];

        $problems = [];
        if ($jwt === null || $webhook === null) {
            $problems[] = ['collaboration.hub_secrets_missing', 'The JWT secret and the webhook secret must both be set.', 'set VAULTER_COLLAB_SECRET and VAULTER_COLLAB_WEBHOOK_SECRET'];
        } elseif ($jwt === $webhook) {
            $problems[] = ['collaboration.hub_secrets_equal', 'The JWT secret and the webhook secret must differ: the hub holds the webhook secret and could sign room tokens with it.', 'use two different values'];
        }
        if ($url === null) {
            $problems[] = ['collaboration.hub_url_missing', 'The hub URL is not set.', 'set vaulter-collaboration.hub.url'];
        } elseif ($context->production && !str_starts_with($url, 'wss://')) {
            $problems[] = ['collaboration.hub_url_insecure', 'The hub URL must use wss:// in production.', 'serve the hub over TLS and use wss://'];
        }
        try {
            $this->config->hubTokenTtl();
        } catch (Throwable) {
            $problems[] = ['collaboration.hub_token_ttl', 'The room token lifetime must be between 1 and 300 seconds: it bounds how long a revoked writer keeps a session the hub was not told about.', 'set vaulter-collaboration.hub.token_ttl to at most 300'];
        }
        $shared = false;
        if ($driver === null) {
            $problems[] = ['collaboration.drain_cache_missing', sprintf('The cache store "%s" of the draining flag is not configured.', $store ?? ''), 'set vaulter-collaboration.drain.cache_store to a store of config/cache.php'];
        } elseif (in_array($driver, self::LOCAL_CACHE_DRIVERS, true)) {
            $problems[] = ['collaboration.drain_cache_local', sprintf('The draining flag of vaulter:collaboration:drain would live in the cache store "%s" (driver %s), which no other PHP process sees: their appends would go on during a drain.', $store, $driver), 'set vaulter-collaboration.drain.cache_store to a store every process shares (redis, database, memcached)'];
        } else {
            $shared = true;
        }

        $warnings = [];
        if ($shared) {
            try {
                $since = $this->draining->since();
                if ($since !== null) {
                    $warnings[] = ['collaboration.draining', sprintf('The collaboration log refuses updates: vaulter:collaboration:drain set the draining flag at %s.', $since->toDateTimeString()), 'run vaulter:collaboration:drain --release to accept updates again'];
                }
            } catch (Throwable) {
                $problems[] = ['collaboration.drain_cache_unreadable', 'The draining flag cannot be read from its cache store, so every append fails.', 'check the cache store vaulter-collaboration.drain.cache_store names'];
            }
        }

        if ($problems === [] && $warnings === []) {
            return [DoctorFinding::ok(code: 'collaboration.hub_ok', message: 'The collaboration settings are complete.', evidence: $evidence)];
        }

        return [
            ...array_map(static fn (array $problem): DoctorFinding => DoctorFinding::error(code: $problem[0], message: $problem[1], hint: $problem[2], evidence: $evidence), $problems),
            ...array_map(static fn (array $warning): DoctorFinding => DoctorFinding::warning(code: $warning[0], message: $warning[1], hint: $warning[2], evidence: $evidence), $warnings),
        ];
    }

    private function string(string $key): ?string
    {
        $value = $this->settings->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
