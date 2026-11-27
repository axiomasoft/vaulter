#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Offline check of the documentation links.
 *
 *   php scripts/check-doc-links.php                  docs/ and README.md (the CI set)
 *   php scripts/check-doc-links.php docs README.md   every relative link and #anchor resolves
 *
 * External links (http, https, mailto) are not fetched. Anchors follow the VitePress
 * slug rule, so a heading and its {#custom-id} both count.
 */
$root = dirname(__DIR__);
$args = array_slice($argv, 1);
if ($args === []) {
    // Bare invocation is the docs gate: the same pages CI passes explicitly.
    $args = ['docs', 'README.md'];
}

/** @return list<string> markdown files below a directory, relative to it */
function markdownFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[] = substr($file->getPathname(), strlen($directory) + 1);
        }
    }
    sort($files);

    return $files;
}

/** The markdown without fenced code blocks (lines kept so line numbers stay true). */
function withoutFences(string $text): string
{
    $out = [];
    $fence = null;
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\s*(```+|~~~+)/', $line, $match) === 1) {
            if ($fence === null) {
                $fence = $match[1][0];
            } elseif ($match[1][0] === $fence) {
                $fence = null;
            }
            $out[] = '';

            continue;
        }
        $out[] = $fence === null ? $line : '';
    }

    return implode("\n", $out);
}

/** The VitePress slug of a heading text; letters with a combining mark are folded (é → e) as VitePress does. */
function slug(string $heading): string
{
    $text = html_entity_decode($heading, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
    $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
    $text = str_replace(['`', '*'], '', $text);
    $text = class_exists(Normalizer::class) ? (Normalizer::normalize($text, Normalizer::FORM_KD) ?: $text) : $text;
    $text = preg_replace('/[\x{0300}-\x{036f}]/u', '', $text) ?? $text;
    $text = preg_replace('/[\x00-\x1f]/', '', $text) ?? $text;
    $text = preg_replace('/[\s~`!@#$%^&*()\-_+=\[\]{}|\\\;:"\'“”‘’<>,.?\/]+/u', '-', $text) ?? $text;
    $text = preg_replace('/-{2,}/', '-', $text) ?? $text;
    $text = trim($text, '-');
    $text = preg_replace('/^(\d)/', '_$1', $text) ?? $text;

    return mb_strtolower($text);
}

/** @return array<string, true> the anchors a page defines */
function anchors(string $file): array
{
    static $cache = [];
    if (isset($cache[$file])) {
        return $cache[$file];
    }
    $found = [];
    $seen = [];
    foreach (explode("\n", withoutFences((string) file_get_contents($file))) as $line) {
        if (preg_match('/^#{1,6}\s+(.*?)\s*$/', $line, $match) !== 1) {
            // Explicit anchors in raw HTML.
            if (preg_match_all('/\bid="([^"]+)"/', $line, $ids) > 0) {
                foreach ($ids[1] as $id) {
                    $found[$id] = true;
                }
            }

            continue;
        }
        $heading = $match[1];
        if (preg_match('/^(.*?)\s*\{#([^}\s]+)\}$/', $heading, $custom) === 1) {
            $found[$custom[2]] = true;
            $heading = $custom[1];
        }
        $base = slug($heading);
        $count = $seen[$base] ?? 0;
        $seen[$base] = $count + 1;
        $suffix = $count === 0 ? '' : '-'.$count;
        $found[$base.$suffix] = true;
        // `code_name` headings also answer to the GitHub spelling with underscores: the problem
        // type URIs and exception messages link to the page on GitHub.
        if (preg_match('/^`([a-z0-9_]+)`$/', $heading, $code) === 1) {
            $found[$code[1].$suffix] = true;
        }
    }

    return $cache[$file] = $found;
}

/** The file a link path names, or null; tries the VitePress clean-URL forms. */
function resolve(string $path, string $from, string $docs, string $root): ?string
{
    $base = str_starts_with($path, '/') ? $docs.$path : dirname($from).'/'.$path;
    foreach ([$base, $base.'.md', rtrim($base, '/').'/index.md'] as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && is_file($real)) {
            return $real;
        }
    }
    // A link to a directory page of the repo (for example a package folder).
    $real = realpath($base);

    return $real !== false && is_dir($real) ? $real : null;
}

$docs = $root.'/docs';
$files = [];
foreach ($args as $arg) {
    $path = is_file($arg) ? $arg : $root.'/'.$arg;
    $real = realpath(file_exists($arg) ? $arg : $path);
    if ($real === false) {
        fwrite(STDERR, "{$arg}: not found\n");
        exit(2);
    }
    if (is_dir($real)) {
        foreach (markdownFiles($real) as $file) {
            if (!str_contains('/'.$file, '/node_modules/') && !str_starts_with($file, '.vitepress/') && !str_starts_with($file, 'plan/')) {
                $files[] = $real.'/'.$file;
            }
        }
    } else {
        $files[] = $real;
    }
}

$errors = 0;
$checked = 0;
foreach ($files as $file) {
    $text = withoutFences((string) file_get_contents($file));
    // Inline code is not a link, whatever it contains.
    $text = preg_replace_callback('/`[^`\n]*`/', static fn (array $m): string => str_repeat(' ', strlen($m[0])), $text) ?? $text;
    $display = ltrim(substr($file, strlen($root)), '/');

    foreach (explode("\n", $text) as $index => $line) {
        $targets = [];
        if (preg_match_all('/\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/', $line, $m) > 0) {
            array_push($targets, ...$m[1]);
        }
        if (preg_match_all('/<a\s[^>]*href="([^"]+)"/i', $line, $m) > 0) {
            array_push($targets, ...$m[1]);
        }
        foreach ($targets as $target) {
            if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $target) === 1) {
                continue;
            }
            $checked++;
            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $path = explode('?', $path, 2)[0];
            $destination = $path === '' ? $file : resolve(rawurldecode($path), $file, $docs, $root);
            if ($destination === null) {
                fwrite(STDERR, "{$display}:".($index + 1).": dead link {$target}\n");
                $errors++;

                continue;
            }
            if ($anchor !== null && $anchor !== '' && is_file($destination) && str_ends_with($destination, '.md')) {
                if (!isset(anchors($destination)[rawurldecode($anchor)])) {
                    fwrite(STDERR, "{$display}:".($index + 1).": no anchor #{$anchor} in ".ltrim(substr($destination, strlen($root)), '/')."\n");
                    $errors++;
                }
            }
        }
    }
}

echo $errors === 0 ? "links ok: {$checked} checked in ".count($files)." files\n" : "{$errors} broken link(s)\n";
exit($errors === 0 ? 0 : 1);
