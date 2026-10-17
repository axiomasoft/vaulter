<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Naming\PermissiveNamePolicy;
use Vaulter\Drive\Naming\PortableNamePolicy;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function namePolicyReason(callable $apply): ?string
{
    try {
        $apply();

        return null;
    } catch (InvalidNodeNameException $exception) {
        return $exception->reason;
    }
}

it('refuses names desktop systems cannot hold under portable-v1, each with its reason', function (string $name, string $reason): void {
    expect(namePolicyReason(fn () => (new PortableNamePolicy)->apply($name)))->toBe($reason);
})->with([
    'leading space' => [' a.txt', 'leading_or_trailing_space'],
    'trailing space' => ['a.txt ', 'leading_or_trailing_space'],
    'no-break space edge' => ["\u{00A0}a", 'leading_or_trailing_space'],
    'trailing dot' => ['a.txt.', 'trailing_dot_or_space'],
    'device name with extension' => ['CON.txt', 'reserved_name'],
    'device name any case' => ['con', 'reserved_name'],
    'device name, several extensions' => ['nul.tar.gz', 'reserved_name'],
    'COM digit' => ['COM0', 'reserved_name'],
    'LPT superscript digit' => ['LPT¹.log', 'reserved_name'],
    'desktop.ini' => ['Desktop.INI', 'reserved_name'],
    '.DS_Store' => ['.DS_Store', 'reserved_name'],
    'colon' => ['a:b', 'reserved_char'],
    'angle bracket' => ['a<b', 'reserved_char'],
    'question mark' => ['why?', 'reserved_char'],
    'pipe' => ['a|b', 'reserved_char'],
    'asterisk' => ['*.txt', 'reserved_char'],
    'double quote' => ['say "hi"', 'reserved_char'],
    'base rule: separator' => ['a/b', 'separator'],
    'base rule: empty' => ['', 'empty'],
    'base rule: too long' => [str_repeat('ж', 128), 'too_long'],
]);

it('accepts ordinary names under portable-v1 and returns them in NFC', function (): void {
    $policy = new PortableNamePolicy;

    expect($policy->key())->toBe('portable-v1')
        ->and($policy->apply('Report 2026.pdf'))->toBe('Report 2026.pdf')
        ->and($policy->apply("Re\u{0301}sume\u{0301}.pdf"))->toBe('Résumé.pdf')
        ->and($policy->apply('CONSOLE.txt'))->toBe('CONSOLE.txt')
        ->and($policy->apply('.gitignore'))->toBe('.gitignore')
        ->and($policy->apply(str_repeat('a', 255)))->toBe(str_repeat('a', 255));
});

it('keeps only the base rules under permissive-v1', function (): void {
    $policy = new PermissiveNamePolicy;

    expect($policy->key())->toBe('permissive-v1')
        ->and($policy->apply('a:b'))->toBe('a:b')
        ->and($policy->apply('CON.txt'))->toBe('CON.txt')
        ->and($policy->apply(' a.txt'))->toBe(' a.txt')
        ->and(namePolicyReason(fn () => $policy->apply('a/b')))->toBe('separator')
        ->and(namePolicyReason(fn () => $policy->apply('..')))->toBe('path_alias');
});

it('treats NFC/NFD and case variants as the same sibling name, but not the same node', function (): void {
    $drive = new Drive;
    $drive->forceFill(['owner_type' => 'team', 'owner_id' => 1, 'name' => 'Disk'])->save();
    $root = new Node;
    $root->forceFill(['drive_id' => $drive->getKey(), 'parent_id' => null, 'kind' => NodeKind::Folder, 'name' => 'root'])->save();
    $resume = new Node;
    $resume->forceFill(['drive_id' => $drive->getKey(), 'parent_id' => $root->getKey(), 'kind' => NodeKind::File, 'name' => 'Résumé.pdf'])->save();
    $report = new Node;
    $report->forceFill(['drive_id' => $drive->getKey(), 'parent_id' => $root->getKey(), 'kind' => NodeKind::File, 'name' => 'report.pdf'])->save();
    $nodes = app(NodeReadRepository::class);

    expect($nodes->siblingNameExists($drive, $root->getKey(), "Re\u{0301}sume\u{0301}.pdf"))->toBeTrue()
        ->and($nodes->siblingNameExists($drive, $root->getKey(), 'Report.pdf'))->toBeTrue()
        ->and($nodes->siblingNameExists($drive, $root->getKey(), 'REPORT.PDF'))->toBeTrue()
        // A rename of report.pdf to Report.pdf is checked without the node itself.
        ->and($nodes->siblingNameExists($drive, $root->getKey(), 'Report.pdf', ignoreId: $report->getKey()))->toBeFalse()
        ->and($nodes->siblingNameExists($drive, $root->getKey(), ' report.pdf'))->toBeFalse();
});
