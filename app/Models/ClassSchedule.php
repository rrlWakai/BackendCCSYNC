<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_offering_id',
        'day_of_week',
        'start_time',
        'end_time',
        'schedule_type',
        'modality',
        'status',
        'timetable_publication_id',
        'candidate_meeting_placement_id',
        'meeting_instance_id',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'timetable_publication_id' => 'integer',
            'candidate_meeting_placement_id' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }

    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }

    public function roomAllocations(): HasMany
    {
        return $this->hasMany(RoomAllocation::class);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(ScheduleConflict::class);
    }

    public function conflictingRecords(): HasMany
    {
        return $this->hasMany(ScheduleConflict::class, 'conflicting_class_schedule_id');
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(TimetablePublication::class);
    }

    public function candidatePlacement(): BelongsTo
    {
        return $this->belongsTo(CandidateMeetingPlacement::class, 'candidate_meeting_placement_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClassScheduleEvent::class);
    }
}
