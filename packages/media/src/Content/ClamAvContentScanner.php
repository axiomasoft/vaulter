<?php

declare(strict_types=1);

namespace Vaulter\Media\Content;

use InvalidArgumentException;
use Packman\Attributes\Api;
use RuntimeException;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanVerdict;

/** The clamd INSTREAM client. Configure the socket on a private network or use a Unix-domain socket. */
#[Api]
final readonly class ClamAvContentScanner implements ContentScanner
{
    private const int CHUNK_BYTES = 65_536;

    private const string MIN_VERSION = '1.5.5';

    public function __construct(private string $endpoint = 'unix:///run/clamav/clamd.ctl', private int $timeoutSeconds = 60)
    {
        if (preg_match('~\A(?:unix:///[^\x00]+|tcp://(?:\[[0-9a-f:]+\]|[a-z0-9.-]+):[0-9]{1,5})\z~i', $endpoint) !== 1 || $timeoutSeconds < 1) {
            throw new InvalidArgumentException('ClamAV requires a Unix or TCP socket and a positive timeout.');
        }
    }

    public function key(): string
    {
        return 'clamav';
    }

    public function version(): string
    {
        return $this->daemonVersion(hrtime(true) + $this->timeoutSeconds * 1_000_000_000);
    }

    public function scan(ScanInput $input): ScanResult
    {
        $seconds = max(0.0, min((float) $input->deadline->format('U.u') - microtime(true), $this->timeoutSeconds));
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
        try {
            if ($this->daemonVersion($deadline) !== $input->scannerVersion) {
                return new ScanResult(ScanVerdict::Failed, 'scanner_unavailable');
            }
            $socket = $this->connect($deadline);
            try {
                $this->send($socket, "zINSTREAM\0", $deadline);
                $size = 0;
                while (!feof($input->stream)) {
                    $this->remaining($deadline);
                    $chunk = @fread($input->stream, self::CHUNK_BYTES);
                    if ($chunk === false || ($chunk === '' && !feof($input->stream))) {
                        return new ScanResult(ScanVerdict::Failed, 'transient_io', true);
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $size += strlen($chunk);
                    if ($size > $input->size) {
                        return new ScanResult(ScanVerdict::Failed, 'content_identity_mismatch');
                    }
                    $this->send($socket, pack('N', strlen($chunk)).$chunk, $deadline);
                }
                if ($size !== $input->size) {
                    return new ScanResult(ScanVerdict::Failed, 'content_identity_mismatch');
                }
                $this->send($socket, pack('N', 0), $deadline);
                $reply = $this->reply($socket, $deadline);
            } finally {
                fclose($socket);
            }
        } catch (RuntimeException $exception) {
            return match ($exception->getCode()) {
                1 => new ScanResult(ScanVerdict::Failed, 'scanner_timeout', true),
                2 => new ScanResult(ScanVerdict::Failed, 'scanner_unavailable'),
                default => new ScanResult(ScanVerdict::Failed, 'scanner_unavailable', true),
            };
        }

        return match (true) {
            $reply === 'stream: OK' => new ScanResult(ScanVerdict::Clean),
            preg_match('/\Astream: [^\r\n]+ FOUND\z/', $reply) === 1 => new ScanResult(ScanVerdict::Infected),
            str_contains($reply, 'size limit exceeded') => new ScanResult(ScanVerdict::Failed, 'too_large'),
            default => new ScanResult(ScanVerdict::Failed, 'unknown'),
        };
    }

    private function daemonVersion(int $deadline): string
    {
        $socket = $this->connect($deadline);
        try {
            $this->send($socket, "zVERSION\0", $deadline);
            $reply = $this->reply($socket, $deadline);
        } finally {
            fclose($socket);
        }
        if (preg_match('~\AClamAV ([0-9]+\.[0-9]+\.[0-9]+)(?:/[^\r\n]+)?\z~', $reply, $match) !== 1
            || version_compare($match[1], self::MIN_VERSION, '<')) {
            throw new RuntimeException('The ClamAV daemon must run version 1.5.5 or newer.', 2);
        }

        return $match[1];
    }

    /** @return resource */
    private function connect(int $deadline): mixed
    {
        $socket = @stream_socket_client($this->endpoint, timeout: $this->remaining($deadline) / 1_000_000_000);
        if ($socket === false) {
            $this->remaining($deadline);
            throw new RuntimeException('The ClamAV daemon is unavailable.');
        }

        return $socket;
    }

    /** @param resource $socket */
    private function send(mixed $socket, string $bytes, int $deadline): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
            $this->limit($socket, $deadline);
            $written = @fwrite($socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw $this->socketFailure($socket);
            }
        }
    }

    /** @param resource $socket */
    private function reply(mixed $socket, int $deadline): string
    {
        $reply = '';
        while (strlen($reply) < 4_096) {
            $this->limit($socket, $deadline);
            $chunk = @fread($socket, 4_096 - strlen($reply));
            if ($chunk === false || $chunk === '') {
                throw $this->socketFailure($socket);
            }
            $reply .= $chunk;
            if (str_contains($reply, "\0")) {
                if (str_ends_with($reply, "\0") && substr_count($reply, "\0") === 1) {
                    return substr($reply, 0, -1);
                }
                break;
            }
        }

        throw new RuntimeException('The ClamAV daemon returned an invalid response.');
    }

    /** @param resource $socket */
    private function limit(mixed $socket, int $deadline): void
    {
        $micros = intdiv($this->remaining($deadline) + 999, 1_000);
        stream_set_timeout($socket, intdiv($micros, 1_000_000), $micros % 1_000_000);
    }

    /** @param resource $socket */
    private function socketFailure(mixed $socket): RuntimeException
    {
        return new RuntimeException('The ClamAV socket closed or timed out.', stream_get_meta_data($socket)['timed_out'] ? 1 : 0);
    }

    private function remaining(int $deadline): int
    {
        $remaining = $deadline - hrtime(true);

        return $remaining > 0 ? $remaining : throw new RuntimeException('The ClamAV scan timed out.', 1);
    }
}
