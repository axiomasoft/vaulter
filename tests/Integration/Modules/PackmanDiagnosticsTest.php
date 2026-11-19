<?php

declare(strict_types=1);

use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Doctor\PackmanConfigValidCheck;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;

uses(TestCase::class, WithoutDatabaseSchema::class);

it('composes shared diagnostics while retaining local configuration check identities', function (): void {
    $shared = $this->app->make(DoctorRegistryContract::class)->all();
    $keys = array_map(static fn ($check): string => $check->key(), $shared);

    expect($keys)->toContain('core.config.valid', 'vaulter.config.valid')
        ->and(array_unique($keys))->toHaveCount(count($keys))
        ->and($this->app->make(DoctorRegistry::class)->matching(['core.config.valid'])[0]->key())->toBe('core.config.valid');

    $context = new DoctorContext;
    $local = $this->app->make(ConfigValidCheck::class)->run($context);
    $findings = $this->app->make(PackmanConfigValidCheck::class)->run($context);

    expect($findings)->toHaveCount(count($local))
        ->and($findings[0]->code)->toBe('vaulter.config.valid')
        ->and($local[0]->code)->toBe('core.config.valid')
        ->and(array_map(static fn (DoctorFinding $finding): string => $finding->message, $findings))
        ->toBe(array_map(static fn (DoctorFinding $finding): string => $finding->message, $local));

    $this->app['config']->set('vaulter.database.connection', []);
    $failed = $this->app->make(PackmanConfigValidCheck::class)->run($context);

    expect($failed[0]->code)->toBe('vaulter.config.invalid')
        ->and($failed[0]->message)->toContain('vaulter.database.connection');
});

it('registers drive validation through the supported configuration SPI', function (): void {
    $validator = $this->app->make('vaulter.config_validator.drive');
    $registered = $this->app->make('packman.config_validators:vaulter.drive');

    expect($registered)->toBe($validator)
        ->and(iterator_to_array($this->app->tagged('vaulter.config_validators')))->toContain($validator);
});
