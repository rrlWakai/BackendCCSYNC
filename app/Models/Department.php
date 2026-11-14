<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'code',
        'name',
        'status',
    ];

    public function professors(): HasMany
    {
        return $this->hasMany(Professor::class);
    }
}
