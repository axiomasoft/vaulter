<?php

declare(strict_types=1);

/**
 * One deletion model: the domain trash is trashed_at on nodes, purge
 * is a real DELETE, grants are deleted and shares are revoked. No package source
 * reaches for Eloquent SoftDeletes, its scopes or its force-delete API.
 */
function noSoftDeletesOffenders(string $source): array
{
    $found = [];
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        [$id, $text, $line] = $token;
        // use ...\SoftDeletes; (import or trait use)
        if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true) && ($text === 'SoftDeletes' || str_ends_with($text, '\\SoftDeletes'))) {
            $found[] = $line.': SoftDeletes';
        }
        if ($id === T_STRING && in_array($text, ['withTrashed', 'onlyTrashed', 'withoutTrashed', 'forceDelete'], true)) {
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                $previous--;
            }
            $next = $i + 1;
            while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }
            $before = $tokens[$previous] ?? null;
            $isCall = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true) && ($tokens[$next] ?? null) === '(';
            if ($isCall) {
                $found[] = $line.': '.$text.'()';
            }
        }
    }

    return $found;
}

it('no package source uses SoftDeletes, withTrashed, onlyTrashed or forceDelete', function (): void {
    $offenders = [];
    foreach (glob(__DIR__.'/../../../packages/*/src') ?: [] as $src) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (noSoftDeletesOffenders((string) file_get_contents($file->getPathname())) as $hit) {
                $offenders[] = 'packages/'.basename(dirname($src)).'/src/'.substr($file->getPathname(), strlen($src) + 1).':'.$hit;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('the scan sees real uses and ignores comments and strings', function (): void {
    $dirty = "<?php\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;\nclass A { use SoftDeletes; function f(\$q) { return \$q->withTrashed()->onlyTrashed(); } }";
    $clean = "<?php\n// SoftDeletes withTrashed()\n\$x = 'forceDelete()'; /* ->forceDelete() */ class B { public function forceDelete() {} public function forceDeleted() {} }";

    expect(noSoftDeletesOffenders($dirty))->not->toBe([])
        ->and(count(noSoftDeletesOffenders($dirty)))->toBeGreaterThanOrEqual(3)
        ->and(noSoftDeletesOffenders($clean))->toBe([]);
});
