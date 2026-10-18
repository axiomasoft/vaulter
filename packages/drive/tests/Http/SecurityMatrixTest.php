<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

/**
 * The security matrix of the drive's mutating routes (P6.11, knowledge p6-design#security-matrix): every route of the
 * package × the rows R1–R9 of the audit.
 *
 *   R1 401 without authentication              R6 a system actor without a reason is refused
 *   R2 404 a resource of another tenant        R7 move into a hidden folder → 404
 *   R3 404 without View                        R8 move without Create on the destination → 403
 *   R4 403 with View but without the ability   R9 a replay after the access was revoked is refused, not answered
 *   R5 an expired or revoked share link is refused                from its receipt
 *
 * A 403 for what the caller may not even see would tell that it exists: R2 and R3 compare the answer with the one for
 * an id that never existed. A row that has no meaning for a route is `n/a` with its reason; the coverage test holds the
 * route set to the operation catalog and a skipped row of a receipt route red.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-drive.features.grants', true);
    config()->set('vaulter-drive.features.share_links', true);
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

/**
 * The routes of the matrix, each row either null (checked) or the reason it does not apply.
 *
 * @return array<string, array<string, string|null>>
 */
function smdrvMatrix(): array
{
    $noShare = 'not a share route: no link with an expiry or a revocation';
    $noMove = 'not a move or a copy: the route has no destination';
    $noReceipt = static fn (string $policy): string => 'a '.$policy.' operation keeps no receipt: a repeat is an ordinary request, authorized anew (R3/R4)';
    $row = static fn (?string $replay = null, ?string $forbidden = null): array => ['R1' => null, 'R2' => null, 'R3' => null, 'R4' => $forbidden, 'R5' => $noShare, 'R6' => null, 'R7' => $noMove, 'R8' => $noMove, 'R9' => $replay];
    $move = static fn (): array => ['R5' => $noShare, 'R7' => null, 'R8' => null];
    $share = static fn (): array => ['R5' => null];
    $ownState = 'the star is the caller\'s own state: View is the ability it needs, so a reader may star';

    return [
        'folders.store' => $row(),
        'nodes.update' => $row(),
        'nodes.move' => [...$row(), ...$move()],
        'nodes.copy' => [...$row(), ...$move()],
        'nodes.properties.update' => $row(),
        'nodes.star' => $row($noReceipt('natural'), $ownState),
        'nodes.unstar' => $row($noReceipt('natural'), $ownState),
        'nodes.trash' => $row(),
        'nodes.restore' => $row(),
        'nodes.purge' => $row(),
        'trash.empty' => $row(),
        'files.store' => $row($noReceipt('conditional')),
        'files.revisions.store' => $row($noReceipt('conditional')),
        'files.revisions.restore' => $row(),
        'files.revisions.destroy' => $row(),
        'files.revisions.update' => $row($noReceipt('natural')),
        'uploads.store' => $row(),
        'uploads.complete' => $row($noReceipt('natural')),
        'uploads.tus.destroy' => $row($noReceipt('natural')),
        'uploads.tus.patch' => $row($noReceipt('protocol')),
        'uploads.parts.store' => $row($noReceipt('protocol')),
        'grants.store' => $row(),
        'grants.destroy' => $row(),
        'access.inheritance.restrict' => $row(),
        'access.inheritance.restore' => $row(),
        'shares.store' => [...$row(), ...$share()],
        'shares.update' => [...$row(), ...$share()],
        'shares.rotate' => [...$row(), ...$share()],
        'shares.destroy' => [...$row(), ...$share()],
        'drives.archive' => $row(),
        'drives.unarchive' => $row(),
        'drives.destroy' => $row(),
    ];
}

/** @return array<string, array{0: string, 1: string}> the cells that are checked: `route R1` => [route, row] */
function smdrvCells(): array
{
    $cells = [];
    foreach (smdrvMatrix() as $route => $rows) {
        foreach ($rows as $row => $reason) {
            if ($reason === null) {
                $cells[$route.' '.$row] = [$route, $row];
            }
        }
    }

    return $cells;
}

dataset('drive matrix', fn (): array => smdrvCells());

/**
 * The request of a route: method, path with the ids of its fixtures as `{placeholders}`, body, and how it is sent
 * (`json`, `multipart`, or `raw` bytes under the tus / part headers).
 *
 * @return array{0: string, 1: string, 2: array<string, mixed>, 3: string}
 */
function smdrvRoute(string $route): array
{
    $subject = ['type' => (new TestUser)->getMorphClass(), 'id' => '3'];

    return match ($route) {
        'folders.store' => ['POST', 'drives/{drive}/folders', ['name' => 'Matrix folder'], 'json'],
        'nodes.update' => ['PATCH', 'nodes/{file}', ['name' => 'renamed.txt'], 'json'],
        'nodes.move' => ['POST', 'nodes/{file}/move', ['destination_id' => '{dest}'], 'json'],
        'nodes.copy' => ['POST', 'nodes/{file}/copy', ['destination_id' => '{dest}', 'name' => 'copy.txt'], 'json'],
        'nodes.properties.update' => ['PATCH', 'nodes/{file}/properties', ['namespace' => 'acme', 'set' => ['k' => 'v']], 'json'],
        'nodes.star' => ['PUT', 'nodes/{file}/star', [], 'json'],
        'nodes.unstar' => ['DELETE', 'nodes/{file}/star', [], 'json'],
        'nodes.trash' => ['POST', 'nodes/{file}/trash', [], 'json'],
        'nodes.restore' => ['POST', 'nodes/{trashed}/restore', [], 'json'],
        'nodes.purge' => ['DELETE', 'nodes/{trashed}', [], 'json'],
        'trash.empty' => ['DELETE', 'drives/{drive}/trash', [], 'json'],
        'files.store' => ['POST', 'drives/{drive}/files', ['file' => 'FILE'], 'multipart'],
        'files.revisions.store' => ['POST', 'files/{file}/revisions', ['file' => 'FILE'], 'multipart'],
        'files.revisions.restore' => ['POST', 'files/{file}/revisions/{rev}/restore', [], 'json'],
        'files.revisions.destroy' => ['DELETE', 'files/{file}/revisions/{rev}', [], 'json'],
        'files.revisions.update' => ['PATCH', 'files/{file}/revisions/{rev}', ['keep_forever' => true], 'json'],
        'uploads.store' => ['POST', 'drives/{drive}/uploads', ['protocol' => 'tus', 'name' => 'matrix.bin', 'total_size_bytes' => 3], 'json'],
        'uploads.complete' => ['POST', 'uploads/{upload}/complete', [], 'json'],
        'uploads.tus.destroy' => ['DELETE', 'uploads/{upload}', [], 'raw'],
        'uploads.tus.patch' => ['PATCH', 'uploads/{upload}', [], 'raw'],
        'uploads.parts.store' => ['POST', 'uploads/{upload}/parts?part_no=1&byte_offset=0', [], 'raw'],
        'grants.store' => ['POST', 'nodes/{file}/grants', ['subject' => $subject, 'role' => 'reader'], 'json'],
        'grants.destroy' => ['DELETE', 'nodes/{file}/grants/{grant}', [], 'json'],
        'access.inheritance.restrict' => ['POST', 'nodes/{folder}/inheritance/restrict', [], 'json'],
        'access.inheritance.restore' => ['POST', 'nodes/{folder}/inheritance/restore', [], 'json'],
        'shares.store' => ['POST', 'nodes/{file}/shares', ['role' => 'reader'], 'json'],
        'shares.update' => ['PATCH', 'nodes/{file}/shares/{share}', ['max_uses' => 4], 'json'],
        'shares.rotate' => ['POST', 'nodes/{file}/shares/{share}/rotate', [], 'json'],
        'shares.destroy' => ['DELETE', 'nodes/{file}/shares/{share}', [], 'json'],
        'drives.archive' => ['POST', 'drives/{drive}/archive', [], 'json'],
        'drives.unarchive' => ['POST', 'drives/{drive}/unarchive', [], 'json'],
        'drives.destroy' => ['DELETE', 'drives/{drive}', [], 'json'],
    };
}

/** What a route's If-Match names: a node's tag, the access tag of a node, a share's, the drive's, or nothing. */
function smdrvTagKind(string $route): ?string
{
    return match ($route) {
        'nodes.update', 'nodes.move', 'nodes.properties.update', 'nodes.trash', 'nodes.restore', 'nodes.purge', 'files.revisions.store', 'files.revisions.restore' => 'node',
        'grants.destroy', 'access.inheritance.restrict', 'access.inheritance.restore' => 'access',
        'shares.update', 'shares.rotate', 'shares.destroy' => 'share',
        'drives.archive', 'drives.unarchive', 'drives.destroy' => 'drive',
        default => null,
    };
}

/**
 * The fixtures a route's request names, made by the system in a drive whose owner is user 1: only the ones its path and
 * body use. `ids` maps the `{placeholders}` to them.
 *
 * @return array{handle: DriveHandle, root: Node, ids: array<string, string>}
 */
function smdrvWorld(string $route): array
{
    [, $template, $body] = smdrvRoute($route);
    $text = $template.json_encode($body);
    $uses = static fn (string $name): bool => str_contains($text, '{'.$name.'}');
    $handle = V1::drive(userId: 1);
    $ids = ['{drive}' => (string) $handle->model()->getKey()];

    if ($uses('file')) {
        $file = V1::file($handle, 'target.txt', 'first');
        $ids['{file}'] = $file->id;
        if ($uses('rev')) {
            $handle->files()->addRevision($file->id, UploadSource::fromStream(DriveTestHelpers::streamFor('second'), 'target.txt'));
            $ids['{rev}'] = (string) DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->orderBy('number')->firstOrFail()->getKey();
        }
        if ($uses('grant')) {
            $ids['{grant}'] = (string) AccessTestHelpers::grant(DriveTestHelpers::model($file), RoleRegistry::READER, subjectId: 8)->getKey();
        }
        if ($uses('share')) {
            $ids['{share}'] = $handle->shares()->create($file->id, new ShareLinkInput)->link->id;
        }
    }
    if ($uses('dest')) {
        $ids['{dest}'] = V1::folder($handle, 'destination')->id;
    }
    if ($uses('folder')) {
        $ids['{folder}'] = V1::folder($handle, 'restricted')->id;
    }
    if ($uses('trashed') || $route === 'trash.empty') {
        $trashed = V1::folder($handle, 'binned');
        $handle->nodes()->trash($trashed->id);
        $ids['{trashed}'] = $trashed->id;
    }
    if ($uses('upload')) {
        $ids['{upload}'] = $handle->actingAs(V1::user(1))->uploads()->initiate(new InitiateUploadInput(protocol: UploadProtocol::Tus, targetName: 'matrix.bin', totalSizeBytes: 3))->upload_id;
    }
    if ($route === 'drives.unarchive') {
        $handle->archive();
    }

    return ['handle' => $handle, 'root' => DriveTestHelpers::model($handle->root()), 'ids' => $ids];
}

/** The ids of nothing, in the place of the world's: what an unknown id looks like. */
function smdrvGhost(array $world): array
{
    return array_map(static fn (string $placeholder): string => 'ghost-'.trim($placeholder, '{}'), array_combine(array_keys($world['ids']), array_keys($world['ids'])));
}

/**
 * The headers of the request: the If-Match the acting user would read for the resource ($tag), or a stale one.
 *
 * @param  array{handle: DriveHandle, root: Node, ids: array<string, string>} $world
 * @param  array<string, string>                                              $extra
 * @return array<string, string>
 */
function smdrvHeaders(string $route, array $world, bool $tag = false, array $extra = []): array
{
    $headers = $extra;
    $kind = smdrvTagKind($route);
    if ($kind !== null) {
        $ids = $world['ids'];
        $headers['If-Match'] = $tag ? match ($kind) {
            'node' => V1::ifMatch($ids['{file}'] ?? $ids['{trashed}'])['If-Match'],
            'access' => (string) test()->getJson(V1::url('nodes/'.($ids['{file}'] ?? $ids['{folder}']).'/grants'))->assertOk()->json('etag'),
            'share' => (string) collect(test()->getJson(V1::url('nodes/'.$ids['{file}'].'/shares'))->assertOk()->json('data'))->firstWhere('id', $ids['{share}'])['etag'],
            'drive' => (string) test()->getJson(V1::url('drives/'.$ids['{drive}']))->assertOk()->json('data.etag'),
        } : '"stale"';
    }

    return $headers;
}

/**
 * @param array<string, string> $ids
 * @param array<string, string> $headers
 */
function smdrvSend(string $route, array $ids, array $headers = []): TestResponse
{
    [$method, $template, $body, $mode] = smdrvRoute($route);
    $url = V1::url(strtr($template, $ids));
    if ($mode === 'json') {
        return test()->json($method, $url, json_decode(strtr(json_encode($body, JSON_THROW_ON_ERROR), $ids), true, flags: JSON_THROW_ON_ERROR), $headers);
    }
    if ($mode === 'multipart') {
        return test()->post($url, ['file' => UploadedFile::fake()->createWithContent('matrix.txt', 'matrix bytes')], ['Accept' => 'application/json', ...$headers]);
    }

    // The tus and part routes speak their own headers and carry raw bytes.
    $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_TUS_RESUMABLE' => '1.0.0'];
    if ($route === 'uploads.tus.patch') {
        $server += ['HTTP_UPLOAD_OFFSET' => '0', 'CONTENT_TYPE' => 'application/offset+octet-stream'];
    }
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call($method, $url, [], [], [], $server, $route === 'uploads.tus.destroy' ? '' : 'abc');
}

/** @return array<string, int> what a refused write must not change */
function smdrvState(array $world): array
{
    $grants = config('vaulter-drive.models.grant');
    $shares = config('vaulter-drive.models.node_share');
    $drives = config('vaulter-drive.models.drive');

    return [
        'nodes' => Node::query()->count(),
        'trashed' => Node::query()->whereNotNull('trashed_at')->count(),
        'sequence' => (int) Node::query()->sum('sequence'),
        'access' => (int) Node::query()->sum('access_version'),
        'grants' => $grants::query()->count(),
        'shares' => $shares::query()->count(),
        'share_versions' => (int) $shares::query()->sum('version'),
        'revoked_shares' => $shares::query()->whereNotNull('revoked_at')->count(),
        'drive_version' => (int) $drives::query()->whereKey($world['ids']['{drive}'])->value('version'),
        'uploads' => UploadSession::query()->count(),
        'received' => (int) UploadSession::query()->sum('received_bytes'),
    ];
}

/** @return array<string, mixed> */
function smdrvProblem(TestResponse $response): array
{
    $body = $response->json();
    unset($body['request_id']);

    return $body;
}

/** The refusal of a caller who may not see the resource, against the one for an id that never existed. */
function smdrvAssertConcealed(string $route, array $world, array $headers, TestResponse $hidden): void
{
    $ghost = smdrvSend($route, smdrvGhost($world), $headers);

    expect($hidden->status())->toBe(404, $route.' answered '.$hidden->status().' for what the caller cannot see: '.$hidden->getContent())
        ->and(smdrvProblem($hidden))->toBe(smdrvProblem($ghost), $route.' tells a hidden resource from a missing one');
}

function smdrvCheck(string $row, string $route): void
{
    $world = $row === 'R2' ? null : smdrvWorld($route);
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
            $before = smdrvState($world);
            // The suite boots with the host's stack emptied: the route gets the guard a host lists in vaulter.routes.middleware.authenticated.
            app('router')->getRoutes()->getByName('vaulter.v1.'.$route)?->middleware('auth');

            smdrvSend($route, $world['ids'], smdrvHeaders($route, $world))->assertUnauthorized();

            expect(smdrvState($world))->toBe($before);
        })(),
        'R2' => (function () use ($route, $strict): void {
            // Tenant A owns the drive and user 1 owns it there; the request comes from tenant B.
            $resolver = $strict();
            $world = smdrvWorld($route);
            $resolver->tenant = 'B';
            test()->actingAs(V1::user(1));
            $before = smdrvState($world);

            smdrvAssertConcealed($route, $world, smdrvHeaders($route, $world), smdrvSend($route, $world['ids'], smdrvHeaders($route, $world)));

            expect(smdrvState($world))->toBe($before);
        })(),
        'R3' => (function () use ($route, $world): void {
            // A user with no grant at all: what he may not see is as unknown as what is not there.
            test()->actingAs(V1::user(9));
            $before = smdrvState($world);
            $headers = smdrvHeaders($route, $world);

            smdrvAssertConcealed($route, $world, $headers, smdrvSend($route, $world['ids'], $headers));
            smdrvAssertConcealed($route, $world, $headers, smdrvSend($route, $world['ids'], [...$headers, 'Idempotency-Key' => 'hidden-'.$route]));

            expect(smdrvState($world))->toBe($before);
        })(),
        'R4' => (function () use ($route, $world): void {
            AccessTestHelpers::grant($world['root'], RoleRegistry::READER, subjectId: 2);
            // The tag is the one the owner reads: a reader may not even list the grants or the shares to read theirs.
            test()->actingAs(V1::user(1));
            $current = smdrvHeaders($route, $world, tag: true);
            test()->actingAs(V1::user(2));
            $before = smdrvState($world);

            if ($route === 'trash.empty') {
                // Emptying the trash purges the roots the caller may delete: a reader deletes none, so nothing goes.
                smdrvSend($route, $world['ids'], $current)->assertOk()->assertJsonPath('data.purged_count', 0);
            } else {
                smdrvSend($route, $world['ids'], smdrvHeaders($route, $world))->assertForbidden()->assertJsonPath('code', 'permission_denied');
                smdrvSend($route, $world['ids'], $current)->assertForbidden();
            }

            expect(smdrvState($world))->toBe($before);
        })(),
        'R6' => (function () use ($world): void {
            $before = smdrvState($world);

            // The reason is checked where the actor is made, before any operation of the route can start.
            expect(fn () => $world['handle']->asSystem(''))->toThrow(InvalidArgumentException::class)
                ->and(fn () => $world['handle']->asSystem("  \t"))->toThrow(InvalidArgumentException::class)
                ->and(fn () => DriveContext::system($world['handle']->model(), ''))->toThrow(InvalidArgumentException::class)
                ->and(smdrvState($world))->toBe($before);
        })(),
        'R9' => (function () use ($route, $world): void {
            test()->actingAs(V1::user(1));
            $headers = smdrvHeaders($route, $world, tag: true, extra: ['Idempotency-Key' => 'matrix-'.str_replace('.', '-', $route)]);

            $first = smdrvSend($route, $world['ids'], $headers);
            expect($first->status())->toBeLessThan(300, $route.': '.$first->getContent())->and($first->headers->has('Idempotent-Replayed'))->toBeFalse($route);
            // Two routes cannot answer a retry from the receipt even before the access goes: the drive is gone, and the caller
            // cannot see the node it just closed to itself. The refusal after the revocation is the same.
            if (!in_array($route, ['drives.destroy', 'access.inheritance.restrict'], true)) {
                smdrvSend($route, $world['ids'], $headers)->assertHeader('Idempotent-Replayed', 'true');
            }
            // The next request is a new one: what is scoped to a request (the replay flag) starts afresh, as in a real worker.
            app()->forgetScopedInstances();

            // The access of the caller is revoked: the same request, key and headers is refused now and the stored answer is not returned.
            $grants = config('vaulter-drive.models.grant');
            app(VaulterDatabase::class)->write(static fn () => $grants::query()->where('subject_id', 1)->get()->each->delete());
            $after = smdrvSend($route, $world['ids'], $headers);

            expect($after->status())->toBeIn([403, 404], $route.': '.$after->getContent())
                ->and($after->headers->has('Idempotent-Replayed'))->toBeFalse($route)
                ->and($after->json('data'))->toBeNull();
        })(),
        default => throw new LogicException('Row '.$row.' of '.$route.' is checked by smdrvCheckExtra'),
    };
}

it('holds the security matrix', function (string $route, string $row): void {
    if (in_array($row, ['R5', 'R7', 'R8'], true)) {
        smdrvCheckExtra($row, $route);

        return;
    }
    smdrvCheck($row, $route);
})->with('drive matrix');

/** R5, R7 and R8: the rows of the share and destination routes. */
function smdrvCheckExtra(string $row, string $route): void
{
    // R7/R8: user 2 may write in `work` and sees `readonly` (View only), and nothing of `secret`.
    $arrange = static function (string $route): array {
        $handle = V1::drive(userId: 1);
        $work = V1::folder($handle, 'work');
        $secret = V1::folder($handle, 'secret');
        $readonly = V1::folder($handle, 'readonly');
        $file = V1::file($handle, 'a.txt', 'one', parent: $work->id);
        AccessTestHelpers::grant(DriveTestHelpers::model($work), RoleRegistry::WRITER, subjectId: 2);
        AccessTestHelpers::grant(DriveTestHelpers::model($readonly), RoleRegistry::READER, subjectId: 2);
        test()->actingAs(V1::user(2));
        $send = static fn (string $destination, array $headers): TestResponse => test()->json('POST', V1::url('nodes/'.$file->id.($route === 'nodes.move' ? '/move' : '/copy')), ['destination_id' => $destination, 'name' => 'copy.txt'], $headers);
        $state = static fn (): array => [Node::query()->count(), Node::query()->findOrFail($file->id)->parent_id, Node::query()->findOrFail($file->id)->sequence];

        return [$file, $secret, $readonly, $send, $state];
    };

    if ($row === 'R7') {
        [$file, $secret, , $send, $state] = $arrange($route);
        $headers = V1::ifMatch($file->id);
        $before = $state();

        $hidden = $send($secret->id, $headers);
        $missing = $send('ghost-destination', $headers);

        expect($hidden->status())->toBe(404, $hidden->getContent())
            ->and(smdrvProblem($hidden))->toBe(smdrvProblem($missing))
            ->and($state())->toBe($before);

        return;
    }
    if ($row === 'R8') {
        [$file, , $readonly, $send, $state] = $arrange($route);
        $headers = V1::ifMatch($file->id);
        $before = $state();

        $send($readonly->id, $headers)->assertForbidden()->assertJsonPath('code', 'permission_denied');

        expect($state())->toBe($before);

        return;
    }

    // R5: a link that is revoked or has expired is refused on the public side, and the routes that manage it do not bring it back.
    $handle = V1::drive(userId: 1);
    $file = V1::file($handle, 'shared.txt', 'one');
    test()->actingAs(V1::user(1));
    $shares = $handle->shares();
    $revoked = $shares->create($file->id, new ShareLinkInput);
    $expiring = $shares->create($file->id, new ShareLinkInput(expiresAt: now()->addHour()));
    $live = $shares->create($file->id, new ShareLinkInput);
    $shares->revoke($file->id, $revoked->link->id);
    $view = static fn (string $token): TestResponse => test()->getJson(V1::url('shares/'.$token));

    $view($live->token)->assertOk();
    $view($revoked->token)->assertNotFound()->assertJsonPath('code', 'share_not_found');
    $view($expiring->token)->assertOk();
    test()->travel(2)->hours();
    $view($expiring->token)->assertNotFound()->assertJsonPath('code', 'share_not_found');

    $base = 'nodes/'.$file->id.'/shares/'.$revoked->link->id;
    // The tag the owner reads for the revoked link: the precondition holds, so what answers is the state of the link.
    $tag = ['If-Match' => '"share.'.$revoked->link->id.'.'.collect($shares->list($file->id))->firstWhere('id', $revoked->link->id)?->version.'"'];
    match ($route) {
        'shares.store' => test()->postJson(V1::url('nodes/'.$file->id.'/shares'), ['role' => 'reader', 'expires_at' => now()->subMinute()->toIso8601String()])->assertStatus(422),
        'shares.update' => test()->patchJson(V1::url($base), ['max_uses' => 4], $tag)->assertNotFound(),
        'shares.rotate' => test()->postJson(V1::url($base.'/rotate'), [], $tag)->assertNotFound(),
        // A second revoke is a no-op: the link stays revoked.
        'shares.destroy' => test()->deleteJson(V1::url($base), [], $tag)->assertNoContent(),
    };

    // Whatever the management route did, the dead links stay dead and the live one is untouched.
    $view($revoked->token)->assertNotFound();
    $view($expiring->token)->assertNotFound();
    $view($live->token)->assertOk();
}

it('covers every mutating route of the package, with a reason for each row that does not apply', function (): void {
    $catalog = [];
    $receipts = [];
    foreach (app(OperationCatalog::class)->all() as $entry) {
        if ($entry['module'] !== 'drive') {
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
        if (str_starts_with($name, 'vaulter.v1.') && str_starts_with($route->getActionName(), 'Vaulter\\Drive\\') && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $registered[] = substr($name, strlen('vaulter.v1.'));
        }
    }
    $matrix = smdrvMatrix();
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
        // R1–R3 and R6 apply to every route; R9 to every route that stores a receipt; R5 only to share routes, R7/R8 only to a
        // move or a copy; R4 to every route but the star, which asks for View only.
        foreach (['R1', 'R2', 'R3', 'R6'] as $row) {
            expect($rows[$row])->toBeNull($route.' '.$row.' applies to every route');
        }
        expect($rows['R9'] === null)->toBe(in_array($route, $receipts, true), $route.' R9 is for the receipt routes')
            ->and($rows['R5'] === null)->toBe(str_starts_with($route, 'shares.'), $route.' R5 is for the share routes')
            ->and($rows['R7'] === null && $rows['R8'] === null)->toBe(in_array($route, ['nodes.move', 'nodes.copy'], true), $route.' R7/R8 are for the move and the copy')
            ->and($rows['R4'] === null)->toBe(!in_array($route, ['nodes.star', 'nodes.unstar'], true), $route.' R4 applies but the star');
    }
});
