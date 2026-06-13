<?php

declare(strict_types=1);

namespace DocVault\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD endpoints for documents. Skeleton only — handlers are implemented in
 * milestone M1 (see docvault-spec.md §7). Every action currently returns 501.
 */
final class DocumentController
{
    public function index(Request $request): JsonResponse
    {
        return $this->notImplemented();
    }

    public function store(Request $request): JsonResponse
    {
        return $this->notImplemented();
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->notImplemented();
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->notImplemented();
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->notImplemented();
    }

    private function notImplemented(): JsonResponse
    {
        return new JsonResponse(['message' => 'Not implemented yet.'], 501);
    }
}
