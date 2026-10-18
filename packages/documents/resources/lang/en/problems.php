<?php

declare(strict_types=1);

return [
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
    'document_state_pending' => [
        'title' => 'Document content pending',
        'detail' => 'Saved collaboration edits are not in the content yet; retry later.',
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
