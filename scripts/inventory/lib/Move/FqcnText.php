<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Move;

/**
 * Replaces the fully qualified names of the moved types inside free text: PHP string literals, comments, docs, JSON, YAML.
 * A name matches whole: the next character is neither a name character nor a separator that continues the name
 * (`Acme\Roles` is not found in `Acme\RolesX` or `Acme\Roles\Perm`), and the previous one is not a name character.
 * Both separators are understood (`\` and the `\\` of an escaped string) and the one found is kept, as is a leading one.
 */
final class FqcnText
{
    private ?string $pattern = null;

    /** @param array<string, string> $map old FQCN => new FQCN */
    public function __construct(private readonly array $map)
    {
        if ($map === []) {
            return;
        }
        $olds = array_keys($map);
        usort($olds, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
        $alternatives = array_map(
            static fn (string $old): string => implode('\\\\{1,2}', array_map(static fn (string $s): string => preg_quote($s, '/'), explode('\\', $old))),
            $olds,
        );
        $this->pattern = '/(?<![A-Za-z0-9_\\\\])(\\\\{1,2})?(?:'.implode('|', $alternatives).')(?![A-Za-z0-9_]|\\\\{1,2}[A-Za-z_])/';
    }

    /** @param int $count incremented by the number of replaced names */
    public function replace(string $text, int &$count = 0): string
    {
        if ($this->pattern === null) {
            return $text;
        }

        return preg_replace_callback($this->pattern, function (array $m) use (&$count): string {
            $lead = $m[1] ?? '';
            $core = substr($m[0], strlen($lead));
            $double = str_contains($core, '\\\\');
            $new = $this->map[$double ? str_replace('\\\\', '\\', $core) : $core] ?? null;
            if ($new === null) {
                return $m[0];
            }
            $count++;

            return $lead.($double ? str_replace('\\', '\\\\', $new) : $new);
        }, $text) ?? $text;
    }
}
