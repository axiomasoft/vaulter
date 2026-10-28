<?php

declare(strict_types=1);

namespace Vaulter\Jobs;

/** @internal Bounds for the neutral, process-local publication intent. */
final class ScalarFields
{
    public static function validate(array $fields, int $maxBytes = 4096): void
    {
        if (count($fields) > 16) {
            throw new \InvalidArgumentException('Too many publication refs.');
        }
        foreach ($fields as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $key)
                || !is_string($value) || $value === '' || strlen($value) > $maxBytes
                || !mb_check_encoding($value, 'UTF-8')) {
                throw new \InvalidArgumentException('Publication refs require bounded nonempty strings.');
            }
        }
        if (strlen(json_encode($fields, JSON_THROW_ON_ERROR)) > $maxBytes) {
            throw new \InvalidArgumentException('Publication refs exceed the byte limit.');
        }
    }
}
