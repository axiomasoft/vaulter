<?php

declare(strict_types=1);

namespace Vaulter\Support;

/**
 * The shared node-name validation contract for the Drive write requests (§2.2):
 * a name is required, bounded in length, and must carry no path separators (the
 * tree is adjacency-based — a name is a single segment, never a path). Kept in
 * one place so create/rename/upload/copy enforce the exact same rule and surface
 * the same message.
 *
 * The owning package passes its length bound. Separators remain a fixed tree invariant.
 */
final class DriveNameRules
{
    /**
     * @return list<string>
     */
    public static function rules(int $maxLength = 255): array
    {
        $pattern = '~^[^/\\\\]+$~u';

        return ['required', 'string', "max:{$maxLength}", "regex:{$pattern}"];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.regex' => trans('vaulter::validation.name_no_separators'),
        ];
    }
}
