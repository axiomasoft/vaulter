<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Exceptions\CollaborationProtocolContractException;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Testing\Contracts\CollaborationProtocolContractTests;
use Vaulter\Documents\Testing\Contracts\DocumentTypeContractTests;
use Vaulter\Documents\Testing\Contracts\ExporterContractTests;
use Vaulter\Documents\Tests\Fixtures\Testing\BrokenDocumentType;
use Vaulter\Documents\Tests\Fixtures\Testing\BrokenExporter;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * Every contract suite is proved twice: green on a reference implementation and red
 * on one that breaks the contract, so a suite that stops catching a violation fails here.
 */
uses(DocumentTypeContractTests::class);

it('accepts the built-in exporters', function (): void {
    $contract = new ExporterContractTests;

    $contract->assertExporterContract(app(HtmlExporter::class));
    $contract->assertExporterContract(app(MarkdownExporter::class));
});

it('rejects an exporter whose output changes between calls', function (): void {
    (new ExporterContractTests)->assertExporterContract(new BrokenExporter);
})->throws(AssertionFailedError::class, 'same bytes');

it('rejects a document type whose key is not vendor/name', function (): void {
    $this->assertDocumentTypeContract(new BrokenDocumentType);
})->throws(AssertionFailedError::class, 'vendor/name');

it('accepts neutral sessions and rejects each grant escape without secrets', function (): void {
    $grant = new CollaborationGrant('node-42', 'drive-7', null,
        new ActorRef('staff', '42'), CollaborationMode::Read,
        7, 1, 'acme/note', 'acme-ot', 'acme-json-v1', time() + 60);
    $driver = new class implements CollaborationProtocolDriver
    {
        public function protocol(): string
        {
            return 'acme-ot';
        }

        public function version(): string
        {
            return 'acme-ot/1';
        }

        public function supportsState(string $documentType, string $stateFormat, int $schemaVersion, bool $hasCheckpoint, CollaborationMode $mode): bool
        {
            return $documentType === 'acme/note' && $stateFormat === 'acme-json-v1' && $schemaVersion === 1;
        }

        public function issueSession(CollaborationGrant $grant): CollaborationSession
        {
            return new CollaborationSession('acme-ot', 'acme-ot/1', null, 'opaque-secret', $grant->notAfter, $grant->mode, $grant->nodeId, $grant->generation, $grant->schemaVersion);
        }
    };
    $session = $driver->issueSession($grant);
    (new CollaborationProtocolContractTests)->assertCollaborationSessionContract($driver, $grant, $session);
    foreach (['protocol' => 'yjs', 'version' => 'acme-ot/2', 'nodeId' => 'aggregate-9', 'generation' => 8,
        'schemaVersion' => 2, 'mode' => CollaborationMode::Write,
        'expiresAt' => $grant->notAfter + 1, 'token' => '', 'options' => ['object' => new stdClass]] as $field => $value) {
        $bad = new CollaborationSession(...array_replace(get_object_vars($session), [$field => $value]));
        expect(fn () => (new CollaborationProtocolContractTests)->assertCollaborationSessionContract($driver, $grant, $bad))->toThrow(CollaborationProtocolContractException::class);
    }
});
