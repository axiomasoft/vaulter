<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Drive', 'Uploads', 'Feature');
