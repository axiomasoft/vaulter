<?php

declare(strict_types=1);

$dir = $argv[1] ?? '';
if ($dir === '' || ! is_dir($dir)) {
    fwrite(STDERR, "usage: dump-cache.php <pest-mutate-cache-dir>\n");
    exit(2);
}

$out = [
    'files' => 0,
    'results' => [],
];

foreach ((array) glob($dir.DIRECTORY_SEPARATOR.'cache-*') as $path) {
    if ($path === false || ! is_file($path)) {
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false || strlen($content) < 10) {
        continue;
    }
    $data = @unserialize(substr($content, 10));
    if (! is_array($data)) {
        continue;
    }
    $out['files']++;
    foreach ($data as $id => $result) {
        if (is_string($id) && is_string($result)) {
            $out['results'][$id] = $result;
        }
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
