#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Keeps the code fragments of the guides in step with the code they describe.
 *
 *   php scripts/check-doc-snippets.php docs
 *
 * Every php/ts/vue/js/bash fence in docs/{getting-started,guides,extending} (and docs/ru/…) must
 * be preceded by a marker comment naming how it is verified:
 *
 *   <!-- recipe: <repo-path>#<region> -->   the block, less its leading `use` lines (and, in ru, comments), equals the "// [recipe:<region>]" … "// [/recipe]" area of a test
 *   <!-- snippet: config -->               top-level keys exist in a package's config/*.php
 *   <!-- snippet: shell -->                every vaulter:* command is registered
 *   <!-- snippet: signature <FQCN> -->     the class/interface and each declared method exist
 *   <!-- snippet: host -->                 host-application code: `use Vaulter\…` imports and @vaulter/vue imports resolve
 *
 * A ru block must carry the same marker and the same code as its en twin; comments may be translated.
 */
$root = dirname(__DIR__);
$args = array_slice($argv, 1);
if ($args === []) {
    fwrite(STDERR, "usage: check-doc-snippets.php <docs-dir>\n");
    exit(2);
}
$docs = realpath($args[0]) ?: $root.'/'.$args[0];

const FENCED = ['php', 'ts', 'typescript', 'vue', 'js', 'javascript', 'bash', 'sh', 'shell'];
const SECTIONS = ['getting-started', 'guides', 'extending'];

/** Vue's own composables: documented examples call them without importing a Vaulter name. */
const VUE_RUNTIME_COMPOSABLES = ['useSlots', 'useAttrs', 'useId', 'useTemplateRef', 'useModel', 'useCssModule'];

$errors = [];
$fail = static function (string $file, int $line, string $message) use (&$errors, $root): void {
    $errors[] = ltrim(str_replace($root, '', $file), '/').':'.$line.' '.$message;
};

registerPackageAutoload($root);

/** @return array<string, list<array{line:int, lang:string, marker:?string, code:string}>> */
function fragments(string $docs): array
{
    $out = [];
    foreach (is_file($docs) ? [null] : SECTIONS as $section) {
        foreach ($section === null ? [$docs] : [$docs.'/'.$section, $docs.'/ru/'.$section] as $dir) {
            if (!is_dir($dir) && !is_file($dir)) {
                continue;
            }
            foreach (is_file($dir) ? [new SplFileInfo($dir)] : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'md') {
                    continue;
                }
                $lines = explode("\n", (string) file_get_contents($file->getPathname()));
                $blocks = [];
                for ($i = 0, $n = count($lines); $i < $n; $i++) {
                    if (!preg_match('/^```(\w+)/', $lines[$i], $m)) {
                        continue;
                    }
                    $start = $i;
                    $body = [];
                    for ($i++; $i < $n && !str_starts_with($lines[$i], '```'); $i++) {
                        $body[] = $lines[$i];
                    }
                    if (!in_array($m[1], FENCED, true)) {
                        continue;
                    }
                    $j = $start - 1;
                    while ($j >= 0 && trim($lines[$j]) === '') {
                        $j--;
                    }
                    $marker = $j >= 0 && preg_match('/^<!--\s*(recipe|snippet):\s*(.+?)\s*-->$/', trim($lines[$j]), $mm) ? $mm[1].': '.$mm[2] : null;
                    $blocks[] = ['line' => $start + 1, 'lang' => $m[1], 'marker' => $marker, 'code' => implode("\n", $body)];
                }
                $out[$file->getPathname()] = $blocks;
            }
        }
    }
    ksort($out);

    return $out;
}

/** Whether a class is declared by a package source file; the file is not loaded, so optional dependencies stay optional. */
function declared(string $fqcn): bool
{
    global $PACKAGE_MAP;
    foreach ($PACKAGE_MAP ?? [] as $prefix => $dir) {
        if (str_starts_with($fqcn, $prefix)) {
            if (is_file($dir.'/'.str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php')) {
                return true;
            }
        }
    }

    return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
}

function registerPackageAutoload(string $root): void
{
    global $PACKAGE_MAP;
    require_once $root.'/vendor/autoload.php';
    $map = [];
    foreach (glob($root.'/packages/*/composer.json') ?: [] as $manifest) {
        $json = json_decode((string) file_get_contents($manifest), true);
        foreach ($json['autoload']['psr-4'] ?? [] as $prefix => $path) {
            $map[$prefix] = dirname($manifest).'/'.rtrim((string) $path, '/');
        }
    }
    $PACKAGE_MAP = $map;
    spl_autoload_register(static function (string $class) use ($map): void {
        foreach ($map as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($file)) {
                    require_once $file;

                    return;
                }
            }
        }
    });
}

function dedent(string $code): string
{
    $lines = array_map('rtrim', explode("\n", trim($code, "\n")));
    $indent = null;
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        $width = strlen($line) - strlen(ltrim($line));
        $indent = $indent === null ? $width : min($indent, $width);
    }

    return implode("\n", array_map(static fn (string $line): string => $line === '' ? '' : substr($line, (int) $indent), $lines));
}

/** The code with comments dropped, so a translated comment does not count as a different example. */
function withoutComments(string $code): string
{
    $kept = [];
    foreach (explode("\n", $code) as $line) {
        $trim = ltrim($line);
        if ($trim === '' || preg_match('~^(//|#|\*|/\*|<!--)~', $trim)) {
            continue;
        }
        // A marker inside a string literal is code, not a comment.
        if (preg_match_all('~\s+(?://|#)\s~', $line, $found, PREG_OFFSET_CAPTURE)) {
            foreach ($found[0] as [, $offset]) {
                $before = substr($line, 0, $offset);
                if (substr_count($before, "'") % 2 === 0 && substr_count($before, '"') % 2 === 0) {
                    $line = $before;
                    break;
                }
            }
        }
        $kept[] = rtrim($line);
    }

    return implode("\n", $kept);
}

function region(string $file, string $name): ?string
{
    $inside = false;
    $body = [];
    foreach (explode("\n", (string) file_get_contents($file)) as $line) {
        $trim = trim($line);
        if ($trim === '// [recipe:'.$name.']') {
            $inside = true;

            continue;
        }
        if ($inside && $trim === '// [/recipe]') {
            return implode("\n", $body);
        }
        if ($inside) {
            $body[] = $line;
        }
    }

    return null;
}

/** @return list<string> */
function configSources(string $root): array
{
    return array_map(static fn (string $f): string => (string) file_get_contents($f), glob($root.'/packages/*/config/*.php') ?: []);
}

/** @return list<string> */
function registeredCommands(string $root): array
{
    static $names = null;
    if ($names === null) {
        $cmd = 'cd '.escapeshellarg($root).' && '.escapeshellarg($root.'/scripts/php84').' vendor/bin/testbench list --raw 2>/dev/null';
        $names = [];
        foreach (explode("\n", (string) shell_exec($cmd)) as $line) {
            $names[] = strtok($line, ' ') ?: '';
        }
        // The list above holds the commands of the modules active in the monorepo stand. An opt-in module (webhooks)
        // registers its commands only when switched on, so its declared signatures count as registered too.
        foreach (glob($root.'/packages/*/src/Console/*.php') ?: [] as $file) {
            if (preg_match("/\\\$signature\\s*=\\s*'(vaulter:[a-z][a-z0-9:_-]*)/", (string) file_get_contents($file), $found) === 1) {
                $names[] = $found[1];
            }
        }
    }

    return $names;
}

function facadeSource(string $fqcn): ?string
{
    global $PACKAGE_MAP;
    foreach ($PACKAGE_MAP ?? [] as $prefix => $dir) {
        $file = $dir.'/'.str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php';
        if (str_starts_with($fqcn, $prefix) && is_file($file)) {
            return (string) file_get_contents($file);
        }
    }

    return null;
}

/** @return list<string> problems of a fragment that is application code: imports, facades, handles, composables, components */
function hostChecks(string $root, string $lang, string $code): array
{
    $problems = [];
    preg_match_all('/^use\s+(Vaulter\\\\[\w\\\\]+?)(?:\s+as\s+\w+)?;/m', $code, $uses);
    foreach ($uses[1] as $class) {
        if (!declared($class)) {
            $problems[] = "import {$class} does not exist";
        }
    }
    preg_match_all('/^use\s+(Vaulter\\\\(?:[\w\\\\]*\\\\)?Facades\\\\(\w+));/m', $code, $facades, PREG_SET_ORDER);
    foreach ($facades as [, $facade, $alias]) {
        $source = facadeSource($facade);
        preg_match_all('/\b'.$alias.'::(\w+)\(/', $code, $calls);
        foreach (array_unique($calls[1]) as $call) {
            if ($source !== null && !preg_match('/@method\s+static\s+\S+\s+'.$call.'\(|static function '.$call.'\(/', $source)) {
                $problems[] = "{$alias}::{$call}() is not a method of the {$alias} facade";
            }
        }
    }
    if ($lang === 'php') {
        foreach (documentOperationsMisuse($root, $code) as $message) {
            $problems[] = $message;
        }
    }
    if (in_array($lang, ['ts', 'typescript', 'vue', 'js', 'javascript'], true)) {
        foreach (clientMisuse($root, $code) as $message) {
            $problems[] = $message;
        }
    }
    if ($lang === 'php') {
        foreach (unimplementedContracts($code) as $message) {
            $problems[] = $message;
        }
    }
    if ($lang === 'php') {
        foreach (driveHandleMisuse($root, $code) as $message) {
            $problems[] = $message;
        }
    }
    if (in_array($lang, ['vue'], true)) {
        foreach (vueComponentMisuse($root, $code) as $message) {
            $problems[] = $message;
        }
    }
    if (in_array($lang, ['ts', 'typescript', 'vue', 'js', 'javascript'], true)) {
        preg_match_all('/import\s+(?:type\s+)?\{([^}]*)\}\s+from\s+[\'"][^\'"]+[\'"]/s', $code, $everyImport);
        $imported = [];
        foreach ($everyImport[1] as $group) {
            foreach (explode(',', $group) as $item) {
                $imported[] = trim(explode(' as ', trim($item))[count(explode(' as ', trim($item))) - 1]);
            }
        }
        preg_match_all('/\b(use[A-Z]\w*)\s*\(/', $code, $composables);
        foreach (array_unique($composables[1]) as $composable) {
            if (!in_array($composable, vueExports($root), true) && !in_array($composable, $imported, true) && !in_array($composable, VUE_RUNTIME_COMPOSABLES, true)) {
                $problems[] = "{$composable}() is not exported by @vaulter/vue";
            }
        }
    }
    preg_match_all('/import\s+(?:type\s+)?\{([^}]*)\}\s+from\s+[\'"]@vaulter\/vue[\'"]/s', $code, $imports);
    foreach ($imports[1] as $group) {
        foreach (explode(',', $group) as $item) {
            $name = trim(preg_replace('/^type\s+/', '', trim($item)) ?? '');
            $name = trim(explode(' as ', $name)[0]);
            if ($name !== '' && !in_array($name, vueExports($root), true)) {
                $problems[] = "@vaulter/vue does not export {$name}";
            }
        }
    }

    return $problems;
}

/** @return list<string> props and events a template gives a `<Vaulter…>` component that the component does not declare */
function vueComponentMisuse(string $root, string $code): array
{
    $problems = [];
    preg_match_all('/<(Vaulter[A-Z]\w*)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $code, $tags, PREG_SET_ORDER);
    foreach ($tags as [, $component, $attributes]) {
        // No GLOB_BRACE: it is undefined on musl builds of PHP (Alpine), so each pattern is globbed on its own.
        $files = [];
        foreach (['components', 'components/*', 'viewers', 'viewers/*'] as $dir) {
            $files = [...$files, ...(glob($root.'/packages/vue/src/'.$dir.'/'.$component.'.vue') ?: [])];
        }
        if ($files === []) {
            $problems[] = "<{$component}> is not a component of @vaulter/vue";

            continue;
        }
        $source = (string) file_get_contents($files[0]);
        $attributes = preg_replace('/=\s*("[^"]*"|\'[^\']*\')/s', '', preg_replace('/<!--.*?-->/s', '', $attributes) ?? $attributes) ?? $attributes;
        preg_match_all('/(?:^|\s)([:@])?([a-zA-Z][\w-]*)(?=[\s=\/>]|$)(?:\.\w+)*/', $attributes, $found, PREG_SET_ORDER);
        foreach ($found as [, $prefix, $name]) {
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $name))));
            if ($prefix === '' && (in_array($name, ['class', 'style', 'key', 'ref', 'id'], true) || str_starts_with($name, 'v-') || str_starts_with($name, 'data-') || str_starts_with($name, 'aria-'))) {
                continue;
            }
            if (str_starts_with($name, 'v-')) {
                continue;
            }
            $declared = $prefix === '@'
                ? preg_match('/(?:\b'.preg_quote($camel, '/').'|\''.preg_quote($name, '/').'\'|\b'.preg_quote($name, '/').')\s*:\s*\[/', $source)
                : preg_match('/\b'.preg_quote($camel, '/').'\??\s*:/', $source);
            if (!$declared) {
                $problems[] = "<{$component}> has no ".($prefix === '@' ? 'event' : 'prop')." '{$name}'";
            }
        }
    }

    return array_values(array_unique($problems));
}

/** @return list<string> `Documents::in($drive)->x()` calls the documents package does not have */
function documentOperationsMisuse(string $root, string $code): array
{
    $problems = [];
    foreach (['in' => 'DocumentOperations', 'comments' => 'CommentOperations'] as $entry => $class) {
        $source = (string) @file_get_contents($root.'/packages/documents/src/Operations/'.$class.'.php');
        preg_match_all('/public function (\w+)\(/', $source, $found);
        preg_match_all('/Documents::'.$entry.'\([^)]*\)\s*->(\w+)\(/', $code, $calls);
        foreach (array_unique($calls[1]) as $call) {
            if (!in_array($call, $found[1], true)) {
                $problems[] = "Documents::{$entry}(…)->{$call}() is not a method of {$class}";
            }
        }
    }

    return $problems;
}

/** @return list<string> `client.<group>.<method>(` calls the TypeScript client does not have */
function clientMisuse(string $root, string $code): array
{
    static $groups = null;
    if ($groups === null) {
        $groups = [];
        $source = (string) @file_get_contents($root.'/packages/vue/src/api/client.ts');
        $start = strpos($source, 'export interface VaulterClient ');
        $interface = $start === false ? '' : substr($source, $start, (int) strpos($source, "\n}\n", $start) - $start);
        $group = null;
        foreach (explode("\n", $interface) as $row) {
            if (preg_match('/^  (\w+): \{/', $row, $m)) {
                $group = $m[1];
                $groups[$group] = [];
            } elseif ($group !== null && preg_match('/^    (\w+)[(<]/', $row, $m)) {
                $groups[$group][] = $m[1];
            } elseif (preg_match('/^  \}/', $row)) {
                $group = null;
            }
        }
    }
    $problems = [];
    preg_match_all('/const\s*\{([^}]*)\}\s*=\s*(use[A-Z]\w*)\(/s', $code, $destructured, PREG_SET_ORDER);
    foreach ($destructured as [, $members, $composable]) {
        $file = $root.'/packages/vue/src/composables/'.$composable.'.ts';
        if (!is_file($file) || !preg_match('/export interface \w+Return\b[^{]*\{(.*?)^\}/ms', (string) file_get_contents($file), $return)) {
            continue;
        }
        preg_match_all('/^\s{2}(\w+)\??\s*[:(]/m', $return[1], $available);
        foreach (explode(',', $members) as $member) {
            $name = trim(explode(':', $member)[0]);
            if ($name !== '' && !in_array($name, $available[1], true)) {
                $problems[] = "{$composable}() returns no '{$name}'";
            }
        }
    }
    preg_match_all('/(?:\bclient|useVaulterClient\(\))\.(\w+)\.(\w+)\(/', $code, $calls, PREG_SET_ORDER);
    foreach ($calls as [, $group, $method]) {
        if (!isset($groups[$group])) {
            $problems[] = "client.{$group} is not a group of the VaulterClient";
        } elseif (!in_array($method, $groups[$group], true)) {
            $problems[] = "client.{$group}.{$method}() is not a method of the VaulterClient";
        }
    }

    return array_values(array_unique($problems));
}

/** @return list<string> contract methods that a documented `class … implements <Vaulter contract>` leaves out */
function unimplementedContracts(string $code): array
{
    $problems = [];
    preg_match_all('/^use\s+(Vaulter\\\\[\w\\\\]+?)(?:\s+as\s+(\w+))?;/m', $code, $uses, PREG_SET_ORDER);
    $imports = [];
    foreach ($uses as $use) {
        $imports[$use[2] ?? substr(strrchr('\\'.$use[1], '\\'), 1)] = $use[1];
    }
    preg_match_all('/class\s+(\w+)[^{]*?\bimplements\s+([\w\\\\,\s]+?)\s*\{(.*?)^\}/ms', $code, $classes, PREG_SET_ORDER);
    foreach ($classes as [, $name, $list, $body]) {
        foreach (preg_split('/\s*,\s*/', trim($list)) ?: [] as $contract) {
            $fqcn = ltrim($imports[$contract] ?? $contract, '\\');
            if (!str_starts_with($fqcn, 'Vaulter\\') || !interface_exists($fqcn)) {
                continue;
            }
            preg_match_all('/function\s+(\w+)\s*\(/', $body, $declared);
            foreach ((new ReflectionClass($fqcn))->getMethods() as $method) {
                if ($method->isAbstract() && !in_array($method->getName(), $declared[1], true)) {
                    $problems[] = "{$name} implements {$contract} without {$method->getName()}()";
                }
            }
            foreach ($declared[1] as $function) {
                if (!str_starts_with($function, '__') && !method_exists($fqcn, $function) && preg_match('/public\s+function\s+'.$function.'\(/', $body)) {
                    $problems[] = "{$name}::{$function}() is not part of {$contract}";
                }
            }
        }
    }

    return $problems;
}

/** @return list<string> methods named on a `$drive` handle (or one of its operation groups) that the handle does not have */
function driveHandleMisuse(string $root, string $code): array
{
    $source = static fn (string $relative): string => (string) @file_get_contents($root.'/packages/drive/src/'.$relative);
    $methods = static function (string $text): array {
        preg_match_all('/public function (\w+)\(/', $text, $found);

        return $found[1];
    };
    $groups = ['nodes' => 'NodeOperations', 'folders' => 'FolderOperations', 'files' => 'FileOperations', 'trash' => 'TrashOperations',
        'access' => 'AccessOperations', 'shares' => 'ShareOperations', 'activity' => 'ActivityOperations', 'changes' => 'ChangeOperations'];
    $handle = $methods($source('DriveHandle.php'));
    $problems = [];
    preg_match_all('/\$drive->(\w+)\(/', $code, $direct);
    foreach (array_unique($direct[1]) as $method) {
        if (!in_array($method, $handle, true)) {
            $problems[] = "\$drive->{$method}() is not a method of DriveHandle";
        }
    }
    preg_match_all('/\$drive->(\w+)\(\)\s*->(\w+)\(/', $code, $chained, PREG_SET_ORDER);
    foreach ($chained as [, $group, $method]) {
        if (isset($groups[$group]) && !in_array($method, $methods($source('Operations/'.$groups[$group].'.php')), true)) {
            $problems[] = "\$drive->{$group}()->{$method}() does not exist";
        }
    }

    return array_values(array_unique($problems));
}

/** @return list<string> */
function vueExports(string $root): array
{
    static $names = null;
    if ($names === null) {
        $names = [];
        $index = (string) file_get_contents($root.'/packages/vue/src/index.ts');
        preg_match_all('/export\s+(?:type\s+)?\{([^}]*)\}/s', $index, $groups);
        foreach ($groups[1] as $group) {
            foreach (explode(',', $group) as $item) {
                $item = trim(preg_replace('~//.*~', '', $item) ?? '');
                if ($item !== '') {
                    $parts = preg_split('/\s+as\s+/', preg_replace('/^type\s+/', '', $item) ?? $item);
                    $names[] = trim(end($parts));
                }
            }
        }
        preg_match_all('/export\s+(?:type\s+)?\*\s+from\s+[\'"]([^\'"]+)[\'"]/', $index, $stars);
        foreach ($stars[1] as $path) {
            foreach ([$root.'/packages/vue/src/'.$path.'.ts', $root.'/packages/vue/src/'.$path.'/index.ts'] as $file) {
                if (is_file($file)) {
                    preg_match_all('/export\s+(?:declare\s+)?(?:async\s+)?(?:function|const|class|interface|type|enum)\s+(\w+)/', (string) file_get_contents($file), $found);
                    array_push($names, ...$found[1]);
                }
            }
        }
    }

    return $names;
}

$byFile = fragments($docs);
if ($byFile === []) {
    fwrite(STDERR, "no fragments found under {$docs}\n");
    exit(2);
}

$configs = configSources($root);

foreach ($byFile as $file => $blocks) {
    foreach ($blocks as $index => $block) {
        $line = $block['line'];
        $marker = $block['marker'];
        if ($marker === null) {
            $fail($file, $line, 'fragment has no <!-- recipe|snippet --> marker');

            continue;
        }

        if (str_starts_with($marker, 'recipe: ')) {
            [$path, $name] = array_pad(explode('#', substr($marker, 8), 2), 2, '');
            $target = $root.'/'.$path;
            if (!is_file($target) || ($area = region($target, $name)) === null) {
                $fail($file, $line, "recipe region {$marker} not found");
            } else {
                // `use` lines cannot sit inside a test body: they are held to the same existence check as host code instead.
                $code = $block['code'];
                if (preg_match('/\A((?:\s*use\s+[^;]+;\s*\n)+)\s*/', $code."\n", $lead)) {
                    $code = substr($code, strlen($lead[0]) - 1);
                    preg_match_all('/^\s*use\s+(?:function\s+)?(Vaulter\\\\[\w\\\\]+)/m', $lead[1], $imports);
                    foreach ($imports[1] as $class) {
                        if (!declared($class)) {
                            $fail($file, $line, "import {$class} does not exist");
                        }
                    }
                }
                // The same holds for TypeScript `import` lines; they are checked as host code below.
                if (in_array($block['lang'], ['ts', 'typescript', 'js', 'javascript'], true)) {
                    $code = preg_replace('/\A(?:\s*import\b[^;]*?\bfrom\s+[\'"][^\'"]+[\'"];?[ \t]*\n)+\s*/s', '', $code."\n") ?? $code;
                    $code = rtrim($code, "\n");
                }
                foreach (hostChecks($root, $block['lang'], $block['code']) as $message) {
                    $fail($file, $line, $message);
                }
                $translated = str_contains($file, '/ru/');
                $same = $translated
                    ? withoutComments(dedent($area)) === withoutComments(dedent($code))
                    : dedent($area) === dedent($code);
                if (!$same) {
                    $fail($file, $line, "block differs from the tested region {$marker}");
                }
            }
        } elseif ($marker === 'snippet: config') {
            // A line marked `// your …` names the reader's own entry (a driver key, say), not a package key.
            $own = implode("\n", array_filter(explode("\n", $block['code']), static fn (string $row): bool => !str_contains($row, '// your ')));
            preg_match_all("/'([a-z_]+)'\s*=>/", $own, $keys);
            foreach (array_unique($keys[1]) as $key) {
                $found = false;
                foreach ($configs as $source) {
                    if (preg_match("/'".preg_quote($key, '/')."'\s*=>/", $source)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $fail($file, $line, "config key '{$key}' is in no packages/*/config/*.php");
                }
            }
        } elseif ($marker === 'snippet: shell') {
            preg_match_all('/\bvaulter:[a-z][a-z0-9:_-]*/', $block['code'], $commands);
            foreach (array_unique($commands[0]) as $command) {
                if (!in_array($command, registeredCommands($root), true)) {
                    $fail($file, $line, "command {$command} is not registered");
                }
            }
        } elseif (str_starts_with($marker, 'snippet: signature ')) {
            $fqcns = array_map(static fn (string $name): string => ltrim($name, '\\'), preg_split('/\s+/', trim(substr($marker, strlen('snippet: signature ')))) ?: []);
            foreach ($fqcns as $fqcn) {
                if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn) && !enum_exists($fqcn)) {
                    $fail($file, $line, "{$fqcn} does not exist");

                    continue 2;
                }
            }
            preg_match_all('/function\s+(\w+)\s*\(/', $block['code'], $methods);
            foreach (array_unique($methods[1]) as $method) {
                $owned = array_filter($fqcns, static fn (string $fqcn): bool => method_exists($fqcn, $method));
                if ($owned === []) {
                    $fail($file, $line, implode(' / ', $fqcns)."::{$method}() does not exist");
                }
            }
        } elseif ($marker === 'snippet: host') {
            foreach (hostChecks($root, $block['lang'], $block['code']) as $message) {
                $fail($file, $line, $message);
            }
        } else {
            $fail($file, $line, "unknown marker '{$marker}'");
        }
    }
}

// The ru mirror: same marker, same code (comments aside), in the same position.
foreach ($byFile as $file => $blocks) {
    if (!str_contains($file, '/docs/ru/') && !str_contains($file, $docs.'/ru/')) {
        continue;
    }
    $twin = str_replace($docs.'/ru/', $docs.'/', $file);
    $en = $byFile[$twin] ?? null;
    if ($en === null) {
        $fail($file, 1, 'no en page with the same path');

        continue;
    }
    if (count($en) !== count($blocks)) {
        $fail($file, 1, 'has '.count($blocks).' fragments, the en page has '.count($en));

        continue;
    }
    foreach ($blocks as $i => $block) {
        if ($block['marker'] !== $en[$i]['marker']) {
            $fail($file, $block['line'], 'marker differs from the en fragment');
        } elseif (withoutComments(dedent($block['code'])) !== withoutComments(dedent($en[$i]['code']))) {
            $fail($file, $block['line'], 'code differs from the en fragment');
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n".count($errors)." doc fragment problem(s)\n");
    exit(1);
}

$total = array_sum(array_map('count', $byFile));
fwrite(STDOUT, "doc fragments OK: {$total} fragments in ".count($byFile)." pages\n");
