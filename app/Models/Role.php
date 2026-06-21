<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'permissions', 'is_active'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
