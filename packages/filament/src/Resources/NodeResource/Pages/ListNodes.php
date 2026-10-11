<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\NodeResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Vaulter\Filament\Resources\NodeResource;

final class ListNodes extends ListRecords
{
    protected static string $resource = NodeResource::class;

    /**
     * The node table is fed by handle listings (custom records), so it has no Eloquent
     * query and no model record to resolve a view/edit link from; the stock table
     * assumes both.
     */
    protected function makeTable(): Table
    {
        $table = $this->makeBaseTable();

        self::getResource()::configureTable($table);

        return $table;
    }
}
