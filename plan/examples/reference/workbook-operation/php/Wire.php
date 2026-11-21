<?php

declare(strict_types=1);

/**
 * PHP half of the private wire contract (plan/spec/implementation/sheets-runtime-wire.md).
 * Target: packages/sheets/src/Runtime/StrictJson.php and src/Runtime/CanonicalJson.php.
 * PHP 8.4, stdlib + mbstring only.
 */

namespace Vaulter\Reference\Workbook;

final class WireException extends \RuntimeException
{
    public function __construct(public readonly string $wireCode, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * Strict decoder: rejects duplicate keys and integer literals that cannot be represented
 * without loss (beyond ±(2^53−1) unless the token is the exact ES rendering of a double), keeps
 * the difference between {} (stdClass) and [] (list). json_decode() alone would keep the
 * last duplicate key silently.
 */
final class StrictJson
{
    private const int MAX_SAFE = 9007199254740991;

    private int $i = 0;

    private function __construct(private readonly string $s)
    {
    }

    public static function decode(string $text): mixed
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new WireException('invalid_message', 'message is not valid UTF-8');
        }
        $parser = new self($text);
        $value = $parser->value(0);
        $parser->ws();
        if ($parser->i !== strlen($text)) {
            $parser->fail('trailing data');
        }
        return $value;
    }

    private function fail(string $why): never
    {
        throw new WireException('invalid_message', "{$why} at offset {$this->i}");
    }

    private function ws(): void
    {
        $this->i += strspn($this->s, " \t\n\r", $this->i);
    }

    private function string(): string
    {
        if (preg_match('/"(?:[^"\\\\\x00-\x1f]|\\\\(?:["\\\\\/bfnrt]|u[0-9a-fA-F]{4}))*"/A', $this->s, $m, 0, $this->i) !== 1) {
            $this->fail('invalid string');
        }
        $this->i += strlen($m[0]);
        try {
            return json_decode($m[0], false, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('invalid string escape');
        }
    }

    private function value(int $depth): mixed
    {
        if ($depth > 256) {
            $this->fail('nesting too deep');
        }
        $this->ws();
        $c = $this->s[$this->i] ?? '';
        if ($c === '{') {
            $this->i++;
            $out = new \stdClass();
            $seen = [];
            $this->ws();
            if (($this->s[$this->i] ?? '') === '}') {
                $this->i++;
                return $out;
            }
            while (true) {
                $this->ws();
                if (($this->s[$this->i] ?? '') !== '"') {
                    $this->fail('expected key');
                }
                $key = $this->string();
                if (isset($seen[$key])) {
                    $this->fail('duplicate key ' . json_encode($key, JSON_UNESCAPED_UNICODE));
                }
                $seen[$key] = true;
                $this->ws();
                if (($this->s[$this->i++] ?? '') !== ':') {
                    $this->fail('expected colon');
                }
                $out->{$key} = $this->value($depth + 1);
                $this->ws();
                $sep = $this->s[$this->i++] ?? '';
                if ($sep === '}') {
                    return $out;
                }
                if ($sep !== ',') {
                    $this->fail('expected comma');
                }
            }
        }
        if ($c === '[') {
            $this->i++;
            $out = [];
            $this->ws();
            if (($this->s[$this->i] ?? '') === ']') {
                $this->i++;
                return $out;
            }
            while (true) {
                $out[] = $this->value($depth + 1);
                $this->ws();
                $sep = $this->s[$this->i++] ?? '';
                if ($sep === ']') {
                    return $out;
                }
                if ($sep !== ',') {
                    $this->fail('expected comma');
                }
            }
        }
        if ($c === '"') {
            return $this->string();
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $word => $literal) {
            if (substr_compare($this->s, $word, $this->i, strlen($word)) === 0) {
                $this->i += strlen($word);
                return $literal;
            }
        }
        if (preg_match('/-?(?:0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?/A', $this->s, $m, 0, $this->i) !== 1) {
            $this->fail('unexpected token');
        }
        $this->i += strlen($m[0]);
        $isInteger = ($m[1] ?? '') === '' && ($m[2] ?? '') === '';
        if ($isInteger) {
            $digits = ltrim($m[0], '-');
            if (strlen($digits) <= 16 && (int) $digits <= self::MAX_SAFE) {
                return (int) $m[0];
            }
            // Same rule as Node: beyond 2^53-1 only the exact ES rendering of a double is accepted.
            $float = (float) $m[0];
            if (!is_finite($float) || CanonicalJson::encode($float) !== $m[0]) {
                $this->fail('integer outside safe range');
            }
            return $float;
        }
        $float = (float) $m[0];
        if (!is_finite($float)) {
            $this->fail('non-finite number');
        }
        return $float;
    }
}

/** RFC 8785 JSON Canonicalization Scheme, byte-identical with the Node side. */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => self::integer($value),
            is_float($value) => self::number($value),
            is_string($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR),
            is_array($value) && array_is_list($value) => '[' . implode(',', array_map(self::encode(...), $value)) . ']',
            $value instanceof \stdClass => self::object(get_object_vars($value)),
            default => throw new WireException('invalid_message', 'value cannot be canonicalized (associative PHP array or resource)'),
        };
    }

    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function integer(int $value): string
    {
        if (abs($value) > 9007199254740991) {
            throw new WireException('invalid_message', 'integer outside safe range');
        }
        return (string) $value;
    }

    /** @param array<string, mixed> $members */
    private static function object(array $members): string
    {
        $keys = array_map('strval', array_keys($members));
        // RFC 8785 §3.2.3: order by UTF-16 code units; big-endian UTF-16 bytes compare the same way.
        usort($keys, static fn (string $a, string $b): int => strcmp(mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'), mb_convert_encoding($b, 'UTF-16BE', 'UTF-8')));
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = self::encode($key) . ':' . self::encode($members[$key]);
        }
        return '{' . implode(',', $parts) . '}';
    }

    /** ECMAScript Number::toString: shortest round-trip digits, ES exponent rules. */
    private static function number(float $value): string
    {
        if (!is_finite($value)) {
            throw new WireException('invalid_message', 'non-finite number');
        }
        if ($value == 0.0) {
            return '0';
        }
        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);
        for ($precision = 1; $precision <= 17; $precision++) {
            $repr = sprintf('%.' . ($precision - 1) . 'e', $abs);
            if ((float) $repr === $abs) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $repr);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        $k = strlen($digits);
        $n = (int) $exponent + 1;
        if ($k <= $n && $n <= 21) {
            return $sign . $digits . str_repeat('0', $n - $k);
        }
        if (0 < $n && $n <= 21) {
            return $sign . substr($digits, 0, $n) . '.' . substr($digits, $n);
        }
        if (-6 < $n && $n <= 0) {
            return $sign . '0.' . str_repeat('0', -$n) . $digits;
        }
        $e = $n - 1;
        $exp = 'e' . ($e < 0 ? '-' : '+') . abs($e);
        return $sign . ($k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1)) . $exp;
    }
}
