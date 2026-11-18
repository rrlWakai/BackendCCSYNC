<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Major extends Model
{
    use HasFactory;

    protected $fillable = [
        'program_id',
        'code',
        'name',
        'description',
        'status',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function curriculumRequirements(): HasMany
    {
        return $this->hasMany(CurriculumRequirement::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function majorSelectionPeriods(): HasMany
    {
        return $this->hasMany(MajorSelectionPeriod::class);
    }

    public function majorSelections(): HasMany
    {
        return $this->hasMany(StudentMajorSelection::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(StudentAcademicPlacement::class);
    }
}
