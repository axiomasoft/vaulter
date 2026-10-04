<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HostProject extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

it('resolves a host node parameter with the host model', function (): void {
    Schema::create('host_projects', function (Blueprint $table): void {
        $table->id();
    });
    $project = HostProject::create();
    Route::get('/projects/{node}', fn (HostProject $node) => (string) $node->getKey())
        ->middleware(SubstituteBindings::class);
    $this->get('/projects/'.$project->getKey())->assertOk()->assertSee((string) $project->getKey());
});

it('returns the problem code for a missing Vaulter storage', function (): void {
    $this->getJson('api/vaulter/storages/999999/nodes')->assertNotFound()->assertJsonPath('code', 'drive_not_found');
});

it('returns the problem code for a missing Vaulter node', function (): void {
    $this->getJson('api/vaulter/nodes/999999')->assertNotFound()->assertJsonPath('code', 'node_not_found');
});
