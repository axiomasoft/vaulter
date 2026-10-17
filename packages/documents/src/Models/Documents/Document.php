<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Documents;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Documents\Casts\BinaryString;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Database\Factories\DocumentFactory;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Support\DocumentTypeKey;
use Vaulter\Drive\Models\Node;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Concerns\StampsDriveTenant;

/**
 * The document aggregate of a kind=document node (1:1 by node_id). It owns the
 * working state — the content every editor sees right now — and points at its head
 * checkpoint. Checkpoints are immutable {@see DocumentRevision} rows. Only
 * DocumentStateWriter writes the working state.
 *
 * @property int|string                $id
 * @property int|string|null           $tenant_id
 * @property int|string                $node_id
 * @property string                    $type
 * @property int                       $schema_version
 * @property int|string|null           $current_revision_id
 * @property int                       $revision_count
 * @property int                       $collab_epoch
 * @property int                       $state_version
 * @property int                       $checkpoint_state_version
 * @property array<string, mixed>|null $content
 * @property string|null               $content_hash
 * @property int|null                  $content_size
 * @property string|null               $yjs_state
 * @property int|null                  $yjs_state_size
 * @property Carbon|null               $last_checkpoint_at
 * @property int|string|null           $created_by
 * @property int|string|null           $updated_by
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 * @property-read Node                  $node
 * @property-read DocumentRevision|null $currentRevision
 */
#[Internal]
class Document extends Model
{
    use GuardsWrites;

    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use StampsDriveTenant;

    /**
     * Nothing is mass-assignable: every column is server-computed and written by
     * the repositories with forceFill().
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The configuration readers, handed over once by the documents service
     * provider so the model does not reach into the container. They read the
     * config repository on every call, so runtime config changes still apply.
     *
     * @var array{database: DatabaseConfig, vaulter: VaulterConfig, documents: DocumentsConfig}|null
     */
    private static ?array $configuration = null;

    public static function configure(DatabaseConfig $database, VaulterConfig $vaulter, DocumentsConfig $documents): void
    {
        self::$configuration = ['database' => $database, 'vaulter' => $vaulter, 'documents' => $documents];
    }

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }

    #[\Override]
    public function getTable(): string
    {
        return $this->table ?? self::configuration()['database']->table('documents');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(self::configuration()['vaulter']->model('node'), 'node_id');
    }

    /**
     * @return BelongsTo<DocumentRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(self::configuration()['documents']->model('document_revision'), 'current_revision_id');
    }

    /**
     * @return HasMany<DocumentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::configuration()['documents']->model('document_revision'), 'document_id');
    }

    /** Whether the working state moved past its head checkpoint. */
    public function hasUncheckpointedChanges(): bool
    {
        return $this->state_version > $this->checkpoint_state_version;
    }

    /** The 0.2 `format` label of this document's type, kept for the current JSON keys. */
    public function legacyFormat(): string
    {
        return DocumentTypeKey::toFormat($this->type);
    }

    /**
     * The working state as rich text. Reading never throws: unknown nodes and marks
     * decay away (see {@see DocumentRevision::tipTapContent()}).
     */
    public function tipTapContent(): TipTapContent
    {
        $content = $this->content;

        return is_array($content) ? TipTapContent::decayFrom($content) : TipTapContent::empty();
    }

    /** The working state as an opaque snapshot (a non-rich-text type, e.g. a workbook). */
    public function opaqueContent(): OpaqueContent
    {
        $content = $this->content;

        return OpaqueContent::fromArray(is_array($content) ? $content : []);
    }

    /**
     * @return array{database: DatabaseConfig, vaulter: VaulterConfig, documents: DocumentsConfig}
     */
    private static function configuration(): array
    {
        return self::$configuration ?? throw new LogicException('[Vaulter] The documents service provider has not booted.');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'content' => 'array',
            // Postgres returns bytea as a stream resource; normalise to a string.
            'yjs_state' => BinaryString::class,
            'schema_version' => 'integer',
            'revision_count' => 'integer',
            'collab_epoch' => 'integer',
            'state_version' => 'integer',
            'checkpoint_state_version' => 'integer',
            'content_size' => 'integer',
            'yjs_state_size' => 'integer',
            'last_checkpoint_at' => 'datetime',
        ];
    }
}
