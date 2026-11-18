<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'label',
        'starts_on',
        'ends_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function academicTerms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class);
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
