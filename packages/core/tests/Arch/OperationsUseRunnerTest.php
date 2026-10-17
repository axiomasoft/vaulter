<?php

declare(strict_types=1);

use Vaulter\Collaboration\Operations\CollaborationOperations;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Drive\Operations\AccessOperations;
use Vaulter\Drive\Operations\ActivityOperations;
use Vaulter\Drive\Operations\ChangeOperations;
use Vaulter\Drive\Operations\FileOperations;
use Vaulter\Drive\Operations\FolderOperations;
use Vaulter\Drive\Operations\NodeOperations;
use Vaulter\Drive\Operations\ShareOperations;
use Vaulter\Drive\Operations\TrashOperations;
use Vaulter\Drive\Operations\UploadOperations;

/**
 * R1 of the operation pipeline (ADR-0028): every public mutating method of an `*Operations` class runs
 * its body through OperationRunner::write() — directly, through the class's private write() helper, or
 * through a private method that does. Reads are listed here and never use the runner.
 */

/** @var array<class-string, list<string>> class => its public methods that only read */
const OPERATIONS_READS = [
    FolderOperations::class => [],
    NodeOperations::class => ['find', 'children', 'search', 'starred', 'properties', 'abilities', 'abilitiesMany'],
    FileOperations::class => ['revisions', 'read'],
    TrashOperations::class => ['list'],
    // patch() and putPart() write request bytes under the session lock; bytes are never read inside a
    // repeated attempt, so these protocol adapters stay outside the runner (P3.10).
    UploadOperations::class => ['status', 'patch', 'putPart'],
    AccessOperations::class => ['grants', 'explain', 'paginate'],
    ShareOperations::class => ['list', 'paginate'],
    ActivityOperations::class => ['forDrive', 'forNode', 'filtered'],
    ChangeOperations::class => ['startCursor', 'list'],
    DocumentOperations::class => ['content', 'revisions', 'revisionPage', 'export'],
    CollaborationOperations::class => ['session'],
    CommentOperations::class => ['list'],
];

function operationsRunnerBody(ReflectionMethod $method): string
{
    $lines = file((string) $method->getFileName()) ?: [];

    return implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
}

/** @return list<string> private methods of $class that run the runner (directly or through write()) */
function operationsRunnerPrivates(ReflectionClass $class): array
{
    $found = [];
    foreach ($class->getMethods(ReflectionMethod::IS_PRIVATE) as $method) {
        if (str_contains(operationsRunnerBody($method), '$this->runner->write(')) {
            $found[] = $method->getName();
        }
    }
    // One more level: a private method that calls one of those.
    foreach ($class->getMethods(ReflectionMethod::IS_PRIVATE) as $method) {
        foreach ($found as $runner) {
            if (!in_array($method->getName(), $found, true) && str_contains(operationsRunnerBody($method), '$this->'.$runner.'(')) {
                $found[] = $method->getName();
            }
        }
    }

    return $found;
}

it('runs every public mutating operation through the operation runner', function (): void {
    $offenders = [];
    foreach (OPERATIONS_READS as $class => $reads) {
        $reflection = new ReflectionClass($class);
        $runners = operationsRunnerPrivates($reflection);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor() || in_array($method->getName(), $reads, true)) {
                continue;
            }
            $body = operationsRunnerBody($method);
            $runs = str_contains($body, '$this->runner->write(')
                || array_filter($runners, static fn (string $runner): bool => str_contains($body, '$this->'.$runner.'(')) !== [];
            if (!$runs) {
                $offenders[] = $class.'::'.$method->getName();
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('lists only reads that exist and do not use the runner', function (): void {
    $stale = [];
    foreach (OPERATIONS_READS as $class => $reads) {
        foreach ($reads as $read) {
            if (!method_exists($class, $read)) {
                $stale[] = $class.'::'.$read.' (missing)';

                continue;
            }
            if (str_contains(operationsRunnerBody(new ReflectionMethod($class, $read)), 'runner->write(')) {
                $stale[] = $class.'::'.$read.' (uses the runner)';
            }
        }
    }

    expect($stale)->toBe([]);
});

it('covers every Operations class of drive, documents and the core-located ones', function (): void {
    $root = dirname(__DIR__, 3);
    $files = [
        ...glob($root.'/drive/src/Operations/*Operations.php') ?: [],
        ...glob($root.'/documents/src/Operations/*Operations.php') ?: [],
    ];
    $listed = array_map(static fn (string $class): string => (new ReflectionClass($class))->getShortName(), array_keys(OPERATIONS_READS));

    foreach ($files as $file) {
        expect($listed)->toContain(basename($file, '.php'));
    }
});
