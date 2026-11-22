<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Documents;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * @property int|string                $document_id
 * @property array<string, mixed>|null $content
 * @property string|null               $content_hash
 * @property int|null                  $content_size
 */
#[Internal]
final class DocumentPayload extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    protected $primaryKey = 'document_id';

    public $incrementing = false;

    public $timestamps = false;

    private static ?string $configuredTable = null;

    public static function configure(DatabaseOptions $database): void
    {
        self::$configuredTable = $database->table('document_payloads');
    }

    #[Override]
    public function getTable(): string
    {
        return $this->table ?? self::$configuredTable ?? throw new LogicException('[Vaulter] The documents service provider has not booted.');
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'content_size' => 'integer',
        ];
    }
}
