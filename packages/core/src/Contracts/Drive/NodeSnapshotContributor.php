<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Models\Drive\Node;

/**
 * Adds one named fragment to the node snapshots of a page (slot `drive`, Contributor): the documents
 * module adds `document`. The mapper calls it once per page, whatever the page size, with every node of
 * the page; it answers only for the nodes it has a fragment for.
 */
interface NodeSnapshotContributor
{
    /** The snapshot fragment key; reserved keys of the snapshot are rejected by the mapper. */
    public function key(): string;

    /**
     * @param  list<Node>            $nodes every node of the page
     * @return array<string, object> node id (string) => fragment
     */
    public function contribute(array $nodes): array;
}
