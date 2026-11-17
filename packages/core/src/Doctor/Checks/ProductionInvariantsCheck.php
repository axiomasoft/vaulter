<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Vaulter\Security\ProductionInvariants;

#[Internal]
final readonly class ProductionInvariantsCheck implements DoctorCheck
{
    public function __construct(private ProductionInvariants $invariants) {}

    public function key(): string
    {
        return 'core.production_invariants';
    }

    public function title(): string
    {
        return 'Production invariants';
    }

    public function run(DoctorContext $context): array
    {
        $result = $this->invariants->evaluate();
        $findings = [];
        foreach ($result['violations'] as $id => $violation) {
            $findings[] = DoctorFinding::at(
                status: $context->strictStatus(),
                severity: $context->strictSeverity(),
                code: 'core.production_invariants.violated',
                message: sprintf('%s: %s', $id, $violation),
                remediation: 'resolve the invariant violation or document an explicit accepted risk in vaulter.security.accepted_risks',
                evidence: ['id' => $id, 'status' => 'not_accepted'],
            );
        }
        foreach ($result['accepted'] as $id => $violation) {
            $findings[] = DoctorFinding::warning(
                code: 'core.production_invariants.accepted',
                message: sprintf('%s: %s (accepted risk)', $id, $violation),
                remediation: 'review accepted risks in vaulter.security.accepted_risks periodically',
                evidence: ['id' => $id, 'status' => 'accepted'],
            );
        }
        foreach ($result['notices'] as $notice) {
            $findings[] = DoctorFinding::warning(
                code: 'core.production_invariants.accepted_risks_invalid',
                message: $notice,
                remediation: 'provide a non-empty reason string for valid invariants in vaulter.security.accepted_risks',
                evidence: ['status' => 'invalid_risk_metadata'],
            );
        }

        return $findings === [] ? [DoctorFinding::ok('core.production_invariants.ok', 'No production invariant is violated.')] : $findings;
    }
}
