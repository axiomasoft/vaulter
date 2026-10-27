<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Support\DocumentTestHelpers as Documents;
use Vaulter\Collaboration\Tests\Support\ValidateReleaseOpenApiResponses;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Http\Problems\ProblemRenderer;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Media\Content\ImmutableContentIdentity;
use Vaulter\Media\Content\SignedContentUrl;
use Vaulter\Media\Contracts\Cas\DirectContentUrlSigner;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookSigner;

require_once dirname(__DIR__, 3).'/packages/collaboration/tests/Support/DocumentTestHelpers.php';
require_once dirname(__DIR__, 3).'/packages/collaboration/tests/Collaboration/HubTestHelpers.php';
require_once dirname(__DIR__, 3).'/packages/collaboration/tests/Support/ValidateReleaseOpenApiResponses.php';

abstract class RedactionTestCase extends TestCase
{
    protected function validateOpenApiResponses(mixed $app): void
    {
        if ((string) env('VAULTER_OPENAPI_VALIDATE') === '1') {
            $app->make(Kernel::class)->pushMiddleware(ValidateReleaseOpenApiResponses::class);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), VaulterDocumentsServiceProvider::class, VaulterCollaborationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('vaulter.modules.collaboration', true);
        $app['config']->set('vaulter.modules.webhooks', true);
    }
}

/** Collect values and keys recursively, including every previous exception. */
final class RedactionCollector
{
    public array $sinks = [];

    public function capture(mixed $value): void
    {
        $this->sinks[] = $this->normalize($value);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return [$value::class, $value->getMessage(), $this->normalize($value->getPrevious())];
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }
        if (is_object($value)) {
            return $this->normalize(get_object_vars($value));
        }

        return $value;
    }

    public function contains(string $canary): bool
    {
        return str_contains((string) json_encode($this->sinks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $canary);
    }

    public function assertAbsent(array $canaries): void
    {
        foreach ($canaries as $index => $canary) {
            foreach (array_unique([$canary, rawurlencode($canary), base64_encode($canary)]) as $form) {
                Assert::assertFalse($this->contains($form), 'Sensitive canary reached an unwanted sink (index '.$index.').');
            }
        }
    }

    public function problem(TestResponse $response): void
    {
        expect($response->headers->get('Content-Type'))->toContain('application/problem+json');
        $this->capture([$response->json(), $response->headers->all()]);
    }
}

uses(RedactionTestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-webhooks.manage_ability', null);
    config()->set('vaulter-drive.features.share_links', true);
    $this->collector = new RedactionCollector;
    $collector = $this->collector;
    Log::listen(static fn (MessageLogged $event) => $collector->capture([$event->message, $event->context]));
    app(ExceptionHandler::class)->reportable(static function (Throwable $exception) use ($collector): void {
        $collector->capture($exception);
    });
    $this->actingAs(V1::user(1));
});

it('proves the collector sees log messages recursive context and reported exception chains', function (): void {
    $message = 'positive-message-'.bin2hex(random_bytes(20));
    $nested = 'positive-context-'.bin2hex(random_bytes(20));
    $previous = 'positive-chain-'.bin2hex(random_bytes(20));
    Log::warning($message, ['nested' => ['exception' => new RuntimeException('outer', 0, new RuntimeException($nested))]]);
    report(new RuntimeException('reported outer', 0, new RuntimeException($previous)));
    foreach ([$message, $nested, $previous] as $canary) {
        expect($this->collector->contains($canary))->toBeTrue();
    }
    $this->collector->sinks = [];
    $this->collector->assertAbsent([$message, $nested, $previous]);
});

it('issues and replays a share credential while keeping failures and validation clean', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::folder($drive, 'private');
    $key = 'idem-private-'.bin2hex(random_bytes(24));
    $password = 'password-private +/='.bin2hex(random_bytes(24));
    $url = V1::url('nodes/'.$node->id.'/shares');
    $input = ['role' => RoleRegistry::READER, 'password' => $password];
    $first = $this->postJson($url, $input, ['Idempotency-Key' => $key])->assertCreated();
    $token = $first->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty();
    $again = $this->postJson($url, $input, ['Idempotency-Key' => $key])->assertSuccessful();
    expect($again->json('data.token'))->toBe($token)->and($again->json('data.id'))->toBe($first->json('data.id'));
    $this->collector->problem($this->getJson(V1::url('shares/'.$token.'rejected'))->assertNotFound());
    $this->collector->problem($this->postJson($url, ['role' => $password, 'password' => $password])->assertUnprocessable());
    $this->collector->capture($this->getJson($url)->assertOk()->json());
    $this->collector->assertAbsent([$token, $key, $password]);
});

it('issues a session with private document content and rejects a bearer credential safely', function (): void {
    Hub::configure();
    $content = 'private-document +/='.bin2hex(random_bytes(32));
    $node = Hub::document(content: Documents::content($content));
    $session = $this->postJson(V1::url('documents/'.$node->getKey().'/collaboration/session'))->assertCreated();
    $token = $session->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty();
    $bad = $token.'rejected';
    $this->collector->problem(Hub::call('authenticate', ['room' => Hub::room($node)], $bad)->assertUnauthorized());
    $this->collector->problem($this->postJson(V1::url('documents/'.$node->getKey().'/collaboration/session'), ['schema_version' => $content])->assertUnprocessable());
    $this->collector->assertAbsent([$token, $bad, 'Bearer '.$bad, $content]);
});

it('returns a signed direct URL only in the successful download contract', function (): void {
    $signature = 'signature-private +/='.bin2hex(random_bytes(32));
    $content = 'private-upload +/='.bin2hex(random_bytes(32));
    $url = 'https://storage.test/object?signature='.rawurlencode($signature);
    $signer = new class($url) implements DirectContentUrlSigner
    {
        public int $calls = 0;

        public function __construct(private string $url) {}

        public function sign(ImmutableContentIdentity $content, DateTimeImmutable $expiresAt): SignedContentUrl
        {
            $this->calls++;

            return new SignedContentUrl($this->url, $expiresAt, $content->fingerprint(), $content->storageVersion);
        }
    };
    app()->instance(DirectContentUrlSigner::class, $signer);
    config()->set('vaulter-drive.downloads.delivery_mode', 'direct_signed');
    $drive = V1::drive(userId: 1);
    $file = V1::file($drive, 'private.txt', $content);
    $this->get(V1::url('files/'.$file->id.'/download'))->assertStatus(302)->assertHeader('Location', $url);
    expect($signer->calls)->toBe(1);
    $this->collector->problem($this->getJson(V1::url('files/999999/download?signature='.rawurlencode($signature)))->assertNotFound());
    $this->collector->assertAbsent([$signature, $url, $content]);
});

it('issues and rotates webhook secrets then inspects a real transport failure persisted error', function (): void {
    $created = $this->postJson(V1::url('webhook-endpoints'), ['url' => 'https://hooks.test/receive', 'events' => ['folder.created']])->assertCreated();
    $old = $created->json('data.secret');
    expect($old)->toStartWith('whsec_');
    $id = $created->json('data.id');
    $etag = $this->getJson(V1::url('webhook-endpoints/'.$id))->assertOk()->headers->get('ETag');
    $rotated = $this->postJson(V1::url('webhook-endpoints/'.$id.'/rotate-secret'), ['overlap_seconds' => 600], ['If-Match' => $etag])->assertOk();
    $secret = $rotated->json('data.secret');
    expect($secret)->toStartWith('whsec_')->not->toBe($old);
    $content = 'private-webhook-payload +/='.bin2hex(random_bytes(32));
    $delivery = app(VaulterDatabase::class)->write(static fn () => WebhookTestHelpers::delivery(WebhookEndpoint::query()->findOrFail($id), ['payload' => ['private' => $content]]));
    $calls = 0;
    Http::fake(function () use (&$calls, $secret, $old, $content): never {
        $calls++;
        throw new ConnectionException('transport Bearer '.$secret.'?previous='.rawurlencode($old).'&payload='.rawurlencode($content));
    });
    (new DeliverWebhook(deliveryId: (string) $delivery->getKey()))->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));
    expect($calls)->toBe(1)->and($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->attempts)->toBe(1);
    $this->collector->capture($delivery->fresh()->last_error);
    $this->collector->assertAbsent([$old, $secret, $content]);
});

it('keeps malicious metadata keys out of renderer warnings and problem responses', function (): void {
    $canary = 'private-meta-key-'.bin2hex(random_bytes(32));
    $exception = new class($canary) extends VaulterException implements ProblemException
    {
        use ProvidesProblemDetails;

        public function problemCode(): string
        {
            return 'concurrent_update';
        }

        public function httpStatus(): int
        {
            return 409;
        }

        public function problemDetails(): array
        {
            return ['reason' => 'kept', 'nested' => ['prefix-'.$this->getMessage().'-suffix' => ['secret' => $this->getMessage()]]];
        }
    };
    // Invoke the renderer without reporting an exception whose secret message this fixture supplies.
    // Real HTTP failures above keep the report collector active throughout their requests.
    $response = app(ProblemRenderer::class)->render($exception, Request::create(V1::url('__probe/metadata')));
    $this->collector->problem(TestResponse::fromBaseResponse($response)->assertStatus(409));
    expect($response->getContent())->toContain('kept')
        ->and($this->collector->contains('Vaulter problem meta carried forbidden keys'))->toBeTrue()
        ->and($this->collector->contains('[redacted]'))->toBeTrue();
    $this->collector->assertAbsent([$canary]);
});
