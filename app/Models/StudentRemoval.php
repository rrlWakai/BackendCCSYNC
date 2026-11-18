<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRemoval extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESTORED = 'restored';

    public const STATUS_PERMANENTLY_DELETED = 'permanently_deleted';

    protected $fillable = [
        'student_id',
        'removed_by_user_id',
        'restored_by_user_id',
        'status',
        'previous_user_status',
        'previous_student_status',
        'removed_at',
        'restore_deadline',
        'restored_at',
        'permanent_deletion_started_at',
        'permanently_deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
            'restore_deadline' => 'datetime',
            'restored_at' => 'datetime',
            'permanent_deletion_started_at' => 'datetime',
            'permanently_deleted_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeDueForDeletion(Builder $query): Builder
    {
        return $query->pending()->where('restore_deadline', '<=', now());
    }

    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->restore_deadline);
    }
}
