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
 * The length bound and separator pattern are a config seam (P4.5 / F-P3.1-01):
 * {@see Config::driveNameMaxLength()}/{@see Config::driveNameSeparatorsPattern()}
 * read `config('vaulter.drive.name_rules')` — a host with a different naming
 * policy points that config at its own values (published in `config/vaulter.php`)
 * instead of forking this file. The class stays `final` and the DEFAULT values
 * are unchanged (255 / no-slash-or-backslash) — only the source of truth moved
 * from a hardcoded constant to config, out-of-the-box behaviour is identical.
 */
final class DriveNameRules
{
    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        $maxLength = Config::driveNameMaxLength();
        $pattern = Config::driveNameSeparatorsPattern();

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
