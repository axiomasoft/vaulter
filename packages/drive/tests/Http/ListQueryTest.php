<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Http\Support\ListQuery;
use Vaulter\Http\Resources\PageResponse;

it('reads per_page and cursor of the query string into the list options of the drive', function (): void {
    $options = ListQuery::options(Request::create('/', 'GET', ['per_page' => '25', 'cursor' => 'abc']));

    expect($options)->toBeInstanceOf(ListOptions::class)
        ->and($options->perPage)->toBe(25)
        ->and($options->cursor)->toBe('abc');
});

it('defaults the page size and drops an empty cursor', function (): void {
    $options = ListQuery::options(Request::create('/', 'GET', ['cursor' => '']));

    expect($options->perPage)->toBe(PageResponse::DEFAULT_PER_PAGE)
        ->and($options->cursor)->toBeNull();
});

it('refuses a page size out of range with a 422 validation error', function (string $perPage): void {
    ListQuery::options(Request::create('/', 'GET', ['per_page' => $perPage]));
})->with(['0', '201', 'many'])->throws(ValidationException::class);
