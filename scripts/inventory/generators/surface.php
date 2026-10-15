<?php

declare(strict_types=1);

use Vaulter\Inventory\Diff;
use Vaulter\Inventory\Generator;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Surface;

require_once dirname(__DIR__).'/lib/Surface.php';

/**
 * packages/<p>/tests/Arch/__snapshots__/surface.txt: the supported surface of each package (D27), read from the
 * #[Api], #[Spi] and #[Internal] attributes of packages/<p>/src. `--check` is red on any difference, so a change of the
 * surface is a visible change of a committed file.
 */
return new class implements Generator
{
    public function name(): string
    {
        return 'surface';
    }

    public function write(Repo $repo, array $options = []): array
    {
        $written = [];
        foreach (Surface::manifests(Surface::read($repo)) as $package => $contents) {
            $repo->write(Surface::snapshotPath($package), $contents);
            $written[] = Surface::snapshotPath($package);
        }

        return $written;
    }

    public function check(Repo $repo, array $options = []): array
    {
        return $this->judge($repo, false);
    }

    public function report(Repo $repo, array $options = []): array
    {
        return $this->judge($repo, true);
    }

    /** @return array{code: int, lines: list<string>} */
    private function judge(Repo $repo, bool $report): array
    {
        $lines = [];
        $code = 0;
        foreach (Surface::manifests(Surface::read($repo)) as $package => $contents) {
            $path = Surface::snapshotPath($package);
            $diff = Diff::unified($repo->exists($path) ? $repo->read($path) : '', $contents, $path);
            if ($report) {
                $lines[] = sprintf('%s: %d surface lines', $package, substr_count($contents, "\n") - 2);
            }
            if ($diff !== []) {
                $code = 1;
                array_push($lines, ...$diff);
            }
        }
        if ($code === 1) {
            $lines[] = 'fix: scripts/php84 php scripts/inventory/inventory.php surface --write';
        }

        return ['code' => $code, 'lines' => $lines];
    }
};
