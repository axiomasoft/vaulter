<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor\Checks;

use Illuminate\Database\Query\Builder;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorSeverity;
use Vaulter\Support\Identity;

/** Samples the structural invariants of the tree and its aggregates: at most --sample rows per invariant. */
#[Internal]
final readonly class SchemaIntegrityCheck implements DoctorCheck
{
    public function __construct(private VaulterDatabase $database, private DatabaseConfig $tables) {}

    public function key(): string
    {
        return 'core.schema.integrity';
    }

    public function title(): string
    {
        return 'Tree and aggregate integrity (sampled)';
    }

    public function run(DoctorContext $context): array
    {
        $limit = $context->sampleLimit;
        $broken = [
            'i1_drives_without_single_root' => $this->drivesWithoutSingleRoot($limit),
            'i5_file_nodes_without_file' => $this->orphans('nodes', 'files', 'node_id', 'file', $limit),
            'i5_document_nodes_without_document' => $this->orphans('nodes', 'documents', 'node_id', 'document', $limit),
            'i5_files_on_other_kinds' => $this->misplaced('files', 'file', $limit),
            'i5_documents_on_other_kinds' => $this->misplaced('documents', 'document', $limit),
            'i6_files_with_foreign_head' => $this->foreignHead('files', 'file_revisions', 'file_id', $limit),
            'i6_documents_with_foreign_head' => $this->foreignHead('documents', 'document_revisions', 'document_id', $limit),
        ];
        $broken = array_filter($broken);

        if ($broken === []) {
            return [DoctorFinding::ok(code: 'core.schema.integrity_ok', message: 'No broken invariant in the sample.', evidence: ['sample' => $limit])];
        }

        return [DoctorFinding::at(
            severity: DoctorSeverity::Warning,
            code: 'core.schema.integrity_broken',
            message: count($broken).' invariant(s) are broken in the sampled rows.',
            hint: 'the counts are lower bounds (at most --sample rows per invariant); inspect the listed rows before repairing',
            evidence: ['sample' => $limit, ...$broken],
        )];
    }

    /** I1: a drive has exactly one node without a parent. */
    private function drivesWithoutSingleRoot(int $limit): int
    {
        $db = $this->database->connection();
        $ids = $db->table($this->tables->table('drives'))->orderBy('id')->limit($limit)->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }
        $roots = [];
        foreach ($db->table($this->tables->table('nodes'))->whereIn('drive_id', $ids)->whereNull('parent_id')
            ->groupBy('drive_id')->selectRaw('drive_id, count(*) as roots')->get() as $row) {
            $roots[Identity::stringify($row->drive_id)] = is_numeric($row->roots) ? (int) $row->roots : 0;
        }

        $broken = 0;
        foreach ($ids as $id) {
            if (($roots[Identity::stringify($id)] ?? 0) !== 1) {
                $broken++;
            }
        }

        return $broken;
    }

    /** I5: a node of $kind has a row in the aggregate table. */
    private function orphans(string $nodes, string $aggregate, string $foreign, string $kind, int $limit): int
    {
        if (!$this->installed($aggregate)) {
            return 0;
        }
        $db = $this->database->connection();

        return count($db->table($this->tables->table($nodes).' as n')->where('n.kind', $kind)
            ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from($this->tables->table($aggregate).' as a')->whereColumn('a.'.$foreign, 'n.id'))
            ->orderBy('n.id')->limit($limit)->pluck('n.id')->all());
    }

    /** I5, the other direction: an aggregate row belongs to a node of its kind. */
    private function misplaced(string $aggregate, string $kind, int $limit): int
    {
        if (!$this->installed($aggregate)) {
            return 0;
        }
        $db = $this->database->connection();

        return count($db->table($this->tables->table($aggregate).' as a')
            ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from($this->tables->table('nodes').' as n')->whereColumn('n.id', 'a.node_id')->where('n.kind', $kind))
            ->orderBy('a.id')->limit($limit)->pluck('a.id')->all());
    }

    /** I6: the head revision of an aggregate is one of its own revisions. */
    private function foreignHead(string $aggregate, string $revisions, string $foreign, int $limit): int
    {
        if (!$this->installed($aggregate) || !$this->installed($revisions)) {
            return 0;
        }
        $db = $this->database->connection();

        return count($db->table($this->tables->table($aggregate).' as a')->whereNotNull('a.current_revision_id')
            ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from($this->tables->table($revisions).' as r')->whereColumn('r.id', 'a.current_revision_id')->whereColumn('r.'.$foreign, 'a.id'))
            ->orderBy('a.id')->limit($limit)->pluck('a.id')->all());
    }

    /** Whether the table of an aggregate exists: a host without the package that owns it has nothing to check. */
    private function installed(string $entity): bool
    {
        return $this->database->connection()->getSchemaBuilder()->hasTable($this->tables->table($entity));
    }
}
