<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function professors(): HasMany
    {
        return $this->hasMany(Professor::class);
    }

    public function departmentPersonnelUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_personnel_programs')
            ->withTimestamps();
    }
}
