<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Tests\Support\OpenApi\ValidateOpenApiResponses;

/**
 * Every v1 feature test is a contract test only while the validating middleware is on; this
 * pins the switch (phpunit.xml sets VAULTER_OPENAPI_VALIDATE=1) and proves the middleware
 * fails a test whose response breaks the contract.
 */
it('has the contract validation switched on in this suite', function (): void {
    expect((string) env('VAULTER_OPENAPI_VALIDATE'))->toBe('1')
        ->and(app(Kernel::class)->hasMiddleware(ValidateOpenApiResponses::class))->toBeTrue();
});

it('fails the test when a v1 response breaks the contract', function (): void {
    // A route that answers as changes.start but with a number where the contract has a string.
    Route::get('__contract/violation', static fn () => response()->json(['data' => ['cursor' => 1]]))->name('vaulter.v1.changes.start');

    expect(fn () => $this->getJson('__contract/violation'))->toThrow(AssertionFailedError::class, 'breaks the OpenAPI contract');
});

it('leaves a route outside vaulter.v1 alone', function (): void {
    Route::get('__contract/host', static fn () => response()->json(['anything' => true]))->name('host.page');

    $this->getJson('__contract/host')->assertOk();
});

it('exempts only the probe fixtures of the HTTP kernel tests', function (): void {
    expect(ValidateOpenApiResponses::isProbe('vaulter.v1.__probe'))->toBeTrue()
        ->and(ValidateOpenApiResponses::isProbe('vaulter.v1.__probe.node.show'))->toBeTrue()
        ->and(ValidateOpenApiResponses::isProbe('vaulter.v1.__probeNot'))->toBeFalse()
        ->and(ValidateOpenApiResponses::isProbe('vaulter.v1.nodes.__probe'))->toBeFalse();
});
