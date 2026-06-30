<?php

declare(strict_types=1);

namespace Vaulter\Enums\Drive;

use Vaulter\Attributes\Icon;
use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasIcon;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The kind of a v_nodes row — the single adjacency tree carries all of them
 * (master plan §2.2). It is the built-in sugar over the kind discriminator; the
 * format/handler registry (a later slice) remains the source of truth for what a
 * given file/document can actually do (H.5).
 *
 *  - folder   — a virtual directory, no payload, holds children.
 *  - file     — a binary leaf, payload lives in v_files/v_file_versions/v_blobs.
 *  - document — a DocDrive leaf (TipTap/Yjs), payload in v_document_versions.
 *  - shortcut — a pointer to another node (shortcut_target_id).
 */
enum NodeKind: string
{
    use HasIcon;
    use HasLabel;

    #[Label('Folder')]
    #[Icon('folder')]
    case Folder = 'folder';

    #[Label('File')]
    #[Icon('document')]
    case File = 'file';

    #[Label('Document')]
    #[Icon('document-text')]
    case Document = 'document';

    #[Label('Shortcut')]
    #[Icon('arrow-top-right-on-square')]
    case Shortcut = 'shortcut';

    /**
     * Whether this is a DocDrive document leaf (payload in v_node_document_versions).
     * Built-in sugar over the kind discriminator — the DocumentHandlerRegistry, not
     * this enum, decides what a node can actually do (H.5).
     */
    public function isDocument(): bool
    {
        return $this === self::Document;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
