<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $path = public_path('app/index.html');
    abort_unless(is_file($path), 404);

    return response((string) file_get_contents($path), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
});
