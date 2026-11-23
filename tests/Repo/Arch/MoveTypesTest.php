<?php

declare(strict_types=1);

/*
 * scripts/inventory/move-types.php on a fixture tree (tests/Repo/Fixtures/move-types: two mini packages with PSR-4
 * `Acme\` and `Acme\Drive\`, a target-map of eight types). The fixture PHP is kept as *.php.stub so that Pint and
 * the test discovery do not touch it; every test copies it to a temporary root and runs the tool there with --root.
 */

function mt_fixture(): string
{
    return dirname(__DIR__).'/Fixtures/move-types';
}

/** A copy of the fixture tree in a temporary directory, `.stub` removed. */
function mt_root(): string
{
    $root = sys_get_temp_dir().'/move-types-'.bin2hex(random_bytes(6));
    register_shutdown_function(static fn () => mt_remove($root));
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(mt_fixture(), FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen(mt_fixture()) + 1);
        $target = $root.'/'.preg_replace('/\.stub$/', '', $relative);
        is_dir(dirname($target)) || mkdir(dirname($target), 0775, true);
        copy($file->getPathname(), $target);
    }

    return $root;
}

function mt_remove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

/** @return array<string, string> every file of the tree => its sha1 */
function mt_tree(string $root): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[substr($file->getPathname(), strlen($root) + 1)] = sha1_file($file->getPathname());
    }
    ksort($files);

    return $files;
}

/**
 * @param  list<string>                                                      $arguments
 * @return array{0: int, 1: array<string, mixed>|null, 2: string, 3: string} exit code, the JSON report, stdout, stderr
 */
function mt_run(string $root, array $arguments): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__, 3).'/scripts/inventory/move-types.php', '--root='.$root, '--no-git', '--json', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $code = proc_close($process);

    return [$code, json_decode($out, true), $out, $err];
}

function mt_read(string $root, string $path): string
{
    return (string) file_get_contents($root.'/'.$path);
}

const MT_APPLY = ['--owner=drive', '--apply'];

it('moves the selected types: file, namespace, imports, fully qualified names, strings and docs', function (): void {
    $root = mt_root();
    [$code, $report] = mt_run($root, MT_APPLY);

    expect($code)->toBe(0)
        ->and(array_column($report['moved'], 'old'))->toBe(['Acme\\Folders\\Folder', 'Acme\\Perm', 'Acme\\Roles\\Policy', 'Acme\\Roles\\Role'])
        ->and(file_exists($root.'/packages/core/src/Roles/Role.php'))->toBeFalse()
        ->and(file_exists($root.'/packages/core/src/Legacy/Old.php'))->toBeTrue();

    $role = mt_read($root, 'packages/drive/src/Roles/Role.php');
    expect($role)->toContain("namespace Acme\\Drive\\Roles;\n")
        ->and($role)->toContain('@param \\Acme\\Drive\\Roles\\Policy $policy')
        ->and($role)->toContain("'Acme\\\\Drive\\\\Roles\\\\Role'")
        ->and($role)->toContain('public function folder(): \\Acme\\Drive\\Folders\\Folder')
        ->and($role)->toContain('return new \\Acme\\Drive\\Folders\\Folder;')
        ->and($role)->toContain("use Acme\\Support\\Clock;\n");

    $folder = mt_read($root, 'packages/drive/src/Folders/Folder.php');
    expect($folder)->toContain('use Acme\\Drive\\Roles\\Role;')
        ->and($folder)->toContain('use Acme\\Drive\\Roles\\Policy;')
        ->and($folder)->toContain('use Acme\\Support\\Clock;')
        ->and($folder)->toContain('use function Acme\\helper;')
        ->and($folder)->not->toContain('use Acme\\{');

    expect(mt_read($root, 'packages/core/config/acme.php'))
        ->toContain('\\Acme\\Drive\\Roles\\Role::class')
        ->toContain("'Acme\\Drive\\Perm'")
        ->toContain("'Acme\\PermX'")
        ->and(mt_read($root, 'docs/guide.md'))->toContain('`Acme\\Drive\\Roles\\Role`')
        ->toContain('`Acme\\\\Drive\\\\Roles\\\\Role`')
        ->and(mt_read($root, 'packages/core/composer.json'))->toContain('"Acme\\\\Drive\\\\Roles\\\\Role"')
        ->toContain('"Acme\\\\PermX"');
});

it('gives a moved type an import for the neighbour that stays and none for one that moves with it', function (): void {
    $root = mt_root();
    mt_run($root, MT_APPLY);

    $role = mt_read($root, 'packages/drive/src/Roles/Role.php');
    expect($role)->toContain("use Acme\\Roles\\Neighbor;\n")
        ->and($role)->not->toContain('use Acme\\Drive\\Roles\\Policy;')
        ->and(mt_read($root, 'packages/drive/src/Roles/Policy.php'))
        ->toContain('use Acme\\Roles\\Neighbor;')
        ->toContain('new \\Acme\\Support\\Clock')
        ->and(mt_read($root, 'packages/drive/src/Perm.php'))->toContain("use Acme\\PermX;\n")
        ->toContain("namespace Acme\\Drive;\n");
});

it('gives a neighbour that stays an import for the type that moved away from its namespace', function (): void {
    $root = mt_root();
    mt_run($root, MT_APPLY);

    $neighbor = mt_read($root, 'packages/core/src/Roles/Neighbor.php');
    expect($neighbor)->toContain('use Acme\\Drive\\Roles\\Policy;')
        ->and($neighbor)->toContain('use Acme\\Drive\\Roles\\Role;')
        ->and($neighbor)->toContain('\\Acme\\Drive\\Perm::class')
        ->and($neighbor)->toContain("'|Acme\\PermX|Acme\\Permanent|'")
        ->and($neighbor)->toContain('public function make(): Role')
        ->and($neighbor)->toContain('public function copy(): self')->not->toContain('use self');
});

it('rewrites the class names of docblock types like code, and leaves templates and imported names alone', function (): void {
    $root = mt_root();
    mt_run($root, MT_APPLY);

    expect(mt_read($root, 'packages/core/src/Roles/Registry.php'))
        ->toContain('@var array<string, \\Acme\\Drive\\Roles\\Role|\\Acme\\Drive\\Roles\\Policy>')
        ->toContain('@param  array{role: \\Acme\\Drive\\Roles\\Role, key: string}|TModel $input')
        ->toContain('@return list<Neighbor>')
        ->toContain('@template TModel of object')
        ->not->toContain('use Acme');
});

it('does not touch a name that merely starts like a moved one', function (): void {
    $root = mt_root();
    mt_run($root, MT_APPLY);

    $perm = mt_read($root, 'packages/drive/src/Perm.php');
    expect($perm)->toContain("'Acme\\Drive\\Perm'")
        ->and($perm)->toContain('"Acme\\\\Drive\\\\Perm"')
        ->and($perm)->toContain("'Acme\\PermX'")
        ->and($perm)->toContain("'Acme\\Perm\\Item'")
        ->and(mt_read($root, 'docs/guide.md'))->toContain('`Acme\\PermX`')->toContain('`Acme\\Permanent`')->toContain('`Acme\\Perm\\Item`');
});

it('is idempotent: a second --apply changes nothing and reports zeros', function (): void {
    $root = mt_root();
    mt_run($root, [...MT_APPLY, '--tests=packages/core/tests/Roles:packages/drive/tests/Roles']);
    $after = mt_tree($root);

    [$code, $report] = mt_run($root, [...MT_APPLY, '--tests=packages/core/tests/Roles:packages/drive/tests/Roles']);

    expect($code)->toBe(0)
        ->and($report)->toMatchArray(['moved' => [], 'edits' => [], 'strings' => [], 'tests' => [], 'ledger' => []])
        ->and(mt_tree($root))->toBe($after);
});

it('does not change the tree in a dry run, and reports what it would do', function (): void {
    $root = mt_root();
    $before = mt_tree($root);

    [$code, $report] = mt_run($root, ['--owner=drive', '--dry-run', '--tests=packages/core/tests/Roles:packages/drive/tests/Roles']);

    expect($code)->toBe(0)
        ->and(mt_tree($root))->toBe($before)
        ->and($report['moved'])->toHaveCount(4)
        ->and($report['edits'])->not->toBeEmpty()
        ->and($report['tests'][0]['to'])->toBe('packages/drive/tests/Roles')
        ->and($report['ledger'])->toHaveCount(1);
});

it('moves a test directory: namespaces, suites, zones and the baseline ledger follow', function (): void {
    $root = mt_root();
    [$code, $report] = mt_run($root, [...MT_APPLY, '--tests=packages/core/tests/Roles:packages/drive/tests/Roles']);

    expect($code)->toBe(0)
        ->and(file_exists($root.'/packages/core/tests/Roles/Helper.php'))->toBeFalse()
        ->and(is_dir($root.'/packages/core/tests/Roles'))->toBeFalse();

    $helper = mt_read($root, 'packages/drive/tests/Roles/Helper.php');
    expect($helper)->toContain("namespace Vaulter\\Drive\\Tests\\Roles;\n")
        ->and($helper)->toContain('use Acme\\Drive\\Roles\\Role;')
        ->and(mt_read($root, 'packages/drive/tests/Roles/RoleCheck.php'))
        ->toContain('use Vaulter\\Drive\\Tests\\Roles\\Helper;')
        ->toContain('use Acme\\Drive\\Roles\\Role;');

    $suites = json_decode(mt_read($root, 'tests/suites.json'), true);
    expect($suites['suites']['core']['partitions'][0]['files'])->toBe(['packages/core/tests/Other/OtherCheck.php'])
        ->and($suites['suites']['drive']['partitions'][0]['files'])->toBe(['packages/drive/tests/Roles/RoleCheck.php']);

    $zones = json_decode(mt_read($root, 'tests/zones.json'), true);
    expect($zones['zones']['core']['tests'])->toBe(['packages/core/tests/Other'])
        ->and($zones['zones']['drive']['tests'])->toBe(['packages/drive/tests']);

    $ledger = json_decode(mt_read($root, 'tests/baseline/CHANGES.json'), true);
    expect($ledger['entries'])->toBe([[
        'suite' => 'core',
        'old' => 'P\\Packages\\core\\tests\\Roles\\RoleCheck::__pest_evaluable_it_builds_a_role',
        'new' => 'P\\Tests\\Roles\\RoleCheck::__pest_evaluable_it_builds_a_role',
        'new_suite' => 'drive',
        'reason' => 'the test file moved to packages/drive/tests/Roles/RoleCheck.php (P4 ownership move)',
        'decision' => 'D40',
    ]])->and($report['ledger'])->toBe($ledger['entries'])
        ->and(mt_read($root, 'tests/baseline/CHANGES.json'))->toContain("\n  \"entries\": [\n    {");
});

it('moves one test file of a mixed directory and runs without a type selection when only tests are given', function (): void {
    $root = mt_root();
    [$code, $report] = mt_run($root, ['--owner=drive', '--rule-file=none-of-the-rules', '--apply', '--tests=packages/core/tests/Roles/RoleCheck.php:packages/drive/tests/Roles/RoleCheck.php']);

    expect($code)->toBe(0)
        ->and($report['moved'])->toBe([])
        ->and(file_exists($root.'/packages/core/tests/Roles/RoleCheck.php'))->toBeFalse()
        ->and(file_exists($root.'/packages/core/tests/Roles/Helper.php'))->toBeTrue()
        ->and(file_exists($root.'/packages/drive/tests/Roles/Helper.php'))->toBeFalse()
        ->and(mt_read($root, 'packages/drive/tests/Roles/RoleCheck.php'))->toContain('use Acme\\Roles\\Role;')->toContain('use Vaulter\\Tests\\Roles\\Helper;');

    $suites = json_decode(mt_read($root, 'tests/suites.json'), true);
    expect($suites['suites']['core']['partitions'][0]['files'])->toBe(['packages/core/tests/Other/OtherCheck.php'])
        ->and($suites['suites']['drive']['partitions'][0]['files'])->toBe(['packages/drive/tests/Roles/RoleCheck.php']);

    $ledger = json_decode(mt_read($root, 'tests/baseline/CHANGES.json'), true);
    expect($ledger['entries'])->toHaveCount(1)
        ->and($ledger['entries'][0]['new'])->toBe('P\\Tests\\Roles\\RoleCheck::__pest_evaluable_it_builds_a_role')
        ->and($ledger['entries'][0]['new_suite'])->toBe('drive');

    [$again, $second] = mt_run($root, ['--owner=drive', '--rule-file=none-of-the-rules', '--apply', '--tests=packages/core/tests/Roles/RoleCheck.php:packages/drive/tests/Roles/RoleCheck.php']);
    expect($again)->toBe(0)->and($second['tests'])->toBe([])->and($second['ledger'])->toBe([]);
});

it('still refuses an empty type selection when no tests are given', function (): void {
    $root = mt_root();
    [$code, , , $err] = mt_run($root, ['--owner=drive', '--rule-file=none-of-the-rules', '--dry-run']);

    expect($code)->toBe(2)->and($err)->toContain('the selection is empty');
});

it('refuses a single test file that does not exist and whose target does not exist either', function (): void {
    $root = mt_root();
    [$code, , , $err] = mt_run($root, ['--owner=drive', '--rule-file=none-of-the-rules', '--dry-run', '--tests=packages/core/tests/Roles/Missing.php:packages/drive/tests/Roles/Missing.php']);

    expect($code)->toBe(2)->and($err)->toContain('neither path exists');
});

it('refuses a test directory whose TestCase key has no partition in the new suite', function (): void {
    $root = mt_root();
    $suites = json_decode(mt_read($root, 'tests/suites.json'), true);
    $suites['suites']['drive']['partitions'][0]['key'] = 'RefreshDatabase+TestCase';
    file_put_contents($root.'/tests/suites.json', json_encode($suites));
    $before = mt_tree($root);

    [$code, , , $err] = mt_run($root, [...MT_APPLY, '--tests=packages/core/tests/Roles:packages/drive/tests/Roles']);

    expect($code)->toBe(2)->and($err)->toContain('has no partition [(unbound)]')
        ->and(mt_tree($root))->toBe($before);
});

it('never selects a replace or split type, and refuses one named explicitly', function (): void {
    $root = mt_root();
    [$code, $report] = mt_run($root, ['--owner=drive', '--dry-run']);
    expect($code)->toBe(0)->and(array_column($report['moved'], 'old'))->not->toContain('Acme\\Legacy\\Old');

    $before = mt_tree($root);
    [$code, , , $err] = mt_run($root, [...MT_APPLY, '--fqcn-prefix=Acme\\Legacy\\Old']);
    expect($code)->toBe(2)->and($err)->toContain('is a replace type')
        ->and(mt_tree($root))->toBe($before);
});

it('narrows the selection by prefix, rule file and exclusion, and refuses an empty one', function (): void {
    $root = mt_root();

    [, $report] = mt_run($root, ['--owner=drive', '--dry-run', '--fqcn-prefix=Acme\\Roles\\']);
    expect(array_column($report['moved'], 'old'))->toBe(['Acme\\Roles\\Policy', 'Acme\\Roles\\Role']);

    [, $report] = mt_run($root, ['--owner=drive', '--dry-run', '--rule-file=other']);
    expect(array_column($report['moved'], 'old'))->toBe(['Acme\\Folders\\Folder', 'Acme\\Perm']);

    [, $report] = mt_run($root, ['--owner=drive', '--dry-run', '--rule-file=other', '--exclude-fqcn=Acme\\Perm']);
    expect(array_column($report['moved'], 'old'))->toBe(['Acme\\Folders\\Folder']);

    [$code, , , $err] = mt_run($root, ['--owner=drive', '--dry-run', '--fqcn-prefix=Nothing\\']);
    expect($code)->toBe(2)->and($err)->toContain('selection is empty');

    [$code] = mt_run($root, ['--owner=media', '--dry-run']);
    expect($code)->toBe(2);
});

it('stops on a conflict before it changes anything', function (): void {
    $root = mt_root();
    mkdir($root.'/packages/drive/src/Roles', 0775, true);
    file_put_contents($root.'/packages/drive/src/Roles/Role.php', "<?php\n");
    $before = mt_tree($root);

    [$code, , , $err] = mt_run($root, MT_APPLY);

    expect($code)->toBe(2)->and($err)->toContain('already exists')->and(mt_tree($root))->toBe($before);
});

it('stops on a file that does not parse, with exit 1 and no change', function (): void {
    $root = mt_root();
    file_put_contents($root.'/packages/core/src/Support/Clock.php', "<?php\nnamespace Acme\\Support;\nfinal class Clock {\n");
    file_put_contents($root.'/packages/core/src/Support/Broken.php', "<?php\nnamespace Acme\\Support;\nuse Acme\\Roles\\Role;\nfinal class Broken {\n");
    $before = mt_tree($root);

    [$code, , , $err] = mt_run($root, MT_APPLY);

    expect($code)->toBe(1)->and($err)->toContain('Broken.php')->and(mt_tree($root))->toBe($before);
});

it('refuses a bad command line with exit 2', function (): void {
    $root = mt_root();

    expect(mt_run($root, ['--apply'])[0])->toBe(2)
        ->and(mt_run($root, ['--owner=drive'])[0])->toBe(2)
        ->and(mt_run($root, ['--owner=drive', '--apply', '--dry-run'])[0])->toBe(2)
        ->and(mt_run($root, ['--owner=drive', '--dry-run', '--bogus'])[0])->toBe(2)
        ->and(mt_run($root, ['--owner=core', '--dry-run'])[0])->toBe(2);
});

it('moves files with git mv when the root is a repository', function (): void {
    $root = mt_root();
    $git = static fn (string ...$args): string => (string) shell_exec('git -C '.escapeshellarg($root).' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1');
    $git('init', '-q');
    $git('add', '-A');
    $git('-c', 'user.email=t@example.com', '-c', 'user.name=t', 'commit', '-q', '-m', 'fixture');

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__, 3).'/scripts/inventory/move-types.php', '--root='.$root, ...MT_APPLY],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    expect(proc_close($process))->toBe(0, $err)
        ->and($git('status', '--porcelain'))->toMatch('#^R. packages/core/src/Roles/Role\.php -> packages/drive/src/Roles/Role\.php$#m');
});

it('selects the phase P4 moves of the owner documents and renames the namespace of tests that leave a package other than core', function (): void {
    $root = mt_root();

    $packages = json_decode(mt_read($root, 'tests/Repo/Arch/Fixtures/packages.json'), true);
    $packages['packages']['documents'] = ['dir' => 'packages/documents', 'exists' => true, 'psr4' => ['Acme\\Documents\\' => 'src/']];
    file_put_contents($root.'/tests/Repo/Arch/Fixtures/packages.json', json_encode($packages));

    $map = json_decode(mt_read($root, 'tests/Repo/Arch/Fixtures/target-map.json'), true);
    $map['types'][] = ['action' => 'move', 'file' => 'packages/drive/src/Rooms/Room.php', 'fqcn' => 'Acme\\Drive\\Rooms\\Room', 'owner' => 'documents', 'phase' => 'P4', 'rule' => 'other:fqcn:Acme\\Drive\\Rooms\\Room', 'target_fqcn' => 'Acme\\Documents\\Rooms\\Room'];
    $map['types'][] = ['action' => 'move', 'file' => 'packages/drive/src/Rooms/Door.php', 'fqcn' => 'Acme\\Drive\\Rooms\\Door', 'owner' => 'documents', 'phase' => 'P5', 'rule' => 'other:fqcn:Acme\\Drive\\Rooms\\Door', 'target_fqcn' => 'Acme\\Documents\\Rooms\\Door'];
    file_put_contents($root.'/tests/Repo/Arch/Fixtures/target-map.json', json_encode($map));

    $zones = json_decode(mt_read($root, 'tests/zones.json'), true);
    $zones['zones']['documents'] = ['suite' => 'documents', 'src' => ['packages/documents/src'], 'tests' => ['packages/documents/tests/Other']];
    file_put_contents($root.'/tests/zones.json', json_encode($zones, JSON_PRETTY_PRINT));

    mkdir($root.'/packages/drive/src/Rooms', 0775, true);
    mkdir($root.'/packages/drive/tests/Rooms', 0775, true);
    file_put_contents($root.'/packages/drive/src/Rooms/Room.php', "<?php\n\nnamespace Acme\\Drive\\Rooms;\n\nfinal class Room {}\n");
    file_put_contents($root.'/packages/drive/src/Rooms/Door.php', "<?php\n\nnamespace Acme\\Drive\\Rooms;\n\nfinal class Door {}\n");
    file_put_contents($root.'/packages/drive/tests/Rooms/RoomCheck.php', "<?php\n\nnamespace Vaulter\\Drive\\Tests\\Rooms;\n\nuse Acme\\Drive\\Rooms\\Room;\n\nfinal class RoomCheck\n{\n    public function room(): Room\n    {\n        return new Room;\n    }\n}\n");

    $suites = json_decode(mt_read($root, 'tests/suites.json'), true);
    $suites['suites']['drive']['partitions'][0]['files'] = ['packages/drive/tests/Rooms/RoomCheck.php'];
    $suites['suites']['documents'] = [...$suites['suites']['drive'], 'cwd' => 'packages/documents', 'tests_dir' => 'packages/documents/tests'];
    $suites['suites']['documents']['partitions'][0]['files'] = [];
    file_put_contents($root.'/tests/suites.json', json_encode($suites));

    [$code, $report, , $err] = mt_run($root, ['--owner=documents', '--apply', '--tests=packages/drive/tests/Rooms:packages/documents/tests/Rooms']);

    // Only the phase P5 type is selected: the one is not a move of this owner.
    expect($code)->toBe(0, $err)
        ->and(array_column($report['moved'], 'old'))->toBe(['Acme\\Drive\\Rooms\\Room'])
        ->and(file_exists($root.'/packages/documents/src/Rooms/Room.php'))->toBeTrue()
        ->and(file_exists($root.'/packages/drive/src/Rooms/Door.php'))->toBeTrue()
        ->and(mt_read($root, 'packages/documents/src/Rooms/Room.php'))->toContain("namespace Acme\\Documents\\Rooms;\n");

    $moved = mt_read($root, 'packages/documents/tests/Rooms/RoomCheck.php');
    expect($moved)->toContain("namespace Vaulter\\Documents\\Tests\\Rooms;\n")
        ->and($moved)->toContain('use Acme\\Documents\\Rooms\\Room;')
        ->and(json_decode(mt_read($root, 'tests/suites.json'), true)['suites']['documents']['partitions'][0]['files'])->toBe(['packages/documents/tests/Rooms/RoomCheck.php']);
});
