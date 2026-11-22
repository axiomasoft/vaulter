<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Documents;

use Packman\Attributes\Internal;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Documents\DocumentPayload;
use Vaulter\Documents\Models\Documents\DocumentSyncState;
use Vaulter\Drive\Models\Node;

/** Lookups of the aggregate of a node. Opens no transactions and makes no access decisions. */
#[Internal]
final class DocumentReadRepository
{
    private const array PAYLOAD_FIELDS = ['content', 'content_hash', 'content_size'];

    private const array SYNC_FIELDS = [
        'collab_protocol', 'collab_state_format', 'collab_state_size',
        'pending_protocol', 'pending_state_format', 'pending_schema_version', 'pending_generation', 'pending_through_seq',
    ];

    public function forNode(Node $node, bool $withState = false, bool $withPayload = true): ?Document
    {
        $head = (new Document)->getTable();
        $query = Document::query()->where($head.'.node_id', $node->getKey());
        if (!$withState) {
            return $query->first();
        }

        // Read the head validators and working payload in one statement so a
        // concurrent commit cannot pair an older ETag with a newer payload.
        $payload = new DocumentPayload;
        $sync = new DocumentSyncState;
        $payloadTable = $payload->getTable();
        $syncTable = $sync->getTable();
        $query->leftJoin($syncTable, $syncTable.'.document_id', '=', $head.'.id')
            ->select($head.'.*', $syncTable.'.document_id as sync_owner');
        if ($withPayload) {
            $query->leftJoin($payloadTable, $payloadTable.'.document_id', '=', $head.'.id')
                ->addSelect($payloadTable.'.document_id as payload_owner');
            foreach (self::PAYLOAD_FIELDS as $field) {
                $query->addSelect($payloadTable.'.'.$field);
            }
        }
        foreach (self::SYNC_FIELDS as $field) {
            $query->addSelect($syncTable.'.'.$field);
        }

        $document = $query->first();
        if ($document === null) {
            return null;
        }

        $attributes = $document->getAttributes();
        $payloadAttributes = array_intersect_key($attributes, array_flip(self::PAYLOAD_FIELDS));
        $syncAttributes = array_intersect_key($attributes, array_flip(self::SYNC_FIELDS));
        $document->setRawAttributes(array_diff_key($attributes, $payloadAttributes, $syncAttributes, ['payload_owner' => true, 'sync_owner' => true]), true);

        return $document
            ->setRelation('payload', ($attributes['payload_owner'] ?? null) === null ? null : $payload->newFromBuilder($payloadAttributes + ['document_id' => $attributes['payload_owner']]))
            ->setRelation('syncState', $attributes['sync_owner'] === null ? null : $sync->newFromBuilder($syncAttributes + ['document_id' => $attributes['sync_owner']]));
    }

    /** The D level of T → N → D. The caller already holds the tree and node locks. */
    public function lockForNode(Node $node): ?Document
    {
        $document = Document::query()->where('node_id', $node->getKey())->lockForUpdate()->first();

        return $document === null ? null : $this->loadLockedState($document);
    }

    public function loadLockedState(Document $document): Document
    {
        // On MySQL, locking reads see the current rows even if an earlier SELECT
        // in this transaction has already established a repeatable-read snapshot.
        return $document
            ->setRelation('payload', $document->payload()->sharedLock()->first())
            ->setRelation('syncState', $document->syncState()->sharedLock()->first());
    }
}
