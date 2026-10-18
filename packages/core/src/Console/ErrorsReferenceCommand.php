<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Vaulter\Attributes\Internal;
use Vaulter\Http\Problems\ErrorsReference;
use Vaulter\Http\Problems\ProblemCatalog;

/**
 * Regenerates the problem-code reference of the docs from the {@see ProblemCatalog}.
 * With --check nothing is written and the exit code says whether the docs are current
 * (for CI). With --openapi it prints the code table for the OpenAPI document instead (P6.7).
 */
#[Internal]
final class ErrorsReferenceCommand extends Command
{
    protected $signature = 'vaulter:errors:reference
        {--openapi : Print the x-vaulter-problems table and the code examples for openapi/vaulter-v1.yaml, writing nothing}
        {--check : Exit 1 when a page differs from the generated reference, without writing}
        {--path=* : Pages to update (default: docs/reference/errors.md and docs/ru/reference/errors.md)}';

    protected $description = 'Generate the problem-code reference in the docs.';

    public function handle(ErrorsReference $reference): int
    {
        if ($this->option('openapi') === true) {
            $this->output->writeln($reference->openApi(), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $paths = array_values(array_filter((array) $this->option('path'), is_string(...)));
        if ($paths === []) {
            // The pages live in the monorepo, not in the host application.
            $root = dirname(__DIR__, 4);
            $paths = [$root.'/docs/reference/errors.md', $root.'/docs/ru/reference/errors.md'];
        }
        $check = (bool) $this->option('check');
        $stale = false;

        foreach ($paths as $path) {
            $locale = str_contains(str_replace('\\', '/', $path), '/ru/') ? 'ru' : 'en';
            $page = is_file($path) ? file_get_contents($path) : false;
            $next = $page === false ? null : $reference->apply($page, $locale);
            if ($page === false || $next === null) {
                $this->components->error(sprintf('%s is missing or has no %s / %s markers.', $path, ErrorsReference::START, ErrorsReference::END));
                $stale = true;

                continue;
            }
            if ($next === $page) {
                continue;
            }
            if ($check) {
                $this->components->error($path.' differs from the generated error reference; run vaulter:errors:reference.');
                $stale = true;

                continue;
            }
            file_put_contents($path, $next);
            $this->components->info('Updated '.$path);
        }

        return $stale ? self::FAILURE : self::SUCCESS;
    }
}
