<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use RuntimeException;
use Vaulter\Documents\Models\NodeComment;
use Vaulter\Documents\Models\NodeDocumentVersion;
use Vaulter\Support\Config;

/**
 * Typed resolvers for the swappable node-comment / document-version models (P2 #5 /
 * ADR-0015). Core's Support\Config returns an opaque `class-string<Model>` for these
 * (it must not name a vaulter-documents class); this package OWNS NodeComment/
 * NodeDocumentVersion, so it narrows the configured class back to its concrete type
 * here — a real guard (a misconfigured host class that does not extend the base fails
 * loudly), not a phpdoc cast.
 */
final class DocumentsModels
{
    /**
     * @return class-string<NodeComment>
     */
    public static function comment(): string
    {
        $class = Config::nodeCommentModel();

        if (!is_a($class, NodeComment::class, true)) {
            throw new RuntimeException(
                "Configured vaulter.models.node_comment [{$class}] must extend ".NodeComment::class.'.',
            );
        }

        return $class;
    }

    /**
     * @return class-string<NodeDocumentVersion>
     */
    public static function documentVersion(): string
    {
        $class = Config::nodeDocumentVersionModel();

        if (!is_a($class, NodeDocumentVersion::class, true)) {
            throw new RuntimeException(
                "Configured vaulter.models.node_document_version [{$class}] must extend ".NodeDocumentVersion::class.'.',
            );
        }

        return $class;
    }
}
