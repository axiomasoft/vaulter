<?php

declare(strict_types=1);

namespace Vaulter\Security;

use Illuminate\Support\Facades\Log;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Exceptions\Configuration\InsecureProductionConfiguration;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Modules\ExtensionRegistry;

/**
 * Evaluates the ProductionInvariant slot of the active modules in a stable id order. An accepted risk
 * (`vaulter.security.accepted_risks`, id => nonempty reason) is reported but not fatal; structural
 * configuration errors never reach this class.
 */
#[Internal]
final class ProductionInvariants
{
    /** Canonical Symfony command names that serve traffic or run side effects for a long time. */
    public const array GUARDED_COMMANDS = [
        'queue:work', 'queue:listen', 'horizon', 'octane:start', 'vaulter:relay', 'schedule:work', 'schedule:run',
        // Registered commands that run outward relay side effects (outbox replay to a consumer, webhook redelivery); no wildcard.
        'vaulter:relay:replay', 'vaulter:webhooks:redeliver',
    ];

    /** @var array<string, true> accepted ids already logged during this boot */
    private array $logged = [];

    /** @param array<array-key, mixed> $acceptedRisks */
    public function __construct(
        private readonly ExtensionRegistry $extensions,
        private readonly array $acceptedRisks = [],
    ) {}

    public static function guards(?string $canonicalCommand): bool
    {
        return $canonicalCommand !== null && in_array($canonicalCommand, self::GUARDED_COMMANDS, true);
    }

    /**
     * A contribution key must equal the invariant id: a mismatch is a diagnostic failure, never an accepted risk.
     *
     * @param array<string, ProductionInvariant> $byKey
     */
    public static function assertKeysMatchIds(array $byKey): void
    {
        foreach ($byKey as $key => $invariant) {
            if ($invariant->id() !== (string) $key) {
                throw new InvalidConfigurationException(sprintf('[Vaulter] ProductionInvariant contributed as "%s" must return that exact id().', $key));
            }
        }
    }

    /** @return array{violations: array<string, string>, accepted: array<string, string>, notices: list<string>} */
    public function evaluate(): array
    {
        $violations = [];
        $accepted = [];
        $notices = [];
        $byKey = [];
        foreach ($this->extensions->contributions(ProductionInvariant::class) as $contribution) {
            if ($contribution->key !== null) {
                $byKey[$contribution->key] = $this->extensions->handler(ProductionInvariant::class, $contribution->key);
            }
        }
        self::assertKeysMatchIds($byKey);
        $invariants = $this->extensions->resolve(ProductionInvariant::class);
        usort($invariants, static fn (ProductionInvariant $a, ProductionInvariant $b): int => strcmp($a->id(), $b->id()));

        $known = [];
        foreach ($invariants as $invariant) {
            $known[$invariant->id()] = true;
            $violation = $invariant->violation();
            if ($violation === null) {
                continue;
            }
            $reason = $this->acceptedRisks[$invariant->id()] ?? null;
            if (is_string($reason) && trim($reason) !== '') {
                $accepted[$invariant->id()] = $violation;

                continue;
            }
            if (array_key_exists($invariant->id(), $this->acceptedRisks)) {
                $notices[] = sprintf('accepted_risks[%s] has an empty reason and does not accept the risk.', $invariant->id());
            }
            $violations[$invariant->id()] = $violation;
        }
        foreach (array_keys($this->acceptedRisks) as $id) {
            if (!isset($known[(string) $id])) {
                $notices[] = sprintf('accepted_risks[%s] names no active production invariant.', (string) $id);
            }
        }
        ksort($violations);
        ksort($accepted);

        return ['violations' => $violations, 'accepted' => $accepted, 'notices' => $notices];
    }

    /** @throws InsecureProductionConfiguration one aggregated, secret-free failure */
    public function assertSecure(): void
    {
        $result = $this->evaluate();
        foreach ($result['accepted'] as $id => $violation) {
            if (!isset($this->logged[$id])) {
                $this->logged[$id] = true;
                Log::warning(sprintf('[Vaulter] Accepted production risk %s: %s', $id, $violation));
            }
        }
        if ($result['violations'] === []) {
            return;
        }
        $lines = [];
        foreach ($result['violations'] as $id => $violation) {
            $lines[] = sprintf('%s: %s', $id, $violation);
        }

        throw new InsecureProductionConfiguration('[Vaulter] Insecure production configuration: '.implode('; ', $lines));
    }
}
