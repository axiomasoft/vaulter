<?php

declare(strict_types=1);

/*
 * Deterministic v0.2.0 data seeder (PLAN2 P0.3), loaded by make-v0.2-dump.php from
 * the `v0.2.0` worktree autoloader: every class used below is the TAG's code.
 *
 * Shapes go through the 0.2 API/models. Raw SQL is used only for shapes the 0.2
 * API never produces; each such statement carries a «raw:» comment saying why.
 *
 * Determinism: frozen clock advanced in fixed steps, ULID/UUID/random-string
 * factories over counters, mt_srand, fixed file bytes, an encrypter with a
 * content-derived IV — two runs on a fresh schema produce identical rows.
 */

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Uid\Ulid;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Actions\Sharing\RevokeShareLinkAction;
use Vaulter\Actions\Webhooks\RegisterWebhookEndpointAction;
use Vaulter\Contracts\Access\EnumeratesRoleSubjects;
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Data\Access\GrantNodeAccessData;
use Vaulter\Data\Sharing\CreateShareLinkData;
use Vaulter\Data\Webhooks\RegisterWebhookEndpointData;
use Vaulter\Documents\Actions\Documents\CreateDocumentNodeAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentNodeData;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentData;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Drive\Data\Uploads\UploadInitData;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Facades\Vaulter;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;

/** The host entity owning each storage (host_type `fixture_host`); never persisted. */
final class FixtureV020Host extends Model
{
    public $incrementing = false;

    protected $table = 'fixture_hosts';

    public function getMorphClass(): string
    {
        return 'fixture_host';
    }
}

/**
 * Open host-role driver that also ENUMERATES role subjects, so the 0.2 grant
 * action accepts the role grants the upgrade must carry (the stock NullRoleDriver
 * refuses them outside production).
 */
final class FixtureV020RoleDriver implements EnumeratesRoleSubjects, HostRoleDriver
{
    public function resolve(Subject $subject, Authorizable $actor, AclScope $scope): ?Decision
    {
        return null;
    }

    public function override(Authorizable $actor, AclScope $scope): ?Effect
    {
        return Effect::Allow;
    }

    public function subjectsFor(Authorizable $actor): array
    {
        return [];
    }

    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool
    {
        return true;
    }
}

/** The SSRF guard resolves the fixture webhook host without DNS. */
final class FixtureV020HostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        return ['93.184.216.34'];
    }
}

/**
 * Laravel's payload format with an IV derived from the plaintext instead of
 * random bytes: still decryptable by the stock encrypter under the same APP_KEY.
 */
final class FixtureV020Encrypter extends Encrypter
{
    private string $fixtureKey;

    public function __construct(string $key, string $cipher = 'aes-256-cbc')
    {
        parent::__construct($key, $cipher);
        $this->fixtureKey = $key;
    }

    public function encrypt(#[SensitiveParameter] $value, $serialize = true): string
    {
        $plain = $serialize ? serialize($value) : $value;
        $rawIv = substr(hash_hmac('sha256', $plain, $this->fixtureKey, true), 0, 16);
        $value = openssl_encrypt($plain, 'aes-256-cbc', $this->fixtureKey, 0, $rawIv);
        $iv = base64_encode($rawIv);
        $mac = hash_hmac('sha256', $iv.$value, $this->fixtureKey);

        return base64_encode(json_encode(['iv' => $iv, 'value' => $value, 'mac' => $mac, 'tag' => ''], JSON_UNESCAPED_SLASHES));
    }
}

return static function ($app, string $strategy, string $variant): void {
    $bigint = $strategy === 'bigint';

    // --- determinism ----------------------------------------------------------
    mt_srand(20260101);
    $now = Carbon::parse('2026-01-01 00:00:00', 'UTC');
    Carbon::setTestNow($now);
    $tick = static function () use (&$now): void {
        $now = $now->copy()->addMinute();
        Carbon::setTestNow($now);
    };

    $crockford = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $ulids = 0;
    Str::createUlidsUsing(static function () use (&$ulids, $crockford): Ulid {
        $n = ++$ulids;
        $tail = '';

        for ($i = 0; $i < 16; $i++) {
            $tail = $crockford[$n % 32].$tail;
            $n = intdiv($n, 32);
        }

        return Ulid::fromString('01KDX6KQ00'.$tail);
    });
    $uuids = 0;
    Str::createUuidsUsing(static fn () => Uuid::fromString(sprintf('00000000-0000-4000-8000-%012x', ++$uuids)));
    $strings = 0;
    Str::createRandomStringsUsing(static function (int $length) use (&$strings): string {
        return substr(str_repeat(sprintf('fx%06d', ++$strings), (int) ceil($length / 8)), 0, $length);
    });

    $key = base64_decode(substr((string) config('app.key'), 7));
    Model::encryptUsing(new FixtureV020Encrypter($key));

    config()->set('vaulter.permission_drivers', ['fixture' => FixtureV020RoleDriver::class]);
    config()->set('vaulter.drivers.permission', 'fixture');
    $app->instance(HostResolver::class, new FixtureV020HostResolver);
    Http::fake(['*' => Http::response('', 200)]);

    $files = sys_get_temp_dir().'/vlt-fixture-v020-'.getmypid();
    config()->set('filesystems.disks.local.root', $files);

    // --- identities (host keys per the tag's schema types, D07) ----------------
    $owners = $bigint
        ? [1, 2, 3]
        : ['01ARZ3NDEKTSV4RRFFQ69G5FAV', '01ARZ3NDEKTSV4RRFFQ69G5FAW', '01ARZ3NDEKTSV4RRFFQ69G5FAX'];
    $actor = $bigint ? 1001 : '01ARZ3NDEKTSV4RRFFQ69G5FB0';
    $users = $bigint ? [101, 102] : ['01ARZ3NDEKTSV4RRFFQ69G5FB1', '01ARZ3NDEKTSV4RRFFQ69G5FB2'];
    $roles = $bigint ? [201, 202] : ['01ARZ3NDEKTSV4RRFFQ69G5FB3', '01ARZ3NDEKTSV4RRFFQ69G5FB4'];

    $host = static function (int|string $id) use ($bigint): FixtureV020Host {
        $model = new FixtureV020Host;
        $model->setKeyType($bigint ? 'int' : 'string');
        $model->forceFill(['id' => $id]);
        $model->exists = true;

        return $model;
    };

    $doc = static fn (string $text): TipTapContent => TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]],
    ]);

    try {
        if ($variant === 'preflight-red') {
            $drive = Vaulter::for($host($owners[0]));
            $tick();
            $folder = $drive->createFolder('Inbox', actorId: $actor);
            $tick();
            $drive->upload('soft-deleted node bytes', $folder, 'gone.txt', 'text/plain', actorId: $actor);
            $tick();
            $docNode = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
                storage: $drive->storage(), name: 'Headless', content: $doc('headless'), parent: $folder, actorId: $actor,
            ));

            // raw: 0.2 never sets v_nodes.deleted_at (trash uses trashed_at); preflight №1 must refuse it.
            DB::table('v_nodes')->where('name', 'gone.txt')->update(['deleted_at' => $now]);
            // raw: a document node whose version rows are gone — the API always writes v1.
            DB::table('v_node_document_versions')->where('node_id', $docNode->getKey())->delete();

            // Own tables of a storage panel (D11 opt-in): a panel with its own prefix.
            $panel = (new Panel)->id('legacy')->tablePrefix('vlegacy');
            (new PanelTablesBuilder)->createTable(panel: $panel, entity: 'storages');
            // raw: one row in the panel's own table; the 0.2 API addresses own tables only through a registered provider.
            DB::table($panel->table('storages'))->insert([
                'id' => $bigint ? 1 : '01ARZ3NDEKTSV4RRFFQ69G5FC0',
                'panel' => 'legacy', 'owner_id' => $owners[1], 'owner_type' => 'fixture_host',
                'name' => 'Legacy panel storage', 'disk' => 'local', 'storage_type' => 'project',
                'used_bytes' => 0, 'status' => 'active', 'default_node_permissions' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // raw: DB-clock default, pinned as in the main variant below.
            DB::table('v_blobs')->update(['first_seen_at' => DB::raw('committed_at')]);

            return;
        }

        // --- drives, trees, file versions (dedup.scope = storage) --------------
        config()->set('vaulter.dedup.scope', 'storage');
        $drives = [];

        foreach ($owners as $i => $owner) {
            $n = $i + 1;
            $drive = $drives[$n] = Vaulter::for($host($owner));
            $tick();
            $projects = $drive->createFolder('Projects', actorId: $actor);
            $tick();
            $reports = $drive->createFolder('Reports', $projects, actorId: $actor);
            $tick();
            $archive = $drive->createFolder('Archive', actorId: $actor);
            $tick();
            $year = $drive->createFolder('2025', $archive, actorId: $actor);
            $tick();
            $drive->upload("brief of storage {$n}\n", $projects, 'brief.txt', 'text/plain', actorId: $actor);
            $tick();
            $q1 = $drive->upload("q1,v1,storage {$n}\n", $reports, 'q1.csv', 'text/csv', actorId: $actor);

            foreach ([2, 3] as $v) {
                $tick();
                $q1 = $drive->uploadVersion($q1, "q1,v{$v},storage {$n}\n", 'text/csv', actorId: $actor);
            }

            $tick();
            $old = $drive->upload("old of storage {$n}\n", $archive, 'old.txt', 'text/plain', actorId: $actor);
            $tick();
            $temp = $drive->createFolder('Temp', actorId: $actor);
            $tick();
            $drive->upload("scratch of storage {$n}\n", $temp, 'note.txt', 'text/plain', actorId: $actor);

            // activity: rename, move, trash + restore, then the trashed file and folder.
            $tick();
            $drive->rename($reports, 'Reports 2025', actorId: $actor);
            $tick();
            $drive->move($year, $projects, actorId: $actor);
            $tick();
            $drive->trash($old, actorId: $actor);
            $tick();
            $old = $drive->restore($old->fresh(), actorId: $actor);
            $tick();
            $drive->trash($old, actorId: $actor);
            $tick();
            $drive->trash($temp, actorId: $actor);
        }

        // raw: legacy:snull — a storage-scoped blob without storage_id; 0.2 always stamps the uploading storage.
        DB::table('v_blobs')->where('sha256', hash('sha256', "brief of storage 1\n"))->update(['storage_id' => null]);

        // --- legacy:tnull + one blob shared by two storages (dedup.scope = tenant, tenancy off) --
        config()->set('vaulter.dedup.scope', 'tenant');
        $tick();
        $drives[2]->upload("shared bytes across storages\n", null, 'shared.bin', 'application/octet-stream', actorId: $actor);
        $tick();
        $drives[3]->upload("shared bytes across storages\n", null, 'shared.bin', 'application/octet-stream', actorId: $actor);
        $tick();
        $drives[3]->upload("tenant-scoped bytes of storage 3\n", null, 'tenant.bin', 'application/octet-stream', actorId: $actor);
        // dedup.scope = global → scope_key `global` (№7).
        config()->set('vaulter.dedup.scope', 'global');
        $tick();
        $drives[1]->upload("globally deduplicated bytes\n", null, 'global.bin', 'application/octet-stream', actorId: $actor);
        config()->set('vaulter.dedup.scope', 'storage');

        // --- documents: every 0.2 DocumentType, 1–2 versions ---------------------
        $s1 = $drives[1];
        $projects = $s1->list()->firstWhere('name', 'Projects');
        $create = app(CreateDocumentNodeAction::class);
        $update = app(UpdateDocumentContentAction::class);
        $docs = [];

        foreach (['richtext' => 'Notes', 'table' => 'Budget', 'markdown' => 'Readme', 'template' => 'Letter template'] as $format => $name) {
            $tick();
            $docs[$format] = $create->execute(new CreateDocumentNodeData(
                storage: $s1->storage(), name: $name, content: $doc("{$name} v1"), parent: $projects,
                format: $format, actorId: $actor,
            ));
        }

        foreach (['richtext', 'markdown'] as $format) {
            $tick();
            $update->execute(new UpdateDocumentContentData(node: $docs[$format], content: $doc("{$format} v2"), actorId: $actor));
        }

        // N04 «second roots»: the 0.2 API stores a parent-less document with parent_id NULL.
        $tick();
        $create->execute(new CreateDocumentNodeData(
            storage: $s1->storage(), name: 'Loose note', content: $doc('loose'), actorId: $actor,
        ));
        $tick();
        $create->execute(new CreateDocumentNodeData(
            storage: $s1->storage(), name: 'Projects', content: $doc('name clash with the root folder'), actorId: $actor,
        ));

        // --- webhook endpoint, then outbox rows: the first batch is relayed (delivered) --
        $tick();
        app(RegisterWebhookEndpointAction::class)->execute(new RegisterWebhookEndpointData(
            url: 'https://hooks.fixture.test/vaulter', events: ['grant.created', 'grant.revoked', 'share.created'],
        ));

        $grant = app(GrantNodeAccessAction::class);
        $revoke = app(RevokeNodeAccessAction::class);
        $subjects = [
            new Subject('user', $users[0]), new Subject('role', $roles[0]),
            new Subject('user', $users[1]), new Subject('role', $roles[1]),
        ];

        foreach ($subjects as $subject) {
            $tick();
            $grant->execute($projects, new GrantNodeAccessData(
                subject: $subject, level: GrantLevel::Writer, grantedBy: $actor,
            ));
        }

        $tick();
        $app->make(Kernel::class)->call('vaulter:relay-outbox');

        // soft-deleted grants: the 0.2 revoke soft-deletes (deleted_at) on user and role.
        foreach ([$subjects[2], $subjects[3]] as $subject) {
            $tick();
            $revoke->execute($projects, $subject, $actor);
        }

        // Re-grant of a revoked user: an active grant next to its soft-deleted twin (№8 uniqueness rebuild).
        $tick();
        $grant->execute($projects, new GrantNodeAccessData(
            subject: $subjects[2], level: GrantLevel::Reader, grantedBy: $actor,
        ));

        // shares: active, revoked, soft-deleted, revoked then soft-deleted (№8 coalesce precedence).
        $brief = $s1->list($projects)->firstWhere('name', 'brief.txt');
        $mint = app(CreateShareLinkAction::class);
        $minted = [];

        foreach (range(1, 4) as $_) {
            $tick();
            $minted[] = $mint->execute($brief, new CreateShareLinkData(
                permissions: 1, expiresAt: $now->copy()->addYear(), grantedBy: $actor,
            ));
        }

        $tick();
        app(RevokeShareLinkAction::class)->execute($minted[1]->share, $actor);
        $tick();
        $minted[2]->share->delete();
        $tick();
        app(RevokeShareLinkAction::class)->execute($minted[3]->share, $actor);
        $tick();
        $minted[3]->share->delete();

        // --- a tus upload in progress ------------------------------------------
        $tick();
        $uploads = app(UploadSessionManager::class);
        $session = $uploads->initiate(new UploadInitData(
            storage: $s1->storage(), protocol: UploadProtocol::Tus, targetParent: $projects,
            targetName: 'large.bin', totalSizeBytes: 10, mimeType: 'application/octet-stream', actorId: $actor,
        ));
        $tick();
        $chunk = fopen('php://memory', 'r+b');
        fwrite($chunk, 'abcd');
        rewind($chunk);
        $uploads->handlerFor($session)->patch($session, $chunk, 0);

        // raw: v_blobs.first_seen_at is a DB-clock default (CURRENT_TIMESTAMP) the 0.2 code never
        // writes; pin it to the app-clock commit time so the dump does not carry the wall clock.
        DB::table('v_blobs')->update(['first_seen_at' => DB::raw('committed_at')]);
    } finally {
        Carbon::setTestNow();
        Str::createUlidsNormally();
        Str::createUuidsNormally();
        Str::createRandomStringsNormally();

        if (is_dir($files)) {
            exec('rm -rf '.escapeshellarg($files));
        }
    }
};
