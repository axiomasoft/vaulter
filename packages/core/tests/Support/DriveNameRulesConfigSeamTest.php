<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Vaulter\Support\DriveNameRules;

/*
 * P4.5 / F-P3.1-01/02 — DriveNameRules' length bound and separator pattern moved
 * from a hardcoded constant to `config('vaulter.drive.name_rules')`; the reject
 * message moved from an inline literal to the `vaulter::validation` lang
 * namespace. These guards pin BOTH the new extensibility AND that the default
 * (unconfigured) behaviour is byte-identical to the pre-P4.5 hardcode.
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

it('honours a host-configured max_length instead of the hardcoded 255, F-P3.1-01', function (): void {
    config(['vaulter.drive.name_rules.max_length' => 10]);

    $validator = Validator::make(
        ['name' => str_repeat('a', 11)],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('name'))->not->toBeNull();

    $validatorWithinBound = Validator::make(
        ['name' => str_repeat('a', 10)],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    expect($validatorWithinBound->fails())->toBeFalse();
});

it('honours a host-configured separator pattern instead of the hardcoded default, F-P3.1-01', function (): void {
    // A host that (unusually) wants to forbid the letter "x" instead of slashes —
    // proves the pattern is read from config, not the private constant.
    config(['vaulter.drive.name_rules.no_separators_pattern' => '/^[^x]+$/']);

    $validator = Validator::make(
        ['name' => 'foo/bar'],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    // Under the reconfigured pattern, a forward slash is now ALLOWED (only "x" is
    // rejected) — this only passes once rules() actually reads the config key.
    expect($validator->fails())->toBeFalse();

    $validatorRejectsX = Validator::make(
        ['name' => 'xylophone'],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    expect($validatorRejectsX->fails())->toBeTrue();
});

it('still rejects a path-separator name under the unconfigured default pattern, characterisation guard', function (): void {
    $validator = Validator::make(
        ['name' => 'foo/bar'],
        ['name' => DriveNameRules::rules()],
        DriveNameRules::messages(),
    );

    expect($validator->fails())->toBeTrue();
});

it('resolves the reject message through the vaulter:: lang namespace, not an inline literal, F-P3.1-02', function (): void {
    $messages = DriveNameRules::messages();

    expect($messages['name.regex'])->toBe(trans('vaulter::validation.name_no_separators'))
        ->and($messages['name.regex'])->toBe('The name must not contain path separators (/ or \\).');
});

it('surfaces a host-overridden lang string for the reject message, F-P3.1-02', function (): void {
    app('translator')->addLines(
        ['validation.name_no_separators' => 'Названия не могут содержать разделители пути.'],
        'en',
        'vaulter',
    );

    $messages = DriveNameRules::messages();

    expect($messages['name.regex'])->toBe('Названия не могут содержать разделители пути.');
});
