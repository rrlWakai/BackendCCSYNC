<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    use HasFactory;

    public const CATEGORIES = [
        'general',
        'academic',
        'class_suspension',
        'examination',
        'department',
        'event',
        'advisory',
    ];

    public const PRIORITIES = [
        'normal',
        'important',
        'urgent',
    ];

    public const AUDIENCE_SCOPES = [
        'all',
        'custom',
    ];

    public const STATUSES = [
        'draft',
        'published',
        'archived',
    ];

    protected $fillable = [
        'author_user_id',
        'title',
        'summary',
        'content',
        'category',
        'priority',
        'audience_scope',
        'status',
        'published_at',
        'expires_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(AnnouncementTarget::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(AnnouncementHistory::class);
    }
}
