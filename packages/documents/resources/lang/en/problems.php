<?php

declare(strict_types=1);

return [
    'collaboration_epoch_mismatch' => [
        'title' => 'Collaboration epoch mismatch',
        'detail' => 'The collaboration session is out of date.',
    ],
    'collaboration_seed_conflict' => [
        'title' => 'Collaboration seed conflict',
        'detail' => 'The document was seeded differently by another session.',
    ],
    'collaboration_unavailable' => [
        'title' => 'Collaboration unavailable',
        'detail' => 'Collaboration is not available for this document.',
    ],
    'comment_not_found' => [
        'title' => 'Comment not found',
        'detail' => 'The comment does not exist or belongs to another node.',
    ],
    'document_content_degraded' => [
        'title' => 'Document content degraded',
        'detail' => 'Saving would drop parts the document type no longer accepts.',
    ],
    'document_revision_not_found' => [
        'title' => 'Document revision not found',
        'detail' => 'The revision does not exist or belongs to another document.',
    ],
    'document_state_mismatch' => [
        'title' => 'Document content changed',
        'detail' => 'The content changed since you read it; reload it and retry.',
    ],
    'document_state_too_large' => [
        'title' => 'Document state too large',
        'detail' => 'The collaborative state exceeds the allowed size.',
    ],
    'document_type_unavailable' => [
        'title' => 'Document type unavailable',
        'detail' => 'The document type is not installed; the document is read-only.',
    ],
    'invalid_document_content' => [
        'title' => 'Invalid document content',
        'detail' => 'The content does not match the document type.',
    ],
    'not_a_document' => [
        'title' => 'Not a document',
        'detail' => 'The operation needs a document node.',
    ],
    'unknown_document_type' => [
        'title' => 'Unknown document type',
        'detail' => 'No such document type is registered.',
    ],
    'unknown_export_format' => [
        'title' => 'Unknown export format',
        'detail' => 'No exporter renders the requested format; see meta.allowed.',
    ],
];
