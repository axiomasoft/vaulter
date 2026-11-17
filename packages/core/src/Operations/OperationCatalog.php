<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Closure;
use InvalidArgumentException;
use Packman\Attributes\Internal;

/**
 * The policy of every mutating operation: the policies the active modules declare with
 * `ModuleBuilder::operations()`. The {@see OperationRunner} refuses a mutating operation that is not here, and
 * `vaulter:operations` prints it.
 */
#[Internal]
final class OperationCatalog
{
    /** @var array<string, array{module: string, policy: OperationPolicy}>|null */
    private ?array $entries = null;

    /**
     * @param Closure(): iterable<string, iterable<OperationPolicySource>> $sources Resolves the sources lazily, by declaring module id
     */
    public function __construct(private readonly Closure $sources) {}

    /** The policy of the operation `$name`, or null when no active module declares it. */
    public function policy(string $name): ?OperationPolicy
    {
        return $this->entries()[$name]['policy'] ?? null;
    }

    /** The module that declares the operation `$name`, or null when no active module declares it. */
    public function module(string $name): ?string
    {
        return $this->entries()[$name]['module'] ?? null;
    }

    /**
     * @return array<string, array{module: string, policy: OperationPolicy}> by operation name
     */
    public function all(): array
    {
        return $this->entries();
    }

    /**
     * @return array<string, array{module: string, policy: OperationPolicy}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        foreach (($this->sources)() as $module => $sources) {
            foreach ($sources as $source) {
                foreach ($source->policies() as $name => $policy) {
                    // The module compiler refuses such a graph; a catalogue built by hand refuses it too.
                    if (isset($entries[$name])) {
                        throw new InvalidArgumentException(sprintf('[Vaulter] The operation "%s" is declared by both the module "%s" and the module "%s".', $name, $entries[$name]['module'], $module));
                    }
                    $entries[$name] = ['module' => $module, 'policy' => $policy];
                }
            }
        }
        ksort($entries, SORT_STRING);

        return $this->entries = $entries;
    }
}
