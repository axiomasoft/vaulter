<?php

declare(strict_types=1);

use Vaulter\Console\CommandsReferenceCommand;

it('renders one row per registered vaulter command with its synopsis', function (): void {
    $table = CommandsReferenceCommand::render();

    expect($table)->toStartWith('| Command | Description | Synopsis |')
        ->and($table)->toContain('| `vaulter:commands:reference` |')
        ->and($table)->toContain('`[--check] [--path [PATH]]`')
        ->and($table)->toContain('| `vaulter:doctor` |')
        ->and($table)->not->toContain('| `migrate`');
});

it('replaces only the block between the markers', function (): void {
    $page = "intro\n".CommandsReferenceCommand::START."\nstale\n".CommandsReferenceCommand::END."\noutro";
    $fresh = CommandsReferenceCommand::apply($page);

    expect($fresh)->toContain('| `vaulter:doctor` |')
        ->and($fresh)->toStartWith("intro\n")->toEndWith("\noutro")
        ->and(CommandsReferenceCommand::apply($fresh))->toBe($fresh)
        ->and(CommandsReferenceCommand::apply('no markers'))->toBeNull();
});

it('exits 1 from --check without writing when the page drifted', function (): void {
    $page = sys_get_temp_dir().'/vaulter-commands-page-'.bin2hex(random_bytes(4)).'.md';
    $stale = CommandsReferenceCommand::START."\nstale\n".CommandsReferenceCommand::END;
    file_put_contents($page, $stale);

    $this->artisan('vaulter:commands:reference', ['--check' => true, '--path' => [$page]])->assertExitCode(1);

    expect(file_get_contents($page))->toBe($stale);

    $this->artisan('vaulter:commands:reference', ['--path' => [$page]])->assertExitCode(0);
    $this->artisan('vaulter:commands:reference', ['--check' => true, '--path' => [$page]])->assertExitCode(0);
});
