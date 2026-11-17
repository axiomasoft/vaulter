<?php

declare(strict_types=1);

namespace Vaulter\Documents\Testing\Contracts;

use Packman\Attributes\Api;
use PHPUnit\Framework\Assert;
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
    /**
     * Historical fixtures must cover each supported older version, with real old
     * content and its current expected projection. Types without UpgradesSchema
     * have no upgrade contract. Decoder limits and viewer/adapter absence belong
     * to the application tests, not arbitrary codec requirements.
     *
     * @param array<int, array{input: array<string, mixed>, expected: array<string, mixed>}> $historicalFixtures
     */
    public function assertDocumentTypeContract(DocumentType $type, array $historicalFixtures = []): void
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
            for ($version = 1; $version < $type->schemaVersion(); $version++) {
                Assert::assertArrayHasKey($version, $historicalFixtures, 'A real historical fixture is required for every supported version.');
                $input = $historicalFixtures[$version]['input'];
                $before = $input;
                $upgraded = $type->upgrade($input, $version);
                Assert::assertSame($before, $input, 'upgrade() must not mutate its input.');
                Assert::assertSame($historicalFixtures[$version]['expected'], $upgraded, 'Every historical version upgrades directly to current.');
                Assert::assertSame($upgraded, $type->upgrade($upgraded, $type->schemaVersion()), 'upgrade() must be idempotent.');
                Assert::assertSame($upgraded, $type->codec()->decode($upgraded, $type->schemaVersion())->toArray());
            }
        }

        foreach ([$default->toArray(), ...array_column($historicalFixtures, 'expected')] as $fixture) {
            $before = $fixture;
            $content = $type->codec()->decode($fixture, $type->schemaVersion());
            Assert::assertSame($before, $fixture, 'decode() must not mutate its input.');
            $permuted = self::permuteContractKeys($fixture);
            Assert::assertSame($content->contentHash(), $type->codec()->decode($permuted, $type->schemaVersion())->contentHash(), 'Associative key order must not change the canonical hash.');
            Assert::assertSame($content->toArray(), $type->codec()->decode($content->toArray(), $type->schemaVersion())->toArray());
        }

        foreach ($type->features()->toArray() as $flag) {
            Assert::assertIsBool($flag);
        }
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed> $value
     * @return array<TKey, mixed>
     */
    private static function permuteContractKeys(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::permuteContractKeys($child);
            }
        }

        return array_is_list($value) ? $value : array_reverse($value, preserve_keys: true);
    }
}
