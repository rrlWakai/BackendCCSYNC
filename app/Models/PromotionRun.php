<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_academic_year_id',
        'target_academic_year_id',
        'source_term_id',
        'target_term_id',
        'status',
        'prepared_by',
        'prepared_at',
        'finalized_by',
        'finalized_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'prepared_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function sourceAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'source_academic_year_id');
    }

    public function targetAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'target_academic_year_id');
    }

    public function sourceTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'source_term_id');
    }

    public function targetTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'target_term_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionRunItem::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(StudentAcademicPlacement::class);
    }
}
