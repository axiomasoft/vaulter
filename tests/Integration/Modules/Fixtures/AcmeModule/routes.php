<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule\AcmePingController;

Route::get('api/vaulter/v1/acme/ping', AcmePingController::class)->name('vaulter.v1.acme.ping');
