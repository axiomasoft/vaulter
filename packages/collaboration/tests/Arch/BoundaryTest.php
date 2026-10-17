<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

/*
 * The bypass p5-design#invariants forbids (D26, D27): collaboration changes the state documents own only through the
 * documents state contract (CollaborativeState). Its sources name no v_documents table, write no document row, and
 * neither the documents' state writer nor their store repository.
 *
 * The one exception is the protocol 1 store (Actions/PersistCollaborationStateAction.php behind
 * vaulter.v1.collaboration.hub.store), which still writes through DocumentStateWriter until P5.7 removes it with its
 * route; the exception ends with the route.
 */

/** The protocol 1 store, the only file still allowed the documents' state writer while its route exists. */
const BOUNDARY_V1_STORE = 'Actions/PersistCollaborationStateAction.php';

/** @return array<string, string> path relative to src/ => contents */
function boundarySources(): array
{
    $root = dirname(__DIR__, 2).'/src';
    $files = [];
    foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
        // Code only: a docblock may name what the code must not do.
        $code = '';
        foreach (token_get_all($file->getContents()) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= is_array($token) ? $token[1] : $token;
            }
        }
        $files[substr($file->getPathname(), strlen($root) + 1)] = $code;
    }
    ksort($files);

    return $files;
}

/**
 * @param  list<string> $allowed
 * @return list<string>
 */
function boundaryOffenders(string $pattern, array $allowed = []): array
{
    $offenders = [];
    foreach (boundarySources() as $path => $contents) {
        if (preg_match($pattern, $contents) === 1 && !in_array($path, $allowed, true)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

it('never names the documents table, except as the target of its own foreign keys', function (): void {
    expect(boundaryOffenders('/v_documents\b|(?<!->on\(\$this->helpers)->table\(\s*[\'"]documents[\'"]\s*\)/'))->toBe([]);
});

it('writes no document row through the documents model', function (): void {
    expect(boundaryOffenders('/\bDocument::(query|where|whereKey)\([^;]*->(update|delete|insert|forceFill|save|increment|decrement)\(/s'))->toBe([])
        ->and(boundaryOffenders('/\$(document|aggregate)\w*->(save|update|forceFill|delete)\(/i'))->toBe([]);
});

it('uses neither the documents state writer nor their store repository, except the protocol 1 store until it goes', function (): void {
    $allowed = Route::has('vaulter.v1.collaboration.hub.store') ? [BOUNDARY_V1_STORE] : [];

    expect(boundaryOffenders('/\bDocumentStateWriter\b|\bDocumentStoreRepository\b|\bDocumentRevisionStoreRepository\b/', $allowed))->toBe([])
        ->and(array_key_exists(BOUNDARY_V1_STORE, boundarySources()))->toBe(Route::has('vaulter.v1.collaboration.hub.store'));
});

it('changes the pending marker of a document only through the documents state contract', function (): void {
    expect(boundaryOffenders('/pending_generation|pending_through_seq/'))->toBe([])
        ->and(boundarySources()['Persistence/UpdateLog.php'])->toContain('->markPending(');
});
