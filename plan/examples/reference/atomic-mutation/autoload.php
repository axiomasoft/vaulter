<?php

declare(strict_types=1);

// PSR-4 for the reference only: VaulterRef\X\Y → src/X/Y.php. Product code never loads this file.
spl_autoload_register(static function (string $class): void {
    $prefix = 'VaulterRef\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require $path;
    }
});
