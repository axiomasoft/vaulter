<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Psr\Http\Message\StreamInterface;
use Vaulter\Data\Drive\UploadSource;

it('reads caller resources at their current position and never closes them', function (): void {
    $stream = tmpfile();
    try {
        fwrite($stream, 'prefixDATA');
        fseek($stream, 6);
        $source = UploadSource::fromStream(stream: $stream, name: 'input.txt', size: 1);
        expect(ftell($stream))->toBe(6)->and($source->declaredSize())->toBe(1)
            ->and($source->declaredMimeType())->toBeNull()->and($source->name())->toBe('input.txt');
        $handle = $source->openForReading();
        expect($handle)->toBe($stream)->and(stream_get_contents($handle))->toBe('DATA');
        $source->releaseIfOwned($handle);
        expect(is_resource($stream))->toBeTrue();
    } finally {
        fclose($stream);
    }
});

it('accepts non-seekable caller streams and leaves them open on consumer failure', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    try {
        fwrite($pair[0], 'prefixDATA');
        stream_socket_shutdown($pair[0], STREAM_SHUT_WR);
        fread($pair[1], 6);
        expect(stream_get_meta_data($pair[1])['seekable'])->toBeFalse();
        $source = UploadSource::fromStream($pair[1], name: 'pipe.txt');
        $handle = $source->openForReading();
        try {
            expect(stream_get_contents($handle))->toBe('DATA');
            throw new RuntimeException('Consumer rejected bytes.');
        } catch (RuntimeException) {
            $source->releaseIfOwned($handle);
        }
        expect(is_resource($pair[1]))->toBeTrue();
    } finally {
        fclose($pair[0]);
        fclose($pair[1]);
    }
});

it('preserves a PSR stream for chunked reads without string conversion detach rewind or close', function (): void {
    $stream = Mockery::mock(StreamInterface::class);
    $stream->shouldReceive('isReadable')->once()->andReturnTrue();
    $stream->shouldReceive('getSize')->once()->andReturnNull();
    $stream->shouldReceive('__toString')->never()->andThrow(new RuntimeException('Whole-buffer conversion forbidden.'));
    foreach (['getContents', 'detach', 'close', 'rewind'] as $method) {
        $stream->shouldReceive($method)->never();
    }
    $source = UploadSource::fromPsrStream(stream: $stream, name: 'psr.txt');
    expect($source->openForReading())->toBe($stream)->and($source->declaredSize())->toBeNull();
    $stream->shouldReceive('read')->with(65536)->once()->andReturn('chunk');
    expect($source->openForReading()->read(65536))->toBe('chunk');
    $source->releaseIfOwned($stream);
    Mockery::close();
});

it('opens path descriptors lazily and closes only its own handles on success and rejection', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'vaulter-p32-');
    $foreign = tmpfile();
    try {
        $source = UploadSource::fromPath(path: $path, name: 'override.txt');
        file_put_contents($path, 'written after factory');
        $first = $source->openForReading();
        $second = $source->openForReading();
        expect($first)->not->toBe($second)->and(stream_get_contents($first))->toBe('written after factory');
        $source->releaseIfOwned($foreign);
        expect(is_resource($foreign))->toBeTrue();
        $source->releaseIfOwned($first);
        expect(is_resource($first))->toBeFalse()->and(is_resource($second))->toBeTrue();
        try {
            throw new RuntimeException('staging rejected');
        } catch (RuntimeException) {
            $source->releaseIfOwned($second);
        }
        $source->releaseIfOwned($second);
        expect(is_resource($second))->toBeFalse();
    } finally {
        fclose($foreign);
        unlink($path);
    }
});

it('opens owned strings as independent byte streams including empty and binary content', function (string $bytes): void {
    $source = UploadSource::fromString(bytes: $bytes, name: 'bytes.bin');
    expect($source->declaredSize())->toBe(strlen($bytes));
    $handle = $source->openForReading();
    try {
        expect(stream_get_contents($handle))->toBe($bytes);
    } finally {
        $source->releaseIfOwned($handle);
    }
    expect(is_resource($handle))->toBeFalse();
})->with(['empty' => [''], 'binary' => ["\x00\xffhello"], 'multiple chunks' => [str_repeat('a', 140000)]]);

it('retains client UploadedFile hints and owns the descriptor it opens', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'vaulter-p32-');
    try {
        file_put_contents($path, 'actual bytes');
        $file = new UploadedFile(path: $path, originalName: 'client.pdf', mimeType: 'application/pdf', test: true);
        $source = UploadSource::fromUploadedFile($file);
        expect($source->name())->toBe('client.pdf')->and($source->declaredMimeType())->toBe('application/pdf')
            ->and($source->declaredSize())->toBe(12);
        $handle = $source->openForReading();
        try {
            expect(stream_get_contents($handle))->toBe('actual bytes');
        } finally {
            $source->releaseIfOwned($handle);
        }
        expect(is_resource($handle))->toBeFalse();
    } finally {
        unlink($path);
    }
});

it('rejects missing paths with a deterministic error that does not expose the path', function (): void {
    $source = UploadSource::fromPath('/private/host/does-not-exist/file');
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, severity: $severity);
    });
    try {
        $source->openForReading();
        test()->fail('Missing file was accepted.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Unable to open upload source.')
            ->and($exception->getPrevious())->toBeNull();
    } finally {
        restore_error_handler();
    }
});

it('rejects non-resource closed write-only and negative-size sources', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'vaulter-p32-');
    $writeOnly = fopen($path, 'wb');
    $readable = tmpfile();
    $closed = tmpfile();
    fclose($closed);
    try {
        foreach ([null, 'bytes', new stdClass, $closed, $writeOnly] as $invalid) {
            expect(fn () => UploadSource::fromStream($invalid, name: 'bad'))->toThrow(InvalidArgumentException::class);
        }
        expect(fn () => UploadSource::fromStream($readable, name: 'bad', size: -1))->toThrow(InvalidArgumentException::class)
            ->and(fn () => UploadSource::fromString('', name: '  '))->toThrow(InvalidArgumentException::class)
            ->and(fn () => UploadSource::fromPath("nul\0path"))->toThrow(InvalidArgumentException::class);
        $psr = Mockery::mock(StreamInterface::class);
        $psr->shouldReceive('isReadable')->once()->andReturnFalse();
        expect(fn () => UploadSource::fromPsrStream($psr, name: 'bad'))->toThrow(InvalidArgumentException::class);
        Mockery::close();
    } finally {
        fclose($writeOnly);
        fclose($readable);
        unlink($path);
    }
});
