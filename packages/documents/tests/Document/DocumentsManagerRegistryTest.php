<?php

declare(strict_types=1);

use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Fixtures\FakeExporter;
use Vaulter\Documents\Tests\Fixtures\Types\KeyedType;
use Vaulter\Exceptions\Registry\RegistryFrozenException;

it('owns the document type registry and exporter registrations in the documents manager', function (): void {
    // Types register while the application boots; afterwards the set is frozen (PLAN2.P4.3).
    expect(array_keys(Documents::types()->all()))->toBe(['vaulter/markdown', 'vaulter/rich-text'])
        ->and(fn () => Documents::types()->register(new KeyedType('acme/sheet')))->toThrow(RegistryFrozenException::class);

    // Exporters are frozen with the registry once the application has booted.
    expect(Documents::exporters()->formatsFor('vaulter/rich-text'))->toBe(['html', 'markdown'])
        ->and(Documents::exporters()->for('vaulter/markdown', 'html')->key())->toBe('vaulter/html')
        ->and(fn () => Documents::exporters()->register(FakeExporter::class))->toThrow(RegistryFrozenException::class);
});
