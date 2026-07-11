<?php

declare(strict_types=1);

use Vaulter\Documents\Documents\Handlers\DownloadOnlyHandler;
use Vaulter\Documents\Documents\Handlers\PreviewHandler;
use Vaulter\Documents\Documents\Handlers\TipTapHandler;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;

/*
|--------------------------------------------------------------------------
| Vaulter Documents — swappable-model + registry defaults (P2 #5 / ADR-0015, T9)
|--------------------------------------------------------------------------
| The node-comment / document-version models, the built-in format handlers and the
| built-in exporters are OWNED by vaulter-documents: core no longer ships their
| defaults (Support\Config guard-throws on the two models; documentHandlers()/
| exporters() fall back to []). The provider merges these arrays under the existing
| `vaulter.models` / `vaulter.handlers` / `vaulter.exporters` keys so a host's
| config('vaulter.models.node_comment') etc. resolve without any core change. The
| v_node_comments / v_node_document_versions SCHEMA stays in core (schema-owner, T2).
| UniverHandler (xlsx) is NOT listed here — the provider tags it separately, ONLY
| when the 'spreadsheets' feature is on, so a stock install never routes xlsx to it.
*/

return [
    'models' => [
        'node_comment' => NodeComment::class,
        'node_document_version' => NodeDocumentVersion::class,
    ],

    'handlers' => [
        TipTapHandler::class,
        PreviewHandler::class,
        DownloadOnlyHandler::class,
    ],

    'exporters' => [
        'html' => HtmlExporter::class,
        'markdown' => MarkdownExporter::class,
    ],
];
