<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\NodeResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Support\PanelActor;
use Vaulter\Models\Drive\Node;

final class ListNodes extends ListRecords
{
    protected static string $resource = NodeResource::class;

    /** @return Collection<array-key, Node>|Paginator<array-key, mixed>|CursorPaginator<array-key, mixed> */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        if (!$records instanceof Collection && !$records instanceof AbstractPaginator && !$records instanceof AbstractCursorPaginator) {
            throw new \LogicException('Node table requires a collection-backed paginator.');
        }
        $collection = $records instanceof Collection ? $records : $records->getCollection();
        $nodes = [];
        foreach ($collection as $record) {
            if (!$record instanceof Node) {
                throw new \LogicException('Node table contains an invalid record.');
            }
            $nodes[] = $record;
        }
        $actor = PanelActor::current();
        $sets = $actor === null ? [] : app(AccessResolver::class)->evaluateMany($actor, $nodes);
        $visible = $collection->filter(fn ($node): bool => $node instanceof Node && isset($sets[(string) $node->id]) && $sets[(string) $node->id]->allows(Ability::View));
        if ($records instanceof Collection) {
            return $this->cachedTableRecords = $visible;
        }
        $records->setCollection($visible);

        return $records;
    }
}
