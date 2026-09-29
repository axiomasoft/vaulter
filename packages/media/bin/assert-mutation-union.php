<?php

declare(strict_types=1);

/**
 * Assert a Pest mutation-ID union from two cold driver cache directories.
 *
 * A mutation is killed when either required driver records it as tested or timed
 * out. IDs seen by only one driver remain in the denominator.
 */
if ($argc < 3 || $argc > 4) {
    fwrite(STDERR, "Usage: php bin/assert-mutation-union.php <first-cache-dir> <second-cache-dir> [minimum]\n");

    exit(2);
}

$minimum = isset($argv[3]) ? (float) $argv[3] : 95.0;

/** @return array<string, string> */
$readCache = static function (string $directory): array {
    if (!is_dir($directory)) {
        throw new RuntimeException("Mutation cache directory does not exist: {$directory}");
    }

    $results = [];

    foreach (glob($directory.DIRECTORY_SEPARATOR.'cache-*') ?: [] as $path) {
        $content = file_get_contents($path);

        if ($content === false || strlen($content) < 10) {
            continue;
        }

        $payload = @unserialize(substr($content, 10));

        if (!is_array($payload)) {
            continue;
        }

        foreach ($payload as $id => $result) {
            if (is_string($id) && is_string($result)) {
                $results[$id] = strtolower($result);
            }
        }
    }

    if ($results === []) {
        throw new RuntimeException("Mutation cache contains no results: {$directory}");
    }

    return $results;
};

try {
    $first = $readCache($argv[1]);
    $second = $readCache($argv[2]);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);

    exit(2);
}

$ids = array_unique([...array_keys($first), ...array_keys($second)]);
$killed = 0;
$escaped = 0;

foreach ($ids as $id) {
    $firstResult = $first[$id] ?? null;
    $secondResult = $second[$id] ?? null;

    if (in_array($firstResult, ['tested', 'timeout'], true)
        || in_array($secondResult, ['tested', 'timeout'], true)) {
        $killed++;
    } else {
        $escaped++;
    }
}

$total = count($ids);
$score = $total === 0 ? 0.0 : ($killed / $total) * 100;

echo json_encode([
    'ids' => $total,
    'killed_union' => $killed,
    'escaped_union' => $escaped,
    'only_first_driver' => count(array_diff_key($first, $second)),
    'only_second_driver' => count(array_diff_key($second, $first)),
    'score_percent' => round($score, 2),
    'minimum_percent' => $minimum,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit($score >= $minimum ? 0 : 1);
