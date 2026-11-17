<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceExcuseRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_session_id',
        'student_subject_enrollment_id',
        'requested_by_user_id',
        'reason',
        'attachment_path',
        'status',
        'decided_by_user_id',
        'decision_reason',
        'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentSubjectEnrollment::class, 'student_subject_enrollment_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(AttendanceExcuseRequestChange::class)
            ->orderBy('changed_at');
    }
}
