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
        'name' => $name,
        'email' => uniqid($name.'-', true).'@example.test',
        'password' => $password,
    ]);
}

/**
 * @return array{0: array<string, mixed>, 1: ?string, 2: string}
 */
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
    $raw = (string) $response->getContent();
    check(
        $response->getStatusCode() === $status,
        "$method $path expected $status, got ".$response->getStatusCode()." body ".$raw,
    );

    return [json_decode($raw, true, flags: JSON_THROW_ON_ERROR), $response->headers->get('ETag'), $raw];
}

/**
 * @param array<string, mixed> $node
 */
function assertTree(array $node, string $type): void
{
    check(($node['type'] ?? null) === $type, "expected node $type");
}

/** @param array<string, mixed> $doc */
function findType(array $doc, string $type): bool
{
    if (($doc['type'] ?? null) === $type) {
        return true;
    }
    foreach ($doc['content'] ?? [] as $child) {
        if (is_array($child) && findType($child, $type)) {
            return true;
        }
    }
    foreach ($doc['marks'] ?? [] as $mark) {
        if (is_array($mark) && findType($mark, $type)) {
            return true;
        }
    }

    return false;
}

$fixtures = getenv('FIXTURES_DIR') ?: '';
check($fixtures !== '' && is_dir($fixtures), 'FIXTURES_DIR is required');
$fixtureFile = getenv('FIXTURE_FILE') ?: '';
check($fixtureFile !== '', 'FIXTURE_FILE is required');

/** @var array<string, mixed> $rich */
$rich = json_decode((string) file_get_contents($fixtures.'/rich-text.json'), true, flags: JSON_THROW_ON_ERROR);
/** @var array<string, mixed> $markdown */
$markdown = json_decode((string) file_get_contents($fixtures.'/markdown.json'), true, flags: JSON_THROW_ON_ERROR);

$fixturePassword = bin2hex(random_bytes(24));
$owner = user('Owner', $fixturePassword);
$reader = user('Reader', $fixturePassword);
$drive = Vaulter::drive($owner)->asSystem('create fixture');
foreach ([[$owner, RoleRegistry::OWNER], [$reader, RoleRegistry::READER]] as [$actor, $role]) {
    $drive->access()->grant($drive->root(), new GrantInput(subject: Subject::ofModel($actor), role: $role));
}
$driveId = (string) $drive->snapshot()->id;

[$richCreated] = requestAs($owner, 'POST', 'drives/'.$driveId.'/documents', 201, [
    'name' => 'Rich text',
    'type' => 'vaulter/rich-text',
    'content' => $rich,
]);
[$markdownCreated] = requestAs($owner, 'POST', 'drives/'.$driveId.'/documents', 201, [
    'name' => 'Markdown',
    'type' => 'vaulter/markdown',
    'content' => $markdown,
]);
$richId = (string) $richCreated['data']['id'];
$markdownId = (string) $markdownCreated['data']['id'];

[$richNode] = requestAs($owner, 'GET', 'nodes/'.$richId, 200);
[$markdownNode] = requestAs($owner, 'GET', 'nodes/'.$markdownId, 200);
[$readerRich] = requestAs($reader, 'GET', 'nodes/'.$richId, 200);
check($richNode['data']['presentation']['viewer']['key'] === 'vaulter/tiptap', 'rich viewer key');
check($richNode['data']['presentation']['viewer']['props']['preset'] === 'rich-text', 'rich preset');
check($markdownNode['data']['presentation']['viewer']['props']['preset'] === 'markdown', 'markdown preset');
check($richNode['data']['abilities']['edit'] === true, 'owner can edit');
check($readerRich['data']['abilities']['edit'] === false, 'reader cannot edit');

[$listed] = requestAs($owner, 'GET', 'drives/'.$driveId.'/nodes', 200);
$names = array_column($listed['data'], 'name');
check(in_array('Rich text', $names, true) && in_array('Markdown', $names, true), 'drive lists both documents');
[$readerListed] = requestAs($reader, 'GET', 'drives/'.$driveId.'/nodes', 200);
$readerNames = array_column($readerListed['data'], 'name');
check(in_array('Rich text', $readerNames, true) && in_array('Markdown', $readerNames, true), 'reader lists both documents');

[$richContent, $richTag] = requestAs($owner, 'GET', 'documents/'.$richId.'/content', 200);
[, $richTagAgain] = requestAs($owner, 'GET', 'documents/'.$richId.'/content', 200);
check(is_string($richTag) && $richTag === $richTagAgain, 'rich ETag is stable');
check(findType($richContent['data']['content'], 'heading'), 'stored heading');
check(findType($richContent['data']['content'], 'link'), 'stored link');
check(findType($richContent['data']['content'], 'image'), 'stored image');
check(findType($richContent['data']['content'], 'table'), 'stored table');
assertTree($richContent['data']['content'], 'doc');

[$markdownContent] = requestAs($owner, 'GET', 'documents/'.$markdownId.'/content', 200);
check(findType($markdownContent['data']['content'], 'heading'), 'stored markdown heading');
check(!findType($markdownContent['data']['content'], 'image'), 'markdown stores no image');
check(!findType($markdownContent['data']['content'], 'table'), 'markdown stores no table');
check(!findType($markdownContent['data']['content'], 'underline'), 'markdown stores no underline');

file_put_contents($fixtureFile, json_encode([
    'driveId' => $driveId,
    'owner' => $owner->email,
    'reader' => $reader->email,
    'password' => $fixturePassword,
    'richText' => ['id' => $richId, 'name' => 'Rich text'],
    'markdown' => ['id' => $markdownId, 'name' => 'Markdown'],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "DOCUMENTS-REST-PASS\n";
