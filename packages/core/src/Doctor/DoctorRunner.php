<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;

/**
 * Runs the selected checks and keeps one broken check from hiding the others: an exception
 * becomes an error finding that names the exception class only, because its message can
 * carry a connection string or a secret.
 */
#[Internal]
final readonly class DoctorRunner
{
    public const int MAX_EVIDENCE_ENTRIES = 20;

    public const int MAX_EVIDENCE_LENGTH = 200;

    public function __construct(private DoctorRegistry $registry) {}

    /**
     * @param list<string> $prefixes       `--check` selection; empty runs everything
     * @param bool         $exposeMessages keep the exception message of a failed check as its detail (verbose mode); it never enters a finding
     */
    public function run(DoctorContext $context, array $prefixes = [], bool $exposeMessages = false): DoctorReport
    {
        $results = [];
        foreach ($this->registry->matching($prefixes) as $check) {
            $results[] = $this->runOne($check, $context, $exposeMessages);
        }

        return new DoctorReport(production: $context->production, generatedAt: $context->now, checks: $results);
    }

    private function runOne(DoctorCheck $check, DoctorContext $context, bool $exposeMessages): DoctorCheckResult
    {
        $key = $check->key();
        try {
            $title = $check->title();
            $findings = [];
            foreach ($check->run($context) as $finding) {
                $findings[] = $this->trimmed($finding);
            }
        } catch (Throwable $exception) {
            return new DoctorCheckResult($key, $this->titleOrKey($check), [DoctorFinding::error(
                code: 'doctor.check_failed',
                message: $exception::class,
                remediation: 'run with -v',
            )], $exposeMessages ? $exception->getMessage() : null);
        }

        if ($findings === []) {
            $findings[] = DoctorFinding::error(code: 'doctor.no_findings', message: 'The check returned no findings.', remediation: 'a check reports an ok finding when there is nothing to fix');
        }

        return new DoctorCheckResult($key, $title, $findings);
    }

    private function titleOrKey(DoctorCheck $check): string
    {
        try {
            return $check->title();
        } catch (Throwable) {
            return $check->key();
        }
    }

    private function trimmed(DoctorFinding $finding): DoctorFinding
    {
        $evidence = [];
        foreach (array_slice($finding->evidence, 0, self::MAX_EVIDENCE_ENTRIES, true) as $name => $value) {
            $evidence[$name] = self::scalar($value);
        }

        return new DoctorFinding(
            status: $finding->status,
            severity: $finding->severity,
            code: $finding->code,
            message: $finding->message,
            remediation: $finding->remediation,
            evidence: $evidence,
            slice: $finding->slice,
        );
    }

    /** Evidence is typed scalar, but a check is third-party code: anything else is replaced by its type. */
    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_EVIDENCE_LENGTH ? mb_substr($value, 0, self::MAX_EVIDENCE_LENGTH - 1).'…' : $value;
        }

        return is_scalar($value) || $value === null ? $value : get_debug_type($value);
    }
}
