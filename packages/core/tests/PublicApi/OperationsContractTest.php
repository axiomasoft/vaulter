<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\CursorPaginator;
use Psr\Http\Message\StreamInterface;
use Vaulter\Access\ValueObjects\AccessExplanation;
use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Sharing\MintedShareLink;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\NodeSort;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Operations\OperationContext;

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

function p32ExpectedType(string $type): string
{
    if ($type === '') {
        return '';
    }
    $symbols = [
        'OperationContext' => OperationContext::class,
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
        'Authorizable' => Authorizable::class,
        'Collection' => Collection::class,
        'CursorPaginator' => CursorPaginator::class,
        'GrantInput' => GrantInput::class,
        'ShareLinkInput' => ShareLinkInput::class,
        'MintedShareLink' => MintedShareLink::class,
        'UploadedFile' => UploadedFile::class,
        'StreamInterface' => StreamInterface::class,
    ];
    $type = str_replace('NodeKey', 'Node|int|string', $type);
    if (str_starts_with($type, '?')) {
        $type = substr($type, 1).'|null';
    }
    $parts = array_map(static fn (string $part): string => $symbols[$part] ?? (in_array($part, ['int', 'string', 'bool', 'array', 'void', 'mixed', 'null', 'self'], true) ? $part : 'Vaulter\\Data\\Drive\\'.$part), explode('|', $type));
    sort($parts);

    return implode('|', $parts);
}

function p32AssertSignature(ReflectionMethod $method, string $parameters, string $return): void
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
            ->and(p32Type($ref->getType()))->toBe(p32ExpectedType($matches[1] ?? ''))
            ->and($ref->isDefaultValueAvailable())->toBe(isset($matches[3]));
        if (isset($matches[3])) {
            $default = trim($matches[3]);
            $value = match ($default) {
                'null' => null, 'true' => true, 'false' => false, '[]' => [], '50' => 50,
                default => constant(p32ExpectedType(explode('::', $default)[0]).'::'.explode('::', $default)[1]),
            };
            expect($ref->getDefaultValue())->toBe($value);
        }
    }
    if ($method->getName() !== '__construct') {
        expect(p32Type($method->getReturnType()))->toBe(p32ExpectedType($return));
    }
}

it('matches all seven Operations signatures against the literal normative PHP API', function (): void {
    $literal = file_get_contents(dirname(__DIR__, 4).'/audits/2026-09-29-stable-audit/opus/05-php-api.md');
    $count = 0;
    foreach (['Drive' => ['NodeOperations', 'FolderOperations', 'FileOperations', 'TrashOperations'], 'Access' => ['AccessOperations'], 'Sharing' => ['ShareOperations'], 'Activity' => ['ActivityOperations']] as $domain => $names) {
        foreach ($names as $name) {
            preg_match('/interface '.$name.'\s*\{(.*?)\n\}/s', $literal, $body);
            preg_match_all('/public function (\w+)\((.*?)\): (\S+);/s', $body[1], $methods, PREG_SET_ORDER);
            $ref = new ReflectionClass('Vaulter\\Contracts\\'.$domain.'\\'.$name);
            expect($ref->isInterface())->toBeTrue()->and($ref->getMethods())->toHaveCount(count($methods));
            foreach ($methods as $method) {
                p32AssertSignature($ref->getMethod($method[1]), $method[2], $method[3]);
                $count++;
            }
        }
    }
    expect($count)->toBe(28);
});

it('matches immutable value constructors and UploadSource factory signatures against literal 05', function (): void {
    $literal = file_get_contents(dirname(__DIR__, 4).'/audits/2026-09-29-stable-audit/opus/05-php-api.md');
    $literal = preg_replace('/\/\/[^\n]*/', '', $literal);
    foreach (['UploadOptions', 'RevisionOptions', 'CreateFolderOptions', 'MoveOptions', 'CopyOptions', 'RestoreOptions', 'UpdateNodeOptions', 'ListOptions', 'SearchOptions', 'FileStream'] as $name) {
        preg_match('/final readonly class '.$name.'\b.*?public function __construct\((.*?)\)/s', $literal, $matches);
        $ref = new ReflectionClass('Vaulter\\Data\\Drive\\'.$name);
        expect($ref->isFinal())->toBeTrue()->and($ref->isReadOnly())->toBeTrue();
        p32AssertSignature($ref->getConstructor(), $matches[1], '');
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

it('matches lifecycle signatures against AD17 and preserves the existing handle until activation', function (): void {
    $literal = file_get_contents(dirname(__DIR__, 4).'/audits/2026-09-29-stable-audit/opus/02-decisions.md');
    preg_match('/interface NodeContentLifecycle\s*\{(.*?)\n\}/s', $literal, $body);
    preg_match_all('/public function (\w+)\((.*?)\): (\S+);/s', $body[1], $methods, PREG_SET_ORDER);
    $ref = new ReflectionClass(NodeContentLifecycle::class);
    foreach ($methods as $method) {
        p32AssertSignature($ref->getMethod($method[1]), $method[2], $method[3]);
    }
    expect($methods)->toHaveCount(3)
        ->and(method_exists(DriveHandle::class, 'upload'))->toBeTrue()
        ->and(method_exists(DriveHandle::class, 'files'))->toBeFalse();
});
