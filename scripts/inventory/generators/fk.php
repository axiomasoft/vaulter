<?php

declare(strict_types=1);

use Vaulter\Inventory\Diff;
use Vaulter\Inventory\Generator;
use Vaulter\Inventory\Json;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\ForeignKeys;

require_once dirname(__DIR__).'/lib/Scans/ForeignKeys.php';

/** tests/Repo/Arch/Fixtures/fk-map.json: every foreign key of identities/schema.json with its owners and verdict. */
return new class implements Generator
{
    public function name(): string
    {
        return 'fk';
    }

    public function write(Repo $repo, array $options = []): array
    {
        Json::write($repo, ForeignKeys::FIXTURE, ForeignKeys::map($repo));

        return [ForeignKeys::FIXTURE];
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
        $map = ForeignKeys::map($repo);
        $lines = [];
        if ($report) {
            $verdicts = array_count_values(array_column($map['fks'], 'verdict'));
            ksort($verdicts, SORT_STRING);
            $lines[] = sprintf('%d foreign keys, %d head', count($map['fks']), count(array_filter(array_column($map['fks'], 'head'))));
            foreach ($verdicts as $verdict => $n) {
                $lines[] = sprintf('%5d  %s', $n, $verdict);
            }
        }

        $diff = Diff::unified($repo->exists(ForeignKeys::FIXTURE) ? $repo->read(ForeignKeys::FIXTURE) : '', Json::encode($map), ForeignKeys::FIXTURE);
        if ($diff !== []) {
            return ['code' => 1, 'lines' => [...$lines, ...$diff, 'fix: scripts/php84 php scripts/inventory/inventory.php fk --write']];
        }

        return ['code' => 0, 'lines' => $lines];
    }
};
