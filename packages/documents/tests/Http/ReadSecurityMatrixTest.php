<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments as Granted;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Support\OpenApi\OpenApiContract;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

/**
 * The read security matrix of the documents' GET/HEAD routes (P5.2, knowledge p5-security#read-matrix): every read route ×
 * the rows R1–R3 and R10–R11 of the audit; R12 is the collaboration session, a POST that is registered explicitly beyond
 * the GET/HEAD catalog. A row without a meaning is `n/a` with its reason; the coverage test holds the route set to
 * `Route::getRoutes()` (GET/HEAD) and to the OpenAPI operations.
 */
beforeEach(function (): void {
    Granted::configure();
    config()->set('vaulter-documents.comments.enabled', true);
});

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

/**
 * @return array<string, array{method: string, path: string, rows: array<string, string|null>}>
 */
function rsmdocMatrix(): array
{
    $noList = 'not a list: one resource, R3 holds its concealment';
    $noDownload = 'not a download: no bytes leave the route';
    $row = static fn (array $over = []): array => [...['R1' => null, 'R2' => null, 'R3' => null, 'R10' => $noList, 'R11' => $noDownload, 'R12' => 'not a collaboration session (owner: the session POST below)'], ...$over];

    return [
        'documents.content.show' => ['method' => 'GET', 'path' => 'documents/{doc}/content', 'rows' => $row()],
        'documents.revisions.index' => ['method' => 'GET', 'path' => 'documents/{doc}/revisions', 'rows' => $row(['R10' => null])],
        'documents.revisions.show' => ['method' => 'GET', 'path' => 'documents/{doc}/revisions/{rev}', 'rows' => $row()],
        'documents.export' => ['method' => 'GET', 'path' => 'documents/{doc}/export/markdown', 'rows' => $row(['R11' => null])],
        'comments.index' => ['method' => 'GET', 'path' => 'nodes/{doc}/comments', 'rows' => $row(['R10' => null])],
        // R12: a POST, beyond the GET/HEAD catalog; the read-only actor gets mode=read (CollaborationSessionV1Test).
        'documents.collaboration.session' => ['method' => 'POST', 'path' => 'documents/{doc}/collaboration/session', 'rows' => ['R1' => null, 'R2' => null, 'R3' => null, 'R10' => 'a session is not a list', 'R11' => 'a session is not a download', 'R12' => null]],
    ];
}

/** @return array<string, array{0: string, 1: string}> */
function rsmdocCells(): array
{
    $cells = [];
    foreach (rsmdocMatrix() as $route => $entry) {
        foreach ($entry['rows'] as $row => $reason) {
            if ($reason === null && $row !== 'R12') {
                $cells[$route.' '.$row] = [$route, $row];
            }
        }
    }

    return $cells;
}

dataset('documents read matrix', fn (): array => rsmdocCells());

/** @return array<string, string> */
function rsmdocWorld(): array
{
    $handle = DocumentTestHelpers::drive();
    $doc = Documents::in($handle)->create('Matrix', content: DocumentTestHelpers::content('matrix body')->toArray());
    $revision = Documents::in($handle)->revisions($doc->id)->items[0]->id;
    AccessTestHelpers::grant(DriveTestHelpers::model($handle->root()), RoleRegistry::OWNER, subjectId: 1);
    Documents::comments($handle->actingAs(AccessTestHelpers::user(1)))->add($doc->id, new AddCommentInput('thread'));

    return ['{doc}' => $doc->id, '{rev}' => (string) $revision, '{drive}' => (string) $handle->model()->getKey()];
}

function rsmdocSend(string $route, array $ids): TestResponse
{
    $entry = rsmdocMatrix()[$route];

    return test()->json($entry['method'], Docs::url(strtr($entry['path'], $ids)));
}

function rsmdocGhost(): array
{
    return ['{doc}' => 'ghost-doc', '{rev}' => 'ghost-rev', '{drive}' => 'ghost-drive'];
}

/** @return array<string, mixed> */
function rsmdocProblem(TestResponse $response): array
{
    $body = $response->json() ?? [];
    unset($body['request_id']);

    return $body;
}

function rsmdocConcealed(string $route, array $ids): void
{
    $hidden = rsmdocSend($route, $ids);
    $ghost = rsmdocSend($route, rsmdocGhost());

    expect($hidden->status())->toBe(404, $route.' answered '.$hidden->status().': '.$hidden->getContent())
        ->and($ghost->status())->toBe(404)
        ->and(rsmdocProblem($hidden))->toBe(rsmdocProblem($ghost), $route.' tells a hidden resource from a missing one');
}

function rsmdocCheck(string $row, string $route): void
{
    match ($row) {
        'R1' => (function () use ($route): void {
            $ids = rsmdocWorld();
            app('router')->getRoutes()->getByName('vaulter.v1.'.$route)?->middleware('auth');

            rsmdocSend($route, $ids)->assertUnauthorized();
        })(),
        'R2' => (function () use ($route): void {
            config()->set('vaulter.tenancy.enabled', true);
            config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
            $resolver = new MutableTenantResolver;
            $resolver->tenant = 'A';
            app()->instance(TenantResolver::class, $resolver);
            $ids = rsmdocWorld();
            $resolver->tenant = 'B';
            test()->actingAs(AccessTestHelpers::user(1));

            rsmdocConcealed($route, $ids);
        })(),
        'R3' => (function () use ($route): void {
            $ids = rsmdocWorld();
            test()->actingAs(AccessTestHelpers::user(9));

            rsmdocConcealed($route, $ids);
        })(),
        'R10' => (function () use ($route): void {
            $ids = rsmdocWorld();
            test()->actingAs(AccessTestHelpers::user(1));
            $owner = rsmdocSend($route, $ids)->assertOk();
            test()->actingAs(AccessTestHelpers::user(9));
            $stranger = rsmdocSend($route, $ids);

            // The owner reads the revision or the comment; a stranger gets the concealed 404 with no item, no total, no cursor.
            expect($owner->json('data'))->not->toBeEmpty()
                ->and($stranger->status())->toBe(404)
                ->and($stranger->json('data') ?? [])->toBe([])
                ->and($stranger->json('meta.next_cursor'))->toBeNull()
                ->and($stranger->json('meta.total'))->toBeNull()
                ->and(json_encode($stranger->json()))->not->toContain('thread');
        })(),
        'R11' => (function () use ($route): void {
            $ids = rsmdocWorld();
            // An export is a read of the content: a stranger and another tenant are the concealed 404 of R2/R3, which hold it.
            test()->actingAs(AccessTestHelpers::user(1));
            rsmdocSend($route, $ids)->assertOk();
            test()->actingAs(AccessTestHelpers::user(9));
            rsmdocConcealed($route, $ids);
        })(),
    };
}

it('holds the documents read security matrix', function (string $route, string $row): void {
    rsmdocCheck($row, $route);
})->with('documents read matrix');

it('covers every GET/HEAD route of the package, with a reason for each row that does not apply', function (): void {
    $registered = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (str_starts_with($name, 'vaulter.v1.') && str_starts_with($route->getActionName(), 'Vaulter\\Documents\\') && array_intersect($route->methods(), ['GET', 'HEAD']) !== []) {
            $registered[] = substr($name, strlen('vaulter.v1.'));
        }
    }
    $spec = Yaml::parseFile(OpenApiContract::specPath());
    $operations = [];
    foreach ($spec['paths'] as $item) {
        foreach (['get', 'head'] as $method) {
            if (isset($item[$method]['operationId'])) {
                $operations[] = (string) $item[$method]['operationId'];
            }
        }
    }
    $matrix = array_filter(rsmdocMatrix(), static fn (array $entry): bool => $entry['method'] === 'GET');
    $routes = array_keys($matrix);
    sort($registered);
    sort($routes);

    expect($registered)->toBe($routes)
        ->and(array_values(array_diff($routes, $operations)))->toBe([], 'routes of the read matrix without an OpenAPI operation');
    foreach (rsmdocMatrix() as $route => $entry) {
        expect(array_keys($entry['rows']))->toBe(['R1', 'R2', 'R3', 'R10', 'R11', 'R12']);
        foreach ($entry['rows'] as $row => $reason) {
            expect($reason === null || strlen($reason) >= 12)->toBeTrue($route.' '.$row.' is n/a without a reason');
        }
    }
    expect(rsmdocMatrix()['documents.collaboration.session']['method'])->toBe('POST');
});
