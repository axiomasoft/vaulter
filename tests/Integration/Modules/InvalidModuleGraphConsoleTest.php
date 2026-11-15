<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Kernel;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;

uses(TestCase::class, WithoutDatabaseSchema::class);

// Revision 3: an invalid module graph never breaks artisan. Framework commands, vaulter:modules and
// vaulter:doctor work and print the reasons; every other vaulter command is not defined.

function invalidGraphApplication(): Application
{
    return FreshApplication::boot(['vaulter.modules' => ['nope' => true, 'webhooks' => 'false']]);
}

it('boots artisan and runs the framework commands', function (): void {
    $console = invalidGraphApplication()->make(ConsoleKernel::class);

    expect($console->call('list'))->toBe(0)
        ->and($console->output())->toContain('vaulter:modules');
});

it('prints the reasons with vaulter:modules and exits 1', function (): void {
    $console = invalidGraphApplication()->make(ConsoleKernel::class);

    $exit = $console->call('vaulter:modules');
    // output() drains the buffer: read it once.
    $output = $console->output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('vaulter.modules.nope names no installed module')
        ->and($output)->toContain('vaulter.modules.webhooks must be true or false, string given');

    expect($console->call('vaulter:modules', ['--json' => true]))->toBe(1)
        ->and(json_decode(trim($console->output()), true))->toMatchArray(['valid' => false, 'fingerprint' => null, 'modules' => []]);
});

it('prints the reasons with vaulter:doctor and exits 1', function (): void {
    $console = invalidGraphApplication()->make(ConsoleKernel::class);

    $exit = $console->call('vaulter:doctor');
    $output = $console->output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('module_graph_invalid')
        ->and($output)->toContain('vaulter.modules.nope names no installed module');
});

it('does not define the other vaulter commands', function (string $command): void {
    $app = invalidGraphApplication();

    expect($app->make(Kernel::class)->isValid())->toBeFalse()
        ->and($app->make(ConsoleKernel::class)->all())->not->toHaveKey($command)
        ->and(fn () => $app->make(ConsoleKernel::class)->call($command))->toThrow(CommandNotFoundException::class);
})->with(['vaulter:install', 'vaulter:module:uninstall', 'vaulter:config:reference', 'vaulter:changes:prune', 'vaulter:relay']);
