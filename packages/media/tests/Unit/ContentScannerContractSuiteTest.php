<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Media\Content\ClamAvContentScanner;
use Vaulter\Media\Content\ScanInput;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Media\Testing\Contracts\ContentScannerContractTests;
use Vaulter\Media\Testing\FakeContentScanner;
use Vaulter\Media\Tests\Fixtures\Testing\ReadErrorSwallowingScanner;
use Vaulter\Testing\Support\FailingStream;

/**
 * The scanner contract suite of the media package is proved twice: green on a reference implementation and red
 * on one that breaks the contract, so a suite that stops catching a violation fails here.
 */
it('accepts a scanner that maps every outcome as the lifecycle records it', function (): void {
    $scanner = (new FakeContentScanner)
        ->returnsFor('infected bytes', new ScanResult(ScanVerdict::Infected))
        ->returnsFor('encrypted bytes', new ScanResult(ScanVerdict::Failed, 'password_protected'))
        ->returnsFor('slow bytes', new ScanResult(ScanVerdict::Failed, 'scanner_timeout', true));

    (new ContentScannerContractTests)->assertContentScannerContract($scanner, [
        'clean' => 'plain',
        'infected' => 'infected bytes',
        'password_protected' => 'encrypted bytes',
        'timeout' => 'slow bytes',
    ]);
});

it('rejects a scanner that mistakes an unreadable stream for a clean file', function (): void {
    (new ContentScannerContractTests)->assertContentScannerContract(new ReadErrorSwallowingScanner, ['clean' => 'plain']);
})->throws(AssertionFailedError::class, 'never be reported clean');

it('rejects a scanner whose outcome does not match the sample', function (): void {
    (new ContentScannerContractTests)->assertContentScannerContract(new FakeContentScanner, ['clean' => 'plain', 'infected' => 'plain']);
})->throws(AssertionFailedError::class, 'must scan infected');

it('rejects a scanner that turns unreadable bytes into a verdict, and accepts one that throws or fails', function (): void {
    $contract = new ContentScannerContractTests;

    expect(fn () => $contract->assertUnreadableBytesAreNeverClean(new ReadErrorSwallowingScanner))->toThrow(AssertionFailedError::class);
    $contract->assertUnreadableBytesAreNeverClean((new FakeContentScanner)->alwaysReturns(new RuntimeException('engine down')));
    $contract->assertUnreadableBytesAreNeverClean(new FakeContentScanner);
    expect(in_array(FailingStream::SCHEME, stream_get_wrappers(), true))->toBeFalse();
});

it('streams 64 KiB clamd frames and enforces the live daemon version', function (string $daemon, ScanVerdict $expected): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $endpoint = 'tcp://'.stream_socket_get_name($server, false);
    $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    $payload = str_repeat('x', 65_536).$eicar;
    $pid = pcntl_fork();
    if ($pid === 0) {
        $version = stream_socket_accept($server, 3);
        if (!is_resource($version) || stream_get_contents($version, 9) !== "zVERSION\0") {
            exit(10);
        }
        fwrite($version, 'ClamAV '.$daemon."/123/date\0");
        fclose($version);
        if ($daemon === '1.5.4') {
            exit(0);
        }
        $scan = stream_socket_accept($server, 3);
        if (!is_resource($scan)) {
            exit(11);
        }
        stream_set_timeout($scan, 3);
        $valid = stream_get_contents($scan, 10) === "zINSTREAM\0"
            && stream_get_contents($scan, 4) === pack('N', 65_536)
            && stream_get_contents($scan, 65_536) === str_repeat('x', 65_536)
            && stream_get_contents($scan, 4) === pack('N', strlen($eicar))
            && stream_get_contents($scan, strlen($eicar)) === $eicar
            && stream_get_contents($scan, 4) === pack('N', 0);
        fwrite($scan, "stream: Eicar-Signature FOUND\0");
        fclose($scan);
        exit($valid ? 0 : 12);
    }
    fclose($server);
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $payload);
    rewind($stream);
    try {
        $scanner = new ClamAvContentScanner($endpoint, 3);
        $input = new ScanInput($stream, 1, 1, 1, null, 'test', hash('sha256', $payload), strlen($payload), null, '1.5.5', '1', new DateTimeImmutable('+3 seconds'));
        expect($scanner->scan($input)->verdict)->toBe($expected);
    } finally {
        fclose($stream);
        pcntl_waitpid($pid, $status);
    }
    expect(pcntl_wexitstatus($status))->toBe(0);
})->with([
    'supported' => ['1.5.5', ScanVerdict::Infected],
    'outdated' => ['1.5.4', ScanVerdict::Failed],
]);

it('fails closed when clamd is unavailable', function (): void {
    $stream = fopen('php://memory', 'r+');
    try {
        $scanner = new ClamAvContentScanner('unix:///missing-vaulter-clamd/socket', 1);
        $input = new ScanInput($stream, 1, 1, 1, null, 'test', hash('sha256', ''), 0, null, '1.5.5', '1', new DateTimeImmutable('+1 second'));
        $failure = $scanner->scan($input);
        expect($failure->verdict)->toBe(ScanVerdict::Failed)->and($failure->reason)->toBe('scanner_unavailable');
    } finally {
        fclose($stream);
    }
});
