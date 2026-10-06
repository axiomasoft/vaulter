<?php

declare(strict_types=1);

use Vaulter\Tests\Support\ConfigurationArchitectureGuard;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';

$violations = (new ConfigurationArchitectureGuard)->scanPackages($root.'/packages');
$retired = '/Support\\\\Config\b|GateConfig|SpatieConfig|UploadModels|DocumentsModels|vaulter-cas/';

foreach (['docs/guide', 'docs/reference', 'docs/ru'] as $scope) {
    $directory = $root.'/'.$scope;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new RuntimeException('Cannot read '.$file->getPathname());
        }
        foreach (explode("\n", $contents) as $line => $text) {
            if (preg_match($retired, $text) === 1) {
                $violations[] = $file->getPathname().':'.($line + 1).': retired helper/file in current documentation';
            }
        }
    }
}

echo $violations === [] ? "Code Guidance D24: no violations; fixture integrity verified.\n" : implode("\n", $violations)."\n";
exit($violations === [] ? 0 : 1);
