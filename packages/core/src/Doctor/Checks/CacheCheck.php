<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;

#[Internal]
final readonly class CacheCheck implements DoctorCheck
{
    public function __construct(private Repository $config, private Factory $cache) {}

    public function key(): string
    {
        return 'core.cache';
    }

    public function title(): string
    {
        return 'Cache store';
    }

    public function run(DoctorContext $context): array
    {
        $default = self::text($this->config->get('cache.default'), 'array');
        $driver = self::text($this->config->get('cache.stores.'.$default.'.driver'), $default);
        $evidence = ['store' => $default, 'driver' => $driver];

        if (in_array($driver, ['array', 'file', 'null'], true)) {
            return [DoctorFinding::warning(
                code: 'core.cache.not_shared',
                message: 'The default cache is per process or per host: replay protection and rate limits do not hold across instances.',
                remediation: 'use redis, memcached, database or dynamodb as CACHE_STORE',
                evidence: $evidence,
            )];
        }

        $store = $this->cache->store();
        if (!$store instanceof CacheRepository || !$store->getStore() instanceof LockProvider) {
            return [DoctorFinding::warning(
                code: 'core.cache.no_atomic_locks',
                message: 'The default cache store has no atomic locks: scheduled tasks cannot run on one server only.',
                remediation: 'use a cache store that supports locks',
                evidence: $evidence,
            )];
        }

        return [DoctorFinding::ok(code: 'core.cache.shared', message: 'The default cache is shared and supports atomic locks.', evidence: $evidence)];
    }

    private static function text(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
