<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Vaulter\Drive\Support\DriveNameRules;

/*
 * DriveNameRules' length bound and separator pattern moved
 * from a hardcoded constant to `config('vaulter.drive.name_rules')`; the reject
 * message moved from an inline literal to the `vaulter::validation` lang
 * namespace. These guards pin BOTH the new extensibility AND that the default
 * (unconfigured) behaviour is byte-identical to the earlier hardcode.
 */

it('rejects the current default max length (255) unchanged, characterisation guard', function (): void {
    $validator = Validator::make(
        ['name' => str_repeat('a', 256)],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    expect($validator->fails())->toBeTrue();
});

it('accepts a name at the current default max length (255) unchanged, characterisation guard', function (): void {
    $validator = Validator::make(
        ['name' => str_repeat('a', 255)],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    expect($validator->fails())->toBeFalse();
});

it('ignores the deprecated host max_length and keeps the fixed byte bound', function (): void {
    config()->set('vaulter.drive.name_rules.max_length', 10);
    config()->set('vaulter-drive.names.max_length', 10);

    $validator = Validator::make(
        ['name' => str_repeat('a', 11)],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    expect($validator->fails())->toBeFalse();
});

it('keeps separators fixed independently of a host naming length', function (): void {
    config()->set('vaulter.drive.name_rules.max_length', 10);

    $validator = Validator::make(
        ['name' => 'foo/bar'],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    expect($validator->fails())->toBeTrue();
});

it('still rejects a path-separator name under the unconfigured default pattern, characterisation guard', function (): void {
    $validator = Validator::make(
        ['name' => 'foo/bar'],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    expect($validator->fails())->toBeTrue();
});

it('resolves the reject message through the vaulter:: lang namespace, not an inline literal', function (): void {
    $messages = DriveNameRules::messages();

    expect($messages['name.regex'])->toBe(trans('vaulter::validation.name_no_separators'))
        ->and($messages['name.regex'])->toBe('The name must not contain path separators (/ or \\).');
});

it('surfaces a host-overridden lang string for the reject message', function (): void {
    app('translator')->addLines(
        ['validation.name_no_separators' => 'Названия не могут содержать разделители пути.'],
        'en',
        'vaulter',
    );

    $messages = DriveNameRules::messages();

    expect($messages['name.regex'])->toBe('Названия не могут содержать разделители пути.');
});
