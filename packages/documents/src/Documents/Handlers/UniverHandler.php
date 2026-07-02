<?php

declare(strict_types=1);

namespace Vaulter\Documents\Documents\Handlers;

use Vaulter\Contracts\Documents\DocumentHandler;
use Vaulter\ValueObjects\Documents\DocumentCapabilities;
use Vaulter\ValueObjects\Documents\FileDescriptor;
use Vaulter\ValueObjects\Documents\HandlerUi;

/**
 * The FEATURE-FLAGGED spreadsheet handler (master plan §7 level 2): the Univer
 * (Apache-2.0) editor for xlsx. In v1 it is editable + previewable but SINGLE-USER
 * (Univer collaboration is a separate Pro/OT engine, not the Yjs lane — so
 * collaborative stays false here).
 *
 * It is behind the 'spreadsheets' feature flag (default OFF): the service provider
 * only registers it in the tagged 'vaulter.handlers' set when the flag is on, so a
 * stock install never resolves xlsx to it (xlsx then falls to DownloadOnly). This is
 * a STUB — it declares routing + capabilities; the actual Univer mount is the Vue
 * slice. It proves the one-class + register extensibility for a non-rich-text
 * editor.
 */
final class UniverHandler implements DocumentHandler
{
    public const int PRIORITY = 90;

    public function id(): string
    {
        return 'univer';
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
        ];
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['xlsx', 'xls'];
    }

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function capabilities(FileDescriptor $file): DocumentCapabilities
    {
        return new DocumentCapabilities(
            editable: true,
            previewable: true,
            downloadable: true,
            thumbnailable: false,
            // Single-user in v1: Univer collaboration is a separate OT engine, not
            // the Yjs/Hocuspocus lane (§7 level 2).
            collaborative: false,
            // The content is a Univer workbook snapshot, NOT a TipTap doc — the write
            // path stores it verbatim (OpaqueDocumentContent), no type:doc validation.
            richText: false,
        );
    }

    public function ui(FileDescriptor $file): HandlerUi
    {
        return new HandlerUi(
            component: 'VaulterSpreadsheet',
            route: 'documents.show',
            props: ['engine' => 'univer'],
        );
    }
}
