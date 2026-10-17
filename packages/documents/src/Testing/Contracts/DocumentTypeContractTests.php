<?php

declare(strict_types=1);

namespace Vaulter\Documents\Testing\Contracts;

use PHPUnit\Framework\Assert;
use Vaulter\Attributes\Api;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\UpgradesSchema;

/**
 * The contract every document type must keep, for the host's own types as well as
 * the built-in ones:
 *
 * ```php
 * uses(DocumentTypeContractTests::class);
 *
 * it('keeps the document type contract', function (): void {
 *     $this->assertDocumentTypeContract(new KanbanDocumentType);
 * });
 * ```
 *
 * Run it inside the application (Testbench or the host's TestCase): codecs may read
 * configuration.
 */
#[Api]
trait DocumentTypeContractTests
{
    public function assertDocumentTypeContract(DocumentType $type): void
    {
        Assert::assertMatchesRegularExpression('/^[a-z0-9-]+\/[a-z0-9-]+$/', $type->key(), 'The key must be vendor/name.');
        Assert::assertMatchesRegularExpression('/^[a-z0-9-]+\/[a-z0-9-]+$/', $type->viewer()->key, 'The viewer key must be vendor/name.');
        Assert::assertNotSame('', $type->label());
        Assert::assertGreaterThanOrEqual(1, $type->schemaVersion(), 'The schema version starts at 1.');

        $default = $type->defaultContent();
        $decoded = $type->codec()->decode($default->toArray(), $type->schemaVersion());
        Assert::assertSame($default->toArray(), $decoded->toArray(), 'decode(defaultContent()->toArray()) must round-trip.');

        $again = $type->codec()->decode($default->toArray(), $type->schemaVersion());
        Assert::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $decoded->contentHash(), 'contentHash() is a sha256 hex digest.');
        Assert::assertSame($decoded->contentHash(), $again->contentHash(), 'contentHash() must be deterministic.');
        Assert::assertSame($default->contentHash(), $decoded->contentHash());

        $limit = $type->codec()->maxSizeBytes();
        if ($limit !== null) {
            Assert::assertGreaterThan(0, $limit, 'maxSizeBytes() is null (global limit) or positive.');
            Assert::assertLessThanOrEqual($limit, $default->sizeInBytes(), 'The default content must fit the size limit.');
        }

        if ($type instanceof UpgradesSchema) {
            $current = $default->toArray();
            Assert::assertSame($current, $type->upgrade($current, $type->schemaVersion()), 'Upgrading current content must change nothing.');
            $once = $type->upgrade($current, 1);
            Assert::assertSame($once, $type->upgrade($once, $type->schemaVersion()), 'upgrade() must be idempotent.');
        }

        foreach ($type->capabilities()->toArray() as $flag) {
            Assert::assertIsBool($flag);
        }
    }
}
