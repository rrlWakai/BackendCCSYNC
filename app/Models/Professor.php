<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Professor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'employee_number',
        'program_id',
        'employment_type',
        'specialization',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function facultyAvailability(): HasMany
    {
        return $this->hasMany(FacultyAvailability::class);
    }

    public function facultyAssignments(): HasMany
    {
        return $this->hasMany(FacultyAssignment::class);
    }

    public function scheduleAssignments(): HasManyThrough
    {
        return $this->hasManyThrough(
            ScheduleAssignment::class,
            FacultyAssignment::class,
            'professor_id',
            'faculty_assignment_id',
        );
    }

    public function scheduleConflicts(): HasMany
    {
        return $this->hasMany(ScheduleConflict::class);
    }
}
