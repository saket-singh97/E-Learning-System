<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Tenancy\TenantModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('isolates every registered tenant model across tenants', function (string $modelClass) {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $currentTenant = app(CurrentTenant::class);

    $currentTenant->set($tenantA);
    $modelClass::create(TenantModelRegistry::seedAttributes($modelClass));

    $currentTenant->set($tenantB);
    $rowB = $modelClass::create(TenantModelRegistry::seedAttributes($modelClass));

    $currentTenant->set($tenantA);

    expect($modelClass::all()->pluck('id'))->not->toContain($rowB->id);
    expect($modelClass::find($rowB->id))->toBeNull();

    $affected = $modelClass::where('id', $rowB->id)->update(TenantModelRegistry::updateAttributes($modelClass));
    expect($affected)->toBe(0);

    $currentTenant->clear();
    expect(fn () => $modelClass::create(TenantModelRegistry::seedAttributes($modelClass)))
        ->toThrow(MissingTenantContextException::class);
})->with(array_combine(
    array_map(fn (string $class) => class_basename($class), TenantModelRegistry::MODELS),
    TenantModelRegistry::MODELS
));

it('fails when a tenant-scoped model in app/Domains is missing from the registry', function () {
    $offenders = [];

    foreach (glob(app_path('Domains/*/Models/*.php')) as $file) {
        $class = 'App\\Domains\\'.basename(dirname($file, 2)).'\\Models\\'.basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $usesTrait = in_array(BelongsToTenant::class, class_uses_recursive($class), true);

        if ($usesTrait && ! in_array($class, TenantModelRegistry::MODELS, true)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});
