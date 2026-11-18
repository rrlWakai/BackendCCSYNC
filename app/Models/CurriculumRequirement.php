<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumRequirement extends Model
{
    use HasFactory;

    public const SEMESTERS = ['first', 'second', 'summer'];

    public const CLASSIFICATIONS = ['common', 'major'];

    protected $fillable = [
        'program_id',
        'year_level_id',
        'semester',
        'major_id',
        'classification',
        'subject_id',
        'requirement_type',
        'status',
    ];

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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
