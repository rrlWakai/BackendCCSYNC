<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentAcademicPlacement extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'academic_term_id',
        'program_id',
        'year_level_id',
        'major_id',
        'section_id',
        'status',
        'is_current',
        'effective_on',
        'ends_on',
        'promotion_run_id',
        'promoted_from_placement_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'effective_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function yearLevel(): BelongsTo
    {
        return $this->belongsTo(YearLevel::class);
    }

    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function promotionRun(): BelongsTo
    {
        return $this->belongsTo(PromotionRun::class);
    }

    public function promotedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'promoted_from_placement_id');
    }

    public function promotedTo(): HasMany
    {
        return $this->hasMany(self::class, 'promoted_from_placement_id');
    }
}
