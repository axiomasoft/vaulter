<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Schema\UpgradePreflight;

return new class extends Migration
{
    public function up(): void
    {
        $problems = app(UpgradePreflight::class)->problems(app(VaulterDatabase::class)->connection());
        if ($problems !== []) {
            throw new UpgradePreflightException($problems);
        }
    }

    public function down(): void {}
};
