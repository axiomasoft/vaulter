<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Exceptions\DuplicateDocumentTypeException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanType;
use Vaulter\Documents\Tests\Fixtures\Types\KeyedType;
use Vaulter\Documents\Tests\Fixtures\Types\OpaqueNoteType;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Registry\RegistryFrozenException;

/**
 * One registry of document types keyed vendor/name — sources, key rules,
 * duplicates, replacement, deterministic order and the freeze after boot.
 */
function freshTypes(): DocumentTypeRegistry
{
    return new DocumentTypeRegistry(app());
}

it('registers the configured types at boot and freezes once the application booted', function (): void {
    $types = Documents::types();

    expect(array_keys($types->all()))->toBe(['acme/kanban', 'acme/opaque-note', 'vaulter/markdown', 'vaulter/rich-text'])
        ->and($types->get('acme/kanban'))->toBeInstanceOf(AcmeKanbanType::class)
        ->and($types->isFrozen())->toBeTrue();

    expect(fn () => $types->register(new KeyedType('acme/late')))->toThrow(RegistryFrozenException::class);
    expect(fn () => $types->replace('acme/kanban', AcmeKanbanType::class))->toThrow(RegistryFrozenException::class);
});

it('answers has, get and find for registered and unknown keys', function (): void {
    $types = freshTypes();
    $types->register(RichTextDocumentType::class);

    expect($types->has('vaulter/rich-text'))->toBeTrue()
        ->and($types->has('acme/gone'))->toBeFalse()
        ->and($types->find('acme/gone'))->toBeNull()
        ->and($types->find('vaulter/rich-text'))->toBeInstanceOf(RichTextDocumentType::class);

    expect(fn (): DocumentType => $types->get('acme/gone'))->toThrow(UnknownDocumentTypeException::class);
});

it('refuses a duplicate key and replaces only through replace()', function (): void {
    $types = freshTypes();
    $types->register(new KeyedType('acme/board'));

    expect(fn () => $types->register(new KeyedType('acme/board')))->toThrow(DuplicateDocumentTypeException::class);

    $replacement = new KeyedType('acme/board');
    $types->replace('acme/board', $replacement);

    expect($types->get('acme/board'))->toBe($replacement);
    expect(fn () => $types->replace('acme/other', new KeyedType('acme/board')))->toThrow(InvalidConfigurationException::class);
    expect(fn () => $types->replace('acme/missing', new KeyedType('acme/missing')))->toThrow(UnknownDocumentTypeException::class);
});

it('rejects keys outside vendor/name and the reserved vaulter/ prefix for third parties', function (string $key): void {
    expect(fn () => freshTypes()->register(new KeyedType($key)))->toThrow(InvalidConfigurationException::class);
})->with([
    'no vendor' => ['kanban'],
    'upper case' => ['Acme/Kanban'],
    'three segments' => ['acme/kanban/board'],
    'underscore' => ['acme/kan_ban'],
    'reserved prefix' => ['vaulter/kanban'],
]);

it('rejects a class name that is not a document type', function (string $class): void {
    expect(fn () => freshTypes()->register($class))->toThrow(InvalidConfigurationException::class);
})->with([
    'missing class' => ['App\\Vaulter\\DocumentTypes\\MissingDocumentType'],
    'not a type' => [stdClass::class],
]);

it('orders types by key whatever order the providers registered them in', function (): void {
    $forward = freshTypes();
    $backward = freshTypes();

    foreach ([AcmeKanbanType::class, OpaqueNoteType::class, MarkdownDocumentType::class, RichTextDocumentType::class] as $class) {
        $forward->register($class);
    }
    foreach ([RichTextDocumentType::class, MarkdownDocumentType::class, OpaqueNoteType::class, AcmeKanbanType::class] as $class) {
        $backward->register($class);
    }

    expect(array_keys($forward->all()))->toBe(array_keys($backward->all()))
        ->and(array_keys($forward->all()))->toBe(['acme/kanban', 'acme/opaque-note', 'vaulter/markdown', 'vaulter/rich-text']);
});

it('collects types from two providers in either order and from the container tag', function (): void {
    $first = new class(app()) extends ServiceProvider
    {
        public function register(): void
        {
            $this->app->make(DocumentTypeRegistry::class)->register(new KeyedType('acme/first'));
        }
    };
    $second = new class(app()) extends ServiceProvider
    {
        public function register(): void
        {
            $this->app->make(DocumentTypeRegistry::class)->register(new KeyedType('acme/second'));
        }
    };

    $orders = [];
    foreach ([[$first, $second], [$second, $first]] as $providers) {
        app()->instance(DocumentTypeRegistry::class, freshTypes());
        foreach ($providers as $provider) {
            $provider->register();
        }
        $orders[] = array_keys(app(DocumentTypeRegistry::class)->all());
    }

    expect($orders[0])->toBe($orders[1])->toBe(['acme/first', 'acme/second']);
});

it('serializes no class name: all() is keyed by the type key', function (): void {
    foreach (Documents::types()->all() as $key => $type) {
        expect($key)->toBe($type->key())
            ->and($type->viewer()->toArray())->not->toContain($type::class);
    }
});
