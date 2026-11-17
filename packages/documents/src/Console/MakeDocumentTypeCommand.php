<?php

declare(strict_types=1);

namespace Vaulter\Documents\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;

/**
 * Scaffolds a host-owned document type with an opaque JSON codec. Registration stays
 * explicit: the command prints the config line instead of editing the host's config.
 */
#[Internal]
final class MakeDocumentTypeCommand extends Command
{
    protected $signature = 'vaulter:make:document-type
        {name : The type name (StudlyCase or kebab-case), e.g. Kanban}
        {--force : Overwrite the class if it already exists}';

    protected $description = 'Scaffold a Vaulter document type';

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string $rawName */
        $rawName = $this->argument('name');
        $studly = Str::studly(preg_replace('/DocumentType$/', '', $rawName) ?? $rawName);
        $kebab = Str::kebab($studly);
        if (preg_match('/^[a-z0-9-]+$/D', $kebab) !== 1 || $studly === '') {
            $this->components->error('The type name must contain letters, digits or hyphens.');

            return self::FAILURE;
        }

        $class = $studly.'DocumentType';
        $directory = app_path('Vaulter/DocumentTypes');
        $path = sprintf('%s/%s.php', $directory, $class);
        if (!(bool) $this->option('force') && $this->files->exists($path)) {
            $this->components->error(sprintf('Document type [App\Vaulter\DocumentTypes\%s] already exists. Pass --force to overwrite.', $class));

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists($directory);
        $this->files->put($path, str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ key }}', '{{ label }}'],
            ['App\\Vaulter\\DocumentTypes', $class, 'app/'.$kebab, Str::headline($studly)],
            $this->files->get(__DIR__.'/../../stubs/document-type.stub'),
        ));

        $this->writeTest($class, 'App\\Vaulter\\DocumentTypes\\'.$class, (bool) $this->option('force'));
        $this->components->task(sprintf('Document type app/Vaulter/DocumentTypes/%s.php', $class));
        $this->newLine();
        $this->line("Register it in <info>config/vaulter-documents.php</info> under 'types':");
        $this->line(sprintf('    \App\Vaulter\DocumentTypes\%s::class,', $class));

        return self::SUCCESS;
    }

    private function writeTest(string $class, string $fqcn, bool $force): void
    {
        $path = sprintf('%s/%sTest.php', base_path('tests/Unit/Vaulter'), $class);
        if (!$force && $this->files->exists($path)) {
            $this->components->warn(sprintf('Test tests/Unit/Vaulter/%sTest.php already exists; left as is. Pass --force to overwrite.', $class));

            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, str_replace(
            ['{{ fqcn }}', '{{ class }}'],
            [$fqcn, $class],
            $this->files->get(__DIR__.'/../../stubs/document-type-test.stub'),
        ));
        $this->components->task(sprintf('Test tests/Unit/Vaulter/%sTest.php', $class));
    }
}
