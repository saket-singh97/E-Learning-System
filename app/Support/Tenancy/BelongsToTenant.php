<?php

namespace App\Support\Tenancy;

use App\Exceptions\MissingTenantContextException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (app(CurrentTenant::class)->isBound()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', app(CurrentTenant::class)->id());
            }
        });

        static::creating(function (Model $model) {
            $model->tenant_id = app(CurrentTenant::class)->id();
        });
    }
}
