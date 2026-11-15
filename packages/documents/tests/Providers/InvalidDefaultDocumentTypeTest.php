<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanType;
use Vaulter\Documents\Tests\TestCase;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * vaulter-documents.default_type must name a registered type.
 * The check runs once every provider has booted, so a type a host registers in its own
 * boot() counts whichever side of the documents provider it is listed on. Each case
 * boots a fresh application; the booted registry of the running test is not touched.
 */
final class AcmeKanbanBootProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(DocumentTypeRegistry::class)->register(AcmeKanbanType::class);
    }
}

final class NotADocumentTypeTagProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('acme.not-a-document-type', static fn (): stdClass => new stdClass);
        $this->app->tag(['acme.not-a-document-type'], 'vaulter.document_types');
    }
}

/**
 * @param array<string, mixed> $config
 * @param list<class-string>   $before providers listed before the Vaulter providers
 * @param list<class-string>   $after  providers listed after them
 */
function bootFreshDocumentsApplication(array $config, array $before = [], array $after = []): Application
{
    $case = new class('freshDocumentsApplication') extends TestCase
    {
        /** @var array<string, mixed> */
        public static array $config = [];

        /** @var list<class-string> */
        public static array $before = [];

        /** @var list<class-string> */
        public static array $after = [];

        protected function getPackageProviders($app): array
        {
            return [...self::$before, ...parent::getPackageProviders($app), ...self::$after];
        }

        protected function defineEnvironment($app): void
        {
            parent::defineEnvironment($app);
            foreach (self::$config as $key => $value) {
                $app['config']->set($key, $value);
            }
        }
    };
    $case::$config = $config;
    $case::$before = $before;
    $case::$after = $after;

    $current = Container::getInstance();
    try {
        return $case->createApplication();
    } finally {
        Container::setInstance($current);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($current);
    }
}

it('refuses to boot when the default type is not registered', function (): void {
    expect(fn () => bootFreshDocumentsApplication(['vaulter-documents.default_type' => 'acme/kanban']))
        ->toThrow(InvalidConfigurationException::class, 'vaulter-documents.default_type "acme/kanban" is not a registered document type');
});

it('boots with a default type registered from config', function (): void {
    $app = bootFreshDocumentsApplication([
        'vaulter-documents.types' => [AcmeKanbanType::class],
        'vaulter-documents.default_type' => 'acme/kanban',
    ]);
    $types = $app->make(DocumentTypeRegistry::class);

    expect($types->has('acme/kanban'))->toBeTrue()
        ->and($types->isFrozen())->toBeTrue();
});

it('accepts a default type a provider registers in boot(), on either side of the documents provider', function (string $side): void {
    $app = bootFreshDocumentsApplication(
        ['vaulter-documents.default_type' => 'acme/kanban'],
        before: $side === 'before' ? [AcmeKanbanBootProvider::class] : [],
        after: $side === 'after' ? [AcmeKanbanBootProvider::class] : [],
    );

    expect($app->make(DocumentTypeRegistry::class)->get('acme/kanban'))->toBeInstanceOf(AcmeKanbanType::class);
})->with(['before', 'after']);

it('refuses to boot when the vaulter.document_types tag resolves to something that is not a type', function (): void {
    expect(fn () => bootFreshDocumentsApplication([], before: [NotADocumentTypeTagProvider::class]))
        ->toThrow(InvalidConfigurationException::class, 'Document type "stdClass" did not resolve to a');
});
