<?php

declare(strict_types=1);

namespace Vaulter\Documents\Doctor;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Types\DocumentTypeRegistry;

#[Internal]
final readonly class DocumentTypesCheck implements DoctorCheck
{
    public function __construct(private DocumentsConfig $config, private DocumentTypeRegistry $types) {}

    public function key(): string
    {
        return 'documents.types';
    }

    public function title(): string
    {
        return 'Document types';
    }

    public function run(DoctorContext $context): array
    {
        $findings = [];
        $default = $this->config->defaultType();
        $findings[] = $this->types->has($default)
            ? DoctorFinding::ok(code: 'documents.types_default_ok', message: 'The default document type "'.$default.'" is registered.', evidence: ['default_type' => $default])
            : DoctorFinding::warning(code: 'documents.types_default_unregistered', message: 'The default document type "'.$default.'" is not registered.', hint: 'register the type or change vaulter-documents.default_type', evidence: ['default_type' => $default]);

        $unknown = [];
        foreach (Document::query()->toBase()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type') as $type => $total) {
            if (!$this->types->has((string) $type)) {
                $unknown[(string) $type] = is_numeric($total) ? (int) $total : 0;
            }
        }
        $findings[] = $unknown === []
            ? DoctorFinding::ok(code: 'documents.types_known', message: 'Every stored document has a registered type.')
            : DoctorFinding::warning(
                code: 'documents.types_unknown',
                message: array_sum($unknown).' document(s) have a type that is not registered.',
                hint: 'register the missing type; such documents cannot be opened or exported',
                evidence: ['documents' => array_sum($unknown), 'types' => implode(', ', array_slice(array_keys($unknown), 0, 10))],
            );

        return $findings;
    }
}
