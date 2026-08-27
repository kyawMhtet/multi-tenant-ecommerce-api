<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($tenantId = static::currentTenantId()) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        }
    }

    public static function currentTenantId(): ?int
    {
        if (app()->bound('tenant')) {
            return app('tenant')->id;
        }

        return auth()->user()?->tenant_id;
    }
}
