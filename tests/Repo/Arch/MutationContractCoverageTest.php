<?php

declare(strict_types=1);

use Vaulter\Documents\Operations\DocumentsOperationPolicies;
use Vaulter\Drive\Operations\DriveOperationPolicies;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Webhooks\WebhooksOperationPolicies;

/*
 * The mutation contract as one in-memory join, with no second catalogue committed. Rows come from the
 * OperationPolicySource tables of the packages (the OperationCatalog contributions), the mutating v1 routes of the
 * identities inventory (tests/Repo/Arch/Fixtures/identities/http.json) and the operation names the package sources
 * construct. Every name is declared by its own package; every mutating route belongs to exactly one policy of the
 * package that owns it; a receipt route reads the Idempotency-Key and no other route does; every receipt operation
 * links an existing behavioral case of its owner (replay after current authorization, one effect). This proves
 * completeness and linkage only: the guards, the compare-and-set and the replay are proven by the linked cases.
 * `vaulter:operations` stays the documentation projection of the same catalogue.
 */

const MCC_SOURCES = [
    'drive' => DriveOperationPolicies::class,
    'documents' => DocumentsOperationPolicies::class,
    'webhooks' => WebhooksOperationPolicies::class,
];

/**
 * The behavioral case of each receipt operation, as [test file, exact case title]: the replay under the key, one
 * effect, and the owner's current authorization or the refusal of another request.
 */
const MCC_EVIDENCE = [
    'drive.folders.create' => ['packages/drive/tests/Http/IdempotencyKeyHttpTest.php', 'replays a folder creation for the same key and body'],
    'drive.nodes.rename' => ['packages/drive/tests/Http/IdempotencyKeyHttpTest.php', 'replays a rename whose If-Match went stale because the first request committed'],
    'drive.nodes.move' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays a move with one event, even when its If-Match went stale'],
    'drive.nodes.copy' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays a copy and makes one copy'],
    'drive.nodes.set_properties' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays set_properties after its If-Match went stale'],
    'drive.nodes.trash' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays a trash that already put the node in the trash, and its restore'],
    'drive.trash.restore' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays a trash that already put the node in the trash, and its restore'],
    'drive.trash.purge' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays a purge although the node is gone, by the recorded scope'],
    'drive.trash.empty' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays an emptied trash with its count and does not empty a later one'],
    'drive.files.restore_revision' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays a restored revision and a deleted revision'],
    'drive.files.delete_revision' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays a restored revision and a deleted revision'],
    'drive.uploads.initiate' => ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'replays an upload initiation with the same session'],
    'drive.access.grant' => ['packages/drive/tests/Http/GrantsV1Test.php', 'replays a grant and a revoke under their keys and refuses another body under a used key'],
    'drive.access.revoke' => ['packages/drive/tests/Http/GrantsV1Test.php', 'replays a grant and a revoke under their keys and refuses another body under a used key'],
    'drive.access.restrict_inheritance' => ['packages/drive/tests/Http/InheritanceV1Test.php', 'replays a restriction under its key although its tag went stale'],
    'drive.access.restore_inheritance' => ['packages/drive/tests/Http/IdempotencyKeyHttpTest.php', 'replays a restored inheritance and an unarchive under their keys although their tags went stale, and answers a retried drive deletion drive_not_found'],
    'drive.shares.create' => ['packages/drive/tests/Sharing/ShareIdempotencyTest.php', 'replays a created link with the same token and keeps that token in the receipt only as ciphertext'],
    'drive.shares.update' => ['packages/drive/tests/Sharing/ShareIdempotencyTest.php', 'replays an update and a revocation once each and stores a stale refusal under its key'],
    'drive.shares.rotate' => ['packages/drive/tests/Sharing/ShareIdempotencyTest.php', 'replays a rotation with the same new token to the same actor, once'],
    'drive.shares.revoke' => ['packages/drive/tests/Sharing/ShareIdempotencyTest.php', 'replays an update and a revocation once each and stores a stale refusal under its key'],
    'drive.drives.archive' => ['packages/drive/tests/Http/DrivesV1Test.php', 'replays an archive under its key although its tag went stale, and refuses another body'],
    'drive.drives.unarchive' => ['packages/drive/tests/Http/IdempotencyKeyHttpTest.php', 'replays a restored inheritance and an unarchive under their keys although their tags went stale, and answers a retried drive deletion drive_not_found'],
    'drive.drives.delete' => ['packages/drive/tests/Http/IdempotencyKeyHttpTest.php', 'replays a restored inheritance and an unarchive under their keys although their tags went stale, and answers a retried drive deletion drive_not_found'],
    'documents.documents.create' => ['packages/documents/tests/Operations/DocumentIdempotencyTest.php', 'replays a document creation once'],
    'documents.documents.update' => ['packages/documents/tests/Operations/DocumentIdempotencyTest.php', 'replays a content update, a checkpoint and a restore without a second write'],
    'documents.documents.snapshot' => ['packages/documents/tests/Operations/DocumentIdempotencyTest.php', 'replays a content update, a checkpoint and a restore without a second write'],
    'documents.documents.restore' => ['packages/documents/tests/Operations/DocumentIdempotencyTest.php', 'replays a content update, a checkpoint and a restore without a second write'],
    'documents.comments.add' => ['packages/documents/tests/Operations/DocumentIdempotencyTest.php', 'replays a comment add, a resolution and a deletion once each'],
    'documents.comments.resolve' => ['packages/documents/tests/Operations/DocumentIdempotencyTest.php', 'replays a comment add, a resolution and a deletion once each'],
    'documents.comments.delete' => ['packages/documents/tests/Operations/DocumentIdempotencyTest.php', 'replays a comment add, a resolution and a deletion once each'],
    'documents.comments.reopen' => ['packages/documents/tests/Operations/DocumentIdempotencyTest.php', 'replays a reopen once'],
    'webhooks.endpoints.register' => ['packages/webhooks/tests/Webhooks/EndpointIdempotencyTest.php', 'registers once per key and replays the same secret from a receipt that holds only its ciphertext'],
    'webhooks.endpoints.update' => ['packages/webhooks/tests/Webhooks/EndpointIdempotencyTest.php', 'replays an update whose If-Match went stale because the first request committed, without a second change'],
    'webhooks.endpoints.deactivate' => ['packages/webhooks/tests/Webhooks/EndpointIdempotencyTest.php', 'deactivates once per key and raises the version once'],
    'webhooks.endpoints.rotate_secret' => ['packages/webhooks/tests/Webhooks/EndpointIdempotencyTest.php', 'rotates the secret once per key, replays the same secret, and keeps the receipt free of it'],
    'webhooks.endpoints.delete' => ['packages/webhooks/tests/Webhooks/EndpointIdempotencyTest.php', 'replays a deletion after the endpoint is gone, on the scope recorded in its receipt'],
    'webhooks.endpoints.redeliver' => ['packages/webhooks/tests/Webhooks/EndpointIdempotencyTest.php', 'answers a keyed retry of a redelivery from its receipt and sends nothing, even once the delivery was delivered again'],
];

function mcc_root(): string
{
    return dirname(__DIR__, 3);
}

/** @return array<string, array{owner: string, policy: OperationPolicy}> by operation name */
function mcc_policies(): array
{
    $policies = [];
    foreach (MCC_SOURCES as $owner => $class) {
        foreach ((new $class)->policies() as $name => $policy) {
            $policies[$name] = ['owner' => $owner, 'policy' => $policy];
        }
    }

    return $policies;
}

/** @return array<string, array{owner: string, middleware: list<string>}> the mutating v1 routes, by name without the prefix */
function mcc_routes(): array
{
    $inventory = json_decode((string) file_get_contents(mcc_root().'/tests/Repo/Arch/Fixtures/identities/http.json'), true, flags: JSON_THROW_ON_ERROR);
    $routes = [];
    foreach ($inventory['items'] as $item) {
        $name = (string) ($item['name'] ?? '');
        // The public share unlock changes nothing: it is a POST only so the share password stays in the body.
        if ($name === 'vaulter.v1.public-shares.unlock') {
            continue;
        }
        if (str_starts_with($name, 'vaulter.v1.') && array_intersect($item['methods'], ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $routes[substr($name, strlen('vaulter.v1.'))] = ['owner' => (string) $item['owner'], 'middleware' => array_values($item['middleware'])];
        }
    }

    return $routes;
}

/** @return array<string, list<string>> the operation names each package source constructs, by owner */
function mcc_names(): array
{
    $names = [];
    foreach (array_keys(MCC_SOURCES) as $owner) {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(mcc_root().'/packages/'.$owner.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getPathname(), '.php')) {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        $found = [];
        $writes = static fn (string $source): array => preg_match_all("/->(?:write|ingestSource)\\((?:\\\$request,\\s*)?'([a-z_]+)'/", $source, $m) > 0 ? $m[1] : [];
        foreach ($files as $source) {
            preg_match_all("/new OperationSpec\\('([a-z_.]+[a-z_])'\\s*,/", $source, $literal);
            array_push($found, ...$literal[1]);
            if (str_contains($source, 'new OperationSpec(self::OPERATION') && preg_match("/const string OPERATION = '([a-z_.]+)'/", $source, $constant) === 1) {
                $found[] = $constant[1];
            }
            if (preg_match_all("/new OperationSpec\\('([a-z_.]+\\.)'\\s*\\.\\s*\\\$verb/", $source, $prefixes) > 0) {
                // The verbs are passed to the writer of the same file, or to the shared writer of the package.
                $verbs = $writes($source) ?: array_merge(...array_values(array_map($writes, $files)));
                foreach ($prefixes[1] as $prefix) {
                    foreach ($verbs as $verb) {
                        $found[] = $prefix.$verb;
                    }
                }
            }
        }
        $found = array_values(array_unique($found));
        sort($found);
        $names[$owner] = $found;
    }

    return $names;
}

/** @return list<string> the case titles of a Pest file */
function mcc_titles(string $file): array
{
    $path = mcc_root().'/'.$file;
    if (!is_file($path)) {
        return [];
    }
    preg_match_all("/^it\\('((?:[^'\\\\]|\\\\.)*)'/m", (string) file_get_contents($path), $matches);

    return array_map(static fn (string $title): string => stripcslashes($title), $matches[1]);
}

/**
 * Every broken link of the join, as one line each.
 *
 * @param  array<string, array{owner: string, policy: OperationPolicy}>  $policies
 * @param  array<string, array{owner: string, middleware: list<string>}> $routes
 * @param  array<string, list<string>>                                   $names
 * @param  array<string, array{0: string, 1: string}>                    $evidence
 * @return list<string>
 */
function mcc_problems(array $policies, array $routes, array $names, array $evidence): array
{
    $problems = [];
    foreach ($names as $owner => $list) {
        foreach ($list as $name) {
            if (!isset($policies[$name])) {
                $problems[] = "unregistered mutation: {$name} ({$owner})";
            } elseif ($policies[$name]['owner'] !== $owner) {
                $problems[] = "foreign mutation: {$name} is run by {$owner} and declared by {$policies[$name]['owner']}";
            }
        }
    }

    $claimed = [];
    foreach ($policies as $name => ['owner' => $owner, 'policy' => $policy]) {
        foreach ($policy->routes as $route) {
            $claimed[$route][] = $name;
            if (!isset($routes[$route])) {
                $problems[] = "unknown route: {$name} names {$route}, not a mutating v1 route";

                continue;
            }
            if ($routes[$route]['owner'] !== $owner) {
                $problems[] = "foreign route: {$route} of {$routes[$route]['owner']} in the policy {$name} of {$owner}";
            }
            $reads = in_array(ReadIdempotencyKey::ALIAS, $routes[$route]['middleware'], true);
            if ($policy->idempotency === IdempotencyPolicy::Receipt && !$reads) {
                $problems[] = "receipt route without the key: {$name} → {$route}";
            }
            if ($policy->idempotency !== IdempotencyPolicy::Receipt && $reads) {
                $problems[] = "key on a {$policy->idempotency->value} route: {$name} → {$route}";
            }
        }
        if ($policy->idempotency === IdempotencyPolicy::Receipt) {
            if (!isset($evidence[$name])) {
                $problems[] = "receipt operation without evidence: {$name}";
            } elseif (!in_array($evidence[$name][1], mcc_titles($evidence[$name][0]), true)) {
                $problems[] = "broken evidence link: {$name} → {$evidence[$name][0]} :: {$evidence[$name][1]}";
            }
        }
    }
    foreach ($routes as $route => $_) {
        if (count($claimed[$route] ?? []) !== 1) {
            $problems[] = sprintf('route in %d policies: %s', count($claimed[$route] ?? []), $route);
        }
    }
    foreach (array_keys($evidence) as $name) {
        if (($policies[$name]['policy'] ?? null)?->idempotency !== IdempotencyPolicy::Receipt) {
            $problems[] = "evidence of no receipt operation: {$name}";
        }
    }
    sort($problems);

    return $problems;
}

it('joins every mutation, route, policy and receipt evidence of the packages without a broken link', function (): void {
    $policies = mcc_policies();
    $names = mcc_names();

    expect(mcc_problems($policies, mcc_routes(), $names, MCC_EVIDENCE))->toBe([])
        // The scan sees the PHP-only operations and the shared writers too, not only the routes.
        ->and($names['drive'])->toContain('drive.access.invalidate', 'drive.trash.empty', 'drive.drives.delete')
        ->and($names['documents'])->toContain('documents.documents.update')
        ->and($names['webhooks'])->toContain('webhooks.endpoints.redeliver')
        ->and(count($policies))->toBeGreaterThan(40);
});

it('keeps a receipt off a generation-fenced protocol and an If-Match policy on routes only', function (): void {
    foreach (mcc_policies() as $name => ['policy' => $policy]) {
        expect($policy->concurrency === ConcurrencyPolicy::Generation && $policy->idempotency === IdempotencyPolicy::Receipt)->toBeFalse($name);
    }
});

it('fails on an unregistered mutation, an unclaimed route, a foreign or unknown policy route and a broken evidence link', function (): void {
    $policies = mcc_policies();
    $routes = mcc_routes();
    $names = mcc_names();

    $names['drive'][] = 'drive.nodes.undeclared';
    $routes['nodes.unclaimed'] = ['owner' => 'drive', 'middleware' => []];
    $policies['drive.nodes.phantom'] = ['owner' => 'drive', 'policy' => new OperationPolicy('drive.node', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt, ['nodes.nowhere'])];
    $policies['drive.nodes.foreign'] = ['owner' => 'webhooks', 'policy' => new OperationPolicy('drive.node', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural, ['nodes.star'])];
    $evidence = MCC_EVIDENCE;
    $evidence['drive.nodes.copy'] = ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'a case that does not exist'];
    $evidence['drive.nodes.star'] = ['packages/drive/tests/PublicApi/NodeIdempotencyTest.php', 'leaves one effect and one event for a repeated star, unstar and retention'];

    expect(mcc_problems($policies, $routes, $names, $evidence))->toBe([
        'broken evidence link: drive.nodes.copy → packages/drive/tests/PublicApi/NodeIdempotencyTest.php :: a case that does not exist',
        'evidence of no receipt operation: drive.nodes.star',
        'foreign route: nodes.star of drive in the policy drive.nodes.foreign of webhooks',
        'receipt operation without evidence: drive.nodes.phantom',
        'route in 0 policies: nodes.unclaimed',
        'route in 2 policies: nodes.star',
        'unknown route: drive.nodes.phantom names nodes.nowhere, not a mutating v1 route',
        'unregistered mutation: drive.nodes.undeclared (drive)',
    ]);
});

it('fails when a receipt route does not read the key or another route does', function (): void {
    $policies = mcc_policies();
    $routes = mcc_routes();
    $routes['nodes.update']['middleware'] = array_values(array_diff($routes['nodes.update']['middleware'], [ReadIdempotencyKey::ALIAS]));
    $routes['nodes.star']['middleware'][] = ReadIdempotencyKey::ALIAS;

    expect(mcc_problems($policies, $routes, mcc_names(), MCC_EVIDENCE))->toBe([
        'key on a natural route: drive.nodes.star → nodes.star',
        'receipt route without the key: drive.nodes.rename → nodes.update',
    ]);
});
