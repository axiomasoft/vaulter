<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\Attributes\Internal;

/**
 * The request-shape check for node names in the Drive write requests: a name
 * is required, at most 255 characters, and carries no path separators (the tree is
 * adjacency-based — a name is a single segment, never a path). Kept in one place so
 * create/rename/upload/copy surface the same message.
 *
 * The drive's name policy (portable-v1 or permissive-v1) is applied by the Actions,
 * which also hold the byte limit; this only rejects obviously malformed input early.
 */
#[Internal]
final class DriveNameRules
{
    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        $pattern = '~^[^/\\\\]+$~u';

        return ['required', 'string', 'max:'.NodeName::MAX_BYTES, "regex:{$pattern}"];
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
