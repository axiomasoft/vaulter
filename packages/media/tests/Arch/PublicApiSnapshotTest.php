<?php

declare(strict_types=1);

use Vaulter\Media\Configuration\MediaConfig;

/** Public typed configuration API; UPDATE_SNAPSHOT is local-only. */
test('vaulter-media configuration API matches the frozen golden (P2.5)', function (): void {
    $reflection = new ReflectionClass(MediaConfig::class);
    $methods = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
            $prefix = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? '\\' : '';
            $nullable = $type !== null && $type->allowsNull() && $name !== 'mixed' ? '?' : '';
            $signature = $nullable.$prefix.$name.' $'.$parameter->getName();

            if ($parameter->isDefaultValueAvailable()) {
                $signature .= ' = '.var_export($parameter->getDefaultValue(), return: true);
            }

            $parameters[] = trim($signature);
        }

        $return = $method->getReturnType();
        $methods[] = '  '.$method->getName().'('.implode(', ', $parameters).')'
            .($return !== null ? ': '.$return : '');
    }

    sort($methods);
    $manifest = MediaConfig::class."\n".implode("\n", $methods)."\n";
    $golden = __DIR__.'/__snapshots__/public-api.txt';

    if (getenv('UPDATE_SNAPSHOT') === '1') {
        if (!is_dir(dirname($golden))) {
            mkdir(dirname($golden), 0o755, true);
        }

        file_put_contents($golden, $manifest);
    }

    expect(is_file($golden))->toBeTrue()
        ->and($manifest)->toBe(file_get_contents($golden), 'Public configuration API changed; review the intended P2.5 surface before regenerating its golden.');
});
