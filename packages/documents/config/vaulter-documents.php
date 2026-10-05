<?php

declare(strict_types=1);

use Vaulter\Documents\Engines\Handlers\TipTapHandler;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;

/*
|--------------------------------------------------------------------------
| Vaulter Documents — swappable-model + registry defaults (P2 #5 / ADR-0015, T9)
|--------------------------------------------------------------------------
| The node-comment / document-version models and the built-in exporters are OWNED by
| vaulter-documents: core no longer ships their defaults (Support\Config guard-throws
| on the two models; exporters() falls back to []). The provider merges these arrays
| under the existing `vaulter.models` / `vaulter.handlers` / `vaulter.exporters` keys
| so a host's config('vaulter.models.node_comment') etc. resolve without any core
| change. The v_node_comments / v_node_document_versions SCHEMA stays in core
| (schema-owner, T2).
|
| The `handlers` list here contributes ONLY TipTapHandler — PreviewHandler/
| DownloadOnlyHandler moved to core's OWN `vaulter.handlers` baseline (VLT-CANON
| P0.4/D10: a drive-only host without this package still resolves preview/download).
| UniverHandler (xlsx) is NOT listed here either — the provider tags it separately,
| ONLY when the 'spreadsheets' feature is on, so a stock install never routes xlsx to
| it.
*/

return [
    'models' => [
        'node_comment' => NodeComment::class,
        'node_document_version' => DocumentRevision::class,
    ],

    'handlers' => [
        TipTapHandler::class,
    ],

    'exporters' => [
        'html' => HtmlExporter::class,
        'markdown' => MarkdownExporter::class,
    ],
];
