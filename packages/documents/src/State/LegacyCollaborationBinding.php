<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Vaulter\Attributes\Internal;

/** Reports an unambiguous prerelease Yjs binding from historical facts; performs no I/O or mutation. */
#[Internal]
final class LegacyCollaborationBinding
{
    /**
     * @param  list<array{source: string, type: string, protocol: string, state_format: string, schema_version: int, generation: int}> $provenance
     * @return array{accepted: bool, binding: ?CollaborationStateBinding, reason: ?string}
     */
    public static function evaluate(string $type, mixed $schemaVersion, mixed $generation, ?string $checkpoint, bool $pending, array $provenance): array
    {
        $refuse = static fn (string $reason): array => ['accepted' => false, 'binding' => null, 'reason' => $reason];
        if (!in_array($type, ['vaulter/rich-text', 'vaulter/markdown'], true)) {
            return $refuse('unknown_historical_type');
        }
        if (!is_int($schemaVersion) || $schemaVersion < 1 || !is_int($generation) || $generation < 0 || $checkpoint === '') {
            return $refuse('ambiguous_historical_metadata');
        }
        $sources = [];
        foreach ($provenance as $fact) {
            if (!in_array($fact['source'], ['checkpoint', 'journal', 'seed'], true)
                || $fact['type'] !== $type || $fact['protocol'] !== 'yjs' || $fact['state_format'] !== 'yjs-update-v1'
                || $fact['schema_version'] !== $schemaVersion || $fact['generation'] !== $generation) {
                return $refuse('contradictory_provenance');
            }
            $sources[] = $fact['source'];
        }
        if (($checkpoint !== null && !in_array('checkpoint', $sources, true))
            || ($pending && !in_array('journal', $sources, true))
            || ($checkpoint === null && !$pending && !in_array('seed', $sources, true))
            || ($checkpoint === null && in_array('checkpoint', $sources, true))) {
            return $refuse('missing_or_ambiguous_provenance');
        }

        return ['accepted' => true, 'binding' => new CollaborationStateBinding('yjs', 'yjs-update-v1', $schemaVersion, $generation), 'reason' => null];
    }
}
