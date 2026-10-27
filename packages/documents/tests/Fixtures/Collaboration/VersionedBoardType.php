<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\UpgradesSchema;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;

/** Three genuinely different schemas exercise every from-version, not a current-default shortcut. */
final class VersionedBoardType implements DocumentType, UpgradesSchema
{
    public function key(): string
    {
        return 'fixture/board';
    }

    public function label(): string
    {
        return 'Versioned board';
    }

    public function schemaVersion(): int
    {
        return 3;
    }

    public function codec(): DocumentCodec
    {
        return new OpaqueJsonCodec(maxSizeBytes: 4096);
    }

    public function defaultContent(): DocumentContent
    {
        return OpaqueContent::fromArray(['lanes' => []]);
    }

    public function viewer(): DocumentViewer
    {
        return new DocumentViewer('fixture/board');
    }

    public function features(): DocumentFeatures
    {
        return new DocumentFeatures(editable: true, previewable: true, downloadable: false);
    }

    public function upgrade(array $content, int $fromVersion): array
    {
        if ($fromVersion === 1) {
            $content = ['lanes' => array_map(fn ($column) => ['name' => $column['title'], 'cards' => $column['cards']], $content['columns'])];
        }
        if ($fromVersion < 3) {
            $content = ['lanes' => array_map(fn ($lane) => ['name' => $lane['name'], 'items' => $lane['cards']], $content['lanes'])];
        }

        return $content;
    }
}
