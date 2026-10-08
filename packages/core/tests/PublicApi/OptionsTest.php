<?php

declare(strict_types=1);

use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\CopyResult;
use Vaulter\Data\Drive\CreateFolderOptions;
use Vaulter\Data\Drive\FileStream;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Data\Drive\RestoreOptions;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\SearchOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\NodeSort;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

it('builds independent options with named fields and preserves original defaults', function (): void {
    $parent = (new ReflectionClass(Node::class))->newInstanceWithoutConstructor();
    $base = UploadOptions::make();
    $upload = $base->parent($parent)->name('report.pdf')->onConflict(OnNameConflict::AddRevision)->declaredMimeType('application/pdf');
    expect($base->parent)->toBeNull()->and($base->name)->toBeNull()->and($base->onConflict)->toBe(OnNameConflict::Fail)
        ->and($upload->parent)->toBe($parent)->and($upload->name)->toBe('report.pdf')->and($upload->declaredMimeType)->toBe('application/pdf');
    $list = ListOptions::make()->perPage(200)->cursor('opaque')->sort(NodeSort::Size, descending: true)->kinds([NodeKind::File]);
    expect($list->perPage)->toBe(200)->and($list->cursor)->toBe('opaque')->and($list->sort)->toBe(NodeSort::Size)
        ->and($list->descending)->toBeTrue()->and($list->kinds)->toBe([NodeKind::File])
        ->and(ListOptions::make()->perPage)->toBe(50);
    expect(SearchOptions::make()->perPage(1)->cursor('next')->within($parent)->within)->toBe($parent)
        ->and(RevisionOptions::make()->expectedSequence(0)->declaredMimeType('text/plain')->expectedSequence)->toBe(0)
        ->and(CreateFolderOptions::make()->onConflict(OnNameConflict::Rename)->onConflict)->toBe(OnNameConflict::Rename)
        ->and(MoveOptions::make()->expectedSequence(5)->onConflict(OnNameConflict::Rename)->expectedSequence)->toBe(5)
        ->and(CopyOptions::make()->name('new')->onConflict(OnNameConflict::Fail)->name)->toBe('new')
        ->and(RestoreOptions::make()->destination('folder-key')->onConflict(OnNameConflict::Fail)->destination)->toBe('folder-key');
    expect(fn () => $upload->name = 'changed')->toThrow(Error::class);
});

it('rejects AddRevision outside upload at constructor and fluent boundaries', function (string $class): void {
    expect(fn () => new $class(onConflict: OnNameConflict::AddRevision))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $class::make()->onConflict(OnNameConflict::AddRevision))->toThrow(InvalidArgumentException::class);
})->with([CreateFolderOptions::class, MoveOptions::class, CopyOptions::class, RestoreOptions::class]);

it('rejects page sizes outside the bounded range at both input boundaries', function (string $class, int $size): void {
    expect(fn () => new $class(perPage: $size))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $class::make()->perPage($size))->toThrow(InvalidArgumentException::class);
})->with([ListOptions::class, SearchOptions::class])->with([0, -1, 201, PHP_INT_MAX]);

it('rejects malformed kind lists and node unions instead of coercing inputs', function (): void {
    expect(fn () => new ListOptions(kinds: ['file']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ListOptions(kinds: [1 => NodeKind::File]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new UploadOptions(parent: new stdClass))->toThrow(TypeError::class)
        ->and(fn () => new SearchOptions(within: []))->toThrow(TypeError::class);
});

it('preserves enum values and the existing iterable NodePage contract', function (): void {
    expect(array_column(OnNameConflict::cases(), 'value'))->toBe(['fail', 'rename', 'add_revision'])
        ->and(array_column(NodeSort::cases(), 'value'))->toBe(['default', 'name', 'updated_at', 'size']);
    $node = (new ReflectionClass(Node::class))->newInstanceWithoutConstructor();
    $page = new NodePage(items: [$node], nextCursor: 'next', scanned: 5);
    expect(iterator_to_array($page))->toBe([$node])->and(count($page))->toBe(1)->and($page->hasMore())->toBeTrue()
        ->and($page->jsonSerialize())->toBe(['items' => [$node], 'nextCursor' => 'next', 'scanned' => 5]);
});

it('adds GrantInput without caller supplied audit identity and leaves migration to its owner', function (): void {
    $ref = new ReflectionClass(GrantInput::class);
    expect($ref->isFinal())->toBeTrue()->and($ref->isReadOnly())->toBeTrue()
        ->and(array_map(static fn (ReflectionParameter $p): string => $p->getName(), $ref->getConstructor()->getParameters()))
        ->toBe(['subject', 'role', 'scope', 'expiresAt'])
        ->and($ref->hasProperty('grantedBy'))->toBeFalse();
});

it('returns a byte stream with exact metadata and caller ownership without a URL', function (): void {
    $stream = tmpfile();
    try {
        $revision = (new ReflectionClass(FileRevision::class))->newInstanceWithoutConstructor();
        $result = new FileStream(stream: $stream, filename: 'file.txt', mimeType: 'text/plain', size: 0, sha256: hash('sha256', ''), revision: $revision);
        expect($result->stream)->toBe($stream)->and($result->revision)->toBe($revision)
            ->and((new ReflectionClass($result))->hasProperty('url'))->toBeFalse()
            ->and(new CopyResult(addedBytes: 0))->addedBytes->toBe(0);
        expect(fn () => new CopyResult(addedBytes: -1))->toThrow(InvalidArgumentException::class);
    } finally {
        fclose($stream);
    }
});
