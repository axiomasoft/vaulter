<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Identities;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\PrettyPrinter\Standard;
use Vaulter\Inventory\AstFiles;
use Vaulter\Inventory\Identities;
use Vaulter\Inventory\IdentityOwners;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;

require_once dirname(__DIR__).'/Identities.php';

/**
 * identities/config.json: the config keys (dotted leaves) of packages/*\/config/*.php with their defaults, the
 * environment variables read anywhere in config and src, and the publish tags of the providers. Config files are
 * parsed, never included (they call env()); the roots that register defaults are found in the provider code.
 *
 * @phpstan-import-type Built from Identities
 */
final class Config
{
    private const SPATIE_TAGS = [
        'hasConfigFile' => 'config',
        'hasMigration' => 'migrations',
        'hasMigrations' => 'migrations',
        'discoversMigrations' => 'migrations',
        'hasTranslations' => 'translations',
        'hasViews' => 'views',
        'hasAssets' => 'assets',
    ];

    /** @return Built */
    public static function build(Repo $repo): array
    {
        $items = [];
        $roots = self::registeredRoots($repo);

        /** @var array<string, array{file: string, symbol: string, keys: list<string>, defaults: list<mixed>}> $env */
        $env = [];
        $printer = new Standard;

        foreach (self::configFiles($repo) as $file) {
            $root = basename($file, '.php');
            $array = self::returned(AstFiles::parse($repo, $file), $file);
            foreach (self::leaves($array, $root, $printer) as $path => $leaf) {
                $item = [
                    'id' => 'config:'.$path,
                    'source' => ['file' => $file, 'symbol' => '<file>'],
                    'root' => $root,
                    'default' => $leaf['value'],
                    'registered_defaults' => isset($roots[$root]),
                ];
                if ($leaf['env'] !== null) {
                    $item['env'] = $leaf['env'];
                }
                $items[] = $item;
                foreach ($leaf['envs'] as $call) {
                    $env[$call['name']] ??= ['file' => $file, 'symbol' => '<file>', 'keys' => [], 'defaults' => []];
                    $env[$call['name']]['keys'][] = $path;
                    $env[$call['name']]['defaults'][] = $call['default'];
                }
            }
        }

        foreach (self::srcFiles($repo) as $file) {
            AstFiles::walk(AstFiles::parse($repo, $file), static function (Node $node, string $symbol) use (&$env, $file, $printer): void {
                $name = self::envCall($node, $printer);
                if ($name !== null) {
                    $env[$name['name']] ??= ['file' => $file, 'symbol' => $symbol, 'keys' => [], 'defaults' => []];
                    $env[$name['name']]['defaults'][] = $name['default'];
                }
            });
        }

        ksort($env, SORT_STRING);
        foreach ($env as $name => $uses) {
            $keys = array_values(array_unique($uses['keys']));
            sort($keys, SORT_STRING);
            $defaults = [];
            foreach ($uses['defaults'] as $default) {
                $defaults[json_encode($default, JSON_THROW_ON_ERROR)] = $default;
            }
            ksort($defaults, SORT_STRING);
            $items[] = [
                'id' => 'env:'.$name,
                '_owner_key' => $keys === [] ? 'env:'.$name : 'config:'.$keys[0],
                'source' => ['file' => $uses['file'], 'symbol' => $uses['symbol']],
                'config_keys' => $keys,
                'defaults' => array_values($defaults),
            ];
        }

        foreach (self::publishTags($repo) as $tag => $source) {
            $items[] = ['id' => 'publish:'.$tag, 'source' => ['file' => $source['file'], 'symbol' => $source['symbol']], 'via' => $source['via']];
        }

        return Identities::assemble('config', $items, IdentityOwners::load($repo, 'config'), ['roots' => array_keys($roots)]);
    }

    /**
     * Every leaf of every config file: dotted path => {file, value}; the defaults other inventories read.
     *
     * @return array<string, array{file: string, value: mixed}>
     */
    public static function defaults(Repo $repo): array
    {
        $printer = new Standard;
        $defaults = [];
        foreach (self::configFiles($repo) as $file) {
            foreach (self::leaves(self::returned(AstFiles::parse($repo, $file), $file), basename($file, '.php'), $printer) as $path => $leaf) {
                $defaults[$path] = ['file' => $file, 'value' => $leaf['value']];
            }
        }
        ksort($defaults, SORT_STRING);

        return $defaults;
    }

    /** A constant expression as JSON-able data (the same rules as config defaults). */
    public static function evaluate(Expr $expr): mixed
    {
        $envs = [];

        return self::value($expr, new Standard, $envs);
    }

    /**
     * Config key => default (bool) of every `vaulter-drive.features.*` flag: the switches that can mount routes.
     *
     * @return array<string, bool>
     */
    public static function featureFlags(Repo $repo): array
    {
        $file = 'packages/drive/config/vaulter-drive.php';
        $flags = [];
        foreach (self::leaves(self::returned(AstFiles::parse($repo, $file), $file), 'vaulter-drive', new Standard) as $path => $leaf) {
            if (str_starts_with($path, 'vaulter-drive.features.') && is_bool($leaf['value'])) {
                $flags[$path] = $leaf['value'];
            }
        }
        ksort($flags, SORT_STRING);

        return $flags;
    }

    /** @return list<string> packages/<p>/config/*.php, sorted */
    private static function configFiles(Repo $repo): array
    {
        return AstFiles::files($repo, 'packages/*/config/*.php');
    }

    /** @return list<string> every PHP file of packages/<p>/src */
    private static function srcFiles(Repo $repo): array
    {
        $files = [];
        foreach ($repo->sourcePackages() as $package) {
            array_push($files, ...AstFiles::tree($repo, "packages/{$package}/src"));
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The roots that call `ConfigNormalizer::registerDefaults` (`root:` named or first positional string), and the
     * roots a module manifest declares with `ModuleBuilder::config('root', $file)` (the kernel registers their defaults).
     *
     * @return array<string, string> root => file
     */
    private static function registeredRoots(Repo $repo): array
    {
        $roots = [];
        foreach (self::srcFiles($repo) as $file) {
            AstFiles::walk(AstFiles::parse($repo, $file), static function (Node $node, string $symbol) use (&$roots, $file): void {
                $root = self::manifestCall($node, $symbol, 'config');
                if ($root !== null) {
                    $roots[$root] = $file;

                    return;
                }
                if (!$node instanceof Expr\MethodCall || !$node->name instanceof Node\Identifier || $node->name->toString() !== 'registerDefaults') {
                    return;
                }
                foreach ($node->args as $position => $arg) {
                    if (!$arg instanceof Node\Arg || !$arg->value instanceof Scalar\String_) {
                        continue;
                    }
                    if (($arg->name !== null && $arg->name->toString() === 'root') || ($arg->name === null && $position === 0)) {
                        $roots[$arg->value->value] = $file;
                    }
                }
            });
        }
        ksort($roots, SORT_STRING);

        return $roots;
    }

    /**
     * @param list<Node\Stmt> $stmts
     */
    private static function returned(array $stmts, string $file): Expr\Array_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Expr\Array_) {
                return $stmt->expr;
            }
        }

        throw new InventoryError("{$file}: no top-level `return [...]`");
    }

    /**
     * Dotted leaves of a config array: an array whose keys are all strings is a section, anything else is a value.
     *
     * @return array<string, array{value: mixed, env: ?string, envs: list<array{name: string, default: mixed}>}>
     */
    private static function leaves(Expr\Array_ $array, string $prefix, Standard $printer): array
    {
        $leaves = [];
        foreach ($array->items as $item) {
            if (!$item->key instanceof Scalar\String_) {
                continue;
            }
            $path = $prefix.'.'.$item->key->value;
            if (self::isSection($item->value)) {
                /** @var Expr\Array_ $section */
                $section = $item->value;
                $leaves += self::leaves($section, $path, $printer);

                continue;
            }
            $envs = [];
            $value = self::value($item->value, $printer, $envs);
            $leaves[$path] = ['value' => $value, 'env' => self::envCall($item->value, $printer)['name'] ?? null, 'envs' => $envs];
        }
        ksort($leaves, SORT_STRING);

        return $leaves;
    }

    private static function isSection(Expr $expr): bool
    {
        if (!$expr instanceof Expr\Array_ || $expr->items === []) {
            return false;
        }
        foreach ($expr->items as $item) {
            if (!$item->key instanceof Scalar\String_) {
                return false;
            }
        }

        return true;
    }

    /**
     * A JSON-normalized default: scalars as they are, `X::class` as the FQCN string, `env('A', d)` as
     * `{env, default}`, anything computed as `{expr: <code>}`.
     *
     * @param list<array{name: string, default: mixed}> $envs the env() calls met, filled
     */
    private static function value(Expr $expr, Standard $printer, ?array &$envs = null): mixed
    {
        if ($expr instanceof Scalar\String_ || $expr instanceof Scalar\Int_ || $expr instanceof Scalar\Float_) {
            return $expr->value;
        }
        if ($expr instanceof Expr\UnaryMinus && ($expr->expr instanceof Scalar\Int_ || $expr->expr instanceof Scalar\Float_)) {
            return -$expr->expr->value;
        }
        if ($expr instanceof Expr\ConstFetch) {
            return match (strtolower($expr->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => ['const' => $expr->name->toString()],
            };
        }
        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'class') {
            return ltrim($expr->class->toString(), '\\');
        }
        if ($expr instanceof Expr\Array_) {
            $out = [];
            foreach ($expr->items as $item) {
                $value = self::value($item->value, $printer, $envs);
                if ($item->key === null) {
                    $out[] = $value;
                } else {
                    $key = self::value($item->key, $printer, $envs);
                    $out[is_scalar($key) ? (string) $key : $printer->prettyPrintExpr($item->key)] = $value;
                }
            }

            return $out;
        }
        $call = self::envCall($expr, $printer, $envs);
        if ($call !== null) {
            return $call['has_default'] ? ['default' => $call['default'], 'env' => $call['name']] : ['env' => $call['name']];
        }

        return ['expr' => $printer->prettyPrintExpr($expr)];
    }

    /**
     * `env('NAME', default)` as {name, default, has_default}; nested env() calls of the default go to $envs too.
     *
     * @param  list<array{name: string, default: mixed}>|null              $envs
     * @return array{name: string, default: mixed, has_default: bool}|null
     */
    private static function envCall(Node $node, Standard $printer, ?array &$envs = null): ?array
    {
        if (!$node instanceof Expr\FuncCall || !$node->name instanceof Node\Name || $node->name->toString() !== 'env') {
            return null;
        }
        $first = $node->args[0] ?? null;
        if (!$first instanceof Node\Arg || !$first->value instanceof Scalar\String_) {
            return null;
        }
        $second = $node->args[1] ?? null;
        $hasDefault = $second instanceof Node\Arg;
        $nested = [];
        $default = $second instanceof Node\Arg ? self::value($second->value, $printer, $nested) : null;
        if ($envs !== null) {
            $envs[] = ['name' => $first->value->value, 'default' => $default];
            foreach ($nested as $call) {
                $envs[] = $call;
            }
        }

        return ['name' => $first->value->value, 'default' => $default, 'has_default' => $hasDefault];
    }

    /**
     * Publish tags: `publishes([...], 'tag')` calls, spatie/laravel-package-tools chains
     * (`->name('x')->hasConfigFile()…` publishes `x-config`, `x-migrations`, `x-translations`, `x-views`), and the
     * declarations of module manifests the kernel publishes: `config('root', …)` as `root-config`. The migrations of a
     * module are never published: `schema(…)` is no tag.
     *
     * @return array<string, array{file: string, symbol: string, via: string}>
     */
    private static function publishTags(Repo $repo): array
    {
        $tags = [];
        foreach (self::srcFiles($repo) as $file) {
            $stmts = AstFiles::parse($repo, $file);
            $moduleIds = self::moduleIds($stmts);
            AstFiles::walk($stmts, static function (Node $node, string $symbol) use (&$tags, $file): void {
                $root = self::manifestCall($node, $symbol, 'config');
                if ($root !== null) {
                    $tags[$root.'-config'] ??= ['file' => $file, 'symbol' => $symbol, 'via' => 'module:config'];

                    return;
                }
                if (!$node instanceof Expr\MethodCall || !$node->name instanceof Node\Identifier) {
                    return;
                }
                $method = $node->name->toString();
                if ($method === 'publishes') {
                    $tag = $node->args[1] ?? null;
                    if ($tag instanceof Node\Arg && $tag->value instanceof Scalar\String_) {
                        $tags[$tag->value->value] ??= ['file' => $file, 'symbol' => $symbol, 'via' => 'publishes'];
                    }

                    return;
                }
                // visited outermost first: the first call of a chain already sees the whole chain below it
                $chain = [];
                for ($call = $node; $call instanceof Expr\MethodCall; $call = $call->var) {
                    if ($call->name instanceof Node\Identifier) {
                        $chain[] = $call;
                    }
                }
                foreach ($chain as $at => $call) {
                    $arg = $call->args[0] ?? null;
                    if ($call->name instanceof Node\Identifier && $call->name->toString() === 'name' && $arg instanceof Node\Arg && $arg->value instanceof Scalar\String_) {
                        $short = preg_replace('/^laravel-/', '', $arg->value->value) ?? $arg->value->value;
                        foreach (array_slice($chain, 0, $at) as $later) {
                            $called = $later->name instanceof Node\Identifier ? $later->name->toString() : '';
                            if (isset(self::SPATIE_TAGS[$called])) {
                                $tags[$short.'-'.self::SPATIE_TAGS[$called]] ??= ['file' => $file, 'symbol' => $symbol, 'via' => 'spatie:'.$called];
                            }
                        }
                    }
                }
            });
        }
        ksort($tags, SORT_STRING);

        return $tags;
    }

    /**
     * A `$method(…)` declaration inside a module manifest's `register()` (a class named `*Module`): the first
     * argument when it is a string literal, `''` for any first argument when `$literal` is false, null otherwise.
     */
    private static function manifestCall(Node $node, string $symbol, string $method, bool $literal = true): ?string
    {
        if (!$node instanceof Expr\MethodCall || !$node->name instanceof Node\Identifier || $node->name->toString() !== $method) {
            return null;
        }
        [$class, $function] = array_pad(explode('::', $symbol, 2), 2, null);
        if ($function !== 'register' || !str_ends_with($class, 'Module')) {
            return null;
        }
        $first = $node->args[0] ?? null;
        if (!$first instanceof Node\Arg) {
            return null;
        }
        if (!$literal) {
            return $first->value instanceof Scalar\String_ ? $first->value->value : '';
        }

        return $first->value instanceof Scalar\String_ ? $first->value->value : null;
    }

    /**
     * Class => module id of the manifests in a file: the literal of `new ModuleId('<id>')` in their `id()`.
     *
     * @param  list<Node\Stmt>       $stmts
     * @return array<string, string>
     */
    private static function moduleIds(array $stmts): array
    {
        $ids = [];
        AstFiles::walk($stmts, static function (Node $node, string $symbol) use (&$ids): void {
            [$class, $function] = array_pad(explode('::', $symbol, 2), 2, null);
            if ($function !== 'id' || !$node instanceof Expr\New_ || !$node->class instanceof Node\Name || $node->class->getLast() !== 'ModuleId') {
                return;
            }
            $arg = $node->args[0] ?? null;
            if ($arg instanceof Node\Arg && $arg->value instanceof Scalar\String_) {
                $ids[$class] = $arg->value->value;
            }
        });

        return $ids;
    }
}
