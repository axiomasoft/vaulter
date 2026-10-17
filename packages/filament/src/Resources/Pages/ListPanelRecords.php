<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Vaulter\Attributes\Internal;

/**
 * The list page of a panel resource: its table is fed by the resource's custom records (the
 * authorized drive listings), so it has no Eloquent query and no model record to resolve a
 * view/edit link from; the stock table assumes both.
 */
#[Internal]
abstract class ListPanelRecords extends ListRecords
{
    protected function makeTable(): Table
    {
        $table = $this->makeBaseTable();

        self::getResource()::configureTable($table);

        return $table;
    }
}
