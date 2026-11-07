<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_schedule_id',
        'class_date',
        'status',
        'created_by_user_id',
        'submitted_at',
        'qr_opened_at',
        'qr_closed_at',
        'cancelled_by_user_id',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'class_date' => 'date',
            'submitted_at' => 'datetime',
            'qr_opened_at' => 'datetime',
            'qr_closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function classSchedule(): BelongsTo
    {
        return $this->belongsTo(ClassSchedule::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(StudentAttendance::class);
    }

    public function qrTokens(): HasMany
    {
        return $this->hasMany(AttendanceQrToken::class);
    }

    public function qrIsActive(): bool
    {
        return $this->qr_opened_at !== null && $this->qr_closed_at === null;
    }
}
