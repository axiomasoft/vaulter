<?php

declare(strict_types=1);

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Attributes\Spi;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Events\Comments\CommentAdded;
use Vaulter\Documents\Events\Revisions\DocumentRestored;
use Vaulter\Drive\Concerns\HasDrives;
use Vaulter\Drive\Contracts\Access\AccessOperations;
use Vaulter\Drive\Contracts\Activity\ActivityOperations;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Contracts\FileOperations;
use Vaulter\Drive\Contracts\FolderOperations;
use Vaulter\Drive\Contracts\Handlers\NodePresenter as NodePresenterContract;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Contracts\NodeOperations;
use Vaulter\Drive\Contracts\NodeSnapshotContributor;
use Vaulter\Drive\Contracts\Presentation\KindPresenter;
use Vaulter\Drive\Contracts\Sharing\ShareOperations;
use Vaulter\Drive\Contracts\TrashOperations;
use Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Events\Files\FileUploaded;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\AccessOperations as CoreAccessOperations;
use Vaulter\Drive\Operations\ActivityOperations as CoreActivityOperations;
use Vaulter\Drive\Operations\ShareOperations as CoreShareOperations;
use Vaulter\Drive\Operations\UploadOperations;
use Vaulter\Drive\Testing\Contracts\PermissionFixture;
use Vaulter\Drive\Testing\InteractsWithVaulter;
use Vaulter\Media\Cas\BlobStore as CasBlobStore;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore as BlobStoreContract;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;

/**
 * No public Operations/handle method returns or takes an Eloquent
 * model, builder, collection or paginator. A method marked @internal (the handle's
 * `model()` and the HTTP list paginators) is outside the contract and exempt.
 */
function forbiddenPublicType(string $type): bool
{
    foreach ([Model::class, Builder::class, Collection::class, AbstractPaginator::class, AbstractCursorPaginator::class, Paginator::class] as $forbidden) {
        if (is_a($type, $forbidden, true)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function leakedTypes(?ReflectionType $type, bool $input = false): array
{
    if ($type === null) {
        return [];
    }
    $members = $type instanceof ReflectionNamedType ? [$type] : ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType ? $type->getTypes() : []);
    $leaked = [];
    foreach ($members as $member) {
        // Borrowed host input: a parameter may take the host's own model as the abstract Eloquent Model, never a
        // Vaulter model; a return never hands out any model.
        if ($member instanceof ReflectionNamedType && !$member->isBuiltin() && forbiddenPublicType($member->getName())
            && !($input && $member->getName() === Model::class)) {
            $leaked[] = $member->getName();
        }
    }

    return $leaked;
}

/** @return list<string> */
function leaks(string $class, bool $input = false): array
{
    $leaks = [];
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() || str_contains((string) $method->getDocComment(), '@internal') || $method->getAttributes(Internal::class) !== []) {
            continue;
        }
        foreach (leakedTypes($method->getReturnType()) as $type) {
            $leaks[] = $class.'::'.$method->getName().'() returns '.$type;
        }
        foreach ($method->getParameters() as $parameter) {
            foreach (leakedTypes($parameter->getType(), $input) as $type) {
                $leaks[] = $class.'::'.$method->getName().'($'.$parameter->getName().') takes '.$type;
            }
        }
    }

    return $leaks;
}

/**
 * Every supported type of the packages, read from their attributes: the Api and Spi classes, interfaces and
 * traits under src/, with the public properties a host reads besides the methods it calls.
 *
 * @return list<class-string>
 */
function supportedPublicTypes(): array
{
    $root = dirname(__DIR__, 3);
    $types = [];
    foreach (['core', 'drive', 'documents', 'media', 'webhooks'] as $package) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages/'.$package.'/src', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/^#\[(Api|Spi)\b/m', $code) !== 1 || preg_match('/^namespace\s+([^;]+);/m', $code, $namespace) !== 1
                || preg_match('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $name) !== 1) {
                continue;
            }
            $class = $namespace[1].'\\'.$name[1];
            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                $reflection = new ReflectionClass($class);
                if ($reflection->getAttributes(Api::class) !== [] || $reflection->getAttributes(Spi::class) !== []) {
                    $types[] = $class;
                }
            }
        }
    }
    sort($types);

    return array_values(array_unique($types));
}

/** @return list<string> the public properties of $class that are not Internal and name an Eloquent type */
function propertyLeaks(string $class): array
{
    $leaks = [];
    foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getAttributes(Internal::class) !== []) {
            continue;
        }
        foreach (leakedTypes($property->getType()) as $type) {
            $leaks[] = $class.'::$'.$property->getName().' is '.$type;
        }
    }

    return $leaks;
}

it('keeps Eloquent out of every supported type: the Operations, the SPI, the snapshots, the events and the testkit', function (): void {
    $types = supportedPublicTypes();
    $leaks = [];
    foreach ($types as $type) {
        array_push($leaks, ...leaks($type, input: true), ...propertyLeaks($type));
    }

    // The discovery reads the attributes, not a hand list: the model-free contracts are all in it.
    expect($types)->toContain(
        UploadOperations::class, UploadProtocolHandler::class, UploadSessionSnapshot::class, InitiateUploadInput::class,
        DownloadGuard::class, NodeContentLifecycle::class, NodePresenterContract::class, KindPresenter::class, NodeSnapshotContributor::class,
        ExportFilenameResolver::class, BlobStoreContract::class, BlobPins::class, BlobSnapshot::class, CasBlobStore::class,
        PermissionFixture::class, InteractsWithVaulter::class, HasDrives::class, FileUploaded::class, DocumentRestored::class, CommentAdded::class,
    )->and(count($types))->toBeGreaterThan(100)
        ->and($leaks)->toBe([]);
});

it('sees the guard on the whole surface: a Vaulter model taken, any model returned or kept public is reported', function (): void {
    $probe = new class
    {
        public ?Drive $kept = null;

        public function take(Node $node): void {}

        public function host(Model $owner): void {}

        public function give(): Model
        {
            throw new LogicException;
        }
    };

    expect(leaks($probe::class, input: true))->toBe([$probe::class.'::take($node) takes '.Node::class, $probe::class.'::give() returns '.Model::class])
        ->and(propertyLeaks($probe::class))->toBe([$probe::class.'::$kept is '.Drive::class]);
});

it('keeps Eloquent out of the public operation contracts', function (string $class): void {
    expect(leaks($class))->toBe([]);
})->with([
    NodeOperations::class,
    FolderOperations::class,
    FileOperations::class,
    TrashOperations::class,
    DriveHandle::class,
    AccessOperations::class,
    ShareOperations::class,
    ActivityOperations::class,
    CoreAccessOperations::class,
    CoreShareOperations::class,
    CoreActivityOperations::class,
]);

it('sees the guard: a contract with a model in its signature is reported', function (): void {
    $probe = new class
    {
        public function find(): Model
        {
            throw new LogicException;
        }
    };

    expect(leaks($probe::class))->not->toBe([]);
});
