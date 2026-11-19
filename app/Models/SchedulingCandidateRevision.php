<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchedulingCandidateRevision extends Model
{
    use HasFactory;

    protected $table = 'scheduling_candidate_revisions';

    protected $fillable = [
        'uuid',
        'scheduling_candidate_id',
        'parent_revision_id',
        'revision_number',
        'status',
        'validation_status',
        'content_canonical',
        'content_sha256',
        'base_snapshot_sha256',
        'base_revision_sha256',
        'version',
        'change_summary',
        'created_by_user_id',
        'updated_by_user_id',
        'validated_by_user_id',
        'submitted_at',
        'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SchedulingCandidate::class, 'scheduling_candidate_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(CandidateRevisionStatusEvent::class, 'scheduling_candidate_revision_id');
    }
}
