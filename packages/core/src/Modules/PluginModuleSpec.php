<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Operations\OperationPolicySource;

/**
 * Vaulter domain runtime specification accompanying an {@see ExtensionPlugin} manifest offered through {@see ModuleCatalog}.
 */
#[Api(since: '1.0')]
final readonly class PluginModuleSpec
{
    /**
     * @param array<string, string>|null                               $requires        protocol constraints, when a host uses Vaulter protocol versioning
     * @param class-string<ExtensionPlugin>                            $plugin
     * @param list<string>                                             $routes
     * @param array<string, string>                                    $translations    namespace => directory
     * @param list<class-string>                                       $commands
     * @param list<array{event: class-string, listener: class-string}> $listeners
     * @param list<class-string<ModuleSchedule>>                       $schedules
     * @param array<string, class-string>                              $routeMiddleware alias => middleware
     * @param list<class-string<ModuleBindings>>                       $bindings
     * @param list<class-string>                                       $events
     * @param list<class-string<ProblemCodeSource>>                    $problems
     * @param list<class-string<OperationPolicySource>>                $operations
     * @param class-string<ModuleReferences>|null                      $heldReferences
     * @param class-string<RemovalGuard>|null                          $removalGuard
     */
    public function __construct(
        public string $plugin,
        public Activation $activation = Activation::Auto,
        public ?string $protocolVersion = null,
        public ?array $requires = null,
        public array $routes = [],
        public array $translations = [],
        public array $commands = [],
        public array $listeners = [],
        public array $schedules = [],
        public array $routeMiddleware = [],
        public array $bindings = [],
        public array $events = [],
        public array $problems = [],
        public array $operations = [],
        public ?string $heldReferences = null,
        public ?string $removalGuard = null,
    ) {}
}
