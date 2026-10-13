<?php

declare(strict_types=1);

use Vaulter\Inventory\Diff;
use Vaulter\Inventory\Generator;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Json;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Rules;
use Vaulter\Inventory\SourceIndex;
use Vaulter\Inventory\TargetMap;

require_once dirname(__DIR__).'/lib/TargetMap.php';

/**
 * tests/Repo/Arch/Fixtures/target-map.json from the five rule files in target-map/rules/*.json.
 *
 * `--report --rules=<basename>` judges only the zone of one rule file (gaps, conflicts, stale rules; exit 1 if any);
 * `--write --summary=<path>` also renders the human summary (type-map.md) to a file.
 */
return new class implements Generator
{
    public function name(): string
    {
        return 'target-map';
    }

    public function write(Repo $repo, array $options = []): array
    {
        $built = $this->build($repo, null);
        if ($built['problems'] !== []) {
            throw new InventoryError(implode("\n", Rules::describe($built['problems']))."\nfix the rule files in ".TargetMap::RULES_DIR);
        }
        Json::write($repo, TargetMap::FIXTURE, $built['data']);
        $written = [TargetMap::FIXTURE];

        if (isset($options['summary']) && $options['summary'] !== '') {
            $path = $options['summary'];
            $contents = TargetMap::markdown($built['data'])."\n";
            if (str_starts_with($path, '/')) {
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                    throw new InventoryError("cannot create the directory of {$path}");
                }
                file_put_contents($path, $contents);
            } else {
                $repo->write($path, $contents);
            }
            $written[] = $path;
        }

        return $written;
    }

    public function check(Repo $repo, array $options = []): array
    {
        return $this->judge($repo, $options['rules'] ?? null, false);
    }

    public function report(Repo $repo, array $options = []): array
    {
        return $this->judge($repo, $options['rules'] ?? null, true);
    }

    /** @return array{code: int, lines: list<string>} */
    private function judge(Repo $repo, ?string $only, bool $report): array
    {
        try {
            $built = $this->build($repo, $only);
        } catch (InventoryError $e) {
            return ['code' => 2, 'lines' => [$e->getMessage()]];
        }

        if ($built['problems'] !== []) {
            $lines = Rules::describe($built['problems']);
            $lines[] = 'fix the rule files in '.TargetMap::RULES_DIR.($only === null ? '' : " ({$only}.json)");

            // a unit-level report is a yes/no on one zone; a full check treats an unjudgeable rule set as an error
            return ['code' => $only !== null ? 1 : 2, 'lines' => $lines];
        }
        if ($only !== null) {
            return ['code' => 0, 'lines' => $report ? ["rules {$only}: no gaps, conflicts or stale rules in its zone"] : []];
        }

        $diff = Diff::unified($repo->exists(TargetMap::FIXTURE) ? $repo->read(TargetMap::FIXTURE) : '', Json::encode($built['data']), TargetMap::FIXTURE);
        $lines = $report ? $this->summary($built['data']) : [];
        if ($diff !== []) {
            return ['code' => 1, 'lines' => [...$lines, ...$diff, 'fix: scripts/php84 php scripts/inventory/inventory.php target-map --write']];
        }

        return ['code' => 0, 'lines' => $lines];
    }

    /** @return array{data: array<string, mixed>, problems: list<array{code: string, message: string, files: list<string>}>} */
    private function build(Repo $repo, ?string $only): array
    {
        $rules = Rules::load($repo, TargetMap::RULES_DIR, TargetMap::OWNERS);
        if ($only !== null && !in_array($only, $rules->files(), true)) {
            throw new InventoryError("unknown rules file {$only} (known: ".implode(', ', $rules->files()).')');
        }

        return TargetMap::build(SourceIndex::forRepo($repo), $rules, TargetMap::sourceFiles($repo), $repo->psr4(), TargetMap::baseline($repo), $only);
    }

    /**
     * @param  array<string, mixed> $data
     * @return list<string>
     */
    private function summary(array $data): array
    {
        $counts = [];
        foreach ($data['types'] as $type) {
            $key = "{$type['package']} -> {$type['owner']} {$type['action']} {$type['phase']}";
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        $lines = [sprintf('types=%d files=%d members=%d filament_model_reads=%d', count($data['types']), count($data['files']), count($data['members']), count($data['consumers']['filament_model_reads']))];
        foreach ($counts as $key => $n) {
            $lines[] = sprintf('%5d  %s', $n, $key);
        }

        return $lines;
    }
};
