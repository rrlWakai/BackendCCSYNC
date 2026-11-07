<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Activity extends Model
{
    use HasFactory;

    public const CATEGORIES = [
        'seminar',
        'workshop',
        'orientation',
        'meeting',
        'competition',
        'conference',
        'research',
        'celebration',
        'organization',
        'training',
        'other',
    ];

    public const STATUSES = [
        'draft',
        'published',
        'cancelled',
        'completed',
    ];

    public const AUDIENCE_SCOPES = [
        'all',
        'custom',
    ];

    protected $fillable = [
        'created_by_user_id',
        'room_id',
        'title',
        'summary',
        'description',
        'category',
        'organizer_name',
        'venue_name',
        'location_details',
        'audience_scope',
        'starts_at',
        'ends_at',
        'status',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by_user_id',
        'completed_at',
        'completed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ActivityTarget::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(ActivityHistory::class);
    }
}
