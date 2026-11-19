<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CandidateRoomAllocation extends Model
{
    use HasFactory;

    protected $table = 'candidate_room_allocations';

    protected $fillable = [
        'candidate_meeting_placement_id',
        'room_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Generated candidate room allocations are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Generated candidate room allocations are immutable.');
        });
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(CandidateMeetingPlacement::class, 'candidate_meeting_placement_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
