<?php

declare(strict_types=1);

/*
 * scripts/php84 php scripts/inventory/boot/routes.php [--set=<config.key>=<json value>…]
 *
 * Boots a Testbench application with the core, media, drive, documents and webhooks providers
 * and prints the registered routes as one JSON list on stdout. A separate process by design:
 * the inventory itself never boots Laravel (database-free, CLI exit 2 on an unusable boot).
 */

use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;
use Vaulter\Webhooks\VaulterWebhooksServiceProvider;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

/** Sets config values before the Vaulter providers register (existing-wins merges then keep them). */
final class RouteBootConfigProvider extends ServiceProvider
{
    /** @var array<string, mixed> */
    public static array $values = [];

    public function register(): void
    {
        foreach (self::$values as $key => $value) {
            $this->app->make('config')->set($key, $value);
        }
    }
}

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--set=([^=]+)=(.*)$/s', $argument, $m) !== 1) {
        fwrite(STDERR, "unknown argument {$argument}\n");
        exit(2);
    }
    RouteBootConfigProvider::$values[$m[1]] = json_decode($m[2], true, flags: JSON_THROW_ON_ERROR);
}

$app = Testbench::create(basePath: null, options: ['extra' => ['providers' => [
    RouteBootConfigProvider::class,
    VaulterServiceProvider::class,
    VaulterMediaServiceProvider::class,
    VaulterDriveServiceProvider::class,
    VaulterDocumentsServiceProvider::class,
    VaulterWebhooksServiceProvider::class,
]], 'load_environment_variables' => false]);

$routes = [];
foreach ($app['router']->getRoutes()->getRoutes() as $route) {
    /** @var Route $route */
    $action = $route->getActionName();
    $uri = $route->uri();
    if (!str_starts_with($uri, 'api/') && $uri !== 'api') {
        // framework routes of the Testbench skeleton (e.g. up) are not Vaulter's
        $name = $route->getName();
        if ($name === null || !str_starts_with($name, 'vaulter.')) {
            continue;
        }
    }
    $middleware = array_map(static fn (mixed $m): string => is_string($m) ? $m : (is_object($m) ? $m::class : gettype($m)), $route->gatherMiddleware());
    $routes[] = [
        'name' => $route->getName(),
        // Laravel adds HEAD to every GET route; a route that is HEAD only keeps it
        'methods' => in_array('GET', $route->methods(), true) ? array_values(array_diff($route->methods(), ['HEAD'])) : $route->methods(),
        'uri' => $uri,
        'middleware' => $middleware,
        'action' => $action,
    ];
}

echo json_encode($routes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
