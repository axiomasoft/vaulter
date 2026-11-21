<?php

declare(strict_types=1);

/**
 * CLI of the reference owner, used by plan/checks/workbook-operation/check.mjs as the
 * WorkbookOperations transport (each invocation is a fresh PHP process).
 *
 *   php owner-cli.php seed   <dir> <node_id> <payload.json> [generation] [position]
 *   php owner-cli.php open   <dir>
 *   php owner-cli.php read   <dir> '<ranges json>'
 *   php owner-cli.php status <dir> <command_id>
 *   php owner-cli.php commit <dir> <command_id> exact_base|rebase_cells|revalidate_by_id <if_match|-> <generation|-> '<commands json>'
 *        [--fault=tamper-candidate] [--barrier] [--mutant=no-structure-guard]
 *
 * Prints one JSON line {"ok":true,"result":...} or {"ok":false,"error":{...}} and exits 0.
 */

namespace Vaulter\Reference\Workbook;

require_once __DIR__ . '/Owner.php';

/** Test fault: bytes of the candidate change after the runtime computed candidate_sha256. */
final class TamperingRuntime implements WorkbookRuntimePort
{
    public function __construct(private readonly WorkbookRuntimePort $inner)
    {
    }

    public function exchange(string $requestLine): array
    {
        $result = $this->inner->exchange($requestLine);
        $response = StrictJson::decode(rtrim($result['stdout'], "\n"));
        if (($response->ok ?? false) === true && isset($response->workbook)) {
            $response->workbook->native->name = 'tampered in transit';
            $result['stdout'] = CanonicalJson::encode($response) . "\n";
        }
        return $result;
    }
}

function emit(array $line): never
{
    fwrite(STDOUT, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

function runtime(): WorkbookRuntimePort
{
    return new ProcessWorkbookRuntime([getenv('VAULTER_NODE') ?: 'node', __DIR__ . '/../src/cli.ts']);
}

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$flags = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => str_starts_with($a, '--')));
[$command, $dir] = [$args[0] ?? '', $args[1] ?? ''];

try {
    if ($command === 'seed') {
        $head = FileDocumentState::seed($dir, $args[2], rtrim((string) file_get_contents($args[3]), "\n"), (int) ($args[4] ?? 1), (int) ($args[5] ?? 0));
        emit(['ok' => true, 'result' => ['etag' => $head->etag(), 'sha256' => $head->sha256]]);
    }
    $runtime = in_array('--fault=tamper-candidate', $flags, true) ? new TamperingRuntime(runtime()) : runtime();
    $owner = new WorkbookOwner(new FileDocumentState($dir), $runtime);
    if (in_array('--mutant=no-structure-guard', $flags, true)) {
        $owner->rebaseStructureGuard = false;
    }
    if (in_array('--barrier', $flags, true)) {
        // Explicit barrier for the concurrency test: announce, then block until the harness answers.
        $owner->beforePublish = static function (): void {
            fwrite(STDERR, "BARRIER before_publish\n");
            fgets(STDIN);
        };
    }
    $result = match ($command) {
        'open' => $owner->open(),
        'read' => $owner->read(array_map(static fn (\stdClass $r): array => (array) $r, StrictJson::decode($args[2]))),
        'status' => $owner->status($args[2]),
        'commit' => $owner->commit(
            $args[2],
            StrictJson::decode($args[6]),
            $args[3],
            $args[4] === '-' ? null : $args[4],
            $args[5] === '-' ? null : (int) $args[5],
        ),
        default => throw new \InvalidArgumentException("unknown command {$command}"),
    };
    emit(['ok' => true, 'result' => $result]);
} catch (OwnerException $e) {
    emit(['ok' => false, 'error' => ['code' => $e->publicCode, 'status' => $e->httpStatus, 'message' => $e->getMessage(), 'detail' => $e->detail]]);
} catch (WireException $e) {
    emit(['ok' => false, 'error' => ['code' => 'invalid_request', 'status' => 400, 'message' => $e->getMessage(), 'detail' => ['wire_code' => $e->wireCode]]]);
}
