<?php

declare(strict_types=1);

use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;

return [
    // Document type classes; add SpreadsheetDocumentType or your own here.
    'types' => [RichTextDocumentType::class, MarkdownDocumentType::class],
    'default_type' => 'vaulter/rich-text',
    'content' => ['max_size_kb' => 5120],
    'revisions' => ['limit' => 50, 'checkpoint_interval_seconds' => 300],
    'comments' => ['enabled' => true],
    'exporters' => ['html' => HtmlExporter::class, 'markdown' => MarkdownExporter::class],
    'tiptap' => [
        'extra_nodes' => [],
        'extra_marks' => [],
        'extra_attributes' => [],
        'url_attributes' => [],
    ],
    'collaboration' => [
        'enabled' => false,
        'driver' => env('VAULTER_COLLAB_DRIVER', 'hocuspocus'),
        'hub' => [
            'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
            'jwt_secret' => env('VAULTER_COLLAB_SECRET'),
            'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
            'token_ttl' => 3600,
            'timestamp_tolerance' => 300,
        ],
        'broadcast' => ['channel_prefix' => 'vaulter.documents'],
    ],
    'models' => [
        'document_revision' => DocumentRevision::class,
        'node_comment' => NodeComment::class,
    ],
];
