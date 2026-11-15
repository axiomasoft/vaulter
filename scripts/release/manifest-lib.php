<?php

declare(strict_types=1);

/*
 * Shared rules of the release manifest (schema vaulter.release/v2), used by check-versions.php, build-artifacts.php and
 * route-release.php so that no tool keeps its own idea of a channel, a release line or a tag prefix.
 *
 * Nothing here publishes, reads a credential or touches the network.
 */

const RELEASE_KINDS = ['php', 'npm', 'images'];
const SEMVER_TAIL = '(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)';

function manifestIsDevConstraint(string $constraint): bool
{
    return str_starts_with($constraint, 'dev-') || str_contains($constraint, '@dev') || str_ends_with($constraint, '-dev');
}

/**
 * Validates EVERY entry of EVERY kind. Callers filter by channel only afterwards, so one malformed or unknown
 * private entry fails a public build as well.
 *
 * @return list<string>
 */
function validateManifest(array $manifest): array
{
    $errors = [];
    if (($manifest['schema'] ?? null) !== 'vaulter.release/v2') {
        return ['schema must be "vaulter.release/v2"'];
    }
    $names = [];
    $paths = [];
    $lines = [];
    foreach (RELEASE_KINDS as $kind) {
        if (!is_array($manifest[$kind] ?? null)) {
            $errors[] = "{$kind}: list is missing";

            continue;
        }
        foreach ($manifest[$kind] as $entry) {
            if (!is_array($entry)) {
                $errors[] = "{$kind}: entry is not an object";

                continue;
            }
            $label = is_string($entry['name'] ?? null) ? $entry['name'] : '?';
            $channel = $entry['channel'] ?? null;
            if ($channel !== 'public' && $channel !== 'private') {
                $errors[] = "{$label}: channel must be \"public\" or \"private\", got ".var_export($channel, true);
            }
            foreach (['name', 'path', 'release_line', 'tag_prefix'] as $field) {
                if (!is_string($entry[$field] ?? null) || $entry[$field] === '') {
                    $errors[] = "{$label}: {$field} must be a non-empty string";
                }
            }
            $access = $entry['access'] ?? null;
            if ($channel === 'private' && $access !== 'restricted') {
                $errors[] = "{$label}: private entry must declare access \"restricted\"";
            }
            if ($channel === 'public' && $access !== null && $access !== 'public') {
                $errors[] = "{$label}: public entry cannot declare access ".var_export($access, true);
            }
            if ($channel === 'private') {
                if ($kind === 'php' && (!is_string($entry['split'] ?? null) || $entry['split'] === '')) {
                    $errors[] = "{$label}: private php entry must name its private split repository";
                }
                if (!is_string($entry['tag_prefix'] ?? null) || in_array($entry['tag_prefix'] ?? '', ['v', ''], true)) {
                    $errors[] = "{$label}: private entry needs its own tag_prefix, never the public \"v\"";
                }
                if (!is_array($entry['tested_ranges'] ?? null) && $kind !== 'images') {
                    $errors[] = "{$label}: private entry must declare tested_ranges";
                }
            }
            if ($kind === 'npm' && (!is_string($entry['registry'] ?? null) || !is_string($access))) {
                $errors[] = "{$label}: npm entry needs registry and access";
            }
            if ($kind === 'images' && (!is_string($entry['registry'] ?? null) || !is_string($access))) {
                $errors[] = "{$label}: image entry needs registry and access";
            }
            if (isset($names[$label])) {
                $errors[] = "{$label}: duplicate name";
            }
            $names[$label] = true;
            $path = (string) ($entry['path'] ?? '');
            if (isset($paths[$path])) {
                $errors[] = "{$label}: duplicate path {$path}";
            }
            $paths[$path] = true;
            $lines[(string) ($entry['release_line'] ?? '')][(string) ($entry['tag_prefix'] ?? '')] = true;
            // One line is one kind and one channel: a patch of one line never drags another along.
            $lineKey = (string) ($entry['release_line'] ?? '');
            $shape = $kind.'/'.(string) $channel;
            $seen = $lines['@shape'][$lineKey] ?? $shape;
            if ($seen !== $shape) {
                $errors[] = "release line {$lineKey}: mixes {$seen} and {$shape}";
            }
            $lines['@shape'][$lineKey] = $shape;
        }
    }
    unset($lines['@shape']);
    $flat = [];
    foreach ($lines as $line => $set) {
        if (count($set) !== 1) {
            $errors[] = "release line {$line}: must have exactly one tag_prefix, got [".implode(', ', array_keys($set)).']';
        }
        $prefix = (string) array_key_first($set);
        if (isset($flat[$prefix])) {
            $errors[] = "tag_prefix collision: {$prefix} is used by release lines {$flat[$prefix]} and {$line}";
        }
        $flat[$prefix] = $line;
    }
    foreach ($flat as $a => $lineA) {
        foreach ($flat as $b => $lineB) {
            if ($a !== $b && $lineA !== $lineB && $a !== 'v' && str_starts_with((string) $b, (string) $a)) {
                $errors[] = "tag_prefix collision: {$a} ({$lineA}) is a prefix of {$b} ({$lineB})";
            }
        }
    }
    $public = array_filter($manifest['php'] ?? [], static fn ($p): bool => is_array($p) && ($p['channel'] ?? null) === 'public');
    if ($public === []) {
        $errors[] = 'public PHP set is empty';
    }

    return $errors;
}

/**
 * Picks exactly one release line from a tag by strict SemVer + prefix. A prerelease, a leading zero, a legacy prefix or
 * a tag that fits two lines never becomes a stable publication.
 *
 * @return array{line: string, kind: string, channel: string, version: string, entries: list<array<string, mixed>>}
 */
function routeTag(array $manifest, string $tag): array
{
    $hits = [];
    foreach (RELEASE_KINDS as $kind) {
        foreach ($manifest[$kind] as $entry) {
            if (isset($entry['legacy_tag_prefix_rejected']) && str_starts_with($tag, $entry['legacy_tag_prefix_rejected'])) {
                throw new RuntimeException("tag {$tag} uses the retired prefix {$entry['legacy_tag_prefix_rejected']}; it starts nothing");
            }
            if (preg_match('/^'.preg_quote($entry['tag_prefix'], '/').'('.SEMVER_TAIL.')$/', $tag, $m) === 1) {
                $hits[$entry['release_line']] = ['kind' => $kind, 'channel' => $entry['channel'], 'version' => $m[1]];
            }
        }
    }
    if (count($hits) !== 1) {
        throw new RuntimeException("tag {$tag} must select exactly one release line, selects ".count($hits));
    }
    $line = (string) array_key_first($hits);
    $entries = [];
    foreach ($manifest[$hits[$line]['kind']] as $entry) {
        if ($entry['release_line'] === $line) {
            $entries[] = $entry;
        }
    }

    return ['line' => $line] + $hits[$line] + ['entries' => $entries];
}

/** Where the artifact of an entry lands; private entries must prove this destination is private. */
function entryDestination(string $kind, array $entry): string
{
    return $kind === 'php' ? (string) $entry['split'] : (string) $entry['name'];
}

/**
 * Fail-closed D18 qualification: a trusted evidence document must bind THIS source SHA, a green CI gate of the SAME SHA,
 * the routed line/channel and an exact artifact identity for every routed entry; a private channel additionally needs a
 * probed private destination. Missing evidence is "not qualified", never a fallback to the SHA alone.
 *
 * @param  array<string, mixed>       $route
 * @param  array<string, mixed>|null  $evidence
 * @param  array<string, string>|null $visibility destination => private|restricted|public
 * @return list<string>
 */
function qualificationErrors(array $route, ?array $evidence, string $sha, ?array $visibility): array
{
    if ($evidence === null) {
        return ['no qualification evidence: release is not qualified (no SHA-only fallback)'];
    }
    $errors = [];
    if (($evidence['schema'] ?? null) !== 'vaulter.release-qualification/v1') {
        $errors[] = 'evidence schema must be "vaulter.release-qualification/v1"';
    }
    if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
        $errors[] = 'release sha must be a full 40-hex commit';
    }
    if (($evidence['source_sha'] ?? null) !== $sha) {
        $errors[] = 'evidence source_sha differs from the release sha';
    }
    $gate = $evidence['ci_gate'] ?? [];
    if (($gate['sha'] ?? null) !== $sha || ($gate['conclusion'] ?? null) !== 'success') {
        $errors[] = 'CI gate must be a success of the same sha';
    }
    if (($evidence['release_line'] ?? null) !== $route['line'] || ($evidence['channel'] ?? null) !== $route['channel']) {
        $errors[] = 'evidence release_line/channel differ from the routed tag';
    }
    $rules = [
        'php' => ['split_commit' => '/^[0-9a-f]{40}$/', 'content_sha256' => '/^[0-9a-f]{64}$/'],
        'npm' => ['tgz_sha256' => '/^[0-9a-f]{64}$/', 'integrity' => '/^sha512-[A-Za-z0-9+\/]+=*$/'],
        'images' => ['digest' => '/^sha256:[0-9a-f]{64}$/'],
    ][$route['kind']];
    foreach ($route['entries'] as $entry) {
        $identity = $evidence['artifacts'][$entry['name']] ?? null;
        if (!is_array($identity)) {
            $errors[] = "{$entry['name']}: no artifact identity in evidence";

            continue;
        }
        foreach ($rules as $field => $pattern) {
            if (!is_string($identity[$field] ?? null) || preg_match($pattern, $identity[$field]) !== 1) {
                $errors[] = "{$entry['name']}: artifact identity {$field} is missing or malformed";
            }
        }
    }
    if ($route['channel'] === 'private') {
        foreach ($route['entries'] as $entry) {
            $destination = entryDestination($route['kind'], $entry);
            $state = $visibility[$destination] ?? null;
            if (!in_array($state, ['private', 'restricted'], true)) {
                $errors[] = "{$destination}: destination visibility is ".var_export($state, true).', private required (API failure or missing owner setup refuses)';
            }
        }
    }

    return $errors;
}

/**
 * Reads the CURRENT runtime constants of the collaboration stack: what the driver announces and accepts, and what the
 * document types produce. The manifest is compared with these, never with itself.
 *
 * @return array{protocol: string, version: string, accepted: list<array<string, mixed>>, produced: list<array<string, mixed>>}
 */
function runtimeWireState(string $root): array
{
    $driver = (string) @file_get_contents($root.'/packages/collaboration/src/HocuspocusCollaborationDriver.php');
    $protocol = preg_match("/function protocol\(\): string\s*\{\s*return '([^']+)'/", $driver, $p) === 1 ? $p[1] : '';
    $version = preg_match("/function version\(\): string\s*\{\s*return '([^']+)'/", $driver, $v) === 1 ? $v[1] : '';
    $accepted = [];
    if (preg_match("/in_array\(\\\$documentType, \[([^\]]+)\], true\) && \\\$stateFormat === '([^']+)' && \\\$schemaVersion === (\d+)/", $driver, $a) === 1) {
        preg_match_all("/'([^']+)'/", $a[1], $types);
        foreach ($types[1] as $type) {
            $accepted[] = ['document_type' => $type, 'state_format' => $a[2], 'schema_version' => (int) $a[3]];
        }
    }
    $produced = [];
    foreach (['RichTextDocumentType', 'MarkdownDocumentType'] as $class) {
        $source = (string) @file_get_contents($root."/packages/documents/src/Types/{$class}.php");
        $type = preg_match("/const string KEY = '([^']+)'/", $source, $t) === 1 ? $t[1] : null;
        $format = preg_match("/function collaborationStateFormat\(\): string\s*\{\s*return '([^']+)'/", $source, $f) === 1 ? $f[1] : null;
        $schema = preg_match('/function schemaVersion\(\): int\s*\{\s*return (\d+)/', $source, $s) === 1 ? (int) $s[1] : null;
        if ($type !== null && $format !== null) {
            $produced[] = ['document_type' => $type, 'state_format' => $format, 'schema_version' => $schema ?? 1];
        }
    }
    $sort = static function (array $rows): array {
        usort($rows, static fn (array $x, array $y): int => strcmp(json_encode($x), json_encode($y)));

        return $rows;
    };

    return ['protocol' => $protocol, 'version' => $version, 'accepted' => $sort($accepted), 'produced' => $sort($produced)];
}
