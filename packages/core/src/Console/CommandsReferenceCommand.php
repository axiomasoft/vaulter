<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\VaulterModule;

/**
 * Regenerates the command table of the reference docs from the registered vaulter:*
 * commands. With --check nothing is written and the exit code says whether the docs
 * are current (for CI).
 */
final class CommandsReferenceCommand extends Command
{
    public const START = '<!-- vaulter:commands-reference:start -->';

    public const END = '<!-- vaulter:commands-reference:end -->';

    protected $signature = 'vaulter:commands:reference
        {--check : Exit 1 when a page differs from the generated table, without writing}
        {--path=* : Pages to update (default: docs/reference/commands.md and docs/ru/reference/commands.md)}';

    protected $description = 'Generate the command table in the reference docs.';

    /**
     * The markdown table of every vaulter:* command, without the markers: the registered ones and those of the
     * offered modules that are not active here (an opt-in module documents its commands too).
     */
    public static function render(): string
    {
        /** @var array<string, SymfonyCommand> $registered */
        $registered = Artisan::all();
        $commands = array_filter(
            $registered,
            static fn (string $name): bool => str_starts_with($name, 'vaulter:'),
            ARRAY_FILTER_USE_KEY,
        );
        foreach (self::declaredCommands() as $class) {
            $command = app($class);
            if ($command instanceof SymfonyCommand && str_starts_with((string) $command->getName(), 'vaulter:')) {
                $commands[(string) $command->getName()] ??= $command;
            }
        }
        ksort($commands);

        $lines = ['| Command | Description | Synopsis |', '|---|---|---|'];
        foreach ($commands as $name => $command) {
            $synopsis = trim(str_replace($name, '', $command->getSynopsis()));
            $lines[] = sprintf(
                '| `%s` | %s | %s |',
                $name,
                str_replace('|', '\|', $command->getDescription()),
                $synopsis === '' ? '—' : '`'.str_replace('|', '\|', $synopsis).'`',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The command classes every offered manifest declares, active or not.
     *
     * @return list<class-string>
     */
    private static function declaredCommands(): array
    {
        $classes = [];
        foreach (ModuleCatalog::of(app())->offered() as $manifest) {
            if (!is_a($manifest, VaulterModule::class, true)) {
                continue;
            }
            $module = new $manifest;
            $builder = new ModuleBuilder($module->id());
            $module->register($builder);
            array_push($classes, ...$builder->commandClasses());
        }

        return $classes;
    }

    /** The block between the markers, markers included. */
    public static function block(): string
    {
        return self::START."\n".self::render()."\n".self::END;
    }

    /** The page with its generated block replaced; null when the markers are missing. */
    public static function apply(string $page): ?string
    {
        $start = strpos($page, self::START);
        $end = strpos($page, self::END);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($page, 0, $start).self::block().substr($page, $end + strlen(self::END));
    }

    public function handle(): int
    {
        $paths = array_values(array_filter((array) $this->option('path'), is_string(...)));
        if ($paths === []) {
            // The pages live in the monorepo, not in the host application.
            $root = dirname(__DIR__, 4);
            $paths = [$root.'/docs/reference/commands.md', $root.'/docs/ru/reference/commands.md'];
        }
        $check = (bool) $this->option('check');
        $stale = false;

        foreach ($paths as $path) {
            $page = is_file($path) ? file_get_contents($path) : false;
            $next = $page === false ? null : self::apply($page);
            if ($page === false || $next === null) {
                $this->components->error(sprintf('%s is missing or has no %s / %s markers.', $path, self::START, self::END));
                $stale = true;

                continue;
            }
            if ($next === $page) {
                continue;
            }
            if ($check) {
                $this->components->error($path.' differs from the generated command table; run vaulter:commands:reference.');
                $stale = true;

                continue;
            }
            file_put_contents($path, $next);
            $this->components->info('Updated '.$path);
        }

        return $stale ? self::FAILURE : self::SUCCESS;
    }
}
