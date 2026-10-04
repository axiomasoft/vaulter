<?php

declare(strict_types=1);

use Vaulter\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Support\NodeName;
use Vaulter\Tests\TestCase;

// NodeName reads the configured byte limit, so it needs the application container.
uses(TestCase::class);

it('normalizes a valid name', function (string $given, string $expected): void {
    expect(NodeName::normalize($given))->toBe($expected);
})->with([
    'unchanged' => ['Report.pdf', 'Report.pdf'],
    'edge whitespace trimmed' => ["  \tPlan 2026 \n", 'Plan 2026'],
    'inner whitespace kept' => ['a  b', 'a  b'],
    'NFC composition' => ["e\u{0301}", "\u{00E9}"],
    'dots inside a name' => ['...hidden..', '...hidden..'],
    'exactly 255 bytes' => [str_repeat('a', 255), str_repeat('a', 255)],
    'multibyte within the byte limit' => [str_repeat('я', 127), str_repeat('я', 127)],
]);

it('rejects an invalid name', function (string $given, string $reason): void {
    expect(fn () => NodeName::normalize($given))->toThrow(InvalidNodeNameException::class, $reason);
})->with([
    'empty' => ['', 'it is empty'],
    'whitespace only' => ["  \t ", 'it is empty'],
    'dot' => ['.', 'it is a path alias'],
    'dot dot' => [' .. ', 'it is a path alias'],
    'slash' => ['a/b', 'it contains a path separator'],
    'backslash' => ['a\\b', 'it contains a path separator'],
    'NUL' => ["a\0b", 'it contains a control character'],
    'leading NUL is not trimmed away' => ["\0a", 'it contains a control character'],
    'C0 control' => ["a\x07b", 'it contains a control character'],
    'DEL' => ["a\x7Fb", 'it contains a control character'],
    'C1 control (NEL)' => ["a\u{0085}b", 'it contains a control character'],
    '256 bytes' => [str_repeat('a', 256), 'longer than 255 bytes'],
    '256 bytes of two-byte characters' => [str_repeat('я', 128), 'longer than 255 bytes'],
    'invalid UTF-8' => ["a\xC3", 'not valid UTF-8'],
]);

it('measures the limit against the configured byte length', function (): void {
    config()->set('vaulter.drive.name_rules.max_length', 4);

    expect(NodeName::normalize('abcd'))->toBe('abcd')
        ->and(fn () => NodeName::normalize('abcde'))->toThrow(InvalidNodeNameException::class, 'longer than 4 bytes');
});

it('renders as a 422 validation error on the name field', function (): void {
    $exception = InvalidNodeNameException::forName('a/b', 'it contains a path separator');
    $response = $exception->render(request()->create('api/vaulter/x', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toMatchArray([
            'code' => 'validation_failed',
            'errors' => ['name' => [$exception->getMessage()]],
        ]);
});
