<?php

declare(strict_types=1);

/*
 | AUDIT-VLT · P1.6 repro (read-only, D6) — collab-шов: JWT allow-list, secret/JWT
 | ветки, room-binding, гейт фиче-флага. Живёт ВНЕ пакетных tests-каталогов (D6). Запуск из
 | каталога packages/documents:
 |   /usr/bin/php8.4 vendor/bin/pest ../../plans/AUDIT-VLT/artifacts/repro/P1.6
 | Явно подключает DocumentTestCase (тот же bootstrap, что штатный webhook-тест),
 | т.к. файл лежит вне сконфигурированного testsuite и авто-binding uses() не ловит.
*/

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Vaulter\Documents\Actions\CreateDocumentNodeAction;
use Vaulter\Documents\Collaboration\CollaborationCall;
use Vaulter\Documents\Contracts\Collaboration\HostUserResolver;
use Vaulter\Documents\Data\CreateDocumentNodeData;
use Vaulter\Documents\Models\NodeDocumentVersion;
use Vaulter\Documents\Tests\Document\DocumentTestCase;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\FakeHostUserResolver;

uses(DocumentTestCase::class)->in(__DIR__);

const REPRO_JWT_SECRET = 'jwt-signing-secret';
const REPRO_WEBHOOK_SECRET = 'webhook-shared-secret';

beforeEach(function (): void {
    config()->set('vaulter.drivers.permission', 'database');
    config()->set('vaulter.collaboration.driver', 'hocuspocus');
    config()->set('vaulter.collaboration.webhook_secret', REPRO_WEBHOOK_SECRET);
    config()->set('vaulter.collaboration.drivers.hocuspocus.secret', REPRO_JWT_SECRET);
    app()->bind(HostUserResolver::class, fn (): FakeHostUserResolver => new FakeHostUserResolver);
});

function reproDocNode(?GrantLevel $level = GrantLevel::Writer, int $actorId = 1): Node
{
    $drive = DocumentTestHelpers::drive();
    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: 'Repro',
        content: DocumentTestHelpers::content('Draft'),
        parent: $drive->root(),
    ));
    if ($level !== null) {
        AccessTestHelpers::grant($node, $level, subjectId: $actorId);
    }

    return $node;
}

// ── C1: allow-list алгоритмов — key confusion / alg:none ────────────────────────────

it('C1: firebase/php-jwt Key(HS256) отвергает alg:none напрямую', function (): void {
    // Кустарный unsecured JWS: header {"alg":"none"}, пустая подпись.
    $b64 = static fn (array $a): string => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
    $noneToken = $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$b64(['room' => 'x', 'sub' => '1']).'.';

    $threw = false;
    try {
        JWT::decode($noneToken, new Key(REPRO_JWT_SECRET, 'HS256'));
    } catch (Throwable $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue(); // библиотека не принимает none как валидный алгоритм
});

it('C1: forged alg:none токен на collab/store отклоняется 401 (не пишет состояние)', function (): void {
    config()->set('vaulter.collaboration.webhook_secret', null); // закрыть secret-путь, оставить только JWT
    $node = reproDocNode();

    $b64 = static fn (array $a): string => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
    $forged = $b64(['alg' => 'none', 'typ' => 'JWT'])
        .'.'.$b64(['room' => Config::collaborationRoom($node->getKey()), 'sub' => '1', 'readOnly' => false, 'exp' => time() + 3600])
        .'.';

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('malicious'),
    ], ['Authorization' => 'Bearer '.$forged])->assertStatus(401);

    expect(NodeDocumentVersion::query()->where('node_id', $node->getKey())->where('version_no', '>', 1)->exists())
        ->toBeFalse();
});

// ── C6/C13/C16: секрет-путь vs JWT-путь, авторизационные решения CollaborationCall ──

it('C6/C13/C16: CollaborationCall — секрет всевластен, JWT room-bound и readOnly-gated', function (): void {
    $secretCall = CollaborationCall::bySecret();
    // Секрет: любая комната + запись.
    expect($secretCall->mayAddressRoom('vaulter.documents.A'))->toBeTrue()
        ->and($secretCall->mayAddressRoom('vaulter.documents.B'))->toBeTrue()
        ->and($secretCall->mayStore())->toBeTrue();

    // JWT для комнаты A, read-write: адресует только A, писать может.
    $rw = CollaborationCall::byJwt(room: 'vaulter.documents.A', subject: '1', readOnly: false);
    expect($rw->mayAddressRoom('vaulter.documents.A'))->toBeTrue()
        ->and($rw->mayAddressRoom('vaulter.documents.B'))->toBeFalse() // C16 cross-node
        ->and($rw->mayStore())->toBeTrue();

    // JWT read-only: адресует свою комнату, но писать НЕ может (C13 write-под-видом-read).
    $ro = CollaborationCall::byJwt(room: 'vaulter.documents.A', subject: '1', readOnly: true);
    expect($ro->mayAddressRoom('vaulter.documents.A'))->toBeTrue()
        ->and($ro->mayStore())->toBeFalse();
});

// ── C18: гейт фиче-флага — отдельного флага у collab НЕТ ─────────────────────────────

it('C18: у collab нет собственного фиче-флага — гейт общий с document_versions', function (): void {
    // Нет ключа vaulter.collaboration.enabled и нет features.collaboration —
    // единственный рычаг это features.document_versions (он же гейтит базовое
    // редактирование content GET/PATCH). Разделить collab и базовое редактирование нельзя.
    expect(config('vaulter.collaboration.enabled'))->toBeNull()
        ->and(config('vaulter.features.collaboration'))->toBeNull()
        ->and(config('vaulter.features.document_versions'))->not->toBeNull();
});
