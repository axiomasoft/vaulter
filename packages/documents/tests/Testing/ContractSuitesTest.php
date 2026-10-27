<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
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
use Vaulter\Documents\Tests\Fixtures\Collaboration\AcmeDriver;
use Vaulter\Documents\Tests\Fixtures\Collaboration\AcmeOtTokenVerifier;
use Vaulter\Documents\Tests\Fixtures\Collaboration\VersionedBoardType;
use Vaulter\Documents\Tests\Fixtures\Testing\BrokenDocumentType;
use Vaulter\Documents\Tests\Fixtures\Testing\BrokenExporter;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanV2Type;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * Every contract suite is proved twice: green on a reference implementation and red
 * on one that breaks the contract, so a suite that stops catching a violation fails here.
 */
uses(DocumentTypeContractTests::class, CollaborationProtocolContractTests::class);

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
    $this->assertCollaborationSessionContract($driver, $grant, $session);
    foreach (['protocol' => 'yjs', 'version' => 'acme-ot/2', 'nodeId' => 'aggregate-9', 'generation' => 8,
        'schemaVersion' => 2, 'mode' => CollaborationMode::Write,
        'expiresAt' => $grant->notAfter + 1, 'token' => '', 'options' => ['object' => new stdClass]] as $field => $value) {
        $bad = new CollaborationSession(...array_replace(get_object_vars($session), [$field => $value]));
        expect(fn () => $this->assertCollaborationSessionContract($driver, $grant, $bad))->toThrow(CollaborationProtocolContractException::class);
    }
});

it('rejects each independently lying verified credential without exposing it', function (string $field, mixed $value): void {
    $driver = new AcmeDriver;
    $read = new CollaborationGrant('42', '7', null, new ActorRef('staff', '42'), CollaborationMode::Read, 3, 1, 'acme/ot', 'acme-ot', 'acme-ot-v1', time() + 60);
    $write = new CollaborationGrant(...array_replace(get_object_vars($read), ['mode' => CollaborationMode::Write]));
    $verify = function (string $token) use ($driver, $field, $value): array {
        $claims = AcmeOtTokenVerifier::normalize($driver->verify($token));

        return array_replace($claims, [$field => $value]);
    };
    expect(fn () => $this->assertCollaborationProtocolContract($driver, $read, $write, $verify))
        ->toThrow(AssertionFailedError::class, 'Verified credential '.$field);
})->with([
    'read DTO write token' => ['mode', CollaborationMode::Write],
    'typed principal' => ['principal', new ActorRef('customer', '42')],
    'principal id' => ['principal', new ActorRef('staff', '43')],
    'tenant null versus string' => ['tenant', '0'],
    'node' => ['node_id', '43'], 'drive' => ['drive_id', '8'],
    'generation' => ['generation', 4], 'schema' => ['schema_version', 2],
    'format' => ['state_format', 'foreign'], 'protocol' => ['protocol', 'foreign'],
    'expiry' => ['expires_at', PHP_INT_MAX],
]);

it('rejects a verifier refusal without credential exposure', function (): void {
    $driver = new AcmeDriver;
    $read = new CollaborationGrant('42', '7', null, new ActorRef('staff', '42'), CollaborationMode::Read, 3, 1, 'acme/ot', 'acme-ot', 'acme-ot-v1', time() + 60);
    $write = new CollaborationGrant(...array_replace(get_object_vars($read), ['mode' => CollaborationMode::Write]));
    try {
        $this->assertCollaborationProtocolContract($driver, $read, $write, fn (string $token): array => throw new InvalidArgumentException($token));
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())->toBe('Credential verifier rejected the issued session.');

        return;
    }
    Assert::fail('The refusing verifier was ignored.');
});

it('checks genuine historical content and recursive canonical key order', function (): void {
    $this->assertDocumentTypeContract(new AcmeKanbanV2Type, [
        1 => ['input' => ['columns' => [['name' => 'Todo', 'cards' => [['id' => 1, 'title' => 'First'], ['id' => 2, 'title' => 'Second']]]]],
            'expected' => ['columns' => [['name' => 'Todo', 'items' => [['id' => 1, 'title' => 'First'], ['id' => 2, 'title' => 'Second']]]]]],
    ]);
});

it('refuses missing historical fixtures instead of treating current defaults as old data', function (): void {
    $this->assertDocumentTypeContract(new AcmeKanbanV2Type);
})->throws(AssertionFailedError::class, 'real historical fixture');

it('detects an incorrect expected historical projection', function (): void {
    $this->assertDocumentTypeContract(new AcmeKanbanV2Type, [
        1 => ['input' => ['columns' => [['name' => 'Todo', 'cards' => []]]], 'expected' => ['columns' => []]],
    ]);
})->throws(AssertionFailedError::class, 'historical version upgrades');

it('covers every historical step and refuses a hole in a three version type', function (): void {
    $expected = ['lanes' => [['name' => 'Todo', 'items' => ['first', 'second']]]];
    $fixtures = [
        1 => ['input' => ['columns' => [['title' => 'Todo', 'cards' => ['first', 'second']]]], 'expected' => $expected],
        2 => ['input' => ['lanes' => [['name' => 'Todo', 'cards' => ['first', 'second']]]], 'expected' => $expected],
    ];
    $type = new VersionedBoardType;
    $this->assertDocumentTypeContract($type, $fixtures);
    unset($fixtures[2]);
    expect(fn () => $this->assertDocumentTypeContract($type, $fixtures))->toThrow(AssertionFailedError::class, 'real historical fixture');
});
