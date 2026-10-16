<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content\TipTap;

use Vaulter\Attributes\Api;
use Vaulter\Documents\Content\ReadContent;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;

/**
 * TipTap JSON under one {@see TipTapSchema}: strict on write ({@see decode()}),
 * lenient on read ({@see decodeForRead()}), and honest about what the lenient read
 * dropped.
 */
#[Api]
final readonly class TipTapCodec implements DocumentCodec
{
    public function __construct(
        private TipTapSchema $schema,
        private ?int $maxSizeBytes = null,
    ) {}

    public function decode(array $raw, int $schemaVersion): DocumentContent
    {
        return TipTapContent::fromArray($raw, $this->schema);
    }

    /**
     * Stored content for display. Never throws; unknown nodes, marks and attribute keys
     * are stripped and the result is flagged lossy so it is not saved back.
     *
     * @param array<string, mixed> $stored
     */
    public function decodeForRead(array $stored): ReadContent
    {
        if ($stored === []) {
            return new ReadContent(TipTapContent::empty(), lossless: true);
        }

        [$content, $lossless] = TipTapContent::decayReporting($stored, $this->schema);

        return new ReadContent($content, $lossless);
    }

    public function maxSizeBytes(): ?int
    {
        return $this->maxSizeBytes;
    }

    public function schema(): TipTapSchema
    {
        return $this->schema;
    }
}
