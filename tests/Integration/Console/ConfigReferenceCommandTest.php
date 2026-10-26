<?php

declare(strict_types=1);

use Vaulter\Console\ConfigReferenceCommand;

function configPackages(string $config): string
{
    $root = sys_get_temp_dir().'/vaulter-config-ref-'.bin2hex(random_bytes(4));
    mkdir($root.'/demo/config', 0777, true);
    file_put_contents($root.'/demo/config/demo.php', $config);

    return $root;
}

it('lists each leaf key with its env, default and the comment above it', function (): void {
    $root = configPackages(<<<'PHP'
        <?php

        return [
            'database' => [
                // Table prefix.
                'prefix' => env('DEMO_PREFIX', 'v_'),
                'pipe' => env('DEMO_PIPE'), // a | b
            ],
            'list' => ['a', 'b'],
            /* Banner
             |------
             | Feature switch
             |------ */
            'enabled' => true,
        ];
        PHP);

    $table = ConfigReferenceCommand::render($root);

    expect($table)->toContain('### `demo`')
        ->and($table)->toContain("| `demo.database.prefix` | `DEMO_PREFIX` | `'v_'` | Table prefix. |")
        ->and($table)->toContain('| `demo.database.pipe` | `DEMO_PIPE` | `null` | a \| b |')
        ->and($table)->toContain('| `demo.list` | — | `[\'a\', \'b\']` | — |')
        ->and($table)->toContain('| `demo.enabled` | — | `true` | Banner Feature switch |');
});

it('documents a group by the comment above it and keeps a match default whole', function (): void {
    $root = configPackages(<<<'PHP'
        <?php

        return [
            // Grammar: null | hourly | daily.
            'schedule' => ['relay' => 'hourly'],
            'mode' => match (true) { true => 'on', false => 'off' },
            'after' => 2,
        ];
        PHP);

    $table = ConfigReferenceCommand::render($root);

    expect($table)->toContain('| `demo.schedule` | — | — | Grammar: null \| hourly \| daily. |')
        ->and($table)->toContain("| `demo.mode` | — | `match (true) { true => 'on', false => 'off' }` | — |")
        ->and($table)->toContain('| `demo.after` | — | `2` | — |');
});

it('replaces only the block between the markers and fails the check on drift', function (): void {
    $root = configPackages("<?php\nreturn ['a' => 1];\n");
    $page = "intro\n".ConfigReferenceCommand::START."\nstale\n".ConfigReferenceCommand::END."\noutro";
    $fresh = ConfigReferenceCommand::apply($page, $root);

    expect($fresh)->toContain('| `demo.a` | — | `1` | — |')
        ->and($fresh)->toStartWith("intro\n")->toEndWith("\noutro")
        ->and(ConfigReferenceCommand::apply($fresh, $root))->toBe($fresh)
        ->and(ConfigReferenceCommand::apply('no markers', $root))->toBeNull();
});

it('keeps the generated block of each reference page current', function (string $path): void {
    $page = file_get_contents($path);

    expect(ConfigReferenceCommand::apply($page, __DIR__.'/../../../packages'))->toBe($page);
})->with([
    'en' => dirname(__DIR__, 3).'/docs/reference/config.md',
    'ru' => dirname(__DIR__, 3).'/docs/ru/reference/config.md',
]);

it('exits 1 from --check without writing when the page drifted', function (): void {
    $page = sys_get_temp_dir().'/vaulter-config-page-'.bin2hex(random_bytes(4)).'.md';
    $stale = ConfigReferenceCommand::START."\nstale\n".ConfigReferenceCommand::END;
    file_put_contents($page, $stale);

    $this->artisan('vaulter:config:reference', ['--check' => true, '--path' => [$page]])->assertExitCode(1);

    expect(file_get_contents($page))->toBe($stale);

    $this->artisan('vaulter:config:reference', ['--path' => [$page]])->assertExitCode(0);
    $this->artisan('vaulter:config:reference', ['--check' => true, '--path' => [$page]])->assertExitCode(0);
});
