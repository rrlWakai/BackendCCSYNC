<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CandidateMeetingOffering extends Model
{
    use HasFactory;

    protected $table = 'candidate_meeting_offerings';

    protected $fillable = [
        'candidate_meeting_placement_id',
        'subject_offering_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Generated candidate placements are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Generated candidate placements are immutable.');
        });
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(CandidateMeetingPlacement::class, 'candidate_meeting_placement_id');
    }

    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function professors(): HasMany
    {
        return $this->hasMany(CandidateMeetingProfessor::class, 'candidate_meeting_offering_id');
    }
}
