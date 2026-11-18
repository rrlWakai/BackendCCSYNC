<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionRunItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'promotion_run_id',
        'student_id',
        'from_placement_id',
        'from_year_level_id',
        'to_year_level_id',
        'major_id',
        'section_id',
        'status',
        'exception_code',
        'exception_message',
    ];

    public function promotionRun(): BelongsTo
    {
        return $this->belongsTo(PromotionRun::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromPlacement(): BelongsTo
    {
        return $this->belongsTo(StudentAcademicPlacement::class, 'from_placement_id');
    }

    public function fromYearLevel(): BelongsTo
    {
        return $this->belongsTo(YearLevel::class, 'from_year_level_id');
    }

    public function toYearLevel(): BelongsTo
    {
        return $this->belongsTo(YearLevel::class, 'to_year_level_id');
    }

    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
