<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

use Illuminate\Support\Carbon;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\State\CollaborationStateBinding;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\State\PendingMarker;

/** A durable local fixture backend: ACK only after flushing the record and marking pending. */
final class AcmeFixtureLog
{
    private string $path;

    public function __construct(private AcmeOtTokenVerifier $verifier, private VaulterDatabase $database)
    {
        $this->path = tempnam(sys_get_temp_dir(), 'vaulter-acme-');
        file_put_contents($this->path, '{}');
    }

    public function __destruct()
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function records(): array
    {
        return json_decode(file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function append(string $token, array $content): PendingMarker
    {
        $claims = $this->verifier->verify($token);
        if ($claims['mode'] !== CollaborationMode::Write || $claims['expires_at'] <= Carbon::now()->getTimestamp()) {
            throw new \InvalidArgumentException('Credential cannot append.');
        }

        return $this->database->transaction(function () use ($claims, $content): PendingMarker {
            $state = app(CollaborativeState::class);
            $locked = $state->lock($claims['node_id']);
            $binding = new CollaborationStateBinding($claims['protocol'], $claims['state_format'], $claims['schema_version'], $claims['generation']);
            if (!$locked->binding?->sameAs($binding) || $locked->driveId !== $claims['drive_id']) {
                throw new \InvalidArgumentException('Stale credential binding.');
            }
            $records = $this->records();
            $key = $locked->nodeId.':'.$locked->generation;
            $sequence = count($records[$key] ?? []) + 1;
            $records[$key][$sequence] = $content;
            $stream = fopen($this->path, 'c+b');
            try {
                flock($stream, LOCK_EX);
                ftruncate($stream, 0);
                fwrite($stream, json_encode($records, JSON_THROW_ON_ERROR));
                fflush($stream);
                fsync($stream);
            } finally {
                fclose($stream);
            }

            return $state->markPending($locked, $binding, $sequence);
        });
    }

    public function projection(PendingMarker $marker): array
    {
        return $this->records()[$marker->nodeId.':'.$marker->generation][$marker->throughSeq]
            ?? throw new \LogicException('Missing ACKed fixture update.');
    }
}
