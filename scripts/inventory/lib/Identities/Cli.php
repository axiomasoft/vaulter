<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Identities;

use Illuminate\Console\Parser;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use Vaulter\Inventory\AstFiles;
use Vaulter\Inventory\Identities;
use Vaulter\Inventory\IdentityOwners;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\SourceIndex;

require_once dirname(__DIR__).'/Identities.php';

/**
 * identities/cli.json: every artisan command class of packages/*\/src with its arguments and options. The
 * `$signature` goes through `Illuminate\Console\Parser::parse()`; a command without one (`$name` + `configure()`) is
 * recorded as `parse: manual`. Exit codes are the `self::SUCCESS|FAILURE|INVALID` constants a class uses.
 * The owner comes from the rules; when the type-map owns the class elsewhere the item says so (`class_owner`).
 *
 * @phpstan-import-type Built from Identities
 */
final class Cli
{
    private const COMMAND_BASES = ['Illuminate\Console\Command', 'Illuminate\Console\GeneratorCommand'];

    private const EXIT_CONSTANTS = ['SUCCESS', 'FAILURE', 'INVALID'];

    /** @return Built */
    public static function build(Repo $repo): array
    {
        $index = SourceIndex::forRepo($repo);
        $items = [];
        $problems = [];

        foreach ($index->declarations() as $declaration) {
            if (!in_array($declaration['kind'], ['class', 'final-class'], true) || !self::isCommand($index, $declaration['fqcn'])) {
                continue;
            }
            $facts = self::facts($repo, $declaration['file'], $declaration['fqcn']);
            try {
                $item = self::item($declaration['fqcn'], $declaration['file'], $facts);
            } catch (Throwable $e) {
                $problems[] = ['code' => 'unparsable-signature', 'message' => "cli: {$declaration['fqcn']}: {$e->getMessage()}", 'exit' => 1];

                continue;
            }
            $items[] = $item;
        }

        $built = Identities::assemble('cli', $items, IdentityOwners::load($repo, 'cli'), [], $problems);

        return Identities::withClassOwners($built, $repo, 'class');
    }

    private static function isCommand(SourceIndex $index, string $fqcn): bool
    {
        $current = $fqcn;
        for ($depth = 0; $depth < 20; $depth++) {
            $declaration = $index->declaration($current);
            if ($declaration === null) {
                return in_array($current, self::COMMAND_BASES, true);
            }
            if ($declaration['extends'] === [] || $declaration['kind'] === 'interface') {
                return false;
            }
            $current = $declaration['extends'][0];
        }

        return false;
    }

    /**
     * @return array{signature: ?string, name: ?string, description: ?string, hidden: bool, exit_codes: list<string>}
     */
    private static function facts(Repo $repo, string $file, string $fqcn): array
    {
        $facts = ['signature' => null, 'name' => null, 'description' => null, 'hidden' => false, 'exit_codes' => []];
        AstFiles::walk(AstFiles::parse($repo, $file), static function (Node $node, string $symbol) use (&$facts, $fqcn): void {
            if ($node instanceof Node\Stmt\Property && explode('::', $symbol)[0] === $fqcn) {
                foreach ($node->props as $prop) {
                    $value = $prop->default;
                    match ($prop->name->toString()) {
                        'signature' => $facts['signature'] = $value instanceof Scalar\String_ ? $value->value : $facts['signature'],
                        'name' => $facts['name'] = $value instanceof Scalar\String_ ? $value->value : $facts['name'],
                        'description' => $facts['description'] = $value instanceof Scalar\String_ ? $value->value : $facts['description'],
                        'hidden' => $facts['hidden'] = $value instanceof Expr\ConstFetch && strtolower($value->name->toString()) === 'true',
                        default => null,
                    };
                }
            }
            if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name && in_array($node->class->toString(), ['self', 'static'], true)
                && $node->name instanceof Node\Identifier && in_array($node->name->toString(), self::EXIT_CONSTANTS, true) && explode('::', $symbol)[0] === $fqcn) {
                $facts['exit_codes'][] = $node->name->toString();
            }
        });
        $facts['exit_codes'] = array_values(array_unique($facts['exit_codes']));
        sort($facts['exit_codes'], SORT_STRING);

        return $facts;
    }

    /**
     * @param  array{signature: ?string, name: ?string, description: ?string, hidden: bool, exit_codes: list<string>} $facts
     * @return array<string, mixed>
     */
    private static function item(string $fqcn, string $file, array $facts): array
    {
        $arguments = [];
        $options = [];
        if ($facts['signature'] !== null) {
            [$name, $parsedArguments, $parsedOptions] = Parser::parse($facts['signature']);
            $parse = 'signature';
            foreach ($parsedArguments as $argument) {
                /** @var InputArgument $argument */
                $arguments[] = ['name' => $argument->getName(), 'required' => $argument->isRequired(), 'array' => $argument->isArray(), 'default' => $argument->getDefault(), 'description' => $argument->getDescription()];
            }
            foreach ($parsedOptions as $option) {
                /** @var InputOption $option */
                $options[] = ['name' => $option->getName(), 'shortcut' => $option->getShortcut(), 'accepts_value' => $option->acceptValue(), 'value_required' => $option->isValueRequired(), 'array' => $option->isArray(), 'default' => $option->getDefault(), 'description' => $option->getDescription()];
            }
        } elseif ($facts['name'] !== null) {
            $name = $facts['name'];
            $parse = 'manual';
        } else {
            throw new InventoryError("{$fqcn} declares neither \$signature nor \$name");
        }

        return [
            'id' => 'command:'.$name,
            'source' => ['file' => $file, 'symbol' => $fqcn],
            'class' => $fqcn,
            'parse' => $parse,
            'description' => $facts['description'],
            'hidden' => $facts['hidden'],
            'arguments' => $arguments,
            'options' => $options,
            'exit_codes' => $facts['exit_codes'],
        ];
    }
}
