<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;

/** The module's own service: it reads the module's own table through the application's connection. */
final readonly class AcmeService
{
    public function __construct(private Repository $config, private ConnectionResolverInterface $db)
    {
        AcmeProbe::resolved(self::class);
    }

    public function greeting(): string
    {
        return (string) $this->config->get('vaulter-acme.greeting');
    }

    public function add(string $body): void
    {
        $this->db->connection()->table('acme_notes')->insert(['body' => $body]);
    }

    /** @return list<string> */
    public function notes(): array
    {
        return array_values($this->db->connection()->table('acme_notes')->orderBy('id')->pluck('body')->all());
    }
}
