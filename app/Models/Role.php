<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    public const ADMINISTRATOR = 'administrator';

    public const DEPARTMENT_PERSONNEL = 'department_personnel';

    public const PROFESSOR = 'professor';

    public const STUDENT = 'student';

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
