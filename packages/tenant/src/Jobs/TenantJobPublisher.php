<?php

declare(strict_types=1);

namespace Vaulter\Tenant\Jobs;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Pipeline\Pipeline;
use Tenant\Contracts\ContextCodec;
use Tenant\Contracts\TenantRuntime;
use Tenant\Data\ContextReference;
use Tenant\Data\IsolationIdentity;
use Tenant\Data\Purpose;
use Tenant\Exceptions\ScopeRequired;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Queue\ContextDispatcher;
use Tenant\Laravel\Queue\QueueRegistry;
use Tenant\Laravel\Queue\ScalarJob;
use Vaulter\Contracts\Jobs\JobPublisher;
use Vaulter\Jobs\JobRegistry;
use Vaulter\Jobs\JobRequest;
use Vaulter\Jobs\PreparedJob;
use Vaulter\Jobs\PublicationContext;
use Vaulter\Tenant\ScalarProjection;

final readonly class TenantJobPublisher implements JobPublisher
{
    public const string PRODUCER = 'vaulter:producer';

    public const string HANDLER = 'vaulter:job';

    public const string PURPOSE = 'vaulter:job';

    /** @var list<string> */
    private array $queues;

    /** @param array<mixed> $queues */
    public function __construct(private TenantRuntime $runtime, private ContextCodec $codec,
        private ContextDispatcher $dispatcher, private QueueRegistry $registry, private JobRegistry $tasks, private LockProvider $locks,
        private Dispatcher $bus, private Pipeline $pipeline, private ScalarProjection $projection,
        array $queues, private string $connection = 'database', private int $lockSeconds = 3600)
    {
        if ($queues === [] || !array_is_list($queues) || !in_array($connection, ['database', 'sync'], true) || $lockSeconds < 1) {
            throw new \InvalidArgumentException('Invalid Vaulter publisher configuration.');
        }
        foreach ($queues as $queue) {
            if (!is_string($queue) || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $queue)) {
                throw new \InvalidArgumentException('Invalid Vaulter queue allowlist.');
            }
        }
        $this->queues = $queues;
    }

    public function prepare(JobRequest $request, ?PublicationContext $origin = null): PreparedJob
    {
        $queue = $request->queue ?? 'default';
        if (!in_array($queue, $this->queues, true)) {
            throw new TenantFailure('denied');
        }
        $reference = $origin === null ? $this->reference() : $this->decode($origin);
        if ($reference->envelope['mode'] === 'central') {
            throw new ScopeRequired;
        }
        $this->projection->encode(new IsolationIdentity($reference->envelope['namespace'], $reference->envelope['isolationId']));
        $wire = $this->codec->encode($reference);
        if (strlen($wire) > 16384) {
            throw new \InvalidArgumentException('Publication envelope exceeds limit.');
        }
        $stamp = $origin ?? new PublicationContext('tenant:context', 1, ['envelope' => $wire, 'purpose' => self::PURPOSE]);
        $prepared = new PreparedJob($request, $stamp);
        $this->tasks->make($prepared); // Validated owning task refs; no model hydration.

        // Capture only. Entering a nested frame here would disconnect the producer's open transaction.
        return $prepared;
    }

    public function publish(PreparedJob $job): void
    {
        if ($job->context === null) {
            throw new ScopeRequired;
        }
        $prepared = $this->prepare($job->request, $job->context);
        $reference = $this->decode($job->context);
        $intent = $this->dispatcher->freezeTenant($reference, new Purpose(self::PURPOSE));
        $key = $this->lockKey($prepared);
        $lock = $this->locks->lock($key, $this->lockSeconds);
        if (!$lock->get()) {
            return;
        }
        try {
            $this->dispatcher->enqueue($intent, new ScalarJob(self::PRODUCER, self::HANDLER, [
                'task' => $job->request->task,
                'refs' => json_encode($job->request->refs, JSON_THROW_ON_ERROR),
                'envelope' => $job->context->refs['envelope'],
                'purpose' => self::PURPOSE,
                'queue' => $job->request->queue ?? 'default',
                'lockOwner' => $lock->owner(),
            ], $this->connection, $job->request->queue ?? 'default'));
        } catch (\Throwable $error) {
            $lock->release();
            throw $error;
        }
    }

    /** Host includes this definition in its immutable QueueRegistry before provider boot. */
    public static function handler(Container $app, array $extensions = []): array
    {
        return [
            'producer' => self::PRODUCER, 'purposes' => [self::PURPOSE], 'extensions' => $extensions,
            'admit' => fn (array $data, Purpose $purpose) => $app->make(self::class)->admit($data, $purpose),
            'handle' => fn (array $data, Job $job) => $app->make(self::class)->handle($data, $job),
        ];
    }

    public function admit(array $data, Purpose $purpose): void
    {
        if ($purpose->id !== self::PURPOSE) {
            throw new TenantFailure('denied');
        }
        $prepared = $this->fromData($data);
        $reference = $this->decode($prepared->context);
        $selection = $this->registry->selection($reference, self::HANDLER);
        $effective = $reference->envelope;
        $effective['extensions'] = array_map(static fn ($e) => ['type' => $e->type, 'version' => $e->version, 'required' => $e->required, 'refs' => $e->refs], $selection->extensions);
        if ($this->codec->encode($this->reference()) !== $this->codec->encode(new ContextReference($effective))) {
            throw new TenantFailure('binding_mismatch');
        }
        $this->projection->encode($this->runtime->current()->context->identity);
    }

    public function handle(array $data, Job $transport): void
    {
        $this->admit($data, new Purpose(self::PURPOSE));
        $prepared = $this->fromData($data);
        // Release only this delivery's lock, before the owning job may prepare its continuation.
        $this->locks->restoreLock($this->lockKey($prepared), $data['lockOwner'])->release();
        $job = $this->tasks->make($prepared);
        if (method_exists($job, 'setJob')) {
            $job->setJob($transport);
        }
        $middleware = method_exists($job, 'middleware') ? $job->middleware() : [];
        $this->pipeline->send($job)->through($middleware)->then(fn (object $instance) => $this->bus->dispatchNow($instance));
    }

    private function reference(): ContextReference
    {
        $state = $this->runtime->current();
        if ($state->kind !== 'active' || $state->context === null) {
            throw new ScopeRequired;
        }
        $c = $state->context;

        return new ContextReference([
            'schema' => 'tenant.context/1', 'mode' => $c->mode,
            'namespace' => $c->identity->namespace, 'isolationId' => $c->identity->id,
            'profileId' => $c->profile->id, 'profileVersion' => $c->profile->version,
            'resourceRef' => $c->binding->resourceRef, 'incarnation' => $c->binding->incarnation,
            'routeRevision' => $c->binding->routeRevision,
            'extensions' => array_map(static fn ($e) => ['type' => $e->type, 'version' => $e->version, 'required' => $e->required, 'refs' => $e->refs], $c->extensions),
        ]);
    }

    private function decode(PublicationContext $origin): ContextReference
    {
        $keys = array_keys($origin->refs);
        sort($keys);
        if ($origin->type !== 'tenant:context' || $origin->version !== 1 || $keys !== ['envelope', 'purpose']
            || $origin->refs['purpose'] !== self::PURPOSE || strlen($origin->refs['envelope']) > 16384) {
            throw new TenantFailure('denied');
        }

        return $this->codec->decode($origin->refs['envelope']);
    }

    private function fromData(array $data): PreparedJob
    {
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['envelope', 'lockOwner', 'purpose', 'queue', 'refs', 'task']) {
            throw new TenantFailure('denied');
        }
        foreach ($data as $value) {
            if (!is_string($value) || $value === '') {
                throw new TenantFailure('denied');
            }
        }
        $refs = json_decode($data['refs'], true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($refs)) {
            throw new TenantFailure('denied');
        }

        return $this->prepare(new JobRequest($data['task'], $refs, $data['queue']),
            new PublicationContext('tenant:context', 1, ['envelope' => $data['envelope'], 'purpose' => $data['purpose']]));
    }

    private function lockKey(PreparedJob $job): string
    {
        $e = $this->decode($job->context)->envelope;
        $refs = $job->request->refs;
        ksort($refs);

        return 'vaulter:job:'.hash('sha256', json_encode([
            $e['mode'], $e['namespace'], $e['resourceRef'], $e['incarnation'], $job->request->task, $refs,
        ], JSON_THROW_ON_ERROR));
    }
}
