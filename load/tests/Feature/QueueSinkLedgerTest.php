<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/webhook-sink/router.php';

it('appends sink ordinals and request identity after the ledger grows beyond a tail block', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'r09-sink-');
    try {
        for ($i = 1; $i <= 60; $i++) {
            sink_record($path, '2xx', 204, 'event-'.$i, [
                'run_id' => 'new-run', 'cell_id' => 'cell', 'slot' => '2',
            ], microtime(true));
        }
        $lines = array_filter(explode("\n", file_get_contents($path)));
        $rows = array_map(fn ($line) => json_decode($line, true), $lines);
        expect(filesize($path))->toBeGreaterThan(4096)
            ->and(array_column($rows, 'ordinal'))->toBe(range(1, 60))
            ->and($rows[59]['run_id'])->toBe('new-run')
            ->and($rows[59]['slot'])->toBe('2');
    } finally {
        unlink($path);
    }
});
