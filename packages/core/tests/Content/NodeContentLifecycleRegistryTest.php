<?php

declare(strict_types=1);

use Vaulter\Content\NodeContentLifecycleRegistry;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Contracts\Drive\PurgeOnlyContentLifecycle;
use Vaulter\Data\Drive\CopyResult;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\UnsupportedNodeKindException;
use Vaulter\Exceptions\Registry\DuplicateNodeContentLifecycleException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;

function registryLifecycle(NodeKind $kind, bool $purgeOnly = false): NodeContentLifecycle
{
    return $purgeOnly
        ? new class($kind) extends RegistryTestLifecycle implements PurgeOnlyContentLifecycle {}
    : new RegistryTestLifecycle($kind);
}

class RegistryTestLifecycle implements NodeContentLifecycle
{
    public function __construct(private readonly NodeKind $kind) {}

    public function kind(): NodeKind
    {
        return $this->kind;
    }

    public function copy(DriveContext $context, Node $source, Node $copy): CopyResult
    {
        return new CopyResult(addedBytes: 0);
    }

    public function purge(DriveContext $context, Node $node): void {}
}

it('resolves the registered lifecycle of a kind', function (): void {
    $registry = new NodeContentLifecycleRegistry;
    $handler = registryLifecycle(NodeKind::File);
    $registry->register($handler);

    expect($registry->forKind(NodeKind::File))->toBe($handler)
        ->and(fn () => $registry->forKind(NodeKind::Document))->toThrow(UnsupportedNodeKindException::class);
});

it('refuses a second registration of a kind with duplicate_registration and keeps the first', function (): void {
    $registry = new NodeContentLifecycleRegistry;
    $first = registryLifecycle(NodeKind::Document);
    $registry->register($first);

    try {
        $registry->register(registryLifecycle(NodeKind::Document));
        $this->fail('A duplicate registration must throw.');
    } catch (DuplicateNodeContentLifecycleException $exception) {
        expect($exception->problemCode())->toBe('duplicate_registration');
    }
    expect($registry->forKind(NodeKind::Document))->toBe($first);
});

it('uses the fallback only while the kind has no primary registration', function (): void {
    $registry = new NodeContentLifecycleRegistry;
    $fallback = registryLifecycle(NodeKind::Document, purgeOnly: true);
    $registry->registerFallback($fallback);
    expect($registry->forKind(NodeKind::Document))->toBe($fallback);

    $primary = registryLifecycle(NodeKind::Document);
    $registry->register($primary);

    expect($registry->forKind(NodeKind::Document))->toBe($primary)
        ->and(fn () => $registry->registerFallback(registryLifecycle(NodeKind::Document, purgeOnly: true)))
        ->toThrow(DuplicateNodeContentLifecycleException::class);
});

it('allows copy of folders and of kinds whose lifecycle is not purge-only', function (): void {
    $registry = new NodeContentLifecycleRegistry;
    $registry->register(registryLifecycle(NodeKind::File));
    $registry->registerFallback(registryLifecycle(NodeKind::Document, purgeOnly: true));
    $registry->register(registryLifecycle(NodeKind::Shortcut, purgeOnly: true));

    $registry->assertCopySupported(NodeKind::Folder);
    $registry->assertCopySupported(NodeKind::File);
    expect(true)->toBeTrue();
});

it('refuses copy of a kind resolved to a purge-only lifecycle, primary or fallback', function (NodeKind $kind, bool $asFallback): void {
    $registry = new NodeContentLifecycleRegistry;
    $asFallback
        ? $registry->registerFallback(registryLifecycle($kind, purgeOnly: true))
        : $registry->register(registryLifecycle($kind, purgeOnly: true));

    expect(fn () => $registry->assertCopySupported($kind))->toThrow(UnsupportedNodeKindException::class);
})->with([
    'document fallback' => [NodeKind::Document, true],
    'shortcut primary' => [NodeKind::Shortcut, false],
]);

it('lets a full lifecycle replace the purge-only fallback for copy', function (): void {
    $registry = new NodeContentLifecycleRegistry;
    $registry->registerFallback(registryLifecycle(NodeKind::Document, purgeOnly: true));
    $registry->register(registryLifecycle(NodeKind::Document));

    $registry->assertCopySupported(NodeKind::Document);
    expect(true)->toBeTrue();
});

it('refuses copy of an unregistered kind', function (): void {
    expect(fn () => (new NodeContentLifecycleRegistry)->assertCopySupported(NodeKind::File))
        ->toThrow(UnsupportedNodeKindException::class);
});
