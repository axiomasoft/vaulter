<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Testing\Contracts\CollaborationProtocolContractTests;
use Vaulter\Documents\Tests\Fixtures\Collaboration\AcmeDriver;
use Vaulter\Documents\Tests\Fixtures\Collaboration\AcmeOtTokenVerifier;
use Vaulter\Documents\Tests\Fixtures\Collaboration\WithAcme;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\ValueObjects\Access\ActorRef;

uses(OperationsTestCase::class, RefreshDatabase::class, WithAcme::class, CollaborationProtocolContractTests::class);

it('keeps the opaque protocol contract through the fixture backend verifier', function (): void {
    $clock = time();
    Carbon::setTestNow(Carbon::createFromTimestamp($clock));
    try {
        $read = new CollaborationGrant('42', '7', null, new ActorRef('staff', '42'), CollaborationMode::Read, 3, 1, 'acme/ot', 'acme-ot', 'acme-ot-v1', $clock + 300);
        $write = new CollaborationGrant(...array_replace(get_object_vars($read), ['mode' => CollaborationMode::Write]));
        $driver = app(AcmeDriver::class);
        $this->assertCollaborationProtocolContract($driver, $read, $write, app(AcmeOtTokenVerifier::class)->verify(...));
        $session = $driver->issueSession($read);
        expect($session->options['document'])->toBe(app(AcmeOtTokenVerifier::class)->verify($session->token)['node_id']);
        expect(fn () => app(AcmeOtTokenVerifier::class)->verify('tampered'))->toThrow(InvalidArgumentException::class, 'Invalid opaque credential.');
        Carbon::setTestNow(Carbon::createFromTimestamp($clock + 300));
        expect(fn () => app(AcmeOtTokenVerifier::class)->verify($session->token))->toThrow(InvalidArgumentException::class, 'Expired opaque credential.');
    } finally {
        Carbon::setTestNow();
    }
});
