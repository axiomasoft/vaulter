<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

use DateTimeImmutable;
use DateTimeZone;
use Vaulter\Attributes\Internal;

#[Internal]
final readonly class DoctorReport
{
    public const string SCHEMA = 'vaulter.doctor/v1';

    /** @param list<DoctorCheckResult> $checks */
    public function __construct(public bool $production, public DateTimeImmutable $generatedAt, public array $checks) {}

    /** @return array{ok: int, warning: int, error: int} */
    public function summary(): array
    {
        $summary = ['ok' => 0, 'warning' => 0, 'error' => 0];
        foreach ($this->checks as $check) {
            foreach ($check->findings as $finding) {
                $summary[$finding->severity->value]++;
            }
        }

        return $summary;
    }

    /** Any error fails the run; warnings do not. */
    public function exitCode(): int
    {
        return $this->summary()['error'] > 0 ? 1 : 0;
    }

    /** @return array<string, mixed> the `vaulter.doctor/v1` document */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'production' => $this->production,
            'generated_at' => $this->generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'summary' => $this->summary(),
            'checks' => array_map(static fn (DoctorCheckResult $check): array => [
                'key' => $check->key,
                'title' => $check->title,
                'findings' => array_map(static fn (DoctorFinding $finding): array => [
                    'severity' => $finding->severity->value,
                    'code' => $finding->code,
                    'message' => $finding->message,
                    'hint' => $finding->hint,
                    'evidence' => (object) $finding->evidence,
                ], $check->findings),
            ], $this->checks),
            'exit_code' => $this->exitCode(),
        ];
    }
}
