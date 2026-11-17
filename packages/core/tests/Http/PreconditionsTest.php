<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Exceptions\Http\PreconditionRequiredException;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Support\Preconditions;

// The kernel keeps the header parsing of the conditional requests (RFC 9110); the owner of a resource raises its own 412
// (drive: node_sequence_mismatch, documents: document_state_mismatch) when `matches()` says no.

function ifMatchRequest(?string $header): Request
{
    $request = Request::create('/');
    if ($header !== null) {
        $request->headers->set('If-Match', $header);
    }

    return $request;
}

it('matches a strong If-Match tag and refuses a weak or another one', function (string $header, bool $expected): void {
    expect(Preconditions::matches(ifMatchRequest($header), '"abc"'))->toBe($expected);
})->with([
    'the current tag' => ['"abc"', true],
    'one of a list' => ['"zzz", "abc"', true],
    'another tag' => ['"zzz"', false],
    'a weak tag of the current one' => ['W/"abc"', false],
    'a comma inside quotes stays in its tag' => ['"a,b", "abc"', true],
]);

it('requires the header: missing is 428, and so is the wildcard', function (?string $header): void {
    expect(fn (): bool => Preconditions::matches(ifMatchRequest($header), '"abc"'))->toThrow(PreconditionRequiredException::class);
})->with(['missing' => [null], 'blank' => ['  '], 'wildcard' => ['*']]);

it('answers 304 for a weak match of If-None-Match, the wildcard included', function (?string $header, bool $expected): void {
    $request = Request::create('/');
    if ($header !== null) {
        $request->headers->set('If-None-Match', $header);
    }

    expect(Preconditions::notModified($request, '"abc"'))->toBe($expected);
})->with([
    'the current tag' => ['"abc"', true],
    'its weak form' => ['W/"abc"', true],
    'the wildcard' => ['*', true],
    'another tag' => ['"zzz"', false],
    'no header' => [null, false],
]);

it('no longer knows a node or a document: the owners raise their own refusal', function (): void {
    expect(method_exists(Preconditions::class, 'matchNode'))->toBeFalse()
        ->and(method_exists(Preconditions::class, 'matchDocument'))->toBeFalse()
        ->and(method_exists(PageResponse::class, 'listOptions'))->toBeFalse();
});
