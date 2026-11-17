<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Packman\Attributes\Api;

#[Api]
abstract class VaulterFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function actorId(): int|string|null
    {
        $id = $this->user()?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }

    protected function scalarId(string $key): int|string
    {
        $value = $this->input($key);

        if (is_int($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    protected function nullableScalarId(string $key): int|string|null
    {
        $value = $this->input($key);

        if ($value === null) {
            return null;
        }

        return is_int($value) ? $value : (is_scalar($value) ? (string) $value : null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function arrayInput(string $key): array
    {
        $value = $this->input($key);

        /** @var array<string, mixed> $array */
        $array = is_array($value) ? $value : [];

        return $array;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function nullableArrayInput(string $key): ?array
    {
        $value = $this->input($key);

        if ($value === null) {
            return null;
        }

        /** @var array<string, mixed> $array */
        $array = is_array($value) ? $value : [];

        return $array;
    }
}
