<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Testing\Support\OpenApi\OpenApiContract;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

/**
 * The read security matrix of the drive's GET/HEAD routes: every read route of the
 * package × the rows R1–R3 and R10–R12 of the audit.
 *
 *   R1  401 without authentication            R10 lists and children hide what the caller may not see
 *   R2  404 a resource of another tenant      R11 a download is refused once the grant is revoked
 *   R3  404 without View                      R12 collaboration session (documents package, POST — not a drive route)
 *
 * R2 and R3 compare the whole problem of the answer with the one for an id that never existed. A row that has no meaning
 * for a route is `n/a` with its reason and the test that holds the requirement instead; the coverage test holds the
 * route set to `Route::getRoutes()` (GET/HEAD) and to the OpenAPI operations, not to this file's own list.
 */
uses(DatabaseMigrations::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-drive.features.grants', true);
    config()->set('vaulter-drive.features.share_links', true);
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

/**
 * The read routes of the matrix: the path with `{placeholders}`, and per row either null (checked) or the reason it does
 * not apply, with the owner of the requirement.
 *
 * @return array<string, array{path: string, rows: array<string, string|null>}>
 */
function rsmdrvMatrix(): array
{
    $caller = 'the answer is scoped to the caller, not to a resource: there is no id to hide (owner: ListSharedWithMeV1Test, ExactListingTest)';
    $noList = 'not a list or search: it answers one resource, R3 holds it';
    $noDownload = 'not a download: no bytes leave the route';
    $feed = 'the change feed may expose scanned progress, has_more and anonymous keyed removes: its cursor is not compared with a world without the object (owner: ChangesFeedV1Test)';
    $public = 'the public share is a bearer capability, not a caller: expiry, revocation and a wrong token are held by SecurityMatrixTest R5 and PublicSharesV1Test';
    $row = static fn (array $over = []): array => [...['R1' => null, 'R2' => null, 'R3' => null, 'R10' => $noList, 'R11' => $noDownload], ...$over];
    $listRow = static fn (array $over = []): array => $row([...['R10' => null], ...$over]);

    return [
        'drives.show' => ['path' => 'drives/{drive}', 'rows' => $row()],
        'nodes.index' => ['path' => 'drives/{drive}/nodes', 'rows' => $listRow()],
        'nodes.search' => ['path' => 'drives/{drive}/search?q=target', 'rows' => $listRow()],
        'nodes.starred' => ['path' => 'drives/{drive}/starred', 'rows' => $listRow()],
        'trash.index' => ['path' => 'drives/{drive}/trash', 'rows' => $listRow()],
        'shared.index' => ['path' => 'shared', 'rows' => $row(['R2' => $caller, 'R3' => $caller, 'R10' => 'listed through SharedWithActorQuery: only the caller\'s own grants and shares appear (owner: ListSharedWithMeV1Test)'])],
        'nodes.show' => ['path' => 'nodes/{file}', 'rows' => $row()],
        'nodes.abilities' => ['path' => 'nodes/{file}/abilities', 'rows' => $row()],
        'files.download' => ['path' => 'files/{file}/download', 'rows' => $row(['R11' => null])],
        'files.revisions.index' => ['path' => 'files/{file}/revisions', 'rows' => $listRow()],
        'files.revisions.download' => ['path' => 'files/{file}/revisions/{rev}/download', 'rows' => $row(['R11' => null])],
        'uploads.show' => ['path' => 'uploads/{upload}', 'rows' => $row()],
        'uploads.tus.head' => ['path' => 'uploads/{upload}', 'rows' => $row()],
        'grants.index' => ['path' => 'nodes/{file}/grants', 'rows' => $listRow()],
        'shares.index' => ['path' => 'nodes/{file}/shares', 'rows' => $listRow()],
        'activity.drive' => ['path' => 'drives/{drive}/activity', 'rows' => $listRow()],
        'activity.node' => ['path' => 'nodes/{file}/activity', 'rows' => $listRow()],
        'changes.start' => ['path' => 'drives/{drive}/changes/start', 'rows' => $row(['R10' => $feed])],
        'changes.index' => ['path' => 'drives/{drive}/changes?cursor=none', 'rows' => $row(['R10' => $feed])],
        'public-shares.show' => ['path' => 'shares/{token}', 'rows' => $row(['R1' => $public, 'R2' => $public, 'R3' => $public])],
        'public-shares.nodes' => ['path' => 'shares/{token}/nodes', 'rows' => $row(['R1' => $public, 'R2' => $public, 'R3' => $public, 'R10' => 'public children: the sealed scoped cursor and the hidden-by-inheritance empty page are held by ReadPaginationSecurityTest (D21)'])],
        'public-shares.download' => ['path' => 'shares/{token}/download', 'rows' => $row(['R1' => $public, 'R2' => $public, 'R3' => $public, 'R11' => 'a revoked or expired link is refused as share_not_found: SecurityMatrixTest R5 and PublicSharesV1Test'])],
    ];
}

/** @return array<string, array{0: string, 1: string}> the cells that are checked: `route R1` => [route, row] */
function rsmdrvCells(): array
{
    $cells = [];
    foreach (rsmdrvMatrix() as $route => $entry) {
        foreach ($entry['rows'] as $row => $reason) {
            if ($reason === null) {
                $cells[$route.' '.$row] = [$route, $row];
            }
        }
    }

    return $cells;
}

dataset('drive read matrix', fn (): array => rsmdrvCells());

/**
 * The fixtures a read names, made by the system in a drive whose owner is user 1.
 *
 * @return array<string, string>
 */
function rsmdrvWorld(): array
{
    $handle = V1::drive(userId: 1);
    $file = V1::file($handle, 'target.txt', 'first');
    $handle->files()->addRevision($file->id, UploadSource::fromStream(DriveTestHelpers::streamFor('second'), 'target.txt'));

    return [
        '{drive}' => (string) $handle->model()->getKey(),
        '{file}' => $file->id,
        '{rev}' => (string) DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->orderBy('number')->firstOrFail()->getKey(),
        '{upload}' => $handle->actingAs(V1::user(1))->uploads()->initiate(new InitiateUploadInput(protocol: UploadProtocol::Tus, targetName: 'matrix.bin', totalSizeBytes: 3))->uploadId,
        '{token}' => $handle->shares()->create($file->id, new ShareLinkInput)->token,
    ];
}

/** @param array<string, string> $ids */
function rsmdrvSend(string $route, array $ids): TestResponse
{
    $url = V1::url(strtr(rsmdrvMatrix()[$route]['path'], $ids));

    return $route === 'uploads.tus.head'
        ? test()->call('HEAD', $url, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_TUS_RESUMABLE' => '1.0.0'])
        : test()->getJson($url);
}

/** The ids of nothing, in the place of the world's: what an unknown id looks like. */
function rsmdrvGhost(array $ids): array
{
    return array_map(static fn (string $placeholder): string => 'ghost-'.trim($placeholder, '{}'), array_combine(array_keys($ids), array_keys($ids)));
}

/** @return array<string, mixed> */
function rsmdrvProblem(TestResponse $response): array
{
    $body = $response->json() ?? [];
    unset($body['request_id']);

    return $body;
}

/** The refusal of a caller who may not see the resource, against the one for an id that never existed. */
function rsmdrvAssertConcealed(string $route, array $ids, TestResponse $hidden): void
{
    $ghost = rsmdrvSend($route, rsmdrvGhost($ids));

    expect($hidden->status())->toBe(404, $route.' answered '.$hidden->status().' for what the caller cannot see: '.$hidden->getContent())
        ->and($ghost->status())->toBe(404, $route.' answered '.$ghost->status().' for an id that never existed');

    // A HEAD answer has no body to decode: json() on it would rethrow the exception the kernel already rendered.
    if ($route === 'uploads.tus.head') {
        expect((string) $hidden->getContent())->toBe('', $route.' sent a body for what the caller cannot see')
            ->and((string) $ghost->getContent())->toBe('', $route.' sent a body for an id that never existed')
            ->and(rsmdrvSafeHeaders($hidden))->toBe(rsmdrvSafeHeaders($ghost), $route.' tells a hidden resource from a missing one by its headers');

        return;
    }

    expect(rsmdrvProblem($hidden))->toBe(rsmdrvProblem($ghost), $route.' tells a hidden resource from a missing one');
}

/**
 * The headers of an answer that say the same for the same refusal: all but the ones that differ per request.
 *
 * @return array<string, string>
 */
function rsmdrvSafeHeaders(TestResponse $response): array
{
    $headers = [];
    foreach ($response->headers->all() as $name => $values) {
        $name = strtolower((string) $name);
        if (in_array($name, ['date', 'set-cookie', 'x-request-id', 'request-id'], true) || str_starts_with($name, 'x-ratelimit-')) {
            continue;
        }
        $headers[$name] = implode(', ', $values);
    }
    ksort($headers);

    return $headers;
}

it('holds the read security matrix', function (string $route, string $row): void {
    match ($row) {
        'R1' => (function () use ($route): void {
            $ids = rsmdrvWorld();
            // The suite boots with the host's stack emptied: the route gets the guard a host lists in vaulter.routes.middleware.authenticated.
            app('router')->getRoutes()->getByName('vaulter.v1.'.$route)?->middleware('auth');

            rsmdrvSend($route, $ids)->assertUnauthorized();
        })(),
        'R2' => (function () use ($route): void {
            config()->set('vaulter.tenancy.enabled', true);
            config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
            $resolver = new MutableTenantResolver;
            $resolver->tenant = 'A';
            app()->instance(TenantResolver::class, $resolver);
            $ids = rsmdrvWorld();
            // Tenant A owns the drive and user 1 owns it there; the request comes from tenant B.
            $resolver->tenant = 'B';
            test()->actingAs(V1::user(1));

            rsmdrvAssertConcealed($route, $ids, rsmdrvSend($route, $ids));
        })(),
        'R3' => (function () use ($route): void {
            $ids = rsmdrvWorld();
            // A user with no grant at all: what he may not see is as unknown as what is not there.
            test()->actingAs(V1::user(9));

            rsmdrvAssertConcealed($route, $ids, rsmdrvSend($route, $ids));
        })(),
        'R10' => (function () use ($route): void {
            $ids = rsmdrvWorld();
            // A reader of the drive sees the target; a stranger gets the concealed 404 and no item, no total, no cursor of it.
            test()->actingAs(V1::user(9));
            $stranger = rsmdrvSend($route, $ids);

            expect($stranger->status())->toBe(404)
                ->and(json_encode($stranger->json()))->not->toContain('target.txt')
                ->and($stranger->json('meta.next_cursor'))->toBeNull()
                ->and($stranger->json('meta.total'))->toBeNull()
                ->and($stranger->json('data') ?? [])->toBe([]);
        })(),
        'R11' => (function () use ($route): void {
            $ids = rsmdrvWorld();
            test()->actingAs(V1::user(1));
            $handle = V1::drive(userId: 1);
            $file = V1::file($handle, 'revoked.txt', 'bytes');
            $reader = AccessTestHelpers::grant(DriveTestHelpers::model($file), RoleRegistry::READER, subjectId: 2);
            $ids = [...$ids, '{drive}' => (string) $handle->model()->getKey(), '{file}' => $file->id, '{rev}' => (string) DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->firstOrFail()->getKey()];

            test()->actingAs(V1::user(2));
            rsmdrvSend($route, $ids)->assertOk();

            $grants = config('vaulter-drive.models.grant');
            app(VaulterDatabase::class)->write(static fn () => $grants::query()->whereKey($reader->getKey())->get()->each->delete());

            // Revoked: the download route authorizes anew and answers as for an object that is not there.
            rsmdrvAssertConcealed($route, $ids, rsmdrvSend($route, $ids));
        })(),
    };
})->with('drive read matrix');

it('covers every GET/HEAD route of the package, with a reason for each row that does not apply', function (): void {
    $registered = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (str_starts_with($name, 'vaulter.v1.') && str_starts_with($route->getActionName(), 'Vaulter\\Drive\\') && array_intersect($route->methods(), ['GET', 'HEAD']) !== []) {
            $registered[] = substr($name, strlen('vaulter.v1.'));
        }
    }

    // Every GET/HEAD operation of the OpenAPI document owned by the drive is a route, and the other way round.
    $spec = Yaml::parseFile(OpenApiContract::specPath());
    $operations = [];
    foreach ($spec['paths'] as $item) {
        foreach (['get', 'head'] as $method) {
            if (isset($item[$method]['operationId'])) {
                $operations[] = (string) $item[$method]['operationId'];
            }
        }
    }

    $matrix = rsmdrvMatrix();
    $routes = array_keys($matrix);
    sort($registered);
    sort($routes);

    expect(array_values(array_diff($registered, $routes)))->toBe([], 'GET/HEAD routes missing from the read matrix')
        ->and(array_values(array_diff($routes, $registered)))->toBe([], 'routes of the read matrix that are not registered')
        ->and(array_values(array_diff($routes, $operations)))->toBe([], 'routes of the read matrix without an OpenAPI operation');
    foreach ($matrix as $route => $entry) {
        expect(array_keys($entry['rows']))->toBe(['R1', 'R2', 'R3', 'R10', 'R11']);
        foreach ($entry['rows'] as $row => $reason) {
            expect($reason === null || strlen($reason) >= 12)->toBeTrue($route.' '.$row.' is n/a without a reason');
        }
    }
});
