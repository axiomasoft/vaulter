<?php

declare(strict_types=1);

/*
 * BC check CLI (D14/D18). Run from a trusted read-only tool checkout T; the candidate repository is DATA only:
 *   php bc-check.php --root=<candidate repo> --candidate=<S sha> --policy=<trusted policy.json> --policy-rev=<T sha>
 *                    --exceptions=<trusted bc-exceptions.json> [--today=YYYY-MM-DD]
 * Exit 0 compatible (or approved_initial_release), 1 BREAK/REVIEW or no predecessor, 2 untrusted input. No network, no fetch.
 */

use Vaulter\Release\Contract\ContractError;
use Vaulter\Release\Contract\ReleaseCheck;
use Vaulter\Release\Contract\TrustPolicy;

foreach (glob(__DIR__.'/Contract/*.php') ?: [] as $file) {
    require_once $file;
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.+)$/', $argument, $m) === 1) {
        $options[$m[1]] = $m[2];
    }
}
try {
    foreach (['root', 'candidate', 'policy', 'policy-rev', 'exceptions'] as $required) {
        if (!isset($options[$required])) {
            throw new ContractError("missing --{$required}");
        }
    }
    $policy = TrustPolicy::parse((string) @file_get_contents($options['policy']), 'policy');
    $result = ReleaseCheck::run($options['root'], $options['candidate'], $options['policy-rev'], $policy, (string) @file_get_contents($options['exceptions']), ReleaseCheck::toolDigest(__DIR__), $options['today'] ?? date('Y-m-d'));
} catch (ContractError $e) {
    $result = ['exit' => 2, 'lines' => [], 'changes' => [['error' => $e->getMessage()]], 'suppressed' => 0];
}
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
exit($result['exit']);
