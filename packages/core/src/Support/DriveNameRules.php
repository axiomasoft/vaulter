<?php

declare(strict_types=1);

namespace Vaulter\Support;

/**
 * The shared node-name validation contract for the Drive write requests (§2.2):
 * a name is required, bounded in length, and must carry no path separators (the
 * tree is adjacency-based — a name is a single segment, never a path). Kept in
 * one place so create/rename/upload/copy enforce the exact same rule and surface
 * the same message.
 */
final class DriveNameRules
{
    /**
     * The reject pattern: any forward/back slash makes a name a path, not a
     * segment. The leading `!` negates the regex match for Laravel's `regex` rule.
     */
    private const string NO_SEPARATORS = 'regex:/^[^\/\\\\]+$/';

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:255', self::NO_SEPARATORS];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.regex' => 'The name must not contain path separators (/ or \\).',
        ];
    }
}
