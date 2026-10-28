#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Builds one composer "artifact" zip per releasable PHP package from the committed tree,
 * so the consumer smoke installs exactly what a dist archive would contain (git archive
 * honours export-ignore). No network access.
 *
 * ONE canonical prepare: the release manifest and every package byte are read statically from the SAME resolved
 * commit (never from the worktree, never executing package PHP). Each package tree is exported once, gets the single
 * approved composer.json transformation, and is recorded in DIR/prepared/<package>.json (split tree/commit, sorted
 * path -> mode+sha256 map, archive sha256). The ZIP is built FROM that prepared tree; a publisher consumes the same
 * record with --verify-prepared. Qualification stays "pending": nothing here pushes, signs or approves.
 *
 * Usage: build-artifacts.php --version=X.Y.Z --out=DIR [--root=DIR] [--ref=HEAD] [--manifest-ref=REF] [--channel=public|private]
 *        build-artifacts.php --verify-prepared=DIR/prepared
 *        build-artifacts.php --materialize-split=DIR/prepared --package=VENDOR/NAME --source-sha=SHA --git-dir=BARE.git
 *        build-artifacts.php --require-approved=DIR/prepared --package=VENDOR/NAME --source-sha=SHA --approval=A.json [--root=DIR]
 *          (publisher gate, run BEFORE any credential is read: the external approved qualification must bind this exact prepared record)
 *          (publisher side: verifies the record, rebuilds the split commit ONLY from the prepared tree bytes, prints its oid)
 */

require __DIR__.'/manifest-lib.php';

$options = ['root' => dirname(__DIR__, 2), 'ref' => 'HEAD', 'channel' => 'public'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(version|out|root|ref|channel|manifest-ref|verify-prepared|materialize-split|package|source-sha|git-dir|require-approved|approval|sbom)=(.+)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "build-artifacts: unknown argument {$arg}\n");
        exit(2);
    }
}

/** Complete local builder/helper/schema closure; actual trust admission remains external (D18). */
function artifactToolDigest(): string
{
    $required = ['build-artifacts.php', 'manifest-lib.php', 'sbom.py', 'schema/bom-1.5.schema.json', 'schema/spdx.schema.json', 'schema/jsf-0.82.schema.json', 'schema/provenance.json'];
    $paths = $required;
    foreach (glob(__DIR__.'/schema/*') ?: [] as $path) {
        $paths[] = 'schema/'.basename($path);
    }
    $paths = array_unique($paths);
    sort($paths);
    $map = [];
    foreach ($paths as $relative) {
        $path = __DIR__.'/'.$relative;
        $map[$relative] = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : null;
    }

    return hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function contentMap(string $dir): array
{
    $map = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) {
            fwrite(STDERR, "build-artifacts: symlink {$file->getPathname()} is not allowed in a prepared tree\n");
            exit(1);
        }
        $map[substr($file->getPathname(), strlen($dir) + 1)] = [
            'mode' => ($file->getPerms() & 0111) !== 0 ? '100755' : '100644',
            'sha256' => hash_file('sha256', $file->getPathname()),
            'size' => $file->getSize(),
        ];
    }
    ksort($map);

    return $map;
}

/**
 * Refuses every symlink (dangling ones included) in an exported tree. It runs right after extraction and BEFORE anything is
 * read from or written into the tree (composer.json, CHANGELOG copy, normalization), so no write can follow a link out of staging.
 */
function refuseSymlinks(string $dir, string $name): void
{
    $links = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) {
            $links[] = substr($file->getPathname(), strlen($dir) + 1);
        }
    }
    if ($links !== []) {
        fwrite(STDERR, "build-artifacts: refused: {$name} exports symlink(s) ".implode(', ', $links)."; a prepared tree has none\n");
        exit(1);
    }
}

function manifestDigest(array $map): string
{
    return hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function runGit(string $gitDir, string $arguments, ?string &$output = null, array $env = []): int
{
    $prefix = 'GIT_DIR='.escapeshellarg($gitDir);
    foreach ($env as $key => $value) {
        $prefix .= ' '.$key.'='.escapeshellarg($value);
    }

    return run($prefix.' git '.$arguments, $output);
}

/** Deterministic split identity of a prepared tree: tree oid + parentless commit; no filters, no push. */
function splitIdentity(string $treeDir, array $files, string $gitDir, string $name, string $version, string $sourceSha, string $date): array
{
    $identity = ['GIT_AUTHOR_NAME' => 'vaulter-prepare', 'GIT_AUTHOR_EMAIL' => 'prepare@vaulter.invalid', 'GIT_COMMITTER_NAME' => 'vaulter-prepare', 'GIT_COMMITTER_EMAIL' => 'prepare@vaulter.invalid', 'GIT_AUTHOR_DATE' => $date, 'GIT_COMMITTER_DATE' => $date];
    if (!is_dir($gitDir)) {
        run('git init -q --bare '.escapeshellarg($gitDir), $log);
    }
    $indexInfo = '';
    foreach ($files as $local => $meta) {
        runGit($gitDir, 'hash-object -w --no-filters '.escapeshellarg($treeDir.'/'.$local), $oid);
        $indexInfo .= $meta['mode'].' '.trim($oid)."\t".$local."\n";
    }
    file_put_contents($gitDir.'/index-info', $indexInfo);
    $code = runGit($gitDir, 'update-index --index-info < '.escapeshellarg($gitDir.'/index-info'), $log, ['GIT_INDEX_FILE' => $gitDir.'/index']);
    $code |= runGit($gitDir, 'write-tree', $tree, ['GIT_INDEX_FILE' => $gitDir.'/index']);
    $tree = trim($tree);
    $code |= runGit($gitDir, 'commit-tree '.escapeshellarg($tree).' -m '.escapeshellarg("split {$name} {$version} from {$sourceSha}"), $commit, $identity);
    $commit = trim($commit);
    if ($code !== 0 || preg_match('/^[0-9a-f]{40}$/', $tree) !== 1 || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
        return [null, null];
    }

    return [$tree, $commit];
}

/** Everything a consumer must hold true before it may use a prepared record. Returns error strings. */
function verifyRecord(string $dir, string $recordPath): array
{
    $label = basename($recordPath, '.json');
    $record = json_decode((string) file_get_contents($recordPath), true);
    foreach (['source_sha', 'package', 'version', 'split_commit', 'split_tree', 'split_commit_date', 'content_manifest_sha256', 'archive', 'archive_sha256', 'policy_sha256', 'qualification', 'files'] as $field) {
        if (!is_array($record) || !array_key_exists($field, $record)) {
            return ["{$label}: record lacks required field {$field}"];
        }
    }
    $errors = [];
    $tree = $dir.'/'.$label.'/tree';
    $actual = is_dir($tree) ? contentMap($tree) : [];
    if ($actual !== $record['files'] || manifestDigest($actual) !== $record['content_manifest_sha256']) {
        $errors[] = "{$label}: prepared tree differs from its record (bytes, modes or file set)";
    }
    $zipPath = dirname($dir).'/'.$record['archive'];
    $zip = new ZipArchive;
    if (!is_file($zipPath) || $zip->open($zipPath) !== true) {
        return array_merge($errors, ["{$label}: archive {$record['archive']} is missing"]);
    }
    if (hash_file('sha256', $zipPath) !== $record['archive_sha256']) {
        $errors[] = "{$label}: archive sha256 differs from the record";
    }
    $inZip = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string) $zip->getNameIndex($i);
        if (!str_ends_with($entry, '/')) {
            $inZip[$entry] = hash('sha256', (string) $zip->getFromIndex($i));
        }
    }
    ksort($inZip);
    if ($inZip !== array_map(static fn (array $f): string => $f['sha256'], $record['files'])) {
        $errors[] = "{$label}: archive content differs from the prepared tree";
    }

    if (($record['sbom_sha256'] ?? null) !== null) {
        foreach (['sbom', 'resolution', 'resolution_provenance'] as $kind) {
            $file = $record[$kind] ?? '';
            $path = dirname($dir).'/'.$file;
            if ($file === '' || basename($file) !== $file || !is_file($path) || hash_file('sha256', $path) !== ($record[$kind.'_sha256'] ?? null)) {
                $errors[] = "{$label}: {$kind} bytes/hash missing or different";
            }
        }
        if ($errors === []) {
            $bom = json_decode((string) file_get_contents(dirname($dir).'/'.$record['sbom']), true);
            $props = [];
            foreach ($bom['metadata']['properties'] ?? [] as $property) {
                $props[$property['name']] = $property['value'];
            }
            foreach (['source_sha', 'resolution_sha256', 'policy_sha256', 'tool_sha256'] as $field) {
                if (($props['vaulter:'.$field] ?? null) !== $record[$field]) {
                    $errors[] = "{$label}: SBOM {$field} differs from record";
                }
            }
            if (($bom['metadata']['component']['name'] ?? null) !== $record['package'] || ($bom['metadata']['component']['version'] ?? null) !== $record['version']) {
                $errors[] = "{$label}: SBOM package/version differs from record";
            }
        }
    }

    if (($record['sbom_sha256'] ?? null) !== null && $errors === []) {
        $command = 'python3 -I -B '.escapeshellarg(__DIR__.'/sbom.py').' --verify';
        foreach ([$tree.'/composer.json', dirname($dir).'/'.$record['resolution_provenance'], $record['source_sha'], $record['version'], $record['tool_sha256'], $record['policy_sha256'], dirname($dir).'/'.$record['sbom']] as $argument) {
            $command .= ' '.escapeshellarg($argument);
        }
        if (run($command, $log) !== 0) {
            $errors[] = "{$label}: reference resolution/SBOM refused: {$log}";
        }
    }
    $sums = @file_get_contents(dirname($dir).'/SHA256SUMS');
    foreach (['archive', 'sbom'] as $kind) {
        if (isset($record[$kind], $record[$kind.'_sha256'])) {
            $expected = $record[$kind.'_sha256'].'  '.$record[$kind];
            if ($sums === false || !in_array($expected, explode("\n", $sums), true)) {
                $errors[] = "{$label}: {$kind} differs from SHA256SUMS";
            }
        }
    }

    return $errors;
}

if (isset($options['verify-prepared'])) {
    $dir = rtrim($options['verify-prepared'], '/');
    $records = glob($dir.'/*.json') ?: [];
    $errors = [];
    foreach ($records as $recordPath) {
        $errors = array_merge($errors, verifyRecord($dir, $recordPath));
    }
    if ($records === [] || $errors !== []) {
        fwrite(STDERR, "build-artifacts: prepared record check failed\n - ".implode("\n - ", $errors ?: ['no prepared records in '.$dir])."\n");
        exit(1);
    }
    echo count($records)." prepared record(s) verified\n";
    exit(0);
}

// Publisher gate. A pending record, a missing approval, or an approval for different bytes/tool/policy refuses; nothing
// here can mint an approval: A is external evidence (D18), authenticated by a protected external SHA-256 of its bytes
// and only then compared with the prepared record.
if (isset($options['require-approved'])) {
    $dir = rtrim($options['require-approved'], '/');
    $package = $options['package'] ?? '';
    $source = $options['source-sha'] ?? '';
    $fail = static function (string $message): never {
        fwrite(STDERR, "build-artifacts: publish refused: {$message}\n");
        exit(1);
    };
    $recordPath = $dir.'/'.str_replace('/', '-', $package).'.json';
    if ($package === '' || preg_match('/^[0-9a-f]{40}$/', $source) !== 1 || !is_file($recordPath)) {
        $fail("no prepared record for '{$package}' at a valid --source-sha");
    }
    if (($errors = verifyRecord($dir, $recordPath)) !== []) {
        $fail(implode('; ', $errors));
    }
    $record = json_decode((string) file_get_contents($recordPath), true);
    // Authenticity: the EXACT bytes of A must hash to a value supplied from OUTSIDE the candidate (the protected
    // repository/owner variable, via the environment only) BEFORE any of A is parsed. A self-consistent JSON that merely
    // says "approved" proves nothing; neither does a hash computed from the candidate or defaulted here.
    $expectedHash = getenv('VAULTER_PREPARED_APPROVAL_SHA256');
    if (!is_string($expectedHash) || preg_match('/^[0-9a-f]{64}$/', $expectedHash) !== 1) {
        $fail('VAULTER_PREPARED_APPROVAL_SHA256 (protected external hash of the approval bytes) is missing or malformed');
    }
    $approvalPath = $options['approval'] ?? '';
    $approvalBytes = is_file($approvalPath) ? file_get_contents($approvalPath) : false;
    if ($approvalBytes === false || !hash_equals($expectedHash, hash('sha256', $approvalBytes))) {
        $fail('the approval bytes are missing or do not match the protected external hash');
    }
    $approval = json_decode($approvalBytes, true);
    if (!is_array($approval) || ($approval['status'] ?? null) !== 'approved') {
        $fail('no approved qualification (external A) for this prepared record; qualification is '.($record['qualification'] ?? 'missing'));
    }
    $entry = $approval['packages'][$package] ?? null;
    if (!is_array($entry)) {
        $fail("the approval does not name {$package}");
    }
    if ($record['source_sha'] !== $source) {
        $fail("record is for {$record['source_sha']}, not {$source}");
    }
    $root = rtrim($options['root'] ?? dirname(__DIR__, 2), '/');
    run(sprintf('git -C %s show %s', escapeshellarg($root), escapeshellarg($source.':release/packages.json')), $manifestBytes);
    $actual = [
        'policy_sha256' => hash('sha256', (string) $manifestBytes),
        'tool_sha256' => artifactToolDigest(),
    ];
    foreach (['source_sha', 'split_commit', 'split_tree', 'content_manifest_sha256', 'archive_sha256', 'sbom_sha256', 'resolution_sha256', 'policy_sha256', 'tool_sha256'] as $field) {
        $value = $record[$field] ?? null;
        if (!is_string($value) || preg_match('/^[0-9a-f]{40,64}$/', $value) !== 1) {
            $fail("prepared record is still pending: {$field} is not set");
        }
        if (($entry[$field] ?? null) !== $value) {
            $fail("approved identity differs from the prepared record at {$field}");
        }
        if (isset($actual[$field]) && $actual[$field] !== $value) {
            $fail("{$field} of this checkout is not the one the record was prepared with");
        }
    }
    if (($approval['source_sha'] ?? null) !== $source || ($approval['policy_sha256'] ?? null) !== $actual['policy_sha256'] || ($approval['tool_sha256'] ?? null) !== $actual['tool_sha256']) {
        $fail('the approval binds a different source, policy or tool');
    }
    echo "approved: {$package} {$record['split_commit']}\n";
    exit(0);
}

// Publisher side: no rebuild, no composer normalization, no second split. The record is verified, the commit is
// recomputed from the prepared tree bytes alone and must equal the recorded tree and commit oids.
if (isset($options['materialize-split'])) {
    $dir = rtrim($options['materialize-split'], '/');
    $package = $options['package'] ?? '';
    $gitDir = $options['git-dir'] ?? '';
    $source = $options['source-sha'] ?? '';
    $recordPath = $dir.'/'.str_replace('/', '-', $package).'.json';
    $fail = static function (string $message): never {
        fwrite(STDERR, "build-artifacts: materialize-split refused: {$message}\n");
        exit(1);
    };
    if ($package === '' || $gitDir === '' || preg_match('/^[0-9a-f]{40}$/', $source) !== 1) {
        $fail('--package, --source-sha (40 hex) and --git-dir are required');
    }
    if (!is_file($recordPath)) {
        $fail("no prepared record for {$package}");
    }
    $errors = verifyRecord($dir, $recordPath);
    $record = $errors === [] ? json_decode((string) file_get_contents($recordPath), true) : [];
    if ($errors !== []) {
        $fail(implode('; ', $errors));
    }
    if ($record['package'] !== $package || $record['source_sha'] !== $source) {
        $fail("record is for {$record['package']} at {$record['source_sha']}, not {$package} at {$source}");
    }
    if (is_dir($gitDir)) {
        $fail('--git-dir must not exist yet');
    }
    $scratch = $gitDir.'.scratch';
    [$tree, $commit] = splitIdentity($dir.'/'.str_replace('/', '-', $package).'/tree', $record['files'], $scratch, $package, $record['version'], $source, $record['split_commit_date']);
    if ($tree !== $record['split_tree'] || $commit !== $record['split_commit']) {
        removeTree($scratch);
        $fail('recomputed split tree/commit differ from the prepared record');
    }
    rename($scratch, $gitDir);
    echo $commit."\n";
    exit(0);
}

$version = $options['version'] ?? null;
$out = $options['out'] ?? null;
if ($version === null || preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) !== 1 || $out === null) {
    fwrite(STDERR, "usage: build-artifacts.php --version=X.Y.Z --out=DIR [--root=DIR] [--ref=HEAD]\n");
    exit(2);
}

$channel = $options['channel'];
if ($channel !== 'public' && $channel !== 'private') {
    fwrite(STDERR, "build-artifacts: unknown channel {$channel}\n");
    exit(2);
}
$root = rtrim($options['root'], '/');
$ref = $options['ref'];

function run(string $command, ?string &$output = null): int
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process);
}

function removeTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function isDevConstraint(string $constraint): bool
{
    return manifestIsDevConstraint($constraint);
}

$code = run(sprintf('git -C %s rev-parse --verify --quiet %s', escapeshellarg($root), escapeshellarg($ref.'^{commit}')), $sourceSha);
$sourceSha = trim($sourceSha);
if ($code !== 0 || preg_match('/^[0-9a-f]{40}$/', $sourceSha) !== 1) {
    fwrite(STDERR, "build-artifacts: cannot resolve --ref {$ref} to a commit\n");
    exit(1);
}
if (isset($options['manifest-ref'])) {
    $code = run(sprintf('git -C %s rev-parse --verify --quiet %s', escapeshellarg($root), escapeshellarg($options['manifest-ref'].'^{commit}')), $manifestSha);
    if ($code !== 0 || trim($manifestSha) !== $sourceSha) {
        fwrite(STDERR, "build-artifacts: mixed source: manifest-ref {$options['manifest-ref']} is not the commit of --ref {$ref} ({$sourceSha})\n");
        exit(1);
    }
}
// The manifest is read from the SAME commit as the package bytes; the worktree is never consulted.
$code = run(sprintf('git -C %s show %s', escapeshellarg($root), escapeshellarg($sourceSha.':release/packages.json')), $manifestBytes);
if ($code !== 0) {
    fwrite(STDERR, "build-artifacts: release/packages.json is not readable at {$sourceSha}\n");
    exit(1);
}
$manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
$policySha = hash('sha256', $manifestBytes);
$toolSha = artifactToolDigest();
$commitDate = '';
run(sprintf('git -C %s show -s --format=%%cI %s', escapeshellarg($root), escapeshellarg($sourceSha)), $commitDate);
$commitDate = trim($commitDate);
$preparedDir = $out.'/prepared';
removeTree($preparedDir);

if (!is_dir($out) && !mkdir($out, 0775, true)) {
    fwrite(STDERR, "build-artifacts: cannot create {$out}\n");
    exit(1);
}
$out = realpath($out);

$staging = sys_get_temp_dir().'/vaulter-artifacts-'.bin2hex(random_bytes(6));
mkdir($staging, 0775, true);
register_shutdown_function(static fn () => removeTree($staging));

// Fail closed on the WHOLE manifest (schema, channel, line, prefix, uniqueness) BEFORE filtering by channel: one
// malformed or unknown private entry breaks the public build too.
$manifestErrors = validateManifest($manifest);
if ($manifestErrors !== []) {
    fwrite(STDERR, "build-artifacts: invalid release manifest\n - ".implode("\n - ", $manifestErrors)."\n");
    exit(1);
}

$built = [];
foreach ($manifest['php'] as $package) {
    $name = $package['name'];
    if ($package['channel'] !== $channel) {
        continue;
    }

    $dir = $staging.'/'.basename($package['path']);
    mkdir($dir, 0775, true);
    // Preflight BEFORE any extraction: the exported tree's mode-120000 entries (symlinks, dangling included) are read from the
    // git tree object itself, so no extractor, no metadata read and no write runs for a tree that carries one.
    $code = run(sprintf('git -C %s ls-tree -r -z %s', escapeshellarg($root), escapeshellarg($sourceSha.':'.$package['path'])), $listing);
    if ($code !== 0) {
        fwrite(STDERR, "build-artifacts: cannot list the exported tree of {$name}: {$listing}\n");
        exit(1);
    }
    $linked = [];
    foreach (explode("\0", $listing) as $entry) {
        if ($entry !== '' && str_starts_with($entry, '120000 ')) {
            $linked[] = substr($entry, (int) strpos($entry, "\t") + 1);
        }
    }
    if ($linked !== []) {
        fwrite(STDERR, "build-artifacts: refused: {$name} exports symlink(s) ".implode(', ', $linked)."; nothing was extracted\n");
        exit(1);
    }

    $pipeline = sprintf('git -C %s archive --format=tar %s | tar -x -C %s', escapeshellarg($root), escapeshellarg($sourceSha.':'.$package['path']), escapeshellarg($dir));
    $code = run('bash -o pipefail -c '.escapeshellarg($pipeline), $log);
    if ($code !== 0) {
        fwrite(STDERR, "build-artifacts: git archive failed for {$name}: {$log}\n");
        exit(1);
    }
    refuseSymlinks($dir, $name);

    $composer = json_decode((string) file_get_contents($dir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    $upstream = $package['requires_tag'] ?? null;
    if (($package['excluded_unless_tagged'] ?? false) && $upstream !== null && isDevConstraint((string) ($composer['require'][$upstream] ?? ''))) {
        echo "skip {$name}: requires {$upstream} {$composer['require'][$upstream]} (dev constraint, upstream not tagged)\n";

        continue;
    }

    // The root CHANGELOG ships inside the prepared tree (public channel) so the split and the ZIP carry the same bytes.
    if ($channel === 'public' && !is_file($dir.'/CHANGELOG.md') && run(sprintf('git -C %s show %s', escapeshellarg($root), escapeshellarg($sourceSha.':CHANGELOG.md')), $changelog) === 0) {
        file_put_contents($dir.'/CHANGELOG.md', $changelog);
    }

    $composer['version'] = $version;
    // Path repositories point at sibling checkouts and mean nothing inside a dist archive.
    unset($composer['repositories']);
    foreach (['require', 'require-dev'] as $section) {
        foreach ($composer[$section] ?? [] as $dependency => $constraint) {
            if ($constraint === 'self.version') {
                $composer[$section][$dependency] = $version;
            }
        }
    }
    file_put_contents($dir.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

    // Single prepared tree: everything below (record, split identity, ZIP) derives from these exact bytes.
    $label = str_replace('/', '-', $name);
    $files = contentMap($dir);
    $preparedTree = $preparedDir.'/'.$label.'/tree';
    mkdir($preparedTree, 0775, true);
    if (run('cp -a '.escapeshellarg($dir.'/.').' '.escapeshellarg($preparedTree), $log) !== 0) {
        fwrite(STDERR, "build-artifacts: cannot keep the prepared tree of {$name}: {$log}\n");
        exit(1);
    }

    // Deterministic split identity from the prepared bytes (no filters, no push): tree oid + parentless commit.
    [$splitTree, $splitCommit] = splitIdentity($dir, $files, $staging.'/'.basename($package['path']).'.git', $name, $version, $sourceSha, $commitDate);
    if ($splitTree === null) {
        fwrite(STDERR, "build-artifacts: cannot compute the split identity of {$name}\n");
        exit(1);
    }

    $archive = $label.'-'.$version.'.zip';
    $zipPath = $out.'/'.$archive;
    @unlink($zipPath);
    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "build-artifacts: cannot write {$zipPath}\n");
        exit(1);
    }
    foreach ($files as $local => $meta) {
        $zip->addFile($preparedTree.'/'.$local, $local);
        $zip->setMtimeName($local, max(315532800, (int) strtotime($commitDate)));
        $zip->setExternalAttributesName($local, ZipArchive::OPSYS_UNIX, (octdec($meta['mode']) << 16));
    }
    $zip->close();

    $sbom = ['sbom_sha256' => null, 'resolution_sha256' => null];
    if (isset($options['sbom'])) {
        $sbomPath = $out.'/'.$label.'-'.$version.'.cdx.json';
        $command = 'python3 -I -B '.escapeshellarg(__DIR__.'/sbom.py');
        foreach ([$preparedTree.'/composer.json', $options['sbom'], $sourceSha, $version, $toolSha, $policySha, $sbomPath] as $argument) {
            $command .= ' '.escapeshellarg($argument);
        }
        if (run($command, $log) !== 0) {
            fwrite(STDERR, "build-artifacts: resolution/SBOM incomplete: {$log}\n");
            exit(1);
        }
        $sbom = json_decode(trim($log), true, 512, JSON_THROW_ON_ERROR);
        $built[] = $sbomPath;
    }

    file_put_contents($preparedDir.'/'.$label.'.json', json_encode($sbom + [
        'source_sha' => $sourceSha,
        'release_line' => $package['release_line'],
        'channel' => $package['channel'],
        'package' => $name,
        'version' => $version,
        'split_commit' => $splitCommit,
        'split_tree' => $splitTree,
        'split_commit_date' => $commitDate,
        'content_manifest_sha256' => manifestDigest($files),
        'archive' => $archive,
        'archive_sha256' => hash_file('sha256', $zipPath),
        'sbom_sha256' => null,
        'resolution_sha256' => null,
        'policy_sha256' => $policySha,
        'tool_sha256' => $toolSha,
        'qualification' => 'pending',
        'files' => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    $built[] = $zipPath;
    echo "built {$zipPath}\n";
}

sort($built);
file_put_contents($out.'/SHA256SUMS', implode("\n", array_map(static fn (string $path): string => hash_file('sha256', $path).'  '.basename($path), $built))."\n");

echo count($built)." artifact(s) for {$version}\n";
