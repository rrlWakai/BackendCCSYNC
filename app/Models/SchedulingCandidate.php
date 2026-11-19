<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SchedulingCandidate extends Model
{
    use HasFactory;

    protected $table = 'scheduling_candidates';

    protected $fillable = [
        'uuid',
        'scheduling_generation_id',
        'academic_term_id',
    ];

    protected $hidden = [
        'approved_by_user_id',
        'approved_at',
        'rejected_by_user_id',
        'rejected_at',
        'rejection_reason',
        'published_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'validation_summary' => 'array',
            'validation_errors' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Solved scheduling candidates are immutable. Create a candidate revision instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('Solved scheduling candidates are immutable. Create a candidate revision instead.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(SchedulingGeneration::class, 'scheduling_generation_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function previousCandidate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_candidate_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(CandidateMeetingPlacement::class, 'scheduling_candidate_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(CandidateStatusEvent::class, 'scheduling_candidate_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SchedulingCandidateRevision::class, 'scheduling_candidate_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
