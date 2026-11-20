<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ClassScheduleEvent extends Model
{
    use HasFactory;

    protected $table = 'class_schedule_events';

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
            throw new LogicException('Schedule audit events cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Schedule audit events cannot be deleted.');
        });
    }

    public function classSchedule(): BelongsTo
    {
        return $this->belongsTo(ClassSchedule::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(TimetablePublication::class);
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(CandidateMeetingPlacement::class, 'candidate_meeting_placement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
