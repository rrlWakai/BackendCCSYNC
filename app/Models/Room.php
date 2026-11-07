<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'building',
        'floor',
        'capacity',
        'room_type',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    public function roomAllocations(): HasMany
    {
        return $this->hasMany(RoomAllocation::class);
    }

    public function scheduleConflicts(): HasMany
    {
        return $this->hasMany(ScheduleConflict::class);
    }
}
