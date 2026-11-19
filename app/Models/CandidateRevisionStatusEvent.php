<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CandidateRevisionStatusEvent extends Model
{
    use HasFactory;

    protected $table = 'candidate_revision_status_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expected_version' => 'integer',
            'observed_version' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit events cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit events cannot be deleted.');
        });
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
