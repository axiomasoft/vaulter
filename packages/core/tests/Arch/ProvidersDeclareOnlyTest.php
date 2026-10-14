<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/*
 * P3.4 (ADR-0027, D39): a package service provider only offers its module manifest; everything else — config,
 * bindings, routes, commands, tags, schedules — is declared by the manifest and registered by the core module
 * kernel. Statically checked on every packages/<pkg>/src/*ServiceProvider.php: the bodies of register(), boot(),
 * packageRegistered() and packageBooted() hold only the calls named here, and configurePackage() only names the
 * package and its translations.
 */

/** Calls allowed in the lifecycle methods, per method; the core provider also runs the module kernel. */
const PROVIDER_ALLOWED_CALLS = [
    'register' => ['offerModule'],
    'packageRegistered' => ['offerModule'],
    'boot' => [],
    'packageBooted' => [],
];

const CORE_PROVIDER_KERNEL_CALLS = [
    'packageRegistered' => ['registerModuleKernel'],
    'packageBooted' => ['bootModuleKernel'],
];

/** Methods a provider class may declare; the core provider also holds the private module-kernel helpers. */
const PROVIDER_ALLOWED_METHODS = ['configurePackage', 'register', 'boot', 'packageRegistered', 'packageBooted'];

const CORE_PROVIDER_KERNEL_METHODS = ['registerModuleKernel', 'bootModuleKernel', 'registerDiagnosticCommands', 'registerInvalidGraphRoute'];

/** The spatie Package calls configurePackage() may chain. */
const PROVIDER_PACKAGE_CALLS = ['name', 'hasTranslations'];

/** @return list<string> packages/<pkg>/src/*ServiceProvider.php, sorted */
function packageProviderFiles(): array
{
    $files = glob(dirname(__DIR__, 3).'/*/src/*ServiceProvider.php') ?: [];
    sort($files, SORT_STRING);

    return $files;
}

/**
 * Every violation of the declare-only rule in one provider file.
 *
 * @return list<string>
 */
function providerDeclareOnlyViolations(string $file): array
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
    $finder = new NodeFinder;
    $class = $finder->findFirstInstanceOf($ast, Stmt\Class_::class);
    if (!$class instanceof Stmt\Class_) {
        return [basename($file).': no provider class'];
    }
    $isCore = $class->name?->toString() === 'VaulterServiceProvider';
    $violations = [];
    foreach ($class->getMethods() as $method) {
        $name = $method->name->toString();
        // registeringPackage()/bootingPackage() and any helper would register things outside the manifest unseen.
        if (!in_array($name, [...PROVIDER_ALLOWED_METHODS, ...($isCore ? CORE_PROVIDER_KERNEL_METHODS : [])], true)) {
            $violations[] = sprintf('%s::%s() line %d: a provider declares only %s', basename($file), $name, $method->getStartLine(), implode('(), ', PROVIDER_ALLOWED_METHODS).'()');

            continue;
        }
        if ($name === 'configurePackage') {
            foreach ($finder->find($method->stmts ?? [], static fn (Node $n): bool => $n instanceof Expr\CallLike) as $call) {
                $called = $call instanceof Expr\MethodCall && $call->name instanceof Node\Identifier ? $call->name->toString() : null;
                if (!in_array($called, PROVIDER_PACKAGE_CALLS, true)) {
                    $violations[] = sprintf('%s::configurePackage() calls %s; only %s', basename($file), $called ?? $call->getType(), implode(', ', PROVIDER_PACKAGE_CALLS));
                }
            }

            continue;
        }
        if (!array_key_exists($name, PROVIDER_ALLOWED_CALLS)) {
            continue;
        }
        $allowed = [...PROVIDER_ALLOWED_CALLS[$name], ...($isCore ? CORE_PROVIDER_KERNEL_CALLS[$name] ?? [] : [])];
        foreach ($method->stmts ?? [] as $stmt) {
            $call = $stmt instanceof Stmt\Expression ? $stmt->expr : null;
            $ok = $call instanceof Expr\MethodCall
                && $call->var instanceof Expr\Variable && $call->var->name === 'this'
                && $call->name instanceof Node\Identifier && in_array($call->name->toString(), $allowed, true)
                && $finder->find($call->args, static fn (Node $n): bool => $n instanceof Expr\CallLike) === [];
            if (!$ok) {
                $violations[] = sprintf('%s::%s() line %d: only $this->%s() is allowed', basename($file), $name, $stmt->getStartLine(), implode('() / $this->', $allowed ?: ['<nothing>']));
            }
        }
    }

    return $violations;
}

it('finds the provider of every package', function (): void {
    $names = array_map(static fn (string $f): string => basename($f), packageProviderFiles());
    sort($names, SORT_STRING);

    expect($names)->toBe([
        'VaulterAzgardServiceProvider.php',
        'VaulterDocumentsServiceProvider.php',
        'VaulterDriveServiceProvider.php',
        'VaulterFilamentServiceProvider.php',
        'VaulterMediaServiceProvider.php',
        'VaulterServiceProvider.php',
    ]);
});

it('lets a package provider only offer its module manifest', function (string $file): void {
    expect(providerDeclareOnlyViolations($file))->toBe([]);
})->with(fn (): array => array_combine(array_map(basename(...), packageProviderFiles()), array_map(static fn (string $f): array => [$f], packageProviderFiles())));

it('refuses a provider that registers anything itself', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'provider-');
    file_put_contents($file, <<<'PHP'
        <?php
        final class RogueServiceProvider extends PackageServiceProvider
        {
            public function configurePackage($package): void { $package->name('rogue')->hasConfigFile(); }
            public function packageRegistered(): void
            {
                $this->offerModule(RogueModule::class);
                $this->app->singleton(Rogue::class);
            }
            public function packageBooted(): void { $this->loadRoutesFrom('routes.php'); }
        }
        PHP);

    try {
        expect(providerDeclareOnlyViolations($file))->toHaveCount(3);
    } finally {
        unlink($file);
    }
});

it('refuses the spatie hooks and any helper method a provider declares', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'provider-');
    file_put_contents($file, <<<'PHP'
        <?php
        final class RogueServiceProvider extends PackageServiceProvider
        {
            public function configurePackage($package): void { $package->name('rogue'); }
            public function registeringPackage(): void { $this->app->singleton(Rogue::class); }
            public function bootingPackage(): void { $this->app->singleton(Rogue::class); }
            private function wire(): void { $this->app->singleton(Rogue::class); }
            public function packageRegistered(): void { $this->offerModule(RogueModule::class); }
        }
        PHP);

    try {
        expect(providerDeclareOnlyViolations($file))->toHaveCount(3);
    } finally {
        unlink($file);
    }
});
