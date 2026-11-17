<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments as Granted;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

/**
 * The security matrix of the documents' mutating routes: every route of the
 * package × the rows R1–R9 of the audit.
 *
 *   R1 401 without authentication              R6 a system actor without a reason is refused
 *   R2 404 a resource of another tenant        R7 move into a hidden folder → 404
 *   R3 404 without View                        R8 move without Create on the destination → 403
 *   R4 403 with View but without the ability   R9 a replay after the access was revoked is refused, not answered
 *   R5 an expired or revoked share link is refused                from its receipt
 *
 * A row that has no meaning for a route is `n/a` with its reason; the coverage test holds the route set to the
 * operation catalog and a skipped row of a receipt route red.
 */
beforeEach(function (): void {
    Granted::configure();
    config()->set('vaulter-documents.comments.enabled', true);
});

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

/**
 * The routes of the matrix, each row either null (checked) or the reason it does not apply.
 *
 * @return array<string, array<string, string|null>>
 */
function smdocMatrix(): array
{
    $noShare = 'not a share route: no link with an expiry or a revocation';
    $noMove = 'not a move: the route has no destination';
    $noReceipt = static fn (string $policy): string => 'a '.$policy.' operation keeps no receipt: a repeat is an ordinary request, authorized anew (R3/R4)';
    $row = static fn (?string $replay = null): array => ['R1' => null, 'R2' => null, 'R3' => null, 'R4' => null, 'R5' => $noShare, 'R6' => null, 'R7' => $noMove, 'R8' => $noMove, 'R9' => $replay];

    return [
        'documents.store' => $row(),
        'documents.collaboration.session' => array_replace($row($noReceipt('natural')), ['R4' => 'View suffices for a read session; editor and reader modes are checked by CollaborationSessionV1Test']),
        'documents.content.update' => $row($noReceipt('conditional')),
        'documents.revisions.store' => $row(),
        'documents.revisions.restore' => $row(),
        'documents.revisions.update' => $row($noReceipt('natural')),
        'comments.store' => $row(),
        'comments.resolve' => $row(),
        'comments.destroy' => $row(),
    ];
}

/** @return array<string, array{0: string, 1: string}> the cells that are checked: `route R1` => [route, row] */
function smdocCells(): array
{
    $cells = [];
    foreach (smdocMatrix() as $route => $rows) {
        foreach ($rows as $row => $reason) {
            if ($reason === null) {
                $cells[$route.' '.$row] = [$route, $row];
            }
        }
    }

    return $cells;
}

dataset('documents matrix', fn (): array => smdocCells());

/**
 * A drive with a document, a revision and a comment: the system makes the first two, user 1 (an owner of the drive) the
 * comment, which has an author.
 *
 * @return array{handle: DriveHandle, doc: string, drive: string, revision: string, comment: string, root: Node, ids: array<string, string>}
 */
function smdocWorld(): array
{
    $handle = DocumentTestHelpers::drive();
    $doc = Documents::in($handle)->create('Matrix', content: DocumentTestHelpers::content('matrix body')->toArray());
    $revision = Documents::in($handle)->revisions($doc->id)->items[0]->id;
    $root = DriveTestHelpers::model($handle->root());
    AccessTestHelpers::grant($root, RoleRegistry::OWNER, subjectId: 1);
    $comment = Documents::comments($handle->actingAs(AccessTestHelpers::user(1)))->add($doc->id, new AddCommentInput('thread'))->id;
    $drive = (string) $handle->model()->getKey();

    return [
        'handle' => $handle, 'doc' => $doc->id, 'drive' => $drive, 'revision' => (string) $revision, 'comment' => (string) $comment,
        'root' => $root,
        'ids' => ['{drive}' => $drive, '{doc}' => $doc->id, '{rev}' => (string) $revision, '{comment}' => (string) $comment],
    ];
}

/** The ids of nothing, in the place of the world's: what an unknown id looks like. */
const SMDOC_GHOST = ['{drive}' => 'ghost-drive', '{doc}' => 'ghost-doc', '{rev}' => 'ghost-rev', '{comment}' => 'ghost-comment'];

/** @return array{0: string, 1: string, 2: array<string, mixed>} method, path template, body */
function smdocRoute(string $route): array
{
    return match ($route) {
        'documents.collaboration.session' => ['POST', 'documents/{doc}/collaboration/session', []],
        'documents.store' => ['POST', 'drives/{drive}/documents', ['name' => 'Matrix new']],
        'documents.content.update' => ['PUT', 'documents/{doc}/content', ['content' => Docs::doc('changed')]],
        'documents.revisions.store' => ['POST', 'documents/{doc}/revisions', ['label' => 'v2']],
        'documents.revisions.restore' => ['POST', 'documents/{doc}/revisions/{rev}/restore', []],
        'documents.revisions.update' => ['PATCH', 'documents/{doc}/revisions/{rev}', ['keep_forever' => true]],
        'comments.store' => ['POST', 'nodes/{doc}/comments', ['body' => 'matrix comment']],
        'comments.resolve' => ['POST', 'nodes/{doc}/comments/{comment}/resolve', []],
        'comments.destroy' => ['DELETE', 'nodes/{doc}/comments/{comment}', []],
    };
}

/**
 * The headers of a request of a route; $tag true carries the If-Match the acting user would read (the caller must be
 * able to see the resource), false a stale one — the refusals under test come before any precondition.
 *
 * @param  array<string, mixed>  $world
 * @param  array<string, string> $extra
 * @return array<string, string>
 */
function smdocHeaders(string $route, array $world, bool $tag = false, array $extra = []): array
{
    $headers = $extra;
    if (in_array($route, ['documents.content.update', 'documents.revisions.restore', 'comments.resolve', 'comments.destroy'], true)) {
        $headers['If-Match'] = match (true) {
            !$tag => '"stale"',
            str_starts_with($route, 'comments.') => '"comment.'.$world['comment'].'.0"',
            default => Docs::etag($world['doc']),
        };
    }

    return $headers;
}

/**
 * @param array<string, string> $ids
 * @param array<string, string> $headers
 */
function smdocSend(string $route, array $ids, array $headers = []): TestResponse
{
    [$method, $template, $body] = smdocRoute($route);

    return test()->json($method, Docs::url(strtr($template, $ids)), $body, $headers);
}

/** @return array<string, int> what a refused write must not change */
function smdocState(array $world): array
{
    return [
        'nodes' => Node::query()->count(),
        'state' => Granted::aggregate(Node::query()->findOrFail($world['doc']))->state_version,
        'revisions' => DocumentTestHelpers::revisions(Node::query()->findOrFail($world['doc']))->count(),
        'comments' => NodeComment::query()->count(),
        'resolved' => NodeComment::query()->whereNotNull('resolved_at')->count(),
    ];
}

/** @return array<string, mixed> */
function smdocProblem(TestResponse $response): array
{
    $body = $response->json();
    unset($body['request_id']);

    return $body;
}

function smdocCheck(string $row, string $route): void
{
    $world = smdocWorld();
    $strict = static function (): MutableTenantResolver {
        config()->set('vaulter.tenancy.enabled', true);
        config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
        $resolver = new MutableTenantResolver;
        $resolver->tenant = 'A';
        app()->instance(TenantResolver::class, $resolver);

        return $resolver;
    };

    match ($row) {
        'R1' => (function () use ($route, $world): void {
            $before = smdocState($world);
            // The suite boots with the host's stack emptied: the route gets the guard a host lists in vaulter.routes.middleware.authenticated.
            app('router')->getRoutes()->getByName('vaulter.v1.'.$route)?->middleware('auth');

            smdocSend($route, $world['ids'], smdocHeaders($route, $world))->assertUnauthorized();

            expect(smdocState($world))->toBe($before);
        })(),
        'R2' => (function () use ($route, $strict): void {
            // Tenant A owns the drive and user 1 owns it there; the request comes from tenant B.
            $resolver = $strict();
            $world = smdocWorld();
            $resolver->tenant = 'B';
            test()->actingAs(AccessTestHelpers::user(1));
            $before = smdocState($world);

            $foreign = smdocSend($route, $world['ids'], smdocHeaders($route, $world))->assertNotFound();
            $unknown = smdocSend($route, SMDOC_GHOST, smdocHeaders($route, $world))->assertNotFound();

            expect(smdocProblem($foreign))->toBe(smdocProblem($unknown))
                ->and(smdocState($world))->toBe($before);
        })(),
        'R3' => (function () use ($route, $world): void {
            // A user with no grant at all: what he may not see is as unknown as what is not there.
            test()->actingAs(AccessTestHelpers::user(9));
            $before = smdocState($world);

            $hidden = smdocSend($route, $world['ids'], smdocHeaders($route, $world))->assertNotFound();
            $unknown = smdocSend($route, SMDOC_GHOST, smdocHeaders($route, $world))->assertNotFound();
            smdocSend($route, $world['ids'], smdocHeaders($route, $world, extra: ['Idempotency-Key' => 'hidden-'.$route]))->assertNotFound();

            expect(smdocProblem($hidden))->toBe(smdocProblem($unknown))
                ->and(smdocState($world))->toBe($before);
        })(),
        'R4' => (function () use ($route, $world): void {
            AccessTestHelpers::grant($world['root'], RoleRegistry::READER, subjectId: 2);
            test()->actingAs(AccessTestHelpers::user(2));
            $before = smdocState($world);

            smdocSend($route, $world['ids'], smdocHeaders($route, $world))->assertForbidden()->assertJsonPath('code', 'permission_denied');
            smdocSend($route, $world['ids'], smdocHeaders($route, $world, tag: true))->assertForbidden();

            expect(smdocState($world))->toBe($before);
        })(),
        'R6' => (function () use ($world): void {
            $before = smdocState($world);

            // The reason is checked where the actor is made, before any operation of the route can start.
            expect(fn () => $world['handle']->asSystem(''))->toThrow(InvalidArgumentException::class)
                ->and(fn () => $world['handle']->asSystem("  \t"))->toThrow(InvalidArgumentException::class)
                ->and(fn (): DriveContext => DriveContext::system($world['handle']->model(), ''))->toThrow(InvalidArgumentException::class)
                ->and(smdocState($world))->toBe($before);
        })(),
        'R9' => (function () use ($route, $world): void {
            $grant = AccessTestHelpers::grant($world['root'], RoleRegistry::OWNER, subjectId: 2);
            test()->actingAs(AccessTestHelpers::user(2));
            $headers = smdocHeaders($route, $world, tag: true, extra: ['Idempotency-Key' => 'matrix-'.str_replace('.', '-', $route)]);

            $first = smdocSend($route, $world['ids'], $headers);
            expect($first->status())->toBeLessThan(300, $route)->and($first->headers->has('Idempotent-Replayed'))->toBeFalse($route);
            smdocSend($route, $world['ids'], $headers)->assertHeader('Idempotent-Replayed', 'true');
            // The next request is a new one: what is scoped to a request (the replay flag) starts afresh, as in a real worker.
            app()->forgetScopedInstances();

            // The access is revoked: the same request, key and headers is refused now and the stored answer is not returned.
            app(VaulterDatabase::class)->write(static fn () => $grant->delete());
            $after = smdocSend($route, $world['ids'], $headers);

            expect($after->status())->toBeIn([403, 404])
                ->and($after->headers->has('Idempotent-Replayed'))->toBeFalse($route)
                ->and($after->json('data'))->toBeNull();
        })(),
    };
}

it('holds the security matrix', function (string $route, string $row): void {
    smdocCheck($row, $route);
})->with('documents matrix');

it('covers every mutating route of the package, with a reason for each row that does not apply', function (): void {
    $catalog = [];
    $receipts = [];
    foreach (app(OperationCatalog::class)->all() as $entry) {
        if ($entry['module'] !== 'documents') {
            continue;
        }
        foreach ($entry['policy']->routes as $route) {
            $catalog[] = $route;
            if ($entry['policy']->idempotency === IdempotencyPolicy::Receipt) {
                $receipts[] = $route;
            }
        }
    }
    $registered = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (str_starts_with($name, 'vaulter.v1.') && str_starts_with($route->getActionName(), 'Vaulter\\Documents\\') && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $registered[] = substr($name, strlen('vaulter.v1.'));
        }
    }
    $matrix = smdocMatrix();
    sort($catalog);
    sort($registered);
    $routes = array_keys($matrix);
    sort($routes);

    expect(array_values(array_diff($catalog, $routes)))->toBe([], 'routes of the catalog missing from the matrix')
        ->and(array_values(array_diff($routes, $catalog)))->toBe([], 'routes of the matrix missing from the catalog')
        ->and($registered)->toBe($catalog);
    foreach ($matrix as $route => $rows) {
        expect(array_keys($rows))->toBe(['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9']);
        foreach ($rows as $row => $reason) {
            expect($reason === null || strlen($reason) >= 12)->toBeTrue($route.' '.$row.' is n/a without a reason');
        }
        // R1–R4 and R6 apply to every route; R9 to every route that stores a receipt; R5 only to share routes, R7/R8 only to moves.
        foreach (['R1', 'R2', 'R3', 'R4', 'R6'] as $row) {
            if ($route !== 'documents.collaboration.session' || $row !== 'R4') {
                expect($rows[$row])->toBeNull($route.' '.$row.' applies to every route');
            }
        }
        expect($rows['R9'] === null)->toBe(in_array($route, $receipts, true), $route.' R9 is for the receipt routes');
    }
});
