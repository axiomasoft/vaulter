<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/*
 * The private add-on boundary (P3.8): the production and shipped Testing code of the private consumer package may only
 * name lower-package types (and, for static calls, constants, constructors and Internal constructors) that the surface
 * manifests publish, and every string service id it hands to the container is declared in a finite map. The scanner is
 * an AST walk over resolved names: aliases, fully qualified names and members are all seen. It proves the static edges,
 * not the PHP dynamic call graph.
 */

const PCB_ROOT = __DIR__.'/../../..';

/** @return array<string, array<string, string>> stable type => its published members => their declared value type text ('' when untyped) */
function pcbSurface(): array
{
    $surface = [];
    foreach (glob(PCB_ROOT.'/packages/*/tests/Arch/__snapshots__/surface.txt') ?: [] as $file) {
        $type = null;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line[0] !== ' ') {
                $type = strtok($line, ' ');
                $surface[$type] ??= [];

                continue;
            }
            if ($type !== null && preg_match('/(?:function\s+&?(\w+)|\$(\w+)|const\s+(?:[\w?|\\\\]+\s+)?(\w+)|case\s+(\w+))/', $line, $m) === 1) {
                $value = '';
                if (preg_match('/function\s.*\)\s*:\s*(.+)$/', $line, $r) === 1) {
                    $value = $r[1];
                } elseif (preg_match('/^\s*(?:public|protected)\s+(?:readonly\s+)?(?:static\s+)?(.+?)\s+\$\w+/', $line, $r) === 1) {
                    $value = $r[1];
                }
                $surface[$type][array_values(array_filter(array_slice($m, 1)))[0]] = $value;
            }
        }
    }

    return $surface;
}

/** @return list<string>|null the class names a surface type text names; [] for scalars; null when it says nothing (mixed/object/untyped) */
function pcbTextTypes(string $text, string $self): ?array
{
    $out = [];
    foreach (preg_split('/[|&?()\s]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
        $token = ltrim($token, '\\');
        $lower = strtolower($token);
        if (in_array($lower, ['mixed', 'object', 'callable', 'iterable'], true)) {
            return null;
        }
        if ($lower === 'static' || $lower === 'self') {
            $out[] = $self;
        } elseif (str_contains($token, '\\')) {
            $out[] = $token;
        }
    }

    return $text === '' ? null : array_values(array_unique($out));
}

/** @return list<string>|null the class names of a declared type node; [] for scalars; null when untyped or too loose to know */
function pcbNodeTypes(?Node $type, ?string $self): ?array
{
    if ($type instanceof Node\NullableType) {
        return pcbNodeTypes($type->type, $self);
    }
    if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
        $out = [];
        foreach ($type->types as $part) {
            $types = pcbNodeTypes($part, $self);
            if ($types === null) {
                return null;
            }
            $out = [...$out, ...$types];
        }

        return array_values(array_unique($out));
    }
    if ($type instanceof Node\Identifier) {
        return in_array(strtolower($type->name), ['mixed', 'object', 'callable', 'iterable'], true) ? null : (strtolower($type->name) === 'static' || strtolower($type->name) === 'self' ? ($self === null ? [] : [$self]) : []);
    }
    if ($type instanceof Node\Name) {
        $name = ($type->getAttribute('resolvedName') ?? $type)->toString();

        return in_array(strtolower($name), ['self', 'static'], true) ? ($self === null ? [] : [$self]) : (strtolower($name) === 'parent' ? [] : [$name]);
    }

    return null;
}

/** @return array<string, array{props: array<string, ?list<string>>, methods: array<string, ?list<string>>, parents: list<string>}> the declared property and return types of the classes in resolved nodes */
function pcbOwnIndex(array $nodes): array
{
    $index = [];
    foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $class) {
        if (!isset($class->namespacedName)) {
            continue;
        }
        $fqcn = $class->namespacedName->toString();
        $entry = ['props' => [], 'methods' => [], 'parents' => []];
        foreach (array_merge($class instanceof Node\Stmt\Class_ ? array_filter([$class->extends]) : [], $class instanceof Node\Stmt\Class_ ? $class->implements : ($class instanceof Node\Stmt\Interface_ ? $class->extends : [])) as $parent) {
            $entry['parents'][] = ($parent->getAttribute('resolvedName') ?? $parent)->toString();
        }
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $entry['props'][$prop->name->toString()] = pcbNodeTypes($stmt->type, $fqcn);
                }
            } elseif ($stmt instanceof Node\Stmt\ClassMethod) {
                $entry['methods'][$stmt->name->toString()] = pcbNodeTypes($stmt->returnType, $fqcn);
                foreach ($stmt->name->toString() === '__construct' ? $stmt->params : [] as $param) {
                    if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                        $entry['props'][$param->var->name] = pcbNodeTypes($param->type, $fqcn);
                    }
                }
            }
        }
        $index[$fqcn] = $entry;
    }

    return $index;
}

/** @return array the own-package type index over the declared scan roots (the receiver types the scanner can follow) */
function pcbGlobalOwn(): array
{
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (pcbFiles(pcbMap()['roots']) as $file) {
            $nodes = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? []);
            $index = [...$index, ...pcbOwnIndex($nodes)];
        }
    }

    return $index;
}

/** Visits a node tree without entering a nested scope; the visitor returns false to stop descending. */
function pcbShallow(array $nodes, callable $visit): void
{
    foreach ($nodes as $node) {
        if (!$node instanceof Node || $visit($node) === false) {
            continue;
        }
        foreach ($node->getSubNodeNames() as $sub) {
            $value = $node->$sub;
            pcbShallow(is_array($value) ? $value : [$value], $visit);
        }
    }
}

const PCB_CONTAINER_METHODS = ['make', 'makeWith', 'bind', 'bindIf', 'singleton', 'singletonIf', 'scoped', 'instance', 'alias', 'extend', 'tag', 'tagged', 'resolve', 'get', 'has', 'bound', 'app'];
const PCB_CONTAINER_TYPES = ['Illuminate\\Contracts\\Container\\Container', 'Illuminate\\Container\\Container', 'Illuminate\\Contracts\\Foundation\\Application', 'Illuminate\\Foundation\\Application', 'Psr\\Container\\ContainerInterface'];

/**
 * @return list<string> the exact violations of one source against the published surface and the declared dynamic ids.
 *                      Receiver types come from declarations (typed properties, promoted constructor parameters, parameters, returns, `new`,
 *                      assignments, surface return types); an instance call on a receiver whose type is unknown fails closed.
 */
function pcbScan(string $source, string $label, array $surface, array $declaredDynamic = [], int &$edges = 0, array &$usedDynamic = []): array
{
    $nodes = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? []);
    $finder = new NodeFinder;
    $violations = [];
    $skip = [];
    foreach ($finder->findInstanceOf($nodes, Node\Stmt\Namespace_::class) as $namespace) {
        if ($namespace->name !== null) {
            $skip[spl_object_id($namespace->name)] = true;
        }
    }
    foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
        if ($call->name instanceof Node\Name) {
            $skip[spl_object_id($call->name)] = true;
        }
    }
    $foreign = static fn (string $name): bool => str_starts_with($name, 'Vaulter\\') && !str_starts_with($name, 'Vaulter\\Collaboration\\');
    $named = static fn (mixed $name): ?string => $name instanceof Node\Name ? ($name->getAttribute('resolvedName') ?? $name)->toString() : null;
    $own = [...pcbGlobalOwn(), ...pcbOwnIndex($nodes)];

    foreach ($finder->findInstanceOf($nodes, Node\Name::class) as $name) {
        $fqcn = $named($name);
        if ($fqcn === null || isset($skip[spl_object_id($name)]) || !$foreign($fqcn)) {
            continue;
        }
        $edges++;
        if (!isset($surface[$fqcn])) {
            $violations[] = "$label: type $fqcn is not on the published surface";
        }
    }

    $memberTypes = static function (string $type, string $kind, string $member) use (&$memberTypes, $surface, $own): ?array {
        if (isset($surface[$type])) {
            return isset($surface[$type][$member]) ? pcbTextTypes($surface[$type][$member], $type) : [];
        }
        if (isset($own[$type])) {
            if (array_key_exists($member, $own[$type][$kind])) {
                return $own[$type][$kind][$member];
            }
            $external = false;
            foreach ($own[$type]['parents'] as $parent) {
                if (isset($own[$parent]) || isset($surface[$parent])) {
                    $found = $memberTypes($parent, $kind, $member);
                    if ($found !== null) {
                        return $found;
                    }
                } else {
                    $external = true;
                }
            }

            // A member neither declared nor inherited from a followed type comes from an outside base class (framework): not a Vaulter edge.
            return $external ? [] : null;
        }

        return [];
    };
    $merge = static fn (?array $a, ?array $b): ?array => $a === null || $b === null ? null : array_values(array_unique([...$a, ...$b]));
    $typeOf = static function (?Node $e, array $vars, ?string $self) use (&$typeOf, $memberTypes, $merge, $named): ?array {
        $classTypes = static fn (mixed $class): ?array => $class instanceof Node\Name ? (in_array(strtolower((string) $named($class)), ['self', 'static'], true) ? ($self === null ? null : [$self]) : [$named($class)]) : null;
        $over = static function (?array $receivers, string $kind, string $member) use ($memberTypes, $merge): ?array {
            $out = [];
            foreach ($receivers ?? [] as $receiver) {
                $out = $merge($out, $memberTypes($receiver, $kind, $member));
            }

            return $receivers === null ? null : $out;
        };
        switch (true) {
            case $e instanceof Node\Expr\Variable:
                return $e->name === 'this' ? ($self === null ? null : [$self]) : (is_string($e->name) ? ($vars[$e->name] ?? null) : null);
            case $e instanceof Node\Expr\Assign:
                return $typeOf($e->expr, $vars, $self);
            case $e instanceof Node\Expr\New_:
                return $e->class instanceof Node\Stmt\Class_ || $e->class instanceof Node\Expr ? [] : $classTypes($e->class);
            case $e instanceof Node\Expr\Clone_:
                return $typeOf($e->expr, $vars, $self);
            case $e instanceof Node\Expr\BinaryOp\Coalesce:
                return $merge($typeOf($e->left, $vars, $self), $typeOf($e->right, $vars, $self));
            case $e instanceof Node\Expr\Ternary:
                return $merge($e->if === null ? $typeOf($e->cond, $vars, $self) : $typeOf($e->if, $vars, $self), $typeOf($e->else, $vars, $self));
            case $e instanceof Node\Expr\PropertyFetch || $e instanceof Node\Expr\NullsafePropertyFetch:
                return $e->name instanceof Node\Identifier ? $over($typeOf($e->var, $vars, $self), 'props', $e->name->toString()) : null;
            case $e instanceof Node\Expr\MethodCall || $e instanceof Node\Expr\NullsafeMethodCall:
                $first = $e->args[0]->value ?? null;
                if ($e->name instanceof Node\Identifier && in_array($e->name->toString(), ['make', 'get', 'resolve'], true) && $first instanceof Node\Expr\ClassConstFetch && $first->name instanceof Node\Identifier && $first->name->toString() === 'class') {
                    return $classTypes($first->class);
                }

                return $e->name instanceof Node\Identifier ? $over($typeOf($e->var, $vars, $self), 'methods', $e->name->toString()) : null;
            case $e instanceof Node\Expr\StaticCall:
                return $e->name instanceof Node\Identifier ? $over($classTypes($e->class), 'methods', $e->name->toString()) : null;
            case $e instanceof Node\Expr\FuncCall:
                $first = $e->args[0]->value ?? null;
                if ($e->name instanceof Node\Name && in_array($e->name->toString(), ['app', 'resolve'], true) && $first instanceof Node\Expr\ClassConstFetch && $first->name instanceof Node\Identifier && $first->name->toString() === 'class') {
                    return $classTypes($first->class);
                }

                // The result of invoking a caller-supplied closure is opaque; its body is scanned where it is written.
                return $e->name instanceof Node\Expr\Variable && is_string($e->name->name) && ($vars[$e->name->name] ?? null) !== null ? [] : null;
            case $e instanceof Node\Expr\Closure || $e instanceof Node\Expr\ArrowFunction || $e instanceof Node\Scalar || $e instanceof Node\Expr\Array_ || $e instanceof Node\Expr\ConstFetch || $e instanceof Node\Expr\Cast:
                return [];
        }

        return null;
    };
    $paramVars = static function (Node\FunctionLike $fn, ?string $self): array {
        $vars = [];
        foreach ($fn->getParams() as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $vars[$param->var->name] = pcbNodeTypes($param->type, $self);
            }
        }

        return $vars;
    };
    $containerId = function (?Node $arg, string $method, int $line) use ($label, $declaredDynamic, &$usedDynamic, &$violations): void {
        if ($arg === null || $arg instanceof Node\Expr\ClassConstFetch || $arg instanceof Node\Expr\Closure) {
            return;
        }
        if (!$arg instanceof Node\Scalar\String_) {
            $violations[] = "$label: unknown dynamic target in $method() at line $line";

            return;
        }
        $usedDynamic[$arg->value] = true;
        if (!in_array(['consumer' => $label, 'target' => $arg->value], $declaredDynamic, true)) {
            $violations[] = "$label: dynamic id '{$arg->value}' in $method() is not declared";
        }
    };
    $checkMember = static function (string $type, string $member, string $arrow, int $line) use ($foreign, $surface, $label, &$edges, &$violations): void {
        if (!$foreign($type)) {
            return;
        }
        $edges++;
        if (!isset($surface[$type])) {
            $violations[] = "$label: type $type is not on the published surface (line $line)";
        } elseif (!isset($surface[$type][$member])) {
            $violations[] = "$label: member $type$arrow$member is not on the published surface";
        }
    };

    $scope = function (array $body, array $vars, ?string $self) use (&$scope, $typeOf, $paramVars, $merge, $containerId, $checkMember, $foreign, $named, $surface, $label, $declaredDynamic, &$usedDynamic, &$edges, &$violations): void {
        $assign = function () use ($body, $typeOf, $merge, $self, $vars): array {
            $current = $vars;
            for ($pass = 0; $pass < 2; $pass++) {
                $next = $vars;
                $narrow = [];
                pcbShallow($body, static function (Node $n) use (&$next, &$narrow, $current, $typeOf, $merge, $self): ?bool {
                    if ($n instanceof Node\FunctionLike || $n instanceof Node\Stmt\ClassLike) {
                        return false;
                    }
                    $target = null;
                    $types = null;
                    if ($n instanceof Node\Expr\Assign && $n->var instanceof Node\Expr\Variable && is_string($n->var->name)) {
                        $target = $n->var->name;
                        $types = $typeOf($n->expr, $current, $self);
                    } elseif ($n instanceof Node\Stmt\Catch_ && $n->var !== null && is_string($n->var->name)) {
                        $target = $n->var->name;
                        $types = array_map(static fn (Node\Name $t): string => ($t->getAttribute('resolvedName') ?? $t)->toString(), $n->types);
                    } elseif ($n instanceof Node\Expr\Instanceof_ && $n->expr instanceof Node\Expr\Variable && is_string($n->expr->name) && $n->class instanceof Node\Name) {
                        $narrow[$n->expr->name][] = ($n->class->getAttribute('resolvedName') ?? $n->class)->toString();
                    } elseif ($n instanceof Node\Stmt\Foreach_) {
                        foreach ([$n->valueVar, $n->keyVar] as $loopVar) {
                            if ($loopVar instanceof Node\Expr\Variable && is_string($loopVar->name)) {
                                $next[$loopVar->name] = null;
                            }
                        }
                    }
                    if ($target !== null) {
                        $next[$target] = array_key_exists($target, $next) ? $merge($next[$target], $types) : $types;
                    }

                    return null;
                });
                // Flow-insensitive narrowing: an `instanceof` guard on a variable replaces its declared types by the guarded classes.
                foreach ($narrow as $variable => $classes) {
                    $next[$variable] = array_values(array_unique($classes));
                }
                $current = $next;
            }

            return $current;
        };
        $vars = $assign();
        $receiver = static fn (?Node $e): ?array => $typeOf($e, $vars, $self);
        pcbShallow($body, function (Node $n) use (&$scope, $body, $vars, $self, $paramVars, $receiver, $declaredDynamic, &$usedDynamic, $containerId, $checkMember, $foreign, $named, $surface, $label, &$edges, &$violations): ?bool {
            if ($n instanceof Node\Stmt\ClassLike) {
                $class = isset($n->namespacedName) ? $n->namespacedName->toString() : null;
                foreach ($n->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod) {
                        $scope($stmt->stmts ?? [], $paramVars($stmt, $class), $class);
                    }
                }

                return false;
            }
            if ($n instanceof Node\Stmt\Function_) {
                $scope($n->stmts, $paramVars($n, null), null);

                return false;
            }
            if ($n instanceof Node\Expr\Closure) {
                $scope($n->stmts, [...$vars, ...$paramVars($n, $self)], $self);

                return false;
            }
            if ($n instanceof Node\Expr\ArrowFunction) {
                $scope([$n->expr], [...$vars, ...$paramVars($n, $self)], $self);

                return false;
            }
            $line = $n->getStartLine();
            if ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall) {
                $member = $n->name instanceof Node\Identifier ? $n->name->toString() : ($n->name instanceof Node\Scalar\String_ ? $n->name->value : null);
                if ($member === null) {
                    $violations[] = "$label: dynamic method name at line $line";

                    return null;
                }
                $types = $receiver($n->var);
                $container = in_array($member, PCB_CONTAINER_METHODS, true) && ($types === null || array_intersect($types, PCB_CONTAINER_TYPES) !== []);
                if ($container) {
                    $containerId($n->args[$member === 'tag' ? 1 : 0]->value ?? null, $member, $line);
                } elseif ($types === null) {
                    $violations[] = "$label: unknown receiver type for ->$member() at line $line";
                } else {
                    foreach ($types as $type) {
                        $checkMember($type, $member, '->', $line);
                    }
                }
            } elseif ($n instanceof Node\Expr\PropertyFetch || $n instanceof Node\Expr\NullsafePropertyFetch) {
                $types = $receiver($n->var);
                $member = $n->name instanceof Node\Identifier ? $n->name->toString() : null;
                if ($member === null && $types !== null && array_filter($types, $foreign) !== []) {
                    $violations[] = "$label: dynamic property name on a published type at line $line";
                }
                foreach ($member === null ? [] : ($types ?? []) as $type) {
                    $checkMember($type, $member, '->', $line);
                }
            } elseif ($n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\ClassConstFetch || $n instanceof Node\Expr\StaticPropertyFetch) {
                $member = $n->name instanceof Node\Identifier || $n->name instanceof Node\VarLikeIdentifier ? $n->name->toString() : ($n->name instanceof Node\Scalar\String_ ? $n->name->value : null);
                if (!$n->class instanceof Node\Name) {
                    if (!($n instanceof Node\Expr\ClassConstFetch && $member === 'class')) {
                        $violations[] = "$label: dynamic class in a static use at line $line";
                    }
                } elseif ($member === null) {
                    $violations[] = "$label: dynamic static member at line $line";
                } else {
                    $fqcn = $named($n->class);
                    if ($fqcn !== null && $foreign($fqcn) && isset($surface[$fqcn]) && $member !== 'class') {
                        $edges++;
                        if (!isset($surface[$fqcn][$member])) {
                            $violations[] = "$label: member $fqcn::$member is not on the published surface";
                        }
                    }
                    if ($n instanceof Node\Expr\StaticCall && $fqcn === 'Closure' && $member === 'fromCallable' && !(($n->args[0]->value ?? null) instanceof Node\Scalar\String_ || ($n->args[0]->value ?? null) instanceof Node\Expr\Array_)) {
                        $violations[] = "$label: dynamic callable in Closure::fromCallable at line $line";
                    }
                }
            } elseif ($n instanceof Node\Expr\New_) {
                $fqcn = $named($n->class);
                if ($n->class instanceof Node\Stmt\Class_) {
                    return null;
                }
                if ($fqcn === null) {
                    $base = $n->class instanceof Node\Expr\Variable && is_string($n->class->name) ? pcbGuardedBase($body, $n->class->name, $named) : null;
                    if ($base === null) {
                        $violations[] = "$label: dynamic class in new at line $line";
                    } else {
                        $target = "new \${$n->class->name} as $base";
                        $usedDynamic[$target] = true;
                        if (!in_array(['consumer' => $label, 'target' => $target], $declaredDynamic, true)) {
                            $violations[] = "$label: dynamic class '$target' in new is not declared";
                        }
                    }
                } elseif ($foreign($fqcn) && isset($surface[$fqcn]) && pcbInternalConstructor($fqcn)) {
                    $violations[] = "$label: constructor of $fqcn is Internal";
                }
            } elseif ($n instanceof Node\Expr\FuncCall) {
                $fn = $n->name instanceof Node\Name ? strtolower($n->name->toString()) : '';
                $first = $n->args[0]->value ?? null;
                if (in_array($fn, ['app', 'resolve'], true)) {
                    $containerId($first, $fn, $line);
                } elseif (in_array($fn, ['call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'constant'], true) && !($first instanceof Node\Scalar\String_ || $first instanceof Node\Expr\Array_ || $first instanceof Node\Expr\Closure || $first instanceof Node\Expr\ArrowFunction)) {
                    $violations[] = "$label: dynamic callable in $fn() at line $line";
                }
            } elseif ($n instanceof Node\Expr\ArrayDimFetch && $n->dim !== null) {
                $types = $receiver($n->var);
                $appLike = $n->var instanceof Node\Expr\Variable && in_array($n->var->name, ['app', 'container'], true) || $n->var instanceof Node\Expr\PropertyFetch && $n->var->name instanceof Node\Identifier && in_array($n->var->name->toString(), ['app', 'container'], true);
                if (($types !== null && array_intersect($types, PCB_CONTAINER_TYPES) !== []) || ($types === null && $appLike)) {
                    $containerId($n->dim, 'offsetGet', $line);
                }
            } elseif ($n instanceof Node\Expr\Array_ && count($n->items) === 2 && ($n->items[0]->key ?? null) === null && $n->items[1]->value instanceof Node\Scalar\String_) {
                $holder = $n->items[0]->value;
                $methodName = $n->items[1]->value->value;
                $classTypes = $holder instanceof Node\Expr\ClassConstFetch && $holder->class instanceof Node\Name && $holder->name instanceof Node\Identifier && $holder->name->toString() === 'class' ? [$named($holder->class)] : ($receiver($holder) ?? []);
                foreach ($classTypes as $type) {
                    if (preg_match('/^[A-Za-z_]\w*$/', $methodName) === 1 && $foreign((string) $type)) {
                        $checkMember((string) $type, $methodName, '::', $line);
                    }
                }
            } elseif ($n instanceof Node\Scalar\String_ && preg_match('/^\\\\?(Vaulter\\\\[A-Za-z0-9_\\\\]+?)(?:::(\w+))?$/', $n->value, $m) === 1 && $foreign($m[1])) {
                if (($m[2] ?? '') === '') {
                    $edges++;
                    if (!isset($surface[$m[1]])) {
                        $violations[] = "$label: type {$m[1]} is not on the published surface (line $line)";
                    }
                } else {
                    $checkMember($m[1], $m[2], '::', $line);
                }
            }

            return null;
        });
    };
    $scope($nodes, [], null);
    $violations = array_values(array_unique($violations));

    // A stable (#[Api]/#[Spi]) type of the scanned source must not leak a foreign non-published type in a public signature.
    foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $type) {
        $stable = array_filter($type->attrGroups, static fn ($g): bool => array_filter($g->attrs, static fn ($a): bool => in_array($a->name->getLast(), ['Api', 'Spi'], true)) !== []);
        if ($stable === []) {
            continue;
        }
        foreach ($finder->findInstanceOf($type->stmts, Node\Stmt\ClassMethod::class) as $method) {
            if (!$method->isPublic()) {
                continue;
            }
            foreach (array_merge($method->params, [$method->returnType]) as $item) {
                $typeNode = $item instanceof Node\Param ? $item->type : $item;
                foreach ($typeNode === null ? [] : $finder->findInstanceOf([$typeNode], Node\Name::class) as $name) {
                    $fqcn = $named($name);
                    if ($fqcn !== null && $foreign($fqcn) && !isset($surface[$fqcn])) {
                        $violations[] = "$label: private type $fqcn in a stable signature";
                    }
                }
            }
        }
    }

    return $violations;
}

/** The class a variable is proven to be a subclass of by an is_a()/is_subclass_of() guard in the same scope, or null. */
function pcbGuardedBase(array $body, string $variable, callable $named): ?string
{
    $base = null;
    pcbShallow($body, static function (Node $n) use ($variable, $named, &$base): ?bool {
        if ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && in_array(strtolower($n->name->toString()), ['is_a', 'is_subclass_of'], true)
            && ($n->args[0]->value ?? null) instanceof Node\Expr\Variable && ($n->args[0]->value->name ?? null) === $variable
            && ($n->args[1]->value ?? null) instanceof Node\Expr\ClassConstFetch && $n->args[1]->value->class instanceof Node\Name) {
            $base = $named($n->args[1]->value->class);
        }

        return $n instanceof Node\FunctionLike ? false : null;
    });

    return $base;
}

function pcbInternalConstructor(string $fqcn): bool
{
    foreach (['Vaulter\\Drive\\' => 'drive', 'Vaulter\\Documents\\' => 'documents', 'Vaulter\\Media\\' => 'media', 'Vaulter\\AzGuard\\' => 'azguard', 'Vaulter\\' => 'core'] as $prefix => $package) {
        if (!str_starts_with($fqcn, $prefix)) {
            continue;
        }
        $file = PCB_ROOT.'/packages/'.$package.'/src/'.str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php';
        if (!is_file($file)) {
            continue;
        }
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassMethod::class) as $method) {
            if ($method->name->toString() === '__construct') {
                foreach ($method->attrGroups as $group) {
                    foreach ($group->attrs as $attr) {
                        if ($attr->name->getLast() === 'Internal') {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    return false;
}

/** @return array{roots: list<string>, dynamic: list<array{consumer: string, target: string}>} */
function pcbMap(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/private-consumer-dependencies.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function pcbFiles(array $roots): array
{
    $files = [];
    foreach ($roots as $root) {
        if (!is_dir(PCB_ROOT.'/'.$root)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PCB_ROOT.'/'.$root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

/** @return list<string> every shipped production PHP file of the private consumer package (tests, vendor and node_modules are not shipped code) */
function pcbShippedFiles(): array
{
    $files = [];
    $base = PCB_ROOT.'/packages/collaboration';
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $f): bool => !$f->isDir() || !in_array($f->getFilename(), ['tests', 'vendor', 'node_modules'], true),
    ));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/** @return list<string> what is wrong with the scan as a whole: empty, unscanned shipped code, and stale, wildcard or duplicate declarations */
function pcbAudit(array $map, array $files, int $edges, array $used, array $surface, array $shipped): array
{
    $problems = [];
    if ($surface === []) {
        $problems[] = 'the published surface is empty';
    }
    if ($files === []) {
        $problems[] = 'the scan visited no file';
    }
    if ($edges === 0) {
        $problems[] = 'the scan found no edge';
    }
    foreach ($map['roots'] as $root) {
        if (!is_dir(PCB_ROOT.'/'.$root)) {
            $problems[] = "scan root $root does not exist";
        }
    }
    foreach (array_diff($shipped, $files) as $file) {
        $problems[] = 'shipped file is not scanned: '.ltrim(substr($file, strlen((string) realpath(PCB_ROOT))), '/');
    }
    $seen = [];
    foreach ($map['dynamic'] as $declared) {
        $key = $declared['consumer'].'|'.$declared['target'];
        if (str_contains($declared['target'], '*')) {
            $problems[] = "wildcard declaration $key";
        }
        if (!is_file(PCB_ROOT.'/'.$declared['consumer'])) {
            $problems[] = "declaration for a missing consumer $key";
        }
        if (!isset($used[$declared['target']])) {
            $problems[] = "stale declaration $key";
        }
        if (isset($seen[$key])) {
            $problems[] = "duplicate declaration $key";
        }
        $seen[$key] = true;
    }

    return $problems;
}

function pcbScanAll(array $map, array $surface, int &$edges, array &$used): array
{
    $violations = [];
    foreach (pcbFiles($map['roots']) as $file) {
        $label = ltrim(substr((string) realpath($file), strlen((string) realpath(PCB_ROOT))), '/');
        $violations = [...$violations, ...pcbScan((string) file_get_contents($file), $label, $surface, $map['dynamic'], $edges, $used)];
    }

    return $violations;
}

it('keeps every production and shipped Testing edge of the private consumer on the published surface', function (): void {
    $map = pcbMap();
    $surface = pcbSurface();
    $edges = 0;
    $used = [];
    $violations = pcbScanAll($map, $surface, $edges, $used);

    expect($violations)->toBe([])
        ->and(pcbAudit($map, pcbFiles($map['roots']), $edges, $used, $surface, pcbShippedFiles()))->toBe([]);
});

it('scans all shipped production PHP of the private consumer: src including Testing, routes, config, database and resources', function (): void {
    $map = pcbMap();
    $files = array_map(static fn (string $f): string => ltrim(substr($f, strlen((string) realpath(PCB_ROOT))), '/'), array_map('realpath', pcbFiles($map['roots'])));

    expect($map['roots'])->toContain('packages/collaboration/src', 'packages/collaboration/routes', 'packages/collaboration/config', 'packages/collaboration/database')
        ->and(array_filter($files, static fn (string $f): bool => str_starts_with($f, 'packages/collaboration/src/Testing/')))->not->toBeEmpty()
        ->and(array_filter($files, static fn (string $f): bool => str_contains($f, '/tests/') || str_contains($f, '/vendor/')))->toBe([])
        ->and(count($files))->toBe(count(pcbShippedFiles()));
});

it('rejects the negative fixtures and accepts the positive ones', function (string $file): void {
    $surface = pcbSurface();
    $source = (string) file_get_contents($file);
    $declared = [['consumer' => 'fixture', 'target' => 'vaulter.declared_key']];
    $violations = pcbScan($source, 'fixture', $surface, $declared);

    if (str_starts_with(basename($file), 'ok-')) {
        expect($violations)->toBe([]);
    } else {
        expect($violations)->not->toBe([]);
    }
})->with(static function (): array {
    // Keyed by file name: the case names stay the same on every machine.
    $fixtures = [];
    foreach (glob(__DIR__.'/Fixtures/private-consumer-boundary/*.php.stub') ?: [] as $file) {
        $fixtures[basename($file, '.php.stub')] = [$file];
    }

    return $fixtures;
});

it('audits the whole map: an empty scan, unscanned shipped code, and a stale, wildcard, duplicate or orphaned declaration fail', function (): void {
    $surface = pcbSurface();
    $map = pcbMap();
    $files = pcbFiles($map['roots']);
    $edges = 0;
    $used = [];
    pcbScanAll($map, $surface, $edges, $used);
    $consumer = 'packages/collaboration/src/Providers/CollaborationRegistrar.php';
    $audit = static fn (array $m, ?array $f = null, ?int $e = null, ?array $u = null, ?array $s = null, ?array $shipped = null): string => implode("\n", pcbAudit($m, $f ?? $files, $e ?? $edges, $u ?? $used, $s ?? $surface, $shipped ?? $files));

    expect($audit($map))->toBe('')
        ->and($audit($map, [], 0, [], [], []))->toContain('surface is empty')->toContain('no file')->toContain('no edge')
        ->and($audit($map, $files, 0))->toContain('no edge')
        ->and($audit($map, $files, $edges, $used, $surface, [...$files, '/x/packages/collaboration/routes/new.php']))->toContain('shipped file is not scanned')
        ->and($audit(['roots' => ['packages/collaboration/gone'], 'dynamic' => []]))->toContain('scan root packages/collaboration/gone does not exist')
        ->and($audit([...$map, 'dynamic' => [...$map['dynamic'], ['consumer' => $consumer, 'target' => 'vaulter.gone']]]))->toContain('stale declaration')
        ->and($audit([...$map, 'dynamic' => [...$map['dynamic'], ['consumer' => $consumer, 'target' => 'vaulter.*']]]))->toContain('wildcard declaration')
        ->and($audit([...$map, 'dynamic' => [...$map['dynamic'], ['consumer' => 'packages/collaboration/src/Gone.php', 'target' => 'config']]]))->toContain('missing consumer')
        ->and($audit([...$map, 'dynamic' => [...$map['dynamic'], $map['dynamic'][0]]]))->toContain('duplicate declaration');

    $wildcard = ['consumer' => 'f', 'target' => 'vaulter.*'];
    $app = "<?php namespace Vaulter\\Collaboration; function f(\$app) { \$app->make('vaulter.real'); }";
    expect(pcbScan($app, 'f', $surface, [$wildcard])[0] ?? '')->toContain('is not declared')
        ->and(pcbScan($app, 'f', $surface, [['consumer' => 'f', 'target' => 'vaulter.real']]))->toBe([]);
});

it('rejects a private DTO leaked through a stable signature while a published type there is accepted', function (): void {
    $surface = pcbSurface();
    $leak = '<?php namespace Vaulter\\Collaboration; use Vaulter\\Drive\\Models\\Node; #[Api] final class X { public function f(Node $n): void {} }';
    $ok = '<?php namespace Vaulter\\Collaboration; use Vaulter\\Database\\VaulterDatabase; #[Api] final class X { public function f(VaulterDatabase $n): void {} }';

    expect(implode("\n", pcbScan($leak, 'leak', $surface)))->toContain('private type Vaulter\\Drive\\Models\\Node in a stable signature')
        ->and(pcbScan($ok, 'ok', $surface))->toBe([]);
});

it('keeps the seven kernel seams allowed while the Internal scheduling callback, tier setter and constructors stay rejected', function (): void {
    $surface = pcbSurface();
    $ok = pcbScan('<?php namespace Vaulter\\Collaboration; use Vaulter\\Providers\\SchedulingRegistrar; use Vaulter\\Support\\LoadTier; use Vaulter\\Modules\\RemovalGuard; function f() { SchedulingRegistrar::task(); LoadTier::label(); }', 'ok', $surface);
    $bad = pcbScan("<?php namespace Vaulter\\Collaboration; use Vaulter\\Providers\\SchedulingRegistrar; use Vaulter\\Support\\LoadTier; use Vaulter\\Configuration\\ScheduleConfig; function f(\$a, \$c) { SchedulingRegistrar::whenScheduling(\$a, \$c); LoadTier::set('M'); LoadTier::REQUEST_ATTRIBUTE; new ScheduleConfig(\$c); }", 'bad', $surface);

    expect($ok)->toBe([])
        ->and(implode("\n", $bad))->toContain('whenScheduling')->toContain('LoadTier::set')->toContain('REQUEST_ATTRIBUTE');
});
