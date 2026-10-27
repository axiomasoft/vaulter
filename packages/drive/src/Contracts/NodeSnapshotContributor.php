<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;

/**
 * Adds one named fragment to the node snapshots of a page (slot `drive`, Contributor): the documents
 * module adds `document`. The mapper calls it once per page, whatever the page size, with every node of
 * the page; it answers only for the nodes it has a fragment for.
 */
#[Spi(SpiKind::Implement)]
interface NodeSnapshotContributor
{
    /** The snapshot fragment key; reserved keys of the snapshot are rejected by the mapper. */
    public function key(): string;

    /**
     * The facts of every node of the page as model-free snapshots, before any fragment, viewer state or reader key
     * is added; the contributor reads its own aggregates by the node ids, never a node row.
     *
     * @param  list<NodeSnapshot>    $nodes every node of the page
     * @return array<string, object> node id (string) => fragment
     */
    public function contribute(array $nodes): array;
}
