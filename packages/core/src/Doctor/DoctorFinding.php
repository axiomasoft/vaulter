<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

/**
 * One result line of a check. Evidence carries counts, names and flags only: never a secret,
 * a connection string or a user's file name (the runner trims it to 20 short scalar entries).
 */
final readonly class DoctorFinding
{
    /** @param array<string, scalar|null> $evidence */
    public function __construct(
        public DoctorSeverity $severity,
        public string $code,
        public string $message,
        public ?string $hint = null,
        public array $evidence = [],
    ) {}

    /** @param array<string, scalar|null> $evidence */
    public static function ok(string $code, string $message, array $evidence = []): self
    {
        return new self(severity: DoctorSeverity::Ok, code: $code, message: $message, evidence: $evidence);
    }

    /** @param array<string, scalar|null> $evidence */
    public static function warning(string $code, string $message, ?string $hint = null, array $evidence = []): self
    {
        return new self(severity: DoctorSeverity::Warning, code: $code, message: $message, hint: $hint, evidence: $evidence);
    }

    /** @param array<string, scalar|null> $evidence */
    public static function error(string $code, string $message, ?string $hint = null, array $evidence = []): self
    {
        return new self(severity: DoctorSeverity::Error, code: $code, message: $message, hint: $hint, evidence: $evidence);
    }

    /** @param array<string, scalar|null> $evidence */
    public static function at(DoctorSeverity $severity, string $code, string $message, ?string $hint = null, array $evidence = []): self
    {
        return new self(severity: $severity, code: $code, message: $message, hint: $hint, evidence: $evidence);
    }
}
