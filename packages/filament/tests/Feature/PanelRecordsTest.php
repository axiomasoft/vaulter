<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Filament\Support\PanelRecords;

/*
 * The shared sort-and-page step of every panel table works on rows that are already authorized,
 * so it must never add a row, reorder across the page cut or lose the row key Filament addresses.
 */

function panelRecordRows(): array
{
    return [
        ['id' => 'a', 'name' => 'beta', 'kind' => NodeKind::File, 'created_at' => CarbonImmutable::parse('2030-01-02')],
        ['id' => 'b', 'name' => 'alpha', 'kind' => NodeKind::Folder, 'created_at' => CarbonImmutable::parse('2030-01-03')],
        ['id' => 'c', 'name' => 'gamma', 'kind' => NodeKind::Document, 'created_at' => CarbonImmutable::parse('2030-01-01')],
    ];
}

it('keys the page by row id and sorts by date, text and enum value', function (): void {
    $byDate = PanelRecords::paginate(panelRecordRows(), null, null, 1, 10, 'created_at');
    $byName = PanelRecords::paginate(panelRecordRows(), 'name', 'asc', 1, 10, 'created_at');
    $byKind = PanelRecords::paginate(panelRecordRows(), 'kind', 'asc', 1, 10, 'created_at');

    expect(array_keys($byDate->items()))->toBe(['b', 'a', 'c'])
        ->and(array_keys($byName->items()))->toBe(['b', 'a', 'c'])
        ->and(array_keys($byKind->items()))->toBe(['c', 'a', 'b']);
});

it('cuts the page and knows whether a next page exists', function (): void {
    $first = PanelRecords::paginate(panelRecordRows(), 'name', 'asc', 1, 2, 'created_at');
    $second = PanelRecords::paginate(panelRecordRows(), 'name', 'asc', 2, 2, 'created_at');

    expect(array_keys($first->items()))->toBe(['b', 'a'])
        ->and($first->hasMorePages())->toBeTrue()
        ->and(array_keys($second->items()))->toBe(['c'])
        ->and($second->hasMorePages())->toBeFalse();
});

it('takes the string a per-page select posts and shows every row for "all"', function (): void {
    expect(PanelRecords::paginate(panelRecordRows(), null, null, 1, '2', 'created_at')->items())->toHaveCount(2)
        ->and(PanelRecords::paginate(panelRecordRows(), null, null, 1, 'all', 'created_at')->items())->toHaveCount(3)
        ->and(PanelRecords::paginate([], null, null, 1, 'all', 'created_at')->items())->toBe([]);
});

it('reads a filter value only when it is a non-empty scalar', function (): void {
    expect(PanelRecords::filterValue(['kind' => ['value' => 'file']], 'kind'))->toBe('file')
        ->and(PanelRecords::filterValue(['kind' => ['value' => 0]], 'kind'))->toBe('0')
        ->and(PanelRecords::filterValue(['kind' => ['value' => true]], 'kind'))->toBe('1')
        ->and(PanelRecords::filterValue(['kind' => ['value' => false]], 'kind'))->toBe('0')
        ->and(PanelRecords::filterValue(['kind' => ['value' => '']], 'kind'))->toBeNull()
        ->and(PanelRecords::filterValue(['kind' => ['value' => ['x']]], 'kind'))->toBeNull()
        ->and(PanelRecords::filterValue(['kind' => 'file'], 'kind'))->toBeNull()
        ->and(PanelRecords::filterValue([], 'kind'))->toBeNull();
});

it('matches a search term case-insensitively and treats an empty term as no filter', function (): void {
    expect(PanelRecords::contains('Alpha Archive', 'ALPHA'))->toBeTrue()
        ->and(PanelRecords::contains('Alpha Archive', 'omega'))->toBeFalse()
        ->and(PanelRecords::contains('Alpha Archive', ''))->toBeTrue()
        ->and(PanelRecords::contains('Alpha Archive', null))->toBeTrue();
});

it('formats sizes in binary units and shows the zero placeholder you give it', function (): void {
    expect(PanelRecords::humanBytes(0))->toBe('—')
        ->and(PanelRecords::humanBytes(0, '0 B'))->toBe('0 B')
        ->and(PanelRecords::humanBytes(1536))->toBe('1.5 KB')
        ->and(PanelRecords::humanBytes(1024 ** 3))->toBe('1 GB');
});
