<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Vaulter\Drive\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Http\Problems\ProblemRenderer;
use Vaulter\Testing\Support\ProblemProbeRoutes;

it('drops forbidden meta keys at any depth and logs a warning without the values', function (): void {
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };
    $this->app->instance(LoggerInterface::class, $logger);
    $this->app->forgetInstance(ProblemRenderer::class);

    $probe = new class('leaky') extends VaulterException implements ProblemException
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
            return [
                'path' => '/srv/storage/a',
                'token' => 'tok-123',
                'reason' => 'kept',
                'nested' => ['secret' => 'hunter2', 'ok' => 1, 'SQL' => 'select 1'],
            ];
        }
    };
    ProblemProbeRoutes::register(['leaky' => $probe]);

    $response = $this->getJson(ProblemProbeRoutes::path('leaky'))->assertStatus(409);

    expect($response->json('meta'))->toBe(['reason' => 'kept', 'nested' => ['ok' => 1]])
        ->and($response->getContent())->not->toContain('hunter2')->not->toContain('/srv/storage')->not->toContain('tok-123');

    $warnings = array_values(array_filter($logger->records, static fn (array $record): bool => $record['level'] === 'warning'));
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['context']['code'])->toBe('concurrent_update')
        ->and($warnings[0]['context']['keys'])->toBe(['path', 'token', 'nested.secret', 'nested.SQL'])
        ->and(json_encode($warnings[0]))->not->toContain('hunter2');
});

it('does not log when the meta is clean', function (): void {
    $logger = new class extends AbstractLogger
    {
        public int $warnings = 0;

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->warnings += $level === 'warning' ? 1 : 0;
        }
    };
    $this->app->instance(LoggerInterface::class, $logger);
    $this->app->forgetInstance(ProblemRenderer::class);
    ProblemProbeRoutes::register(['limit' => FileSizeLimitExceededException::forLimit(1)]);

    $this->getJson(ProblemProbeRoutes::path('limit'))->assertStatus(413);

    expect($logger->warnings)->toBe(0);
});

it('redacts objects, nested lists and any leaf that carries the exception message', function (): void {
    $probe = new class('[Vaulter] boom path=/tmp/x') extends VaulterException implements ProblemException
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
            return [
                'context' => (object) ['secret' => 'object-secret', 'ok' => 2],
                'items' => [['token' => 'list-token-secret'], ['ok' => 1]],
                'note' => ['url' => ['[Vaulter] boom path=/tmp/x']],
            ];
        }
    };
    ProblemProbeRoutes::register(['leaky' => $probe]);

    $response = $this->getJson(ProblemProbeRoutes::path('leaky'))->assertStatus(409);

    expect($response->json('meta'))->toBe(['context' => ['ok' => 2], 'items' => [[], ['ok' => 1]], 'note' => ['url' => []]])
        ->and($response->getContent())->not->toContain('object-secret')->not->toContain('list-token-secret')->not->toContain('/tmp/x');
});

it('treats the errors key of problemDetails() case-insensitively as the field map', function (): void {
    $probe = new class('[Vaulter] hidden') extends VaulterException implements ProblemException
    {
        use ProvidesProblemDetails;

        public function problemCode(): string
        {
            return 'validation_failed';
        }

        public function httpStatus(): int
        {
            return 422;
        }

        public function problemDetails(): array
        {
            return ['Errors' => ['name' => ['[Vaulter] hidden']]];
        }
    };
    ProblemProbeRoutes::register(['mixed' => $probe]);

    $this->getJson(ProblemProbeRoutes::path('mixed'))
        ->assertStatus(422)
        ->assertJsonPath('errors', ['name' => ['The request data is invalid; see the errors field.']])
        ->assertJsonPath('meta', []);
});
