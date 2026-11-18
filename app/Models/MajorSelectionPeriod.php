<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MajorSelectionPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_year_id',
        'program_id',
        'target_year_level_id',
        'opens_at',
        'closes_at',
        'allow_changes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'allow_changes' => 'boolean',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function targetYearLevel(): BelongsTo
    {
        return $this->belongsTo(YearLevel::class, 'target_year_level_id');
    }

    public function selections(): HasMany
    {
        return $this->hasMany(StudentMajorSelection::class, 'period_id');
    }
}
