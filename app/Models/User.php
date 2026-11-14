<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'role_id',
        'name',
        'email',
        'password',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function professor(): HasOne
    {
        return $this->hasOne(Professor::class);
    }

    public function professorRegistrationReviews(): HasMany
    {
        return $this->hasMany(ProfessorRegistrationReview::class, 'account_user_id');
    }

    public function departmentPersonnelPrograms(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'department_personnel_programs')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role?->code, $roles, true);
    }
}
