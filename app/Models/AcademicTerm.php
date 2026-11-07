<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicTerm extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_year',
        'term',
        'name',
        'starts_on',
        'ends_on',
        'is_current',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function subjectOfferings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }

    public function facultyAvailability(): HasMany
    {
        return $this->hasMany(FacultyAvailability::class);
    }

    public function scheduleConflicts(): HasMany
    {
        return $this->hasMany(ScheduleConflict::class);
    }
}
