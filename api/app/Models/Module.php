<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['client_allowed' => 'boolean'];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }
}
