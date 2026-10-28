<?php

declare(strict_types=1);

use Vaulter\Inventory\Diff;
use Vaulter\Inventory\Generator;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Surface;

require_once dirname(__DIR__).'/lib/Surface.php';

/**
 * packages/<p>/tests/Arch/__snapshots__/surface.txt: the supported surface of each package (D27), read from the
 * #[Api], #[Spi] and #[Internal] attributes of packages/<p>/src, and beside it surface.json, the versioned structural view
 * of the same reading for the BC check (P6.2). `--check` is red on any difference of either, so a change of the surface is
 * a visible change of a committed file.
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
        foreach ($this->outputs($repo) as $path => $contents) {
            $repo->write($path, $contents);
            $written[] = $path;
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
        foreach ($this->outputs($repo) as $path => $contents) {
            $diff = Diff::unified($repo->exists($path) ? $repo->read($path) : '', $contents, $path);
            if ($report && str_ends_with($path, '.txt')) {
                $lines[] = sprintf('%s: %d surface lines', basename(dirname($path, 4)), substr_count($contents, "\n") - 2);
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

    /** @return array<string, string> path => contents: the txt and the structural json of every package, from one reading */
    private function outputs(Repo $repo): array
    {
        $types = Surface::read($repo);
        $outputs = [];
        foreach (Surface::manifests($types, $repo->sourcePackages()) as $package => $contents) {
            $outputs[Surface::snapshotPath($package)] = $contents;
        }
        foreach (Surface::structures($types, $repo->sourcePackages()) as $package => $contents) {
            $outputs[Surface::structurePath($package)] = $contents;
        }

        return $outputs;
    }
};
