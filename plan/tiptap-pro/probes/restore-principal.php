<?php

declare(strict_types=1);

// Run with a Composer autoloader built from this checkout (PHP 8.4+).
$autoload = $argv[1] ?? dirname(__DIR__, 3).'/vendor/autoload.php';

require $autoload;

use Vaulter\Collaboration\Testing\Fixtures\CollaborationTestUser;
use Vaulter\Collaboration\Testing\Fixtures\FakeHostUserResolver;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\Actor;

$user = (new TestUser)->forceFill(['id' => 1]);
$user->exists = true;
$principal = Actor::user($user)->ref();
$default = new FakeHostUserResolver();
$configured = new FakeHostUserResolver(userType: $principal->type);
$resolved = $configured->find($principal);

$result = [
    'http_fixture_principal_type' => $principal->type,
    'hub_fixture_default_type' => (new CollaborationTestUser())->getMorphClass(),
    'default_fixture_resolves' => $default->find($principal) !== null,
    'matching_fixture_reconstructs_same_principal' => $resolved !== null
        && Actor::user($resolved)->ref()->type === $principal->type
        && (string) Actor::user($resolved)->ref()->id === (string) $principal->id,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;

exit(!$result['default_fixture_resolves'] && $result['matching_fixture_reconstructs_same_principal'] ? 0 : 1);
