<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Closure;
use Override;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Events\Outbox\Models\OutboxEvent;

/**
 * Immutable compiled configuration snapshot for the `vaulter` kernel config root.
 */
#[Api(since: '1.0')]
final readonly class VaulterOptions implements OptionsFactory
{
    /**
     * @param array<string, bool> $modules
     */
    public function __construct(
        public DatabaseOptions $database = new DatabaseOptions,
        public IdsOptions $ids = new IdsOptions,
        public TenancyOptions $tenancy = new TenancyOptions,
        public SecurityOptions $security = new SecurityOptions,
        public array $modules = [],
        public RoutesOptions $routes = new RoutesOptions,
        public OutboxOptions $outbox = new OutboxOptions,
        public IdempotencyOptions $idempotency = new IdempotencyOptions,
        public ScheduleOptions $schedule = new ScheduleOptions,
        public ObservabilityOptions $observability = new ObservabilityOptions,
        public ModelsOptions $models = new ModelsOptions,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter'): self
    {
        $validator = new ConfigurationValidator;

        self::assertNoClosures($config, $path, $validator);

        if (array_key_exists('features', $config) && is_array($config['features']) && array_key_exists('webhooks', $config['features'])) {
            throw InvalidConfiguration::forMessage('[Vaulter] vaulter.features.webhooks was removed: webhooks are a module, switch them with vaulter.modules.webhooks.');
        }

        $validator->rejectUnknownKeys(
            $config,
            ['database', 'ids', 'tenancy', 'security', 'modules', 'routes', 'outbox', 'idempotency', 'schedule', 'observability', 'models'],
            $path,
        );

        $modules = self::modulesMap($validator, $path.'.modules', $config['modules'] ?? []);

        $sectionArrays = [];
        foreach (['database', 'ids', 'tenancy', 'security', 'routes', 'outbox', 'idempotency', 'schedule', 'observability', 'models'] as $section) {
            $raw = $config[$section] ?? [];
            if (!is_array($raw)) {
                $validator->add(path: $path.'.'.$section, message: 'must be an array');
                $raw = [];
            }
            $sectionArrays[$section] = $raw;
        }

        $validator->failIfAny();

        return new self(
            database: DatabaseOptions::fromArray($sectionArrays['database'], $path.'.database'),
            ids: IdsOptions::fromArray($sectionArrays['ids'], $path.'.ids'),
            tenancy: TenancyOptions::fromArray($sectionArrays['tenancy'], $path.'.tenancy'),
            security: SecurityOptions::fromArray($sectionArrays['security'], $path.'.security'),
            modules: $modules,
            routes: RoutesOptions::fromArray($sectionArrays['routes'], $path.'.routes'),
            outbox: OutboxOptions::fromArray($sectionArrays['outbox'], $path.'.outbox'),
            idempotency: IdempotencyOptions::fromArray($sectionArrays['idempotency'], $path.'.idempotency'),
            schedule: ScheduleOptions::fromArray($sectionArrays['schedule'], $path.'.schedule'),
            observability: ObservabilityOptions::fromArray($sectionArrays['observability'], $path.'.observability'),
            models: ModelsOptions::fromArray($sectionArrays['models'], $path.'.models'),
        );
    }

    /** @return class-string<OutboxEvent> */
    #[Internal]
    public function outboxModel(): string
    {
        return $this->models->outbox;
    }

    /**
     * @return array<string, bool>
     */
    private static function modulesMap(ConfigurationValidator $validator, string $path, mixed $config): array
    {
        if (!is_array($config)) {
            $validator->add(path: $path, message: 'must be an array of module id => bool');

            return [];
        }

        $modules = [];
        foreach ($config as $id => $enabled) {
            if (!is_string($id) || $id === '' || !is_bool($enabled)) {
                $validator->add(path: $path.'.'.$id, message: 'must map module id to boolean');

                continue;
            }
            $modules[$id] = $enabled;
        }

        return $modules;
    }

    private static function assertNoClosures(mixed $value, string $key, ConfigurationValidator $validator): void
    {
        if ($value instanceof Closure) {
            $validator->add(path: $key, message: 'contains a Closure; use a FQCN or scalar value');

            return;
        }

        if (is_array($value)) {
            foreach ($value as $name => $item) {
                self::assertNoClosures($item, $key.'.'.$name, $validator);
            }
        }
    }
}
