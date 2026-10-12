<?php
declare(strict_types=1);
require getcwd().'/vendor/autoload.php';
use Illuminate\Container\Container;
use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;
$handler = new class($argv[1]) implements FileHandler {
    public function __construct(private readonly string $handlerKey) {}
    public function key(): string { return $this->handlerKey; }
    public function mimeTypes(): array { return []; }
    public function extensions(): array { return []; }
    public function priority(): int { return 0; }
    public function capabilities(FileDescriptor $file): ContentCapabilities { return ContentCapabilities::downloadOnly(); }
    public function viewer(FileDescriptor $file): ViewerDescriptor { return new ViewerDescriptor(key: 'test/viewer'); }
};
$registry = new FileHandlerRegistry(new Container);
$registry->register($handler);
echo 'registered '.$handler->key().PHP_EOL;
