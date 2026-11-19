<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CandidateStatusEvent extends Model
{
    use HasFactory;

    protected $table = 'candidate_status_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
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

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SchedulingCandidate::class, 'scheduling_candidate_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
