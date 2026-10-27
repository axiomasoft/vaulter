<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
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
            $findings[] = DoctorFinding::at($context->strictSeverity(), 'core.production_invariants.violated', sprintf('%s: %s', $id, $violation), evidence: ['id' => $id, 'status' => 'not_accepted']);
        }
        foreach ($result['accepted'] as $id => $violation) {
            $findings[] = DoctorFinding::warning('core.production_invariants.accepted', sprintf('%s: %s (accepted risk)', $id, $violation), evidence: ['id' => $id, 'status' => 'accepted']);
        }
        foreach ($result['notices'] as $notice) {
            $findings[] = DoctorFinding::warning('core.production_invariants.accepted_risks_invalid', $notice, evidence: ['status' => 'invalid_risk_metadata']);
        }

        return $findings === [] ? [DoctorFinding::ok('core.production_invariants.ok', 'No production invariant is violated.')] : $findings;
    }
}
