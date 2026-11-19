<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateRevisionStatusEvent extends Model
{
    use HasFactory;

    protected $table = 'candidate_revision_status_events';

    public $timestamps = false;

    protected $fillable = [
        'scheduling_candidate_revision_id',
        'from_status',
        'to_status',
        'actor_user_id',
        'reason',
        'expected_version',
        'observed_version',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_version' => 'integer',
            'observed_version' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(SchedulingCandidateRevision::class, 'scheduling_candidate_revision_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
