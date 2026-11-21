<?php

declare(strict_types=1);

// One office process. Input: argv[1] = JSON command. Env: OWNER_DSN, CORE_DSN, REF_KEY_HEX, REF_APP (application_name),
// REF_FAULT ("point=action,..."), REF_MUTANT (mode_first | authorize_before_lock). Output: one JSON line on stdout.

require __DIR__.'/../../examples/reference/atomic-mutation/autoload.php';
require __DIR__.'/mutants.php';

use VaulterRef\Ai\Core\Data\CommandScope;
use VaulterRef\Ai\Core\Protocol\RequestFingerprint;
use VaulterRef\Ai\Core\Support\SecretBox;
use VaulterRef\Database\Pg;
use VaulterRef\Documents\Contracts\LockedDocumentMutation;
use VaulterRef\Drive\DriveContext;
use VaulterRef\Drive\SqlAccessGate;
use VaulterRef\Support\FaultPoint;
use VaulterRef\Wiring;

final class EnvFaults implements FaultPoint
{
    public int $serializationInjected = 0;

    /** @param array<string, string> $actions */
    public function __construct(private readonly array $actions) {}

    public static function fromEnv(): self
    {
        $actions = [];
        foreach (array_filter(explode(',', (string) getenv('REF_FAULT'))) as $pair) {
            [$point, $action] = explode('=', $pair, 2);
            $actions[$point] = $action;
        }

        return new self($actions);
    }

    public function reach(string $point, ?Pg $db = null): void
    {
        $action = $this->actions[$point] ?? null;
        if ($action === 'kill') {
            posix_kill(getmypid(), SIGKILL); // no shutdown handlers, no answer: the response is lost
        }
        if ($action === 'pause' && $db !== null) {
            fwrite(STDOUT, 'PAUSED '.$db->backendPid()."\n");
            fflush(STDOUT);
            if (trim((string) fgets(STDIN)) === 'fail') {
                throw new RuntimeException('injected operational failure before COMMIT');
            }
        }
        if ($action === 'serialization_once' && $db !== null && $this->serializationInjected === 0) {
            $this->serializationInjected++;
            // A real server-side 40001 inside the transaction; VaulterDatabase-style retry must rerun the callback.
            $db->execute("DO \$\$BEGIN RAISE EXCEPTION 'injected serialization failure' USING ERRCODE = 'serialization_failure'; END\$\$");
        }
    }
}

$command = json_decode($argv[1], true, 32, JSON_THROW_ON_ERROR);
$app = (string) (getenv('REF_APP') ?: 'ref-worker');
$owner = Pg::connect((string) getenv('OWNER_DSN'), $app);
$core = Pg::connect((string) getenv('CORE_DSN'), $app.'-core');
$box = new SecretBox('ref-k1', (string) hex2bin((string) getenv('REF_KEY_HEX')));
$faults = EnvFaults::fromEnv();
$access = new SqlAccessGate;
$mutant = (string) getenv('REF_MUTANT');
$atomic = $mutant === 'authorize_before_lock' ? new AuthorizeBeforeLock($owner, $access) : null;
$wiring = new Wiring($owner, $core, $box, $faults, $access, $atomic);
if ($mutant === 'mode_first') {
    $wiring = new Wiring($owner, $core, $box, $faults, $access, null, new ModeFirstOwner($wiring->atomic, $wiring->textOwner));
}
$context = new DriveContext(1, $command['drive'], 'user', (int) ($command['subject_id'] ?? 42), 'corr-'.$app);
$scope = fn (): CommandScope => CommandScope::of($context, $command['project'], $command['resource'], $command['command']);

$result = match ($command['op']) {
    'commit' => $wiring->commit->handle($context, $scope(), $command['proposal'], $command['digest']),
    'status' => $wiring->status->handle($context, $scope()),
    'owner_commit_direct' => (array) $wiring->textOwner->commit($context, $scope(),
        RequestFingerprint::of('text.replace_exact@1', $command['proposal'], $command['digest']),
        $wiring->proposals->load($scope(), $command['proposal'])['plan']),
    'to_journal' => $wiring->writeMode->toJournal($context, $command['resource'], $command['generation'], $command['position']),
    'human_edit' => $wiring->atomic->run($context, $command['resource'], function (LockedDocumentMutation $locked) use ($command): array {
        $state = $locked->snapshot();
        $doc = $state->content;
        foreach ($doc['content'] as $i => $block) {
            if (($block['attrs']['blockId'] ?? null) === $command['block']) {
                $doc['content'][$i]['content'] = $command['segments'];
            }
        }
        $after = $locked->replace($doc, $state->position);

        return ['generation' => $after->generation, 'position' => $after->position];
    }),
};
$result['faults'] = ['serialization_injected' => $faults->serializationInjected];
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
