<?php

declare(strict_types=1);

namespace Vaulter\Console;

use Illuminate\Console\Command;
use Vaulter\Events\EventsReference;

/**
 * Regenerates the event catalogue table of the reference docs from the EventSpec of
 * every type in the event catalogue. With --check nothing is written and the exit code says whether the
 * docs are current (for CI).
 */
final class EventsReferenceCommand extends Command
{
    protected $signature = 'vaulter:events:reference
        {--check : Exit 1 when a page differs from the generated table, without writing}
        {--path=* : Pages to update (default: docs/reference/events.md and docs/ru/reference/events.md)}';

    protected $description = 'Generate the event catalogue table in the reference docs.';

    public function handle(EventsReference $reference): int
    {
        $paths = array_values(array_filter((array) $this->option('path'), is_string(...)));
        if ($paths === []) {
            // The pages live in the monorepo, not in the host application.
            $root = dirname(__DIR__, 4);
            $paths = [$root.'/docs/reference/events.md', $root.'/docs/ru/reference/events.md'];
        }
        $check = (bool) $this->option('check');
        $stale = false;

        foreach ($paths as $path) {
            $page = is_file($path) ? file_get_contents($path) : false;
            $next = $page === false ? null : $reference->apply($page);
            if ($page === false || $next === null) {
                $this->components->error(sprintf('%s is missing or has no %s / %s markers.', $path, EventsReference::START, EventsReference::END));
                $stale = true;

                continue;
            }
            if ($next === $page) {
                continue;
            }
            if ($check) {
                $this->components->error($path.' differs from the generated event table; run vaulter:events:reference.');
                $stale = true;

                continue;
            }
            file_put_contents($path, $next);
            $this->components->info('Updated '.$path);
        }

        return $stale ? self::FAILURE : self::SUCCESS;
    }
}
