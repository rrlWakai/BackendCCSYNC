<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomOccupancy extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'scheduled_room_id',
        'professor_id',
        'class_schedule_id',
        'schedule_assignment_id',
        'room_allocation_id',
        'room_qr_identity_id',
        'checked_in_by_user_id',
        'class_date',
        'scheduled_start_at',
        'scheduled_end_at',
        'checked_in_at',
        'checked_out_at',
        'checkout_source',
        'mismatch_reason',
        'discrepancy_status',
        'discrepancy_resolved_at',
        'discrepancy_resolved_by_user_id',
        'discrepancy_resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'class_date' => 'date',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'discrepancy_resolved_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function scheduledRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'scheduled_room_id');
    }

    public function professor(): BelongsTo
    {
        return $this->belongsTo(Professor::class);
    }

    public function classSchedule(): BelongsTo
    {
        return $this->belongsTo(ClassSchedule::class);
    }

    public function scheduleAssignment(): BelongsTo
    {
        return $this->belongsTo(ScheduleAssignment::class);
    }

    public function roomAllocation(): BelongsTo
    {
        return $this->belongsTo(RoomAllocation::class);
    }

    public function qrIdentity(): BelongsTo
    {
        return $this->belongsTo(RoomQrIdentity::class, 'room_qr_identity_id');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by_user_id');
    }

    public function discrepancyResolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discrepancy_resolved_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RoomOccupancyEvent::class);
    }
}
