<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'email', 'address', 'notes'])]
class Customer extends Model
{
    use BelongsToTenant, HasFactory;

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
