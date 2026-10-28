<?php

declare(strict_types=1);

require getenv('TENANT_LANE_AUTOLOAD');
if (getenv('VAULTER_USE_SOURCE') === '1') {
    $loader = new ClassLoader;
    $loader->addPsr4('Vaulter'.chr(92).'Tenant'.chr(92), getenv('VAULTER_CONSUMER_CHECKOUT').'/packages/tenant/src');
    $loader->addPsr4('Vaulter'.chr(92), getenv('VAULTER_CONSUMER_CHECKOUT').'/packages/core/src');
    $loader->register(true);

}

use Composer\Autoload\ClassLoader;
use Tenant\Data\IsolationIdentity;
use Vaulter\Jobs\JobRequest;
use Vaulter\Jobs\PreparedJob;
use Vaulter\Jobs\PublicationContext;
use Vaulter\Tenant\ScalarProjection;

$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $count++;
};
$refuse = static function (Closure $call) use ($check): void {
    try {
        $call();
    } catch (InvalidArgumentException) {
        $check(true, 'refusal');

        return;
    }
    throw new RuntimeException('Expected bounded input refusal');
};
$p = new ScalarProjection;
$a = $p->encode(new IsolationIdentity('alpha', 'same'));
$b = $p->encode(new IsolationIdentity('beta', 'same'));
$check($a !== $b && $a === 't1.'.rtrim(strtr(base64_encode(chr(5).'alphasame'), '+/', '-_'), '='), 'injective exact frozen bytes');
$check($p->encode(new IsolationIdentity('alpha', '01')) !== $p->encode(new IsolationIdentity('alpha', '1')), 'no numeric normalization');
$check($p->encode(new IsolationIdentity('alpha', 'A')) !== $p->encode(new IsolationIdentity('alpha', 'a')), 'case significant');
$check(strlen($p->encode(new IsolationIdentity('ns', str_repeat('x', 42)))) === 63, 'maximum exact scalar length');
$refuse(fn () => $p->encode(new IsolationIdentity(str_repeat('a', 33), 'id')));
$refuse(fn () => $p->encode(new IsolationIdentity('ns', str_repeat('x', 43))));
$fixed = new ScalarProjection('alpha');
$check($fixed->encode(new IsolationIdentity('alpha', 'same')) === $a, 'fixed namespace same encoding');
$refuse(fn () => $fixed->encode(new IsolationIdentity('beta', 'same')));
$request = new JobRequest('vaulter.drive:purge', ['driveId' => '01']);
$context = new PublicationContext('tenant:context', 1, ['envelope' => '{}', 'purpose' => 'vaulter:job']);
$job = new PreparedJob($request, $context);
$check($job->request === $request && $job->context === $context, 'immutable neutral original stamp');
$refuse(fn () => new JobRequest('vaulter.drive:purge', ['driveId' => 1]));
$refuse(fn () => new JobRequest('vaulter.drive:purge', ['driveId' => new stdClass]));
$refuse(fn () => new PublicationContext('tenant:context', 0, []));
$refuse(fn () => new PublicationContext('tenant:context', 1, ['envelope' => str_repeat('x', 32769)]));
echo "PASS frozen injective scalar and bounded neutral DTOs: {$count} assertions\n";
