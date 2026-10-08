<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Data\Snapshots\DocumentHeadSnapshot;
use Vaulter\Models\Drive\Node;

/**
 * Supplies the document head of document nodes for NodeSnapshot::$document. Core does
 * not know the documents package: it binds an implementation when installed, and
 * without one every document node has a null head.
 *
 * @internal
 */
interface NodeDocumentHeads
{
    /**
     * @param  list<Node>                          $documents nodes of kind document
     * @return array<string, DocumentHeadSnapshot> node id (string) => head
     */
    public function heads(array $documents): array;
}
