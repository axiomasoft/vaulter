<?php

declare(strict_types=1);

/**
 * Owner side of one workbook command.
 * Targets in vaulter-sheets: src/Actions/CommitWorkbookCommand.php (WorkbookOwner::commit),
 * src/Runtime/NodeWorkbookRuntime.php (ProcessWorkbookRuntime), src/Operations/LaravelWorkbookOperations.php.
 */

namespace Vaulter\Reference\Workbook;

require_once __DIR__ . '/Wire.php';

final class OwnerException extends \RuntimeException
{
    /** @param array<string, mixed> $detail */
    public function __construct(public readonly string $publicCode, public readonly int $httpStatus, string $message, public readonly array $detail = [])
    {
        parent::__construct($message);
    }
}

/** Authoritative head as read outside the transaction. */
final readonly class Head
{
    /** @param array<string, \stdClass> $receipts */
    public function __construct(
        public string $nodeId,
        public int $generation,
        public int $position,
        public string $sha256,
        public string $payloadFile,
        public array $receipts,
    ) {
    }

    public function etag(): string
    {
        return sprintf('"%s-g%d-p%d"', $this->nodeId, $this->generation, $this->position);
    }
}

/**
 * BOUNDARY: in Vaulter this port is Documents' AtomicDocumentMutation::run() with
 * LockedDocumentMutation::snapshot()/replace($content, $expectedStateVersion) on one PostgreSQL
 * connection (v_document_payloads + v_sheets_commands + outbox in one commit). This reference
 * keeps the same three steps on files: read outside the lock, re-read under an exclusive lock,
 * publish payload + receipt with one atomic rename.
 */
interface DocumentStatePort
{
    public function head(): Head;

    /** Canonical payload bytes of the head, verified against Head::$sha256. */
    public function payload(Head $head): string;

    /**
     * @template T
     * @param callable(Head): T $underLock receives the head re-read under the exclusive lock
     * @return T
     */
    public function locked(callable $underLock): mixed;

    /** Called only inside locked(): one atomic publication of payload, head and receipt. */
    public function publish(Head $expected, string $canonicalPayload, string $sha256, string $commandId, \stdClass $receipt): Head;

    /** Records an observation for tests (runtime call, publication). */
    public function count(string $event): void;
}

final class FileDocumentState implements DocumentStatePort
{
    public function __construct(private readonly string $dir)
    {
    }

    public static function seed(string $dir, string $nodeId, string $canonicalPayload, int $generation = 1, int $position = 0): Head
    {
        if (!is_dir($dir) && !mkdir($dir, 0o700, true)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        $sha = hash('sha256', $canonicalPayload);
        if (CanonicalJson::encode(StrictJson::decode($canonicalPayload)) !== $canonicalPayload) {
            throw new \RuntimeException('seed payload is not canonical JSON');
        }
        $file = "payload-{$generation}-{$position}.json";
        file_put_contents("{$dir}/{$file}", $canonicalPayload);
        $head = new Head($nodeId, $generation, $position, $sha, $file, []);
        (new self($dir))->writeHead($head);
        return $head;
    }

    public function head(): Head
    {
        $raw = StrictJson::decode((string) file_get_contents("{$this->dir}/head.json"));
        return new Head($raw->node_id, $raw->generation, $raw->position, $raw->sha256, $raw->payload_file, get_object_vars($raw->receipts));
    }

    public function payload(Head $head): string
    {
        $bytes = (string) file_get_contents("{$this->dir}/{$head->payloadFile}");
        if (hash('sha256', $bytes) !== $head->sha256) {
            throw new OwnerException('storage_integrity', 500, 'stored payload does not match head digest');
        }
        return $bytes;
    }

    public function locked(callable $underLock): mixed
    {
        $lock = fopen("{$this->dir}/lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('cannot lock document');
        }
        try {
            return $underLock($this->head());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function publish(Head $expected, string $canonicalPayload, string $sha256, string $commandId, \stdClass $receipt): Head
    {
        $position = $expected->position + 1;
        $file = "payload-{$expected->generation}-{$position}.json";
        file_put_contents("{$this->dir}/{$file}", $canonicalPayload);
        $receipts = $expected->receipts;
        $receipts[$commandId] = $receipt;
        $head = new Head($expected->nodeId, $expected->generation, $position, $sha256, $file, $receipts);
        $this->writeHead($head);
        $this->count('published');
        return $head;
    }

    public function count(string $event): void
    {
        file_put_contents("{$this->dir}/events.log", $event . "\n", FILE_APPEND | LOCK_EX);
    }

    private function writeHead(Head $head): void
    {
        $doc = (object) [
            'node_id' => $head->nodeId, 'generation' => $head->generation, 'position' => $head->position,
            'sha256' => $head->sha256, 'payload_file' => $head->payloadFile, 'receipts' => (object) $head->receipts,
        ];
        $tmp = "{$this->dir}/head.json.tmp";
        file_put_contents($tmp, CanonicalJson::encode($doc));
        rename($tmp, "{$this->dir}/head.json"); // the commit point
    }
}

/** Port of the calculation process; HTTP transport (src/Runtime/HttpWorkbookRuntime.php) implements the same. */
interface WorkbookRuntimePort
{
    /** @return array{stdout: string, exit: int, stderr: string} */
    public function exchange(string $requestLine): array;
}

final class ProcessWorkbookRuntime implements WorkbookRuntimePort
{
    /** @param list<string> $command argument vector, never a shell string */
    public function __construct(
        private readonly array $command,
        private readonly float $timeoutSeconds = 25.0,
        private readonly int $maxStdoutBytes = 8 * 1024 * 1024 + 1,
    ) {
    }

    public function exchange(string $requestLine): array
    {
        $process = proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new OwnerException('runtime_unavailable', 503, 'cannot start runtime');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdin = $requestLine;
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (true) {
            $read = array_filter([$pipes[1], $pipes[2]], 'is_resource');
            $write = $stdin !== '' && is_resource($pipes[0]) ? [$pipes[0]] : [];
            if ($stdin === '' && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            if ($read === [] && $write === []) {
                break;
            }
            $except = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                proc_terminate($process, 9);
                throw new OwnerException('runtime_timeout', 504, 'runtime did not answer in time');
            }
            if (stream_select($read, $write, $except, 0, (int) min($left * 1e6, 200000)) === false) {
                break;
            }
            foreach ($write as $pipe) {
                $written = fwrite($pipe, $stdin);
                $stdin = substr($stdin, (int) $written);
            }
            foreach ($read as $pipe) {
                $chunk = (string) fread($pipe, 65536);
                if ($pipe === $pipes[1]) {
                    $stdout .= $chunk;
                    if (strlen($stdout) > $this->maxStdoutBytes) {
                        proc_terminate($process, 9);
                        throw new OwnerException('runtime_failed', 503, 'runtime stdout exceeds limit');
                    }
                } else {
                    $stderr .= substr($chunk, 0, 65536);
                }
                if (feof($pipe)) {
                    fclose($pipe);
                }
            }
        }
        return ['stdout' => $stdout, 'exit' => proc_close($process), 'stderr' => $stderr];
    }
}

final class WorkbookOwner
{
    public const string PROFILE_ID = 'univer-oss-basic-1';
    private const int MAX_ATTEMPTS = 3;
    private const int MAX_PAYLOAD_BYTES = 4 * 1024 * 1024;

    /** @var (\Closure(): void)|null test-only barrier between candidate verification and the locked re-check */
    public ?\Closure $beforePublish = null;

    public function __construct(private readonly DocumentStatePort $state, private readonly WorkbookRuntimePort $runtime)
    {
    }

    /** GET /workbooks/{nodeId}: snapshot, marker, ETag. ACL is checked by the host before this call. */
    public function open(): array
    {
        $head = $this->state->head();
        return ['etag' => $head->etag(), 'marker' => ['generation' => $head->generation, 'position' => $head->position], 'workbook' => StrictJson::decode($this->state->payload($head))];
    }

    /**
     * Confirmed values of a range: the runtime recalculates the stored snapshot behind a barrier.
     * @param list<array{sheet_id: string, range: string}> $ranges
     */
    public function read(array $ranges): array
    {
        $head = $this->state->head();
        $payload = $this->state->payload($head);
        $response = $this->call((object) [
            'version' => 1, 'request_id' => 'read-' . bin2hex(random_bytes(6)), 'operation' => 'read',
            'base' => (object) ['generation' => $head->generation, 'position' => $head->position, 'sha256' => $head->sha256],
            'profile_id' => self::PROFILE_ID, 'workbook' => StrictJson::decode($payload),
            'selector' => (object) ['ranges' => array_map(static fn (array $r): object => (object) $r, $ranges)],
        ]);
        if ($response->base_sha256 !== $head->sha256 || $response->calculation->state !== 'ready' || $response->calculation->source_sha256 !== $head->sha256) {
            throw new OwnerException('runtime_integrity', 503, 'runtime read is not bound to the stored snapshot');
        }
        return ['marker' => ['generation' => $head->generation, 'position' => $head->position], 'calculated_at_sha256' => $head->sha256, 'ranges' => $response->data->ranges];
    }

    public function status(string $commandId): ?\stdClass
    {
        return $this->state->head()->receipts[$commandId] ?? null;
    }

    /**
     * POST /workbooks/{nodeId}/commands (UI: exact_base, or rebase_cells for pure cell edits; both need If-Match) and
     * WorkbookOperations::commit (SDK, policy revalidate_by_id + expected generation).
     *
     * rebase_cells: when the head moved after the user's If-Match, the same cell commands are re-run on the new
     * head and the runtime re-checks every `expected` cell content. It is allowed only while no structural command
     * touched the same sheet in between, because then an A1 address may name another record whose content happens
     * to equal `expected`.
     *
     * @param list<\stdClass> $commands
     */
    public function commit(string $commandId, array $commands, string $policy, ?string $ifMatch, ?int $expectedGeneration): \stdClass
    {
        if (!in_array($policy, ['exact_base', 'rebase_cells', 'revalidate_by_id'], true) || ($policy !== 'revalidate_by_id') !== ($ifMatch !== null)) {
            throw new OwnerException('invalid_command', 422, 'exact_base and rebase_cells require If-Match, revalidate_by_id forbids it');
        }
        if ($policy === 'rebase_cells') {
            foreach ($commands as $command) {
                if (!in_array($command->kind ?? null, self::CELL_KINDS, true)) {
                    throw new OwnerException('invalid_command', 422, 'rebase_cells accepts only cell content commands', ['kind' => $command->kind ?? null]);
                }
            }
        }
        $fingerprint = CanonicalJson::sha256((object) ['commands' => $commands, 'policy' => $policy, 'if_match' => $ifMatch, 'expected_generation' => $expectedGeneration]);
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            // 1. Outside any transaction: snapshot of head and payload.
            $head = $this->state->head();
            if (isset($head->receipts[$commandId])) {
                return $this->replay($head->receipts[$commandId], $fingerprint);
            }
            if ($ifMatch !== null && $ifMatch !== $head->etag()) {
                if ($policy !== 'rebase_cells') {
                    throw new OwnerException('version_conflict', 409, 'If-Match does not name the current version', ['current_etag' => $head->etag()]);
                }
                $this->assertRebasable($head, $ifMatch, $commands);
            }
            if ($expectedGeneration !== null && $expectedGeneration !== $head->generation) {
                throw new OwnerException('generation_changed', 409, 'document was restored into a new generation');
            }
            $payload = $this->state->payload($head);
            // 2. Calculation in a separate process, no DB transaction held.
            $request = (object) [
                'version' => 1, 'request_id' => "{$commandId}#{$attempt}", 'operation' => 'apply',
                'base' => (object) ['generation' => $head->generation, 'position' => $head->position, 'sha256' => $head->sha256],
                'profile_id' => self::PROFILE_ID, 'workbook' => StrictJson::decode($payload), 'commands' => $commands,
            ];
            $response = $this->call($request);
            [$candidate, $candidateSha] = $this->verifyCandidate($response, $head, $payload);
            if ($this->beforePublish !== null) {
                ($this->beforePublish)();
            }
            // 3. Under the exclusive lock: re-check the base the candidate was computed from.
            $outcome = $this->state->locked(function (Head $current) use ($head, $commandId, $fingerprint, $candidate, $candidateSha, $response, $commands): array {
                if (isset($current->receipts[$commandId])) {
                    return ['replay', $current->receipts[$commandId]];
                }
                if ($current->generation !== $head->generation || $current->position !== $head->position || $current->sha256 !== $head->sha256) {
                    return ['moved', null];
                }
                $receipt = (object) [
                    'command_id' => $commandId, 'receipt_id' => 'receipt-' . substr($candidateSha, 0, 16), 'state' => 'committed',
                    'fingerprint' => $fingerprint,
                    'marker' => (object) ['generation' => $current->generation, 'position' => $current->position + 1],
                    'base_sha256' => $current->sha256, 'candidate_sha256' => $candidateSha,
                    'changed_ranges' => $response->changed_ranges, 'cells' => $response->checks->cells,
                    'structural_sheets' => self::structuralSheets($commands),
                ];
                $this->state->publish($current, $candidate, $candidateSha, $commandId, $receipt);
                return ['published', $receipt];
            });
            if ($outcome[0] === 'replay') {
                return $this->replay($outcome[1], $fingerprint);
            }
            if ($outcome[0] === 'published') {
                return $outcome[1];
            }
            if ($policy === 'exact_base') {
                throw new OwnerException('version_conflict', 409, 'document changed while the candidate was calculated');
            }
            // revalidate_by_id: recompute on the new head; the runtime re-resolves IDs and re-checks scope.
            // rebase_cells: the next attempt re-checks the structural guard against the newer head.
        }
        throw new OwnerException('contention', 503, 'document keeps changing, retry later');
    }

    /** Cell content commands: they never move rows or columns. */
    private const array CELL_KINDS = ['set_number', 'set_text', 'set_formula', 'set_values'];

    /** Test-only switch for the mutation check: the rebase without its structural guard. */
    public bool $rebaseStructureGuard = true;

    /** @param list<\stdClass> $commands @return list<string> */
    private static function structuralSheets(array $commands): array
    {
        $sheets = [];
        foreach ($commands as $command) {
            if (!in_array($command->kind, self::CELL_KINDS, true) && $command->kind !== 'fill_formula') {
                $sheets[$command->sheet_id] = true;
            }
        }
        $sheets = array_keys($sheets);
        sort($sheets, SORT_STRING);
        return $sheets;
    }

    /**
     * Every publication between If-Match and the head must be a known receipt of this generation that changed no
     * structure on the sheets the commands address. A publication without a receipt (restore, import, foreign
     * writer) or a receipt without `structural_sheets` counts as structural.
     *
     * @param list<\stdClass> $commands
     */
    private function assertRebasable(Head $head, string $ifMatch, array $commands): void
    {
        if (preg_match('/^"(.+)-g(\d+)-p(\d+)"$/', $ifMatch, $m) !== 1 || $m[1] !== $head->nodeId) {
            throw new OwnerException('version_conflict', 409, 'If-Match does not name a version of this workbook', ['current_etag' => $head->etag()]);
        }
        [$generation, $position] = [(int) $m[2], (int) $m[3]];
        if ($generation !== $head->generation) {
            throw new OwnerException('generation_changed', 409, 'document was restored into a new generation', ['current_etag' => $head->etag()]);
        }
        if ($position > $head->position) {
            throw new OwnerException('version_conflict', 409, 'If-Match is ahead of the head', ['current_etag' => $head->etag()]);
        }
        $sheets = array_fill_keys(array_map(static fn (\stdClass $c): string => $c->sheet_id, $commands), true);
        $between = array_filter($head->receipts, static fn (\stdClass $r): bool => $r->marker->generation === $generation && $r->marker->position > $position);
        $structural = [];
        foreach ($between as $receipt) {
            foreach ($receipt->structural_sheets ?? array_keys($sheets) as $sheet) {
                if (isset($sheets[$sheet])) {
                    $structural[] = $receipt->command_id;
                }
            }
        }
        $unknown = $head->position - $position - count($between);
        if ($this->rebaseStructureGuard && ($structural !== [] || $unknown !== 0)) {
            throw new OwnerException('version_conflict', 409, 'rows or columns moved since If-Match; reload before editing by address', [
                'current_etag' => $head->etag(), 'structural_receipts' => array_values(array_unique($structural)), 'publications_without_receipt' => $unknown,
            ]);
        }
    }

    private function replay(\stdClass $receipt, string $fingerprint): \stdClass
    {
        if ($receipt->fingerprint !== $fingerprint) {
            throw new OwnerException('idempotency_conflict', 409, 'command_id was used with a different body');
        }
        return (object) [...get_object_vars($receipt), 'replayed' => true];
    }

    private function call(\stdClass $request): \stdClass
    {
        $this->state->count('runtime_call');
        $result = $this->runtime->exchange(CanonicalJson::encode($request) . "\n");
        if ($result['exit'] !== 0) {
            throw new OwnerException('runtime_failed', 503, "runtime exited with {$result['exit']}");
        }
        if (!str_ends_with($result['stdout'], "\n") || substr_count($result['stdout'], "\n") !== 1) {
            throw new OwnerException('runtime_failed', 503, 'runtime must answer with exactly one line');
        }
        try {
            $response = StrictJson::decode(substr($result['stdout'], 0, -1));
        } catch (WireException $e) {
            throw new OwnerException('runtime_failed', 503, 'runtime answer violates the wire: ' . $e->getMessage());
        }
        if (!$response instanceof \stdClass || ($response->version ?? null) !== 1 || ($response->request_id ?? null) !== $request->request_id) {
            throw new OwnerException('runtime_failed', 503, 'runtime answer has wrong version or request_id');
        }
        if ($response->ok !== true) {
            throw self::mapRuntimeError($response->error);
        }
        return $response;
    }

    /** Owner-side digest verification: nothing the runtime says is trusted without recomputation. */
    private function verifyCandidate(\stdClass $response, Head $head, string $basePayload): array
    {
        if ($response->base_sha256 !== $head->sha256) {
            throw new OwnerException('runtime_integrity', 503, 'runtime calculated from another base');
        }
        $canonical = CanonicalJson::encode($response->workbook);
        $sha = hash('sha256', $canonical);
        if ($sha !== $response->candidate_sha256) {
            throw new OwnerException('runtime_integrity', 503, 'candidate bytes do not match candidate_sha256', ['owner_sha256' => $sha]);
        }
        if ($response->calculation->state !== 'ready' || $response->calculation->source_sha256 !== $sha) {
            throw new OwnerException('calculation_pending', 503, 'calculation is not bound to the candidate');
        }
        if (CanonicalJson::encode($response->workbook->profile) !== CanonicalJson::encode(StrictJson::decode($basePayload)->profile)) {
            throw new OwnerException('runtime_integrity', 503, 'runtime changed the profile');
        }
        if (strlen($canonical) > self::MAX_PAYLOAD_BYTES) {
            throw new OwnerException('limit_exceeded', 413, 'snapshot exceeds the Documents payload limit');
        }
        return [$canonical, $sha];
    }

    /** Runtime diagnostic code -> public error; nothing is published on any of them. */
    private static function mapRuntimeError(\stdClass $error): OwnerException
    {
        [$public, $status] = match ($error->code) {
            'precondition_failed' => ['version_conflict', 409],
            'scope_changed' => ['scope_changed', 409],
            'unknown_target' => ['unknown_target', 422],
            'identity_required' => ['invalid_command', 422],
            'unsupported_command', 'unsupported_content', 'profile_mismatch' => ['unsupported_content', 422],
            'limit_exceeded' => ['limit_exceeded', 413],
            'calculation_timeout' => ['calculation_pending', 503],
            default => ['runtime_failed', 503], // invalid_message, unsupported_version, base_mismatch: our own fault
        };
        return new OwnerException($public, $status, $error->message, ['runtime_code' => $error->code, 'runtime_detail' => $error->detail ?? null]);
    }
}
