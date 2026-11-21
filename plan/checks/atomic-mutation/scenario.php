<?php

declare(strict_types=1);

// Scenarios for AtomicDocumentMutation + SingleTextOwner + Core routes on PostgreSQL 18 with two databases
// (OWNER = Documents, CORE = AI Core). Every worker is a separate PHP process with its own connections.
// Concurrency waits are observed through pg_blocking_pids(), never through sleep.

require __DIR__.'/../../examples/reference/atomic-mutation/autoload.php';

use VaulterRef\Ai\Core\Data\CommandScope;
use VaulterRef\Ai\Core\Persistence\Proposals;
use VaulterRef\Ai\Core\Support\SecretBox;
use VaulterRef\Ai\Documents\Writing\SingleTextOwner;
use VaulterRef\Ai\Documents\Writing\TextReplacement;
use VaulterRef\Database\Pg;

const FIXTURE_DIGEST = '73ae0653dc7b339151e295629775c224395862ab1cbb0267e7c09849834e7847';
const PROJECT = 'project-a';
const SUBJECT = 'user/42';

$keyHex = bin2hex(random_bytes(32));
$owner = Pg::connect((string) getenv('OWNER_DSN'), 'ref-harness');
$core = Pg::connect((string) getenv('CORE_DSN'), 'ref-harness-core');
$proposals = new Proposals($core, new SecretBox('ref-k1', (string) hex2bin($keyHex)));
$failures = 0;

function check(bool $ok, string $label): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'OK   ' : 'FAIL ').$label."\n";
}

/** @return list<array<string, mixed>> */
function p42Segments(string $amount): array
{
    return [
        ['type' => 'text', 'text' => 'Покупатель оплачивает счёт в течение '],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => $amount],
        ['type' => 'text', 'text' => ' рабочих дней.'],
    ];
}

/** @return array<string, mixed> */
function fixtureDoc(): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 1, 'blockId' => 'h1'], 'content' => [['type' => 'text', 'text' => '4. Оплата']]],
        ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => p42Segments('15')],
        ['type' => 'paragraph', 'attrs' => ['blockId' => 'p71'], 'content' => [['type' => 'text', 'text' => 'Срок рассмотрения претензии — 10 рабочих дней.']]],
    ]];
}

$drive = $owner->value('SELECT uuidv7()::text');
$folder = $owner->value('SELECT uuidv7()::text');
$owner->transaction(function (Pg $db) use ($drive, $folder): void {
    $db->execute("INSERT INTO v_drives (id, tenant_id, owner_id, owner_type, name) VALUES (CAST(:d AS uuid), 1, 1, 'organization', 'Office')", ['d' => $drive]);
    $db->execute("INSERT INTO v_nodes (id, tenant_id, drive_id, kind, name, name_key) VALUES (CAST(:f AS uuid), 1, CAST(:d AS uuid), 'folder', 'Contracts', 'contracts')", ['f' => $folder, 'd' => $drive]);
    $db->execute('UPDATE v_drives SET root_node_id = CAST(:f AS uuid) WHERE id = CAST(:d AS uuid)', ['f' => $folder, 'd' => $drive]);
    $db->execute("INSERT INTO v_node_grants (id, tenant_id, node_id, subject_id, subject_type, role_key, scope) VALUES (uuidv7(), 1, CAST(:f AS uuid), 42, 'user', 'writer', 'subtree')", ['f' => $folder]);
});

/** A rich-text document at generation 3 / position 27 and an approved proposal "15 → 30" in p42. */
function seed(string $label, bool $ownGrantOnly = false, string $digest = FIXTURE_DIGEST): array
{
    global $owner, $proposals, $drive, $folder;
    $node = $owner->value('SELECT uuidv7()::text');
    $owner->transaction(function (Pg $db) use ($node, $label, $ownGrantOnly, $drive, $folder): void {
        $db->execute(<<<'SQL'
            INSERT INTO v_nodes (id, tenant_id, drive_id, parent_id, kind, name, name_key, inherit_grants)
            VALUES (CAST(:n AS uuid), 1, CAST(:d AS uuid), CAST(:f AS uuid), 'document', :label, :label, CAST(:inherit AS boolean))
            SQL, ['n' => $node, 'd' => $drive, 'f' => $folder, 'label' => $label, 'inherit' => $ownGrantOnly ? 'false' : 'true']);
        if ($ownGrantOnly) {
            $db->execute("INSERT INTO v_node_grants (id, tenant_id, node_id, subject_id, subject_type, role_key, scope) VALUES (uuidv7(), 1, CAST(:n AS uuid), 42, 'user', 'writer', 'item')", ['n' => $node]);
        }
        $document = $db->value(<<<'SQL'
            INSERT INTO v_documents (id, tenant_id, node_id, type, schema_version, write_mode, collab_epoch, state_version)
            VALUES (uuidv7(), 1, CAST(:n AS uuid), 'vaulter/rich-text', 2, 'snapshot', 3, 27) RETURNING id::text
            SQL, ['n' => $node]);
        $content = fixtureDoc();
        $encoded = \VaulterRef\Documents\Content\ContentHasher::encode($content);
        $db->execute('INSERT INTO v_document_payloads (document_id, content, content_hash, content_size) VALUES (CAST(:d AS uuid), CAST(:c AS jsonb), :h, :s)',
            ['d' => $document, 'c' => json_encode($content, JSON_UNESCAPED_UNICODE), 'h' => hash('sha256', $encoded), 's' => strlen($encoded)]);
    });
    $proposal = proposal($node, $digest);

    return ['node' => $node, 'proposal' => $proposal, 'digest' => $digest];
}

function proposal(string $node, string $digest, string $writeMode = 'snapshot', int $generation = 3): string
{
    global $core, $proposals;
    $id = (string) $core->value('SELECT uuidv7()::text');
    $p42 = fixtureDoc()['content'][1];
    $plan = ['proposal_id' => $id, 'writer' => SingleTextOwner::KEY, 'document_type' => 'vaulter/rich-text', 'schema_version' => 2,
        'write_mode' => $writeMode, 'generation' => $generation, 'block_id' => 'p42', 'expected' => '15', 'replacement' => '30',
        'marks' => [['type' => 'bold']], 'source_block_hash' => TextReplacement::blockHash($p42),
        'expires_at' => (string) $core->value("SELECT to_char((now() + interval '1 hour') AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')")];
    $proposals->store(new CommandScope('1', PROJECT, SUBJECT, $node, 'unused'), $id, 'text.replace_exact@1', 'rt-text-27', 3, 27, $digest, $plan);

    return $id;
}

/** @return array{proc: resource, pipes: array<int, resource>, app: string} */
function start(array $command, array $env = []): array
{
    global $keyHex, $drive;
    static $n = 0;
    $app = 'ref-worker-'.(++$n);
    $command += ['drive' => $drive, 'project' => PROJECT];
    $proc = proc_open([PHP_BINARY, __DIR__.'/worker.php', json_encode($command, JSON_UNESCAPED_UNICODE)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        ['OWNER_DSN' => getenv('OWNER_DSN'), 'CORE_DSN' => getenv('CORE_DSN'), 'REF_KEY_HEX' => $keyHex, 'REF_APP' => $app,
            'PATH' => getenv('PATH')] + $env);

    return ['proc' => $proc, 'pipes' => $pipes, 'app' => $app];
}

/** @return array{signal: ?int, exit: ?int, out: string, err: string, json: ?array} */
function finish(array $worker): array
{
    $out = stream_get_contents($worker['pipes'][1]);
    $err = stream_get_contents($worker['pipes'][2]);
    do {
        $status = proc_get_status($worker['proc']);
    } while ($status['running'] && usleep(10000) === null);
    proc_close($worker['proc']);
    $lines = array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => str_starts_with($l, '{')));
    if (getenv('REF_VERBOSE') && (trim($err) !== '' || $lines === [])) {
        fwrite(STDERR, "[{$worker['app']}] ".trim($out.' '.$err)."\n");
    }

    return ['signal' => $status['signaled'] ? $status['termsig'] : null, 'exit' => $status['signaled'] ? null : $status['exitcode'],
        'out' => $out, 'err' => $err, 'json' => $lines === [] ? null : json_decode(end($lines), true)];
}

function run(array $command, array $env = []): array
{
    $worker = start($command, $env);
    fclose($worker['pipes'][0]);

    return finish($worker);
}

/** Barrier: the worker's OWNER backend waits on a lock held by $holderPid. */
function waitBlocked(string $app, int $holderPid): void
{
    global $owner;
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        if ($owner->value('SELECT bool_or(:h = ANY(pg_blocking_pids(pid))) FROM pg_stat_activity WHERE application_name = :a',
            ['h' => $holderPid, 'a' => $app])) {
            return;
        }
        usleep(20000);
    }
    throw new RuntimeException("{$app} did not wait on backend {$holderPid}");
}

function counts(string $node): array
{
    global $owner;

    return $owner->row(<<<'SQL'
        SELECT d.collab_epoch AS generation, d.state_version AS position, d.write_mode,
               (SELECT count(*) FROM v_ai_text_commands c WHERE c.resource_id = d.node_id) AS commands,
               (SELECT count(*) FROM v_ai_text_commands c WHERE c.resource_id = d.node_id AND c.state = 'committed') AS committed,
               (SELECT count(*) FROM v_outbox o WHERE o.aggregate_id = d.node_id::text AND o.type = 'document.content_updated') AS content_events,
               (SELECT count(*) FROM v_outbox o WHERE o.type = 'office.text_command_committed' AND o.payload->>'resource_id' = d.node_id::text) AS command_events,
               (SELECT count(*) FROM v_ai_text_command_evidence e JOIN v_ai_text_commands c USING (receipt_id) WHERE c.resource_id = d.node_id) AS evidence,
               p.content #>> '{content,1,content,1,text}' AS p42_amount,
               p.content #> '{content,1,content,1,marks}' AS p42_marks,
               p.content #>> '{content,2,content,0,text}' AS p71
        FROM v_documents d JOIN v_document_payloads p ON p.document_id = d.id WHERE d.node_id = CAST(:n AS uuid)
        SQL, ['n' => $node]);
}

function routeReceipt(string $node, string $command): ?string
{
    global $core;

    return $core->value('SELECT receipt_id FROM v_ai_owner_routes WHERE resource_id = :r AND command_id = :c', ['r' => $node, 'c' => $command]);
}

function body(array $r): array
{
    return $r['json']['body'] ?? [];
}

echo "== A. lost response after owner commit, then snapshot→journal, retry in a new process\n";
$a = seed('contract-17');
$base = ['resource' => $a['node'], 'command' => 'edit-1', 'proposal' => $a['proposal'], 'digest' => $a['digest']];
$edit = run(['op' => 'human_edit', 'resource' => $a['node'], 'block' => 'p71',
    'segments' => [['type' => 'text', 'text' => 'Срок рассмотрения претензии — 12 рабочих дней.']]]);
check(($edit['json']['position'] ?? null) === 28, 'human edit of p71: 27 → 28');
$lost = run(['op' => 'commit'] + $base, ['REF_FAULT' => 'after_owner_commit=kill']);
$owned = $owner->row('SELECT receipt_id::text AS receipt, source_generation AS g, source_position AS p FROM v_ai_text_commands WHERE resource_id = CAST(:n AS uuid)', ['n' => $a['node']]);
check($lost['signal'] === SIGKILL && $lost['json'] === null, 'first commit process killed after owner COMMIT, no answer left the process');
check($owned !== null && [(int) $owned['g'], (int) $owned['p']] === [3, 29], 'owner holds committed receipt at 3/29 (separate connection)');
check(routeReceipt($a['node'], 'edit-1') === null, 'CORE route exists with receipt_id NULL (locator not written)');
$switch = run(['op' => 'to_journal', 'resource' => $a['node'], 'generation' => 3, 'position' => 29]);
check(($switch['json'] ?? []) === ['generation' => 4, 'position' => 30, 'faults' => ['serialization_injected' => 0]], 'write mode snapshot→journal: 3/29 → 4/30');
$mutant = run(['op' => 'commit'] + $base, ['REF_MUTANT' => 'mode_first']);
check((body($mutant)['error']['code'] ?? null) === 'generation_changed', 'MUTANT mode_first answers generation_changed: the committed receipt would be lost');
$retry = run(['op' => 'commit'] + $base);
$result = body($retry)['result'] ?? [];
check($retry['json']['http'] === 200 && ($result['receipt_id'] ?? null) === $owned['receipt']
    && $result['marker'] === ['generation' => 3, 'position' => 29], 'retry: same receipt_id and marker 3/29, not the current 4/30');
check(routeReceipt($a['node'], 'edit-1') === $owned['receipt'], 'retry filled the CORE locator');
$status = run(['op' => 'status'] + $base);
check((body($status)['result']['receipt_id'] ?? null) === $owned['receipt'], 'status in a third process returns the same receipt');
$c = counts($a['node']);
check($c['commands'] === 1 && $c['committed'] === 1 && $c['command_events'] === 1 && $c['evidence'] === 1, 'one command row, one command event, one evidence for the key');
check($c['content_events'] === 2 && $c['position'] === 30 && $c['write_mode'] === 'journal', 'content events: human 28 + agent 29 only; head 4/30 journal');
check($c['p42_amount'] === '30' && json_decode($c['p42_marks'], true) === [['type' => 'bold']] && str_contains($c['p71'], '12'), 'p42 "30" keeps bold, human p71 "12" survived');
$p2 = proposal($a['node'], hash('sha256', 'another plan'));
$conflict = run(['op' => 'commit', 'resource' => $a['node'], 'command' => 'edit-1', 'proposal' => $p2, 'digest' => hash('sha256', 'another plan')]);
check((body($conflict)['error']['code'] ?? null) === 'idempotency_conflict', 'same key, other proposal/digest: idempotency_conflict at CORE');
$direct = run(['op' => 'owner_commit_direct', 'resource' => $a['node'], 'command' => 'edit-1', 'proposal' => $p2, 'digest' => hash('sha256', 'another plan')]);
check(($direct['json']['state'] ?? null) === 'conflict', 'same key, other fingerprint sent straight to the owner: conflict');
$twoClicks = run(['op' => 'commit', 'resource' => $a['node'], 'command' => 'edit-2', 'proposal' => $a['proposal'], 'digest' => $a['digest']]);
check((body($twoClicks)['error']['code'] ?? null) === 'idempotency_conflict', 'same proposal under a second command_id: idempotency_conflict (UNIQUE proposal_id)');
$owner->transaction(fn (Pg $db): int => $db->execute("DELETE FROM v_node_grants WHERE node_id = CAST(:f AS uuid)", ['f' => $folder])
    + $db->execute('UPDATE v_nodes SET access_version = access_version + 1 WHERE id = CAST(:f AS uuid)', ['f' => $folder]));
$hiddenCommit = run(['op' => 'commit'] + $base);
$hiddenStatus = run(['op' => 'status'] + $base);
check((body($hiddenCommit)['error']['code'] ?? null) === 'resource_unavailable' && !str_contains($hiddenCommit['out'], $owned['receipt']), 'after revoke: commit retry → resource_unavailable, receipt not disclosed');
check((body($hiddenStatus)['error']['code'] ?? null) === 'resource_unavailable' && !str_contains($hiddenStatus['out'], $owned['receipt']), 'after revoke: status → resource_unavailable, receipt not disclosed');
check(counts($a['node'])['commands'] === 1, 'still one command row after all retries');
$owner->execute("INSERT INTO v_node_grants (id, tenant_id, node_id, subject_id, subject_type, role_key, scope) VALUES (uuidv7(), 1, CAST(:f AS uuid), 42, 'user', 'writer', 'subtree')", ['f' => $folder]);

echo "== B. revoke holds the node lock; writer waits, then decides on the committed revoke\n";
foreach (['reference' => [], 'MUTANT authorize_before_lock' => ['REF_MUTANT' => 'authorize_before_lock']] as $variant => $env) {
    $b = seed('race-'.str_replace(' ', '-', $variant), ownGrantOnly: true);
    $revoker = Pg::connect((string) getenv('OWNER_DSN'), 'ref-revoker');
    $revoker->pdo->beginTransaction();
    $revoker->row('SELECT id FROM v_nodes WHERE id = CAST(:n AS uuid) FOR UPDATE', ['n' => $b['node']]);
    $revoker->execute('DELETE FROM v_node_grants WHERE node_id = CAST(:n AS uuid)', ['n' => $b['node']]);
    $revoker->execute('UPDATE v_nodes SET access_version = access_version + 1 WHERE id = CAST(:n AS uuid)', ['n' => $b['node']]);
    $worker = start(['op' => 'commit', 'resource' => $b['node'], 'command' => 'edit-1', 'proposal' => $b['proposal'], 'digest' => $b['digest']], $env);
    fclose($worker['pipes'][0]);
    waitBlocked($worker['app'], $revoker->backendPid());
    $revoker->pdo->commit();
    $r = finish($worker);
    $c = counts($b['node']);
    if ($variant === 'reference') {
        check((body($r)['error']['code'] ?? null) === 'resource_unavailable' && $c['position'] === 27 && $c['commands'] === 0,
            'reference: blocked on N, then Edit denied; position 27, no command row');
    } else {
        check((body($r)['result']['state'] ?? null) === 'committed' && $c['position'] === 28,
            'MUTANT authorize_before_lock wrote after the revoke committed (position 28): today\'s writeContent order is unsafe');
    }
}

echo "== C. two processes, same key, independent connections\n";
foreach (['commit' => 'go', 'rollback' => 'fail'] as $variant => $signal) {
    $cdoc = seed('same-key-'.$variant);
    $cmd = ['op' => 'commit', 'resource' => $cdoc['node'], 'command' => 'edit-1', 'proposal' => $cdoc['proposal'], 'digest' => $cdoc['digest']];
    $x = start($cmd, ['REF_FAULT' => 'owner_before_commit=pause']);
    $paused = fgets($x['pipes'][1]);
    $xPid = (int) substr(trim((string) $paused), strlen('PAUSED '));
    $y = start($cmd);
    fclose($y['pipes'][0]);
    waitBlocked($y['app'], $xPid);
    fwrite($x['pipes'][0], $signal."\n");
    fclose($x['pipes'][0]);
    $rx = finish($x);
    $ry = finish($y);
    $c = counts($cdoc['node']);
    $stored = $owner->value('SELECT receipt_id::text FROM v_ai_text_commands WHERE resource_id = CAST(:n AS uuid)', ['n' => $cdoc['node']]);
    $yReceipt = body($ry)['result']['receipt_id'] ?? null;
    if ($variant === 'commit') {
        check((body($rx)['result']['receipt_id'] ?? null) === $stored && $yReceipt === $stored, 'commit: Y waited on X, then returned X\'s receipt');
    } else {
        check($rx['exit'] !== 0 && $yReceipt === $stored, 'rollback: X failed before COMMIT (key stays unresolved), Y produced the only effect');
    }
    check($c['commands'] === 1 && $c['content_events'] === 1 && $c['position'] === 28, "{$variant}: one command row, one content event, position 28");
}

echo "== D. server-side 40001 inside the callback: the whole callback is retried\n";
$d = seed('retry-40001');
$rd = run(['op' => 'commit', 'resource' => $d['node'], 'command' => 'edit-1', 'proposal' => $d['proposal'], 'digest' => $d['digest']],
    ['REF_FAULT' => 'owner_after_receipt=serialization_once']);
$c = counts($d['node']);
check(($rd['json']['faults']['serialization_injected'] ?? 0) === 1 && $rd['json']['http'] === 200, 'one injected 40001, answer 200');
check($c['commands'] === 1 && $c['content_events'] === 1 && $c['position'] === 28 && (body($rd)['result']['receipt_id'] ?? null) === $owner->value(
    'SELECT receipt_id::text FROM v_ai_text_commands WHERE resource_id = CAST(:n AS uuid)', ['n' => $d['node']]), 'retried attempt: one row, one event, answered receipt = stored receipt');

echo "== E. target changed by a human: durable rejected, no partial write\n";
$e = seed('text-conflict');
$cmdE = ['resource' => $e['node'], 'command' => 'edit-1', 'proposal' => $e['proposal'], 'digest' => $e['digest']];
run(['op' => 'human_edit', 'resource' => $e['node'], 'block' => 'p42', 'segments' => p42Segments('20')]);
$re1 = run(['op' => 'commit'] + $cmdE);
$re2 = run(['op' => 'commit'] + $cmdE);
$se = run(['op' => 'status'] + $cmdE);
$c = counts($e['node']);
check((body($re1)['error']['code'] ?? null) === 'precondition_failed' && (body($re2)['error']['code'] ?? null) === 'precondition_failed', 'commit and retry: precondition_failed');
check((body($se)['result'] ?? null) === ['command_id' => 'edit-1', 'state' => 'rejected', 'reason' => 'precondition_failed'], 'status: rejected/precondition_failed');
check($c['p42_amount'] === '20' && $c['position'] === 28 && $c['commands'] === 1 && $c['committed'] === 0, 'document keeps the human "20", one rejected row');

echo "== F. snapshot→journal before the first commit: the pinned single-text owner refuses\n";
$f = seed('mode-before-commit');
run(['op' => 'to_journal', 'resource' => $f['node'], 'generation' => 3, 'position' => 27]);
$rf = run(['op' => 'commit', 'resource' => $f['node'], 'command' => 'edit-1', 'proposal' => $f['proposal'], 'digest' => $f['digest']]);
$c = counts($f['node']);
check((body($rf)['error']['code'] ?? null) === 'generation_changed' && $c['content_events'] === 0 && $c['p42_amount'] === '15', 'generation_changed, no single replace in journal mode');

echo $failures === 0 ? "OK atomic-mutation: all scenarios and mutants distinguished\n" : "FAILED: {$failures}\n";
exit($failures === 0 ? 0 : 1);
