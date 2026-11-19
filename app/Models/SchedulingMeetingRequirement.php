<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulingMeetingRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_plan_id',
        'meeting_type',
        'frequency_per_week',
        'duration_minutes',
        'modality',
        'required_room_type',
        'mandatory',
        'allowed_days',
        'valid_start_times',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'frequency_per_week' => 'integer',
            'duration_minutes' => 'integer',
            'mandatory' => 'boolean',
            'allowed_days' => 'array',
            'valid_start_times' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SchedulingMeetingPlan::class, 'meeting_plan_id');
    }
}
