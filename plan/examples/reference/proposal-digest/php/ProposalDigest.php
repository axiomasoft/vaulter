<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Protocol;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Reference of AI Core `src/Protocol/ProposalDigest.php` (plan/spec/implementation/ai.md, 3.2a/3.3a).
 * PHP 8.4 stdlib + mbstring. Input values come from json_decode(..., false): JSON objects are
 * stdClass (so {} and {"0":..} stay objects), arrays are lists.
 *
 * Office v1 digest profile of RFC 8785: strings (valid UTF-8), booleans, null, safe integers by
 * value (27.0 → "27"), objects, lists. Any other number is rejected instead of being formatted;
 * v1 records never contain one. Members are ordered by UTF-16 code units.
 */
final class ProposalDigest
{
    public const MAX_SAFE_INTEGER = 9007199254740991;

    public static function record(stdClass $prepareInput, stdClass $prepareResult): stdClass
    {
        $plan = clone $prepareResult;
        unset($plan->digest);

        return (object) ['version' => 1, 'prepare_input' => $prepareInput, 'plan' => $plan];
    }

    public static function digest(stdClass $prepareInput, stdClass $prepareResult): string
    {
        return hash('sha256', self::canonicalize(self::record($prepareInput, $prepareResult)));
    }

    public static function canonicalize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => self::integer($value),
            is_float($value) => self::integralFloat($value),
            is_string($value) => self::string($value),
            is_array($value) => self::list($value),
            $value instanceof stdClass => self::object($value),
            default => throw new InvalidArgumentException('JCS: unsupported value ' . get_debug_type($value)),
        };
    }

    private static function integer(int $value): string
    {
        if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException("JCS profile: integer outside ±(2^53-1): $value");
        }

        return (string) $value;
    }

    private static function integralFloat(float $value): string
    {
        if (!is_finite($value) || floor($value) !== $value || abs($value) > self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException('JCS profile: non-integer or unsafe number');
        }

        return self::integer((int) $value); // -0.0 → "0"
    }

    private static function string(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('JCS: string is not valid UTF-8');
        }
        $short = ["\x08" => '\b', "\t" => '\t', "\n" => '\n', "\x0c" => '\f', "\r" => '\r', '"' => '\"', '\\' => '\\\\'];

        // Only " \ and U+0000..U+001F are escaped; '/', DEL, U+2028/U+2029 and non-ASCII stay raw.
        return '"' . preg_replace_callback('/[\x00-\x1f"\\\\]/', static fn (array $m): string => $short[$m[0]]
            ?? sprintf('\u%04x', ord($m[0])), $value) . '"';
    }

    private static function list(array $value): string
    {
        if (!array_is_list($value)) {
            throw new InvalidArgumentException('JCS: PHP array with keys; decode JSON objects as stdClass');
        }

        return '[' . implode(',', array_map(self::canonicalize(...), $value)) . ']';
    }

    private static function object(stdClass $value): string
    {
        $members = [];
        foreach (get_object_vars($value) as $key => $item) {
            $members[(string) $key] = $item; // numeric names come back as int keys
        }
        $keys = array_map('strval', array_keys($members));
        usort($keys, static fn (string $a, string $b): int => strcmp(
            mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'),
        ));

        return '{' . implode(',', array_map(
            static fn (string $k): string => self::string($k) . ':' . self::canonicalize($members[$k]),
            $keys,
        )) . '}';
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $vectors = json_decode((string) file_get_contents($argv[1] ?? __DIR__ . '/../vectors.json'), false, 512, JSON_THROW_ON_ERROR);
    $failures = 0;
    $report = static function (bool $ok, string $id) use (&$failures): void {
        $failures += $ok ? 0 : 1;
        fwrite(STDERR, ($ok ? 'ok   ' : 'FAIL ') . "php $id\n");
    };
    foreach ($vectors->jcs as $v) {
        try {
            $text = ProposalDigest::canonicalize(json_decode($v->input_json, false, 512, JSON_THROW_ON_ERROR));
            $ok = $v->profile === 'accept' && $text === $v->jcs && bin2hex($text) === $v->utf8_hex && hash('sha256', $text) === $v->sha256;
        } catch (InvalidArgumentException|JsonException) {
            $ok = $v->profile === 'reject';
        }
        $report($ok, $v->id);
    }
    foreach ($vectors->proposals as $p) {
        $input = $p->prepare_request->input;
        $result = $p->prepare_response->result;
        $text = ProposalDigest::canonicalize(ProposalDigest::record($input, $result));
        $permuted = ProposalDigest::canonicalize(json_decode($p->permuted_record_json, false, 512, JSON_THROW_ON_ERROR));
        $report($text === $p->canonical_record && $permuted === $text
            && ProposalDigest::digest($input, $result) === $p->digest && $result->digest === $p->digest, $p->id);
    }
    exit($failures === 0 ? 0 : 1);
}
