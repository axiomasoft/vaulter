<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/** @param Closure(): void $callback @return list<string> */
function collectConfigurationDeprecations(Closure $callback): array
{
    $messages = [];
    set_error_handler(static function (int $severity, string $message) use (&$messages): bool {
        if ($severity === E_USER_DEPRECATED) {
            $messages[] = $message;

            return true;
        }

        return false;
    });
    try {
        $callback();
    } finally {
        restore_error_handler();
    }

    return $messages;
}

it('fills the missing nested defaults and keeps what the host set', function (): void {
    $defaults = ['vaulter' => ['database' => ['connection' => null, 'table_prefix' => 'v_'], 'tenancy' => ['enabled' => false]]];
    $config = new Repository(['vaulter' => ['database' => ['table_prefix' => 'custom_']]]);

    (new ConfigNormalizer)->normalize($config, $defaults);

    expect($config->get('vaulter.database.table_prefix'))->toBe('custom_')
        ->and($config->get('vaulter.tenancy.enabled'))->toBeFalse()
        ->and($config->has('vaulter.database.connection'))->toBeTrue();
});

it('neither translates nor warns about the keys of earlier development releases', function (string $key, mixed $value, string $canonical): void {
    $config = new Repository;
    $config->set($key, $value);
    $normalizer = new ConfigNormalizer;

    $messages = collectConfigurationDeprecations(static fn () => $normalizer->normalize($config, ['vaulter' => ['database' => ['table_prefix' => 'v_']], 'vaulter-media' => ['dedup' => ['scope' => 'tenant']]]));

    expect($messages)->toBe([])
        ->and($config->get($key))->toBe($value)
        ->and($config->has($canonical) ? $config->get($canonical) : null)->not->toBe($value);
})->with([
    'table_prefix' => ['vaulter.table_prefix', 'old_', 'vaulter.database.table_prefix'],
    'dedup scope' => ['vaulter.dedup.scope', 'drive', 'vaulter-media.dedup.scope'],
]);

it('preserves the host replacement map instead of merging the shipped entries back', function (): void {
    $defaults = ['vaulter' => ['access' => ['permission_drivers' => ['database' => 'BuiltIn\\Driver', 'gate' => 'BuiltIn\\Gate']]]];
    $config = new Repository($defaults);
    $config->set('vaulter.access.permission_drivers', ['custom' => 'Host\\CustomDriver']);

    (new ConfigNormalizer)->normalize($config, $defaults);

    expect($config->get('vaulter.access.permission_drivers'))->toBe(['custom' => 'Host\\CustomDriver']);
});

it('keeps an explicit vaulter-documents.types list from growing default entries back (PLAN2.P4.3)', function (): void {
    $config = new Repository(['vaulter-documents' => ['types' => ['Host\\KanbanDocumentType']]]);
    $defaults = ['vaulter-documents' => ['types' => ['Vaulter\\Documents\\Types\\RichTextDocumentType', 'Vaulter\\Documents\\Types\\MarkdownDocumentType']]];

    (new ConfigNormalizer)->normalize($config, $defaults);

    expect($config->get('vaulter-documents.types'))->toBe(['Host\\KanbanDocumentType']);
});

it('retains module defaults registered before the core provider', function (): void {
    $previousContainer = Container::getInstance();
    try {
        $application = new Application(__DIR__);
        $application->instance('config', new Repository);
        $application->register(VaulterMediaServiceProvider::class);
        $application->register(VaulterServiceProvider::class);
        // Module defaults are registered when the module graph is applied, after every provider registered.
        $application->singleton(Kernel::class, Illuminate\Foundation\Http\Kernel::class);
        $application->singleton(ExceptionHandler::class, Handler::class);
        $application->boot();

        expect(array_keys($application->make(ConfigNormalizer::class)->packageDefaults()))->toEqualCanonicalizing(['vaulter-media', 'vaulter']);
    } finally {
        Container::setInstance($previousContainer);
    }
});

it('retains default exporter formats alongside host overrides', function (array $override): void {
    $defaults = ['vaulter-documents' => ['exporters' => ['html' => 'BuiltIn\\HtmlExporter', 'markdown' => 'BuiltIn\\MarkdownExporter']]];
    $config = new Repository;
    $config->set('vaulter-documents.exporters', $override);

    (new ConfigNormalizer)->normalize($config, $defaults);

    expect($config->get('vaulter-documents.exporters'))->toBe(array_replace($defaults['vaulter-documents']['exporters'], $override));
})->with([
    [['html' => 'Host\\HtmlExporter']],
    [[]],
]);
