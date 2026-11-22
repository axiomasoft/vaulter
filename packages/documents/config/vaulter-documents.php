<?php

declare(strict_types=1);

use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;

return [
    // Document type classes; add your own DocumentType implementations here.
    'types' => [RichTextDocumentType::class, MarkdownDocumentType::class],
    'default_type' => 'vaulter/rich-text',
    'collaboration' => ['session_ttl' => 300],
    'content' => ['max_size_kb' => 5120],
    'revisions' => ['limit' => 50, 'checkpoint_interval_seconds' => 300],
    'comments' => ['enabled' => true],
    // Document exporter classes, resolved by (document type, format).
    'exporters' => [HtmlExporter::class, MarkdownExporter::class],
    'tiptap' => [
        'extra_nodes' => [],
        'extra_marks' => [],
        'extra_attributes' => [],
        'url_attributes' => [],
    ],
    // The private broadcast channels of a document (`<prefix>.<nodeId>`): the comment events use them, and the
    // collaboration package authorizes them and names its rooms with the same prefix.
    'broadcast' => ['channel_prefix' => 'vaulter.documents'],
    'models' => [
        'document_revision' => DocumentRevision::class,
        'node_comment' => NodeComment::class,
    ],
];
