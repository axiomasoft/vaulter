<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Facades\Vaulter;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function user(string $name, string $password): User
{
    return User::query()->create([
        'name' => $name, 'email' => uniqid().'@example.test', 'password' => $password,
    ]);
}
function requestAs(User $user, string $method, string $path, int $status, ?array $body = null, ?string $tag = null): array
{
    global $kernel;
    Auth::guard('web')->setUser($user);
    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($tag !== null) {
        $headers['HTTP_IF_MATCH'] = $tag;
    }
    $request = Request::create('/api/vaulter/v1/'.$path, $method, [], [], [], $headers, $body ? json_encode($body) : null);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    check($response->getStatusCode() === $status, "$method $path expected $status, got ".$response->getStatusCode());

    return [json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR), $response->headers->get('ETag')];
}
function document(string $text): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

$fixturePassword = bin2hex(random_bytes(24));
$owner = user('Owner', $fixturePassword);
$reader = user('Reader', $fixturePassword);
$drive = Vaulter::drive($owner)->asSystem('create fixture');
foreach ([[$owner, RoleRegistry::OWNER], [$reader, RoleRegistry::READER]] as [$actor, $role]) {
    $drive->access()->grant($drive->root(), new GrantInput(subject: Subject::ofModel($actor), role: $role));
}
[$created] = requestAs($owner, 'POST', 'drives/'.$drive->snapshot()->id.'/documents', 201, ['name' => 'Spec', 'content' => document('Initial')]);
$path = 'documents/'.$created['data']['id'].'/content';
[$before, $tagA] = requestAs($owner, 'GET', $path, 200);
[, $tagB] = requestAs($owner, 'GET', $path, 200);
check($before['data']['state_version'] === 1 && $before['data']['content'] === document('Initial'), 'initial state');
check(is_string($tagA) && $tagA === $tagB, 'expected matching ETags');
[$edit, $nextTag] = requestAs($owner, 'PUT', $path, 200, ['content' => document('New')], $tagA);
check($edit['data']['state_version'] === 2 && is_string($nextTag) && $nextTag !== $tagA, 'saved version');
[$stale] = requestAs($owner, 'PUT', $path, 412, ['content' => document('Stale')], $tagB);
check($stale['code'] === 'document_state_mismatch', 'stale write');
[, $readerTag] = requestAs($reader, 'GET', $path, 200);
[$denied] = requestAs($reader, 'PUT', $path, 403, ['content' => document('Unauthorized')], $readerTag);
check($denied['code'] === 'permission_denied', 'reader access');
[$final] = requestAs($owner, 'GET', $path, 200);
check($final['data']['state_version'] === 2 && $final['data']['content'] === document('New'), 'stored content');
file_put_contents(getenv('FIXTURE_FILE'), json_encode([
    'id' => $created['data']['id'], 'owner' => $owner->email, 'reader' => $reader->email,
    'password' => $fixturePassword,
], JSON_THROW_ON_ERROR));
echo "CMS-REST-PASS\n";
