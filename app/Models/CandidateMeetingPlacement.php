<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandidateMeetingPlacement extends Model
{
    use HasFactory;

    protected $table = 'candidate_meeting_placements';

    protected $fillable = [
        'uuid',
        'scheduling_candidate_id',
        'offering_meeting_requirement_id',
        'previous_published_schedule_id',
        'meeting_instance_id',
        'occurrence_number',
        'day_of_week',
        'start_time',
        'end_time',
        'duration_minutes',
        'meeting_type',
        'modality',
        'is_primary',
        'status',
        'previous_meeting_instance_id',
        'placement_change_status',
        'previous_day_of_week',
        'previous_start_time',
        'previous_end_time',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_number' => 'integer',
            'day_of_week' => 'integer',
            'duration_minutes' => 'integer',
            'is_primary' => 'boolean',
            'previous_day_of_week' => 'integer',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SchedulingCandidate::class, 'scheduling_candidate_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SchedulingMeetingRequirement::class, 'offering_meeting_requirement_id');
    }

    public function previousPublishedSchedule(): BelongsTo
    {
        return $this->belongsTo(ClassSchedule::class, 'previous_published_schedule_id');
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(CandidateMeetingOffering::class, 'candidate_meeting_placement_id');
    }

    public function professors(): HasMany
    {
        return $this->hasMany(CandidateMeetingProfessor::class, 'candidate_meeting_placement_id');
    }

    public function roomAllocations(): HasMany
    {
        return $this->hasMany(CandidateRoomAllocation::class, 'candidate_meeting_placement_id');
    }
}
