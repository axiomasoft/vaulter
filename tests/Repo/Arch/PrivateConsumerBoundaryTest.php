<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/*
 * The private add-on boundary (P3.8): the production and shipped Testing code of the private consumer package may only
 * name lower-package types (and, for static calls, constants, constructors and Internal constructors) that the surface
 * manifests publish, and every string service id it hands to the container is declared in a finite map. The scanner is
 * an AST walk over resolved names: aliases, fully qualified names and members are all seen. It proves the static edges,
 * not the PHP dynamic call graph.
 */

const PCB_ROOT = __DIR__.'/../../..';

/** @return array<string, array<string, true>> stable type => its published members */
function pcbSurface(): array
{
    $surface = [];
    foreach (glob(PCB_ROOT.'/packages/*/tests/Arch/__snapshots__/surface.txt') ?: [] as $file) {
        $type = null;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line[0] !== ' ') {
                $type = strtok($line, ' ');
                $surface[$type] ??= [];

                continue;
            }
            if ($type !== null && preg_match('/(?:function\s+&?(\w+)|\$(\w+)|const\s+(?:[\w?|\\\\]+\s+)?(\w+)|case\s+(\w+))/', $line, $m) === 1) {
                $surface[$type][array_values(array_filter(array_slice($m, 1)))[0]] = true;
            }
        }
    }

    return $surface;
}

/** @return list<string> the exact violations of one source against the published surface and the declared dynamic ids */
function pcbScan(string $source, string $label, array $surface, array $declaredDynamic = [], int &$edges = 0, array &$usedDynamic = []): array
{
    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $traverser = new NodeTraverser(new NameResolver);
    $nodes = $traverser->traverse($nodes);
    $finder = new NodeFinder;
    $violations = [];
    $skip = [];
    foreach ($finder->findInstanceOf($nodes, Node\Stmt\Namespace_::class) as $namespace) {
        if ($namespace->name !== null) {
            $skip[spl_object_id($namespace->name)] = true;
        }
    }
    foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
        if ($call->name instanceof Node\Name) {
            $skip[spl_object_id($call->name)] = true;
        }
    }
    $foreign = static fn (string $name): bool => str_starts_with($name, 'Vaulter\\') && !str_starts_with($name, 'Vaulter\\Collaboration\\');
    $named = static fn (mixed $name): ?string => $name instanceof Node\Name ? ($name->getAttribute('resolvedName') ?? $name)->toString() : null;

    foreach ($finder->findInstanceOf($nodes, Node\Name::class) as $name) {
        $fqcn = $named($name);
        if ($fqcn === null || isset($skip[spl_object_id($name)]) || !$foreign($fqcn)) {
            continue;
        }
        $edges++;
        if (!isset($surface[$fqcn])) {
            $violations[] = "$label: type $fqcn is not on the published surface";
        }
    }
    foreach ($finder->find($nodes, static fn (Node $n): bool => $n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\ClassConstFetch || $n instanceof Node\Expr\StaticPropertyFetch) as $use) {
        $fqcn = $named($use->class);
        $member = $use->name instanceof Node\Identifier ? $use->name->toString() : (($use->name instanceof Node\VarLikeIdentifier) ? $use->name->toString() : null);
        if ($fqcn === null || !$foreign($fqcn) || !isset($surface[$fqcn]) || $member === null || $member === 'class') {
            continue;
        }
        $edges++;
        if (!isset($surface[$fqcn][$member])) {
            $violations[] = "$label: member $fqcn::$member is not on the published surface";
        }
    }
    foreach ($finder->findInstanceOf($nodes, Node\Expr\New_::class) as $new) {
        $fqcn = $named($new->class);
        if ($fqcn !== null && $foreign($fqcn) && isset($surface[$fqcn]) && pcbInternalConstructor($fqcn)) {
            $violations[] = "$label: constructor of $fqcn is Internal";
        }
    }
    foreach ($finder->find($nodes, static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\FuncCall) as $call) {
        $method = $call instanceof Node\Expr\MethodCall ? ($call->name instanceof Node\Identifier ? $call->name->toString() : '') : ($call->name instanceof Node\Name ? $call->name->toString() : '');
        if (!in_array($method, ['make', 'bind', 'singleton', 'tag', 'app', 'tagged'], true)) {
            continue;
        }
        $arg = $call->args[$method === 'tag' ? 1 : 0]->value ?? null;
        if ($arg === null || $arg instanceof Node\Expr\ClassConstFetch || ($arg instanceof Node\Expr\Closure)) {
            continue;
        }
        if (!$arg instanceof Node\Scalar\String_) {
            $violations[] = "$label: unknown dynamic target in $method() at line {$call->getStartLine()}";

            continue;
        }
        $usedDynamic[$arg->value] = true;
        if (!in_array(['consumer' => $label, 'target' => $arg->value], $declaredDynamic, true)) {
            $violations[] = "$label: dynamic id '{$arg->value}' in $method() is not declared";
        }
    }
    // A stable (#[Api]/#[Spi]) type of the scanned source must not leak a foreign non-published type in a public signature.
    foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $type) {
        $stable = array_filter($type->attrGroups, static fn ($g): bool => array_filter($g->attrs, static fn ($a): bool => in_array($a->name->getLast(), ['Api', 'Spi'], true)) !== []);
        if ($stable === []) {
            continue;
        }
        foreach ($finder->findInstanceOf($type->stmts, Node\Stmt\ClassMethod::class) as $method) {
            if (!$method->isPublic()) {
                continue;
            }
            foreach (array_merge($method->params, [$method->returnType]) as $item) {
                $typeNode = $item instanceof Node\Param ? $item->type : $item;
                foreach ($typeNode === null ? [] : $finder->findInstanceOf([$typeNode], Node\Name::class) as $name) {
                    $fqcn = $named($name);
                    if ($fqcn !== null && $foreign($fqcn) && !isset($surface[$fqcn])) {
                        $violations[] = "$label: private type $fqcn in a stable signature";
                    }
                }
            }
        }
    }

    return $violations;
}

function pcbInternalConstructor(string $fqcn): bool
{
    foreach (['Vaulter\\Drive\\' => 'drive', 'Vaulter\\Documents\\' => 'documents', 'Vaulter\\Media\\' => 'media', 'Vaulter\\Azgard\\' => 'azgard', 'Vaulter\\' => 'core'] as $prefix => $package) {
        if (!str_starts_with($fqcn, $prefix)) {
            continue;
        }
        $file = PCB_ROOT.'/packages/'.$package.'/src/'.str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php';
        if (!is_file($file)) {
            continue;
        }
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassMethod::class) as $method) {
            if ($method->name->toString() === '__construct') {
                foreach ($method->attrGroups as $group) {
                    foreach ($group->attrs as $attr) {
                        if ($attr->name->getLast() === 'Internal') {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    return false;
}

/** @return array{roots: list<string>, dynamic: list<array{consumer: string, target: string}>} */
function pcbMap(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/private-consumer-dependencies.json'), true, flags: JSON_THROW_ON_ERROR);
}

function pcbFiles(array $roots): array
{
    $files = [];
    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PCB_ROOT.'/'.$root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

it('keeps every production and shipped Testing edge of the private consumer on the published surface', function (): void {
    $map = pcbMap();
    $surface = pcbSurface();
    $edges = 0;
    $used = [];
    $violations = [];
    $files = pcbFiles($map['roots']);
    foreach ($files as $file) {
        $label = ltrim(substr((string) realpath($file), strlen((string) realpath(PCB_ROOT))), '/');
        $violations = [...$violations, ...pcbScan((string) file_get_contents($file), $label, $surface, $map['dynamic'], $edges, $used)];
    }

    expect($files)->not->toBeEmpty()
        ->and($edges)->toBeGreaterThan(0)
        ->and($surface)->not->toBeEmpty()
        ->and($violations)->toBe([]);

    // Declarations must be exact: no wildcard, none for a file that is gone or an id it no longer uses.
    foreach ($map['dynamic'] as $declared) {
        expect($declared['target'])->not->toContain('*')
            ->and(is_file(PCB_ROOT.'/'.$declared['consumer']))->toBeTrue()
            ->and(isset($used[$declared['target']]))->toBeTrue();
    }
});

it('rejects the negative fixtures and accepts the positive ones', function (string $file): void {
    $surface = pcbSurface();
    $source = (string) file_get_contents($file);
    $declared = [['consumer' => 'fixture', 'target' => 'vaulter.declared_key']];
    $violations = pcbScan($source, 'fixture', $surface, $declared);

    if (str_starts_with(basename($file), 'ok-')) {
        expect($violations)->toBe([]);
    } else {
        expect($violations)->not->toBe([]);
    }
})->with(fn (): array => array_map(static fn (string $f): array => [$f], glob(__DIR__.'/Fixtures/private-consumer-boundary/*.php.stub') ?: []));

it('fails on an empty scan, a stale or wildcard declaration and an unreadable map', function (): void {
    $surface = pcbSurface();
    $edges = 0;
    pcbScan('<?php namespace Vaulter\\Collaboration; final class Nothing {}', 'empty', $surface, [], $edges);
    expect($edges)->toBe(0);

    $used = [];
    pcbScan("<?php namespace Vaulter\\Collaboration; function f(\$app) { \$app->make('vaulter.real'); }", 'f', $surface, [['consumer' => 'f', 'target' => 'vaulter.real']], $edges, $used);
    $stale = ['consumer' => 'f', 'target' => 'vaulter.gone'];
    $wildcard = ['consumer' => 'f', 'target' => 'vaulter.*'];

    expect(isset($used['vaulter.real']))->toBeTrue()
        ->and(isset($used[$stale['target']]))->toBeFalse()
        ->and(str_contains($wildcard['target'], '*'))->toBeTrue()
        ->and(pcbScan("<?php namespace Vaulter\\Collaboration; function f(\$app) { \$app->make('vaulter.real'); }", 'f', $surface, [$wildcard])[0] ?? '')->toContain('is not declared');
});

it('keeps the seven kernel seams allowed while the Internal scheduling callback, tier setter and constructors stay rejected', function (): void {
    $surface = pcbSurface();
    $ok = pcbScan('<?php namespace Vaulter\\Collaboration; use Vaulter\\Providers\\SchedulingRegistrar; use Vaulter\\Support\\LoadTier; use Vaulter\\Modules\\RemovalGuard; function f() { SchedulingRegistrar::task(); LoadTier::label(); }', 'ok', $surface);
    $bad = pcbScan("<?php namespace Vaulter\\Collaboration; use Vaulter\\Providers\\SchedulingRegistrar; use Vaulter\\Support\\LoadTier; use Vaulter\\Configuration\\ScheduleConfig; function f(\$a, \$c) { SchedulingRegistrar::whenScheduling(\$a, \$c); LoadTier::set('M'); LoadTier::REQUEST_ATTRIBUTE; new ScheduleConfig(\$c); }", 'bad', $surface);

    expect($ok)->toBe([])
        ->and(implode("\n", $bad))->toContain('whenScheduling')->toContain('LoadTier::set')->toContain('REQUEST_ATTRIBUTE');
});
