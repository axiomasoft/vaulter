<?php

declare(strict_types=1);

require getenv('TENANT_VAULTER_SUPPORT').'/Host.php';

use Tenant\Contracts\TenantRuntime;
use Tenant\Data\IsolationIdentity;
use Tenant\Data\ProfileRef;
use Tenant\Data\Purpose;
use Tenant\Data\Selection;
use Tenant\Exceptions\OpenResource;
use Tenant\Exceptions\TenantFailure;
use TenantVaulter\Host;
use TenantVaulter\HostRecord;
use Vaulter\Contracts\Jobs\JobPublisher;
use Vaulter\Contracts\Tenancy\ResolvesOwnerTenant;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Drives\DeleteDriveAction;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Facades\Vaulter as Vault;
use Vaulter\Drive\Jobs\PurgeDriveJob;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Jobs\JobRequest;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Tenant\TenantResolver;

$m = json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')), true, flags: JSON_THROW_ON_ERROR);
$h = new Host($m);
$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) {
        throw new RuntimeException('Assertion: '.$message);
    }
    $count++;
    echo 'PASS '.$message.PHP_EOL;
};
$refuse = static function (Closure $callback, string $message) use ($check): void {
    try {
        $callback();
    } catch (Throwable $error) {
        if (!$error instanceof TenantFailure && !$error instanceof NodeNotFoundException && !$error instanceof InvalidArgumentException) {
            throw $error;
        }
        $check(true, $message.' ['.get_class($error).':'.$error->getMessage().']');

        return;
    }
    throw new RuntimeException('Expected refusal: '.$message);
};
$app = $h->app;
$central = $app['db']->connection('central');
$resolver = $app->make(TenantResolver::class);
$check($resolver === $app->make(Vaulter\Contracts\Tenancy\TenantResolver::class) && $resolver === $app->make(ResolvesOwnerTenant::class), 'same configured resolver instance owns both ports');
$refuse(fn () => $resolver->current(), 'Missing scalar refuses before operation');
$refuse(fn () => Vault::drive(HostRecord::query()->findOrFail('owner-a')), 'Missing actual Drive entry refuses');
$publisher = $app->make(JobPublisher::class);
$refuse(fn () => $publisher->prepare(new JobRequest('vaulter.drive:purge', ['driveId' => '1'])), 'Missing publication refuses');
$h->runtime->run($h->lab->central(), new Purpose('vaulter:test'), function () use ($refuse, $resolver, $publisher): void {
    $refuse(fn () => $resolver->current(), 'Central scalar refuses');
    $refuse(fn () => $publisher->prepare(new JobRequest('vaulter.drive:purge', ['driveId' => '1'])), 'Central publication refuses');
});
$snapshots = [];
$foreign = HostRecord::query()->findOrFail('owner-a');
foreach (['alpha' => 'owner-a', 'beta' => 'owner-b'] as $namespace => $ownerId) {
    $h->run($namespace, function () use ($h, $app, $namespace, $ownerId, $resolver, $foreign, $refuse, $check, &$snapshots): void {
        $record = HostRecord::query()->findOrFail($ownerId);
        $scalar = $resolver->current();
        $check($resolver->tenantOf($record) === $scalar, 'fresh owner projection equals current '.$namespace);
        if ($namespace === 'beta') {
            $check($resolver->tenantOf($foreign) !== $scalar, 'tenantOf A remains A under ambient B');
            $refuse(fn () => Vault::drive($foreign), 'foreign owner refuses before Drive write');
        }
        $drive = Vault::drive($record)->asSystem('consumer fixture');
        $file = $drive->files()->upload(UploadSource::fromStream(fopen('data://text/plain,'.rawurlencode('bytes-'.$namespace), 'rb'), 'probe.txt'), new UploadOptions);
        $row = FileRevision::query()->firstOrFail();
        $snapshots[$namespace] = ['id' => (string) $drive->model()->getKey(), 'revision' => (string) $row->id, 'tenant' => $scalar, 'disk' => $app['filesystem']->disk('local'), 'db' => $app['db']->connection()->getDatabaseName(), 'connection' => $app['db']->connection(), 'cache' => $app['cache']->store('tenant')];
        $app['cache']->store('tenant')->put('vault-shared', 'value-'.$namespace, 60);
        $check($drive->model()->tenant_id === $scalar, 'actual Drive stamped injective '.$namespace);
        $check($app['db']->connection()->getDatabaseName() === $h->manifest['databases'][$namespace === 'alpha' ? 'a' : 'b'], 'actual physical connection '.$namespace);
    });
}
$check($snapshots['alpha']['id'] === $snapshots['beta']['id'], 'equal driveId seeded in distinct resources');
$check($snapshots['alpha']['tenant'] !== $snapshots['beta']['tenant'], 'same opaque id across namespaces has distinct scalars');
$refuse(fn () => $snapshots['alpha']['disk']->exists('anything'), 'stale resource disk permanently refuses');
$refuse(fn () => $snapshots['alpha']['connection']->table('probes')->count(), 'stale physical DB handle permanently refuses');
$refuse(fn () => $snapshots['alpha']['cache']->put('foreign', 'bad'), 'stale Redis cache handle permanently refuses');
foreach (['alpha', 'beta'] as $namespace) {
    $h->run($namespace, function () use ($app, $namespace, $check): void {
        $check($app['cache']->store('tenant')->get('vault-shared') === 'value-'.$namespace, 'real Redis cache remains separated '.$namespace);
        $blob = Blob::query()->firstOrFail();
        $disk = $app['filesystem']->disk('local');
        $stream = $disk->readStream($blob->storage_key);
        try {
            try {
                $h = app(TenantRuntime::class);
                $h->enter(Selection::tenant(new IsolationIdentity('beta', 'same'), new ProfileRef('laravel-pg-database', 1)), new Purpose('vaulter:test'));
                throw new RuntimeException('Open stream allowed a switch');
            } catch (OpenResource) {
                $check(true, 'lease-backed native stream prevents switching until closed');
            }
        } finally {
            fclose($stream);
        }
    });
}

$check($central->table('jobs')->count() >= 2, 'actual scan producers enqueue both resources without unique lock collision');
$queue = $central->table('jobs')->orderBy('id')->get()->all();
$check(array_all($queue, static fn ($row) => !str_contains($row->payload, 'O:') && str_contains($row->payload, 'tenant.context')), 'queued transport uses signed scalar envelopes no serialized private jobs');

function worker(Host $h, string $label): void
{
    $command = [PHP_BINARY, getenv('TENANT_VAULTER_SUPPORT').'/Worker.php'];
    $env = getenv();
    $env['APP_ENV'] = 'testing';
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $env);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    echo 'CHILD '.$label.PHP_EOL.$out.$err;
    if ($exit !== 0) {
        throw new RuntimeException('Worker failed '.$label.' exit'.$exit);
    }
}
worker($h, 'actual scan A/B');
$check($central->table('failed_jobs')->count() === 0, 'initial scan jobs no failures');
$check($central->table('scan_reads')->where('namespace', 'alpha')->where('bytes', 'bytes-alpha')->count() === 1 && $central->table('scan_reads')->where('namespace', 'beta')->where('bytes', 'bytes-beta')->count() === 1, 'actual scanner bytes isolated and delivered once A/B');

foreach (['alpha', 'beta'] as $namespace) {
    $h->run($namespace, function () use ($check): void {
        $check(FileRevision::query()->firstOrFail()->scan_status === 'clean', 'actual scan evidence persisted in selected resource');
    });
}

// Actual DeleteDriveAction capture is observed inside the transaction. Detach the registered callback,
// commit without it, close A, enter B, then execute that exact captured framework callback.
$callbacks = [];
$h->run('alpha', function () use ($app, &$callbacks, $check): void {
    $record = HostRecord::query()->findOrFail('owner-a');
    $handle = Vault::drive($record)->asSystem('consumer fixture');
    for ($i = 0; $i < PurgeDriveJob::BATCH + 3; $i++) {
        $handle->folders()->create('chunk-'.$i);
    }
    $connection = $app->make(VaulterDatabase::class)->connection();
    $connection->beginTransaction();
    $app->make(DeleteDriveAction::class)->execute(DriveContext::system($handle->model(), 'consumer fixture'));
    $manager = $app['db.transactions'];
    $pending = $manager->getPendingTransactions();
    $tx = $pending->last();
    $callbacks = $tx->getCallbacks();
    $property = new ReflectionProperty($tx, 'callbacks');
    $property->setValue($tx, []);
    $connection->commit();
    $check($callbacks !== [], 'actual purge prepared before registered afterCommit callback');
});
$before = $central->table('jobs')->count();
$h->run('beta', function () use ($callbacks, $check, $central): void {
    foreach ($callbacks as $callback) {
        $callback();
    }
    $check($central->table('jobs')->count() === 1, 'original A purge enqueued while B active');
    $payload = json_decode($central->table('jobs')->first()->payload, true);
    $check(str_contains($payload['data']['wire'], 'alpha'), 'deferred callback retains frozen A wire');
});
$h->run('beta', function () use ($app): void {
    $handle = Vault::drive(HostRecord::query()->findOrFail('owner-b'))->asSystem('consumer fixture');
    $app->make(DeleteDriveAction::class)->execute(DriveContext::system($handle->model(), 'consumer fixture'));
});
$check($central->table('jobs')->count() === 2, 'same driveId purge locks are partitioned by original resource/incarnation');
worker($h, 'purge and continuation');
$check($central->table('failed_jobs')->count() === 0, 'purge continuation no failures');
foreach (['alpha', 'beta'] as $namespace) {
    $h->run($namespace, function () use ($check): void {
        $check(Drive::query()->count() === 0 && Node::query()->count() === 0 && FileRevision::query()->count() === 0, 'actual purge converged all owning rows with continuation');
    });
}
echo 'COMPLETE Vaulter actual consumer '.$count.' assertions'.PHP_EOL;
