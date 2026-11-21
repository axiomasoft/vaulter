<?php

declare(strict_types=1);

/**
 * Router for `php -S 127.0.0.1:PORT php/http.php` with VAULTER_STORE_ROOT=<dir>.
 * Synthetic owner host for the Vue call point (src/vue-client.ts). In vaulter-sheets this is
 * routes/sheets.php + src/Http/WorkbookController.php behind host middleware; authentication
 * and Drive ACL are BOUNDARY here (the fixture has one trusted editor).
 *
 *   GET  /workbooks/{nodeId}                         -> snapshot, marker, ETag header
 *   POST /workbooks/{nodeId}/commands  (If-Match)    -> receipt | 409 version_conflict | 422 ...
 *   GET  /workbooks/{nodeId}/commands/{commandId}    -> stored receipt (lost-response recovery)
 *   GET  /workbooks/{nodeId}/ranges?sheet_id=&range= -> recalculated values with marker
 */

namespace Vaulter\Reference\Workbook;

require_once __DIR__ . '/Owner.php';

function respond(int $status, mixed $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/workbooks/([a-z0-9-]{1,64})(?:/(commands|ranges)(?:/([A-Za-z0-9._-]{1,128}))?)?$#', (string) $path, $m) !== 1) {
    respond(404, ['error' => ['code' => 'not_found']]);
    return true;
}
$nodeId = $m[1];
$dir = rtrim((string) getenv('VAULTER_STORE_ROOT'), '/') . '/' . $nodeId;
if (!is_file("{$dir}/head.json")) {
    respond(404, ['error' => ['code' => 'not_found']]);
    return true;
}
$owner = new WorkbookOwner(
    new FileDocumentState($dir),
    new ProcessWorkbookRuntime([getenv('VAULTER_NODE') ?: 'node', __DIR__ . '/../src/cli.ts']),
);
$method = $_SERVER['REQUEST_METHOD'];
$section = $m[2] ?? '';
try {
    if ($method === 'GET' && $section === '') {
        $opened = $owner->open();
        respond(200, $opened, ['ETag' => $opened['etag']]);
    } elseif ($method === 'POST' && $section === 'commands' && !isset($m[3])) {
        $body = StrictJson::decode((string) file_get_contents('php://input'));
        $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? null;
        if ($ifMatch === null) {
            respond(428, ['error' => ['code' => 'precondition_required']]);
        } else {
            // The browser may choose only the two editor policies; revalidate_by_id belongs to WorkbookOperations (SDK).
            $policy = in_array($body->policy ?? 'exact_base', ['exact_base', 'rebase_cells'], true) ? ($body->policy ?? 'exact_base') : 'invalid';
            $receipt = $owner->commit((string) $body->command_id, $body->commands, $policy, $ifMatch, null);
            $head = (new FileDocumentState($dir))->head();
            respond(200, ['receipt' => $receipt], ['ETag' => $head->etag()]);
        }
    } elseif ($method === 'GET' && $section === 'commands' && isset($m[3])) {
        $receipt = $owner->status($m[3]);
        $receipt === null ? respond(404, ['error' => ['code' => 'not_found']]) : respond(200, ['receipt' => $receipt]);
    } elseif ($method === 'GET' && $section === 'ranges') {
        respond(200, $owner->read([['sheet_id' => (string) ($_GET['sheet_id'] ?? ''), 'range' => (string) ($_GET['range'] ?? '')]]));
    } else {
        respond(405, ['error' => ['code' => 'method_not_allowed']]);
    }
} catch (OwnerException $e) {
    respond($e->httpStatus, ['error' => ['code' => $e->publicCode, 'message' => $e->getMessage(), 'detail' => $e->detail]]);
} catch (WireException $e) {
    respond(400, ['error' => ['code' => 'invalid_request', 'message' => $e->getMessage()]]);
}
return true;
