<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Load\Http\Controllers\MetricsController;

Route::get('/metrics', [MetricsController::class, 'index']);
Route::post('/metrics/run/open', [MetricsController::class, 'open']);
Route::post('/metrics/run/finalize', [MetricsController::class, 'finalize']);
