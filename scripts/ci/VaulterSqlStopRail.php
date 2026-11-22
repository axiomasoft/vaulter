<?php

declare(strict_types=1);

namespace Vaulter\Ci;

use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Node> */
final class VaulterSqlStopRail implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Larastan resolves facade methods against the forwarded object. Also
        // inspect the written facade call, whose owning source type is stable.
        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier
            && strcasecmp($scope->resolveName($node->class), DB::class) === 0 && strtolower($node->name->toString()) === 'transaction') {
            return [RuleErrorBuilder::message('Use VaulterDatabase::transaction() instead of DB::transaction().')
                ->identifier('vaulter.rawTransaction')->build()];
        }
        if (!$node instanceof String_ && !$node instanceof InterpolatedStringPart) {
            return [];
        }

        $violations = [];
        foreach ([
            'advisoryLock' => ['~\b(?:hashtext|pg_(?:try_)?advisory_(?:lock|unlock)(?:_shared|_all)?)\s*\(~i', 'Use 64-bit transaction-scoped PostgreSQL advisory locks.'],
            'tenantContext' => ["~\bset\s+(?:(?:session|local)\s+)?(?:app|vaulter)\.tenant_id\b|\bset_config\s*\([^)]*,\s*(?:false|'false'|0)\s*\)~i", 'Set PostgreSQL context only for the current transaction.'],
            'unsafeDdl' => ['~\bINITIALLY\s+DEFERRED\b|\bREPACK\s*\(\s*CONCURRENTLY\b~i', 'Use initially immediate constraints and avoid concurrent REPACK on Vaulter tables.'],
        ] as $id => [$pattern, $message]) {
            if (preg_match($pattern, $node->value) === 1) {
                $violations[] = RuleErrorBuilder::message($message)->identifier('vaulter.'.$id)->build();
            }
        }

        return $violations;
    }
}
