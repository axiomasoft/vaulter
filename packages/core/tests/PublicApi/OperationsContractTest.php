<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\CursorPaginator;
use Psr\Http\Message\StreamInterface;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Data\Snapshots\GrantSnapshot;
use Vaulter\Data\Snapshots\MintedShareLink as SnapshotMintedShareLink;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Access\ValueObjects\AccessExplanation;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Sharing\MintedShareLink;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\InheritanceRestriction;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\NodeSort;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;

function p32Type(?ReflectionType $type): string
{
    if ($type === null) {
        return '';
    }
    if ($type instanceof ReflectionUnionType) {
        $parts = array_map(p32Type(...), $type->getTypes());
    } else {
        $parts = [$type->getName()];
        if ($type->allowsNull() && !in_array($type->getName(), ['mixed', 'null'], true)) {
            $parts[] = 'null';
        }
    }
    sort($parts);

    return implode('|', $parts);
}

/**
 * @param bool $snapshots PLAN2.D44 amends the literal model types of the operations to snapshots and pages
 */
function p32ExpectedType(string $type, bool $snapshots = false): string
{
    if ($type === '') {
        return '';
    }
    $symbols = [
        // The audit literal (AD17) predates the P3.8 split: its `OperationContext` is today's DriveContext.
        'OperationContext' => DriveContext::class,
        'DriveContext' => DriveContext::class,
        'Node' => Node::class,
        'FileRevision' => FileRevision::class,
        'NodeGrant' => NodeGrant::class,
        'NodeShare' => NodeShare::class,
        'Activity' => Activity::class,
        'NodeKind' => NodeKind::class,
        'OnNameConflict' => OnNameConflict::class,
        'NodeSort' => NodeSort::class,
        'NodeAbilities' => NodeAbilities::class,
        'AccessExplanation' => AccessExplanation::class,
        'Ability' => Ability::class,
        'InheritanceRestriction' => InheritanceRestriction::class,
        'Authorizable' => Authorizable::class,
        'Collection' => Collection::class,
        'CursorPaginator' => CursorPaginator::class,
        'GrantInput' => GrantInput::class,
        'ShareLinkInput' => ShareLinkInput::class,
        'ShareLinkUpdate' => ShareLinkUpdate::class,
        'MintedShareLink' => MintedShareLink::class,
        'UploadedFile' => UploadedFile::class,
        'StreamInterface' => StreamInterface::class,
        'DateTimeInterface' => DateTimeInterface::class,
    ];
    if ($snapshots) {
        $symbols = [...$symbols,
            'Node' => NodeSnapshot::class,
            'FileRevision' => FileRevisionSnapshot::class,
            'NodeGrant' => GrantSnapshot::class,
            'NodeShare' => ShareLinkSnapshot::class,
            'Activity' => ActivityEntrySnapshot::class,
            'MintedShareLink' => SnapshotMintedShareLink::class,
            'NodePage' => Page::class,
            'CursorPaginator' => Page::class,
            'Collection' => 'array',
        ];
    }
    $type = str_replace('NodeKey', 'Node|int|string', $type);
    if (str_starts_with($type, '?')) {
        $type = substr($type, 1).'|null';
    }
    $parts = array_map(static fn (string $part): string => $symbols[$part] ?? (in_array($part, ['int', 'string', 'bool', 'array', 'void', 'mixed', 'null', 'self'], true) ? $part : 'Vaulter\\Data\\Drive\\'.$part), explode('|', $type));
    sort($parts);

    return implode('|', $parts);
}

function p32AssertSignature(ReflectionMethod $method, string $parameters, string $return, bool $snapshots = false): void
{
    $parameters = preg_replace('/\/\*\*.*?\*\//s', '', $parameters);
    $parameters = preg_replace('/\/\/[^\n]*/', '', $parameters);
    $expected = array_values(array_filter(array_map(trim(...), explode(',', trim($parameters, " \n\r\t,")))));
    $actual = $method->getParameters();
    expect($actual)->toHaveCount(count($expected));
    foreach ($expected as $index => $parameter) {
        preg_match('/^(?:public\s+)?(?:(\S+)\s+)?\$(\w+)(?:\s*=\s*(.+))?$/s', $parameter, $matches);
        expect($matches)->not->toBeEmpty();
        $ref = $actual[$index];
        expect($ref->getName())->toBe($matches[2])
            ->and(p32Type($ref->getType()))->toBe(p32ExpectedType($matches[1] ?? '', $snapshots))
            ->and($ref->isDefaultValueAvailable())->toBe(isset($matches[3]));
        if (isset($matches[3])) {
            $default = trim($matches[3]);
            $value = match ($default) {
                'null' => null, 'true' => true, 'false' => false, '[]' => [], '50' => 50,
                default => constant(p32ExpectedType(explode('::', $default)[0], $snapshots).'::'.explode('::', $default)[1]),
            };
            expect($ref->getDefaultValue())->toBe($value);
        }
    }
    if ($method->getName() !== '__construct') {
        expect(p32Type($method->getReturnType()))->toBe(p32ExpectedType($return, $snapshots));
    }
}

it('matches all seven Operations signatures against the literal normative PHP API', function (): void {
    $literal = file_get_contents(dirname(__DIR__, 4).'/audits/2026-09-29-stable-audit/opus/05-php-api.md');
    // Plan amendments of the literal 05 interfaces (PLAN2.D40, D41, D45, D47, D48, D62). PLAN2.D44 turns the
    // model, pagination and collection types of every operation into snapshots and Page. PLAN2.D62 (C07)
    // gives purge and restoreRevision an optional expected sequence: the literal method is replaced.
    $removed = ['NodeOperations::update', 'TrashOperations::purge', 'FileOperations::restoreRevision'];
    $amendments = ['NodeOperations' => '
        public function star(NodeKey $node): Node;
        public function unstar(NodeKey $node): Node;
        public function starred(?ListOptions $options = null): NodePage;
        public function properties(NodeKey $node): array;
        public function setProperties(NodeKey $node, string $namespace, array $set, array $unset = [], ?int $expectedSequence = null): Node;
        public function abilitiesMany(array $nodes): array;
    ', 'FileOperations' => '
        public function deleteRevision(NodeKey $file, FileRevision|int|string $revision): void;
        public function setRevisionRetention(NodeKey $file, FileRevision|int|string $revision, bool $keepForever): FileRevision;
        public function restoreRevision(NodeKey $file, FileRevision|int|string $revision, ?int $expectedSequence = null): FileRevision;
    ', 'TrashOperations' => '
        public function purge(NodeKey $node, ?int $expectedSequence = null): void;
    ', 'AccessOperations' => '
        public function restrictInheritance(NodeKey $node, InheritanceRestriction $mode = InheritanceRestriction::Empty): Node;
        public function restoreInheritance(NodeKey $node): Node;
    ', 'ShareOperations' => '
        public function update(NodeKey $node, NodeShare|int|string $share, ShareLinkUpdate $changes): NodeShare;
        public function rotate(NodeKey $node, NodeShare|int|string $share): MintedShareLink;
    '];
    $count = 0;
    foreach (['Drive' => ['NodeOperations', 'FolderOperations', 'FileOperations', 'TrashOperations'], 'Access' => ['AccessOperations'], 'Sharing' => ['ShareOperations'], 'Activity' => ['ActivityOperations']] as $domain => $names) {
        foreach ($names as $name) {
            preg_match('/interface '.$name.'\s*\{(.*?)\n\}/s', $literal, $body);
            preg_match_all('/public function (\w+)\((.*?)\): (\S+);/s', $body[1], $methods, PREG_SET_ORDER);
            preg_match_all('/public function (\w+)\((.*?)\): (\S+);/s', $amendments[$name] ?? '', $amended, PREG_SET_ORDER);
            // PLAN2.D40 removes the shared pinned/position update in favour of personal stars;
            // a replaced literal method is dropped here and comes back from the amendments.
            $methods = [...array_values(array_filter($methods, static fn (array $method): bool => !in_array($name.'::'.$method[1], $removed, true))), ...$amended];
            $ref = new ReflectionClass((in_array($domain, ['Access', 'Sharing'], true) ? 'Vaulter\\Drive\\Contracts\\' : 'Vaulter\\Contracts\\').$domain.'\\'.$name);
            expect($ref->isInterface())->toBeTrue()->and($ref->getMethods())->toHaveCount(count($methods));
            foreach ($methods as $method) {
                p32AssertSignature($ref->getMethod($method[1]), $method[2], $method[3], snapshots: true);
                $count++;
            }
        }
    }
    expect($count)->toBe(39);
});

it('matches immutable value constructors and UploadSource factory signatures against literal 05', function (): void {
    $literal = file_get_contents(dirname(__DIR__, 4).'/audits/2026-09-29-stable-audit/opus/05-php-api.md');
    $literal = preg_replace('/\/\/[^\n]*/', '', $literal);
    foreach (['UploadOptions', 'RevisionOptions', 'CreateFolderOptions', 'MoveOptions', 'CopyOptions', 'RestoreOptions', 'ListOptions', 'SearchOptions', 'FileStream'] as $name) {
        preg_match('/final readonly class '.$name.'\b.*?public function __construct\((.*?)\)/s', $literal, $matches);
        $ref = new ReflectionClass('Vaulter\\Data\\Drive\\'.$name);
        expect($ref->isFinal())->toBeTrue()->and($ref->isReadOnly())->toBeTrue();
        if (in_array($name, ['UploadOptions', 'RevisionOptions'], true) && !str_contains($matches[1], 'declaredMimeType')) {
            $matches[1] = rtrim($matches[1], " \n\r\t,").', public ?string $declaredMimeType = null';
        }
        if ($name === 'RestoreOptions') {
            // PLAN2.D62 (C07): the trashed node's sequence the caller last saw.
            $matches[1] = rtrim($matches[1], " \n\r\t,").', public ?int $expectedSequence = null';
        }
        if ($name === 'UploadOptions') {
            // PLAN2.D42: the uploader's own modification time, informational only.
            $matches[1] = rtrim($matches[1], " \n\r\t,").', public ?DateTimeInterface $clientModifiedAt = null';
        }
        p32AssertSignature($ref->getConstructor(), $matches[1], '', snapshots: true);
    }
    preg_match('/final readonly class UploadSource\s*\{(.*?)\n\}/s', $literal, $body);
    preg_match_all('/public (static )?function (\w+)\((.*?)\): (\S+);/s', $body[1], $methods, PREG_SET_ORDER);
    $ref = new ReflectionClass(UploadSource::class);
    expect($ref->isFinal())->toBeTrue()->and($ref->isReadOnly())->toBeTrue()->and($methods)->toHaveCount(10);
    foreach ($methods as $method) {
        p32AssertSignature($ref->getMethod($method[2]), $method[3], $method[4]);
        expect($ref->getMethod($method[2])->isStatic())->toBe($method[1] !== '');
    }
});

it('matches lifecycle signatures against AD17 and exposes canonical operation families', function (): void {
    $literal = file_get_contents(dirname(__DIR__, 4).'/audits/2026-09-29-stable-audit/opus/02-decisions.md');
    preg_match('/interface NodeContentLifecycle\s*\{(.*?)\n\}/s', $literal, $body);
    preg_match_all('/public function (\w+)\((.*?)\): (\S+);/s', $body[1], $methods, PREG_SET_ORDER);
    $ref = new ReflectionClass(NodeContentLifecycle::class);
    foreach ($methods as $method) {
        p32AssertSignature($ref->getMethod($method[1]), $method[2], $method[3]);
    }
    expect($methods)->toHaveCount(3)
        ->and(method_exists(DriveHandle::class, 'upload'))->toBeFalse()
        ->and(method_exists(DriveHandle::class, 'files'))->toBeTrue();
});
