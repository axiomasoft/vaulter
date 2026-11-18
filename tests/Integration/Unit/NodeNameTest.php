<?php

declare(strict_types=1);

use Packman\Errors\Data\ErrorDescriptor;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Exceptions\Access\UnenumerableGrantSubjectException;
use Vaulter\Drive\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Support\NodeName;
use Vaulter\Integration\Tests\TestCase;

// The base rules every name policy builds on; nothing is trimmed.
uses(TestCase::class);

it('normalizes a valid name', function (string $given, string $expected): void {
    expect(NodeName::normalize($given))->toBe($expected);
})->with([
    'unchanged' => ['Report.pdf', 'Report.pdf'],
    'edge spaces kept, never trimmed' => ['  Plan 2026 ', '  Plan 2026 '],
    'inner whitespace kept' => ['a  b', 'a  b'],
    'NFC composition' => ["e\u{0301}", "\u{00E9}"],
    'dots inside a name' => ['...hidden..', '...hidden..'],
    'exactly 255 bytes' => [str_repeat('a', 255), str_repeat('a', 255)],
    'multibyte within the byte limit' => [str_repeat('я', 127), str_repeat('я', 127)],
]);

it('rejects an invalid name with a stable reason', function (string $given, string $reason): void {
    try {
        NodeName::normalize($given);
        $this->fail('The name was accepted.');
    } catch (InvalidNodeNameException $exception) {
        expect($exception->reason)->toBe($reason);
    }
})->with([
    'empty' => ['', 'empty'],
    'tab is a control character, not trimmed away' => ["  \t ", 'control_char'],
    'dot' => ['.', 'path_alias'],
    'dot dot' => ['..', 'path_alias'],
    'slash' => ['a/b', 'separator'],
    'backslash' => ['a\\b', 'separator'],
    'NUL' => ["a\0b", 'control_char'],
    'leading NUL' => ["\0a", 'control_char'],
    'C0 control' => ["a\x07b", 'control_char'],
    'DEL' => ["a\x7Fb", 'control_char'],
    'C1 control (NEL)' => ["a\u{0085}b", 'control_char'],
    '256 bytes' => [str_repeat('a', 256), 'too_long'],
    '256 bytes of two-byte characters' => [str_repeat('я', 128), 'too_long'],
    'invalid UTF-8' => ["a\xC3", 'reserved_char'],
]);

it('keeps " .. " as a name: only the bare aliases are refused by the base rules', function (): void {
    expect(NodeName::normalize(' .. '))->toBe(' .. ');
});

it('keys names by NFC and full case folding', function (): void {
    expect(NodeName::key('Report.PDF'))->toBe(NodeName::key('report.pdf'))
        ->and(NodeName::key("Re\u{0301}sume\u{0301}.pdf"))->toBe(NodeName::key('Résumé.pdf'))
        ->and(NodeName::key('STRASSE'))->toBe(NodeName::key('straße'))
        ->and(NodeName::key('ΣΑΣ'))->toBe(NodeName::key('σας'))
        ->and(NodeName::key(' a'))->not->toBe(NodeName::key('a'));
});

it('renders as a 422 invalid_node_name problem with the reason and the name field', function (): void {
    $exception = InvalidNodeNameException::forName('a/b', InvalidNodeNameException::SEPARATOR);

    expect($exception->httpStatus())->toBe(422)
        ->and($exception->problemCode())->toBe('invalid_node_name')
        ->and($exception->problemDetails())->toMatchArray([
            'reason' => 'separator',
            'errors' => ['name' => [$exception->getMessage()]],
        ]);
});

it('bounds ErrorDescriptor details well below 4096 bytes for oversized or control-character node names', function (): void {
    $longAscii = InvalidNodeNameException::forName(str_repeat('a', 5000), InvalidNodeNameException::TOO_LONG)->errorDescriptor();
    $longEmoji = InvalidNodeNameException::forName(str_repeat('😀', 2000), InvalidNodeNameException::TOO_LONG)->errorDescriptor();
    $conflict = NodeNameConflictException::forName(str_repeat("bad\nname", 800), str_repeat('p', 500))->errorDescriptor();
    $subject = UnenumerableGrantSubjectException::for(new Subject('role', str_repeat("id\t", 2000)), DatabasePermissionDriver::class)->errorDescriptor();

    foreach ([$longAscii, $longEmoji, $conflict, $subject] as $descriptor) {
        expect($descriptor)->toBeInstanceOf(ErrorDescriptor::class)
            ->and(strlen(json_encode($descriptor->details, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(ErrorDescriptor::MAX_DETAIL_BYTES);
    }
});
