#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Strips internal task codes from comments in packages/{*}/src and packages/vue/src.
 *
 * Usage: strip-task-codes.php {--check|--write} [--root=<dir>]
 *
 * Only comment tokens are touched (PHP: T_COMMENT/T_DOC_COMMENT; TS/Vue: a small
 * lexer that skips string literals), so code and string literals stay as is.
 * ADR-NNNN references become docs/adr/NNNN-<slug>.md when the file exists.
 */
$mode = null;
$root = dirname(__DIR__);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--check' || $arg === '--write') {
        $mode = $arg;
    } elseif (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/');
    } else {
        fwrite(STDERR, "unknown argument: $arg\n");
        exit(2);
    }
}
if ($mode === null) {
    fwrite(STDERR, "usage: strip-task-codes.php {--check|--write} [--root=<dir>]\n");
    exit(2);
}

const CODE = '(?:\bPLAN\d*(?:\.[A-Za-z]?\d+)*|\bF-P\d+(?:\.\d+)*-\d+|\bP\d+(?:\.\d+)+|\bP\d+ #\d+|\bH\.\d{1,2}(?!\d)|\bEV\d+|\bD\d{1,2}\b″?|\bC0\d\b|\bN\d{2}\b|\bV\d{2,3}\b|§\d+(?:\.\d+)*|\bU\d+\.\d+\b|\bVLT-[A-Z]+\b|\bmaster plan\b|\bR-\d{2}\b|\bS\d\.\d\b|\bM\d+[a-z](?:-[a-z]+)?\b|\bQ\d\b)';
const FILLER = '(?:\band\b|\bи\b)';

/** @return array<string, string> NNNN => docs/adr path */
function adrMap(string $root): array
{
    $map = [];
    foreach (glob($root.'/docs/adr/[0-9][0-9][0-9][0-9]-*.md') ?: [] as $file) {
        $map[substr(basename($file), 0, 4)] = 'docs/adr/'.basename($file);
    }

    return $map;
}

function hasCode(string $text, array $adr): bool
{
    if (preg_match('/'.CODE.'/u', $text) === 1) {
        return true;
    }

    return preg_match_all('/ADR[- ]?(\d{4})/', $text, $m) > 0 && array_intersect($m[1], array_keys($adr)) !== [];
}

function cleanText(string $text, array $adr): string
{
    $text = preg_replace_callback('/ADR[- ]?(\d{4})/', fn (array $m) => $adr[$m[1]] ?? $m[0], $text);

    $text = preg_replace_callback('/[ \t]*\(([^()]*)\)/u', function (array $m): string {
        if (preg_match('/'.CODE.'/u', $m[1]) !== 1) {
            return $m[0];
        }
        $rest = preg_replace(['/'.CODE.'/u', '/'.FILLER.'/u'], '', $m[1]);

        return preg_match('/[\p{L}\p{N}]/u', (string) $rest) === 1 ? $m[0] : "\x02";
    }, $text);
    $text = preg_replace(['/^\x02[ \t]*/m', '/\x02/'], '', $text);

    $text = preg_replace('/\b(?:see|per|cf\.?|см\.?)[ \t]+(?='.CODE.')/iu', '', $text);
    $text = preg_replace('/'.CODE.'/u', "\x01", $text);

    $text = preg_replace('/^[:;,][ \t]*(?=\S)/m', '', $text);

    // Tidy only around the removal sites so alignment elsewhere stays intact.
    return preg_replace([
        '/\x01-(?=[a-z])/',
        '/^\x01[ \t]*(?:[:,;-]|—|–)+[ \t]*/m',
        '/[ \t]*(?:—|–)[ \t]*\x01[ \t]*(?=[,.;:!?)])/u',
        '/\([ \t]*(?:\x01[ \t]*(?:[:,;\/-]|—|–)*[ \t]*)+/u',
        '/\x01(?:[ \t]*[,;\/][ \t]*\x01)+/',
        '/,[ \t]*\x01[ \t]*(?=,)/',
        '/,[ \t]*\x01(?=[.;:)])/',
        '/[ \t]*\x01[ \t]*(?=[,.;:!?)])/',
        '/(?<=\S)[ \t]*\x01[ \t]*(?=\S)/',
        '/[ \t]*\x01[ \t]*/',
    ], ['', '', '', '(', "\x01", '', '', '', ' ', ''], $text);
}

const DELETE = "\0DEL\0";

function cleanComment(string $comment, array $adr): string
{
    if (! hasCode($comment, $adr)) {
        return $comment;
    }

    if (str_starts_with($comment, '/*')) {
        return cleanBlock($comment, $adr);
    }

    preg_match('/^(\/\/|#)[ \t]?/', $comment, $p);
    $text = trim(cleanText(substr($comment, strlen($p[0])), $adr));

    return $text === '' ? DELETE : $p[1].' '.$text;
}

function cleanBlock(string $comment, array $adr): string
{
    $lines = explode("\n", $comment);
    $last = count($lines) - 1;
    $suffix = '';
    if (preg_match('/[ \t]*\*\/$/', $lines[$last], $s) === 1) {
        $suffix = $s[0];
        $lines[$last] = substr($lines[$last], 0, -strlen($suffix));
    }

    $pre = [];
    $texts = [];
    foreach ($lines as $i => $line) {
        preg_match($i === 0 ? '/^\/\*+[ \t]?/' : '/^[ \t]*\*?[ \t]?/', $line, $p);
        $pre[$i] = $p[0];
        $texts[$i] = substr($line, strlen($p[0]));
    }

    if ($last === 0) {
        $text = trim(cleanText($texts[0], $adr));

        return $text === '' ? DELETE : $pre[0].$text.$suffix;
    }

    // Header (`/**`) and footer (` */`) lines without text are structure, not content.
    $head = trim($texts[0]) === '' ? $pre[0] : null;
    $foot = trim($texts[$last]) === '' ? rtrim($pre[$last]).$suffix : null;
    $from = $head === null ? 0 : 1;
    $to = $foot === null ? $last : $last - 1;
    $cont = $pre[1];

    $middle = cleanText(implode("\n", array_slice($texts, $from, $to - $from + 1)), $adr);
    $body = [];
    foreach (explode("\n", $middle) as $text) {
        $text = rtrim($text);
        if ($text === '' && ($body === [] || end($body) === '')) {
            continue;
        }
        $body[] = $text;
    }
    while ($body !== [] && end($body) === '') {
        array_pop($body);
    }
    if ($body === []) {
        return DELETE;
    }

    $out = [];
    if ($head !== null) {
        $out[] = rtrim($head);
    }
    foreach ($body as $i => $text) {
        $prefix = $i === 0 && $head === null ? $pre[0] : $cont;
        $out[] = rtrim($prefix.$text);
    }
    $result = implode("\n", $out);

    return $foot !== null ? $result."\n".$foot : $result.$suffix;
}

/** @return list<array{int,int}> offset/length pairs of comments */
function phpComments(string $src): array
{
    $out = [];
    $offset = 0;
    foreach (token_get_all($src) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            $out[] = [$offset, strlen(rtrim($text, "\r\n")) ];
        }
        $offset += strlen($text);
    }

    return $out;
}

/** @return list<array{int,int}> */
function tsComments(string $src): array
{
    $out = [];
    $n = strlen($src);
    for ($i = 0; $i < $n; $i++) {
        $c = $src[$i];
        if ($c === '"' || $c === "'" || $c === '`') {
            for ($i++; $i < $n && $src[$i] !== $c; $i++) {
                if ($src[$i] === '\\') {
                    $i++;
                }
                if ($c !== '`' && ($src[$i] ?? '') === "\n") {
                    break;
                }
            }
        } elseif ($c === '/' && ($src[$i + 1] ?? '') === '/' && ($i === 0 || $src[$i - 1] !== ':')) {
            $end = strpos($src, "\n", $i);
            $end = $end === false ? $n : $end;
            $out[] = [$i, $end - $i];
            $i = $end;
        } elseif ($c === '/' && ($src[$i + 1] ?? '') === '*') {
            $end = strpos($src, '*/', $i + 2);
            $end = $end === false ? $n : $end + 2;
            $out[] = [$i, $end - $i];
            $i = $end - 1;
        }
    }

    return $out;
}

function listFiles(string $root): array
{
    $files = [];
    foreach (glob($root.'/packages/*/src') ?: [] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (preg_match('/\.(php|ts|vue)$/', $file->getFilename()) === 1) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

$adr = adrMap($root);
$found = 0;
$changedFiles = [];
foreach (listFiles($root) as $file) {
    $src = file_get_contents($file);
    $comments = str_ends_with($file, '.php') ? phpComments($src) : tsComments($src);
    $new = $src;
    $count = 0;
    foreach (array_reverse($comments) as [$off, $len]) {
        $old = substr($src, $off, $len);
        $clean = cleanComment($old, $adr);
        if ($clean === $old) {
            continue;
        }
        $count++;
        if ($mode === '--check') {
            $line = substr_count($src, "\n", 0, $off) + 1;
            echo substr($file, strlen($root) + 1).":$line: task code in comment\n";
        }
        $new = substr_replace($new, $clean, $off, $len);
    }
    if ($count === 0) {
        continue;
    }
    $found += $count;
    $new = preg_replace('/^[ \t]*'.preg_quote(DELETE, '/').'[ \t]*\R/m', '', $new);
    $new = preg_replace('/[ \t]*'.preg_quote(DELETE, '/').'/', '', $new);
    $changedFiles[$file] = $count;
    if ($mode === '--write') {
        file_put_contents($file, $new);
    }
}

if ($mode === '--write') {
    foreach ($changedFiles as $file => $count) {
        echo substr($file, strlen($root) + 1).": $count\n";
    }
    echo count($changedFiles)." files, $found comments rewritten\n";
    exit(0);
}

if ($found > 0) {
    fwrite(STDERR, "$found comment(s) with task codes in ".count($changedFiles)." file(s)\n");
    exit(1);
}
echo "OK: no task codes in comments\n";
