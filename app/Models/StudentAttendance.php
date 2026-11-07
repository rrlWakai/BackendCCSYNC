<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentAttendance extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_QR = 'qr';

    protected $table = 'student_attendance';

    protected $fillable = [
        'attendance_session_id',
        'student_subject_enrollment_id',
        'status',
        'check_in_at',
        'remarks',
        'submission_source',
        'recorded_by_user_id',
        'updated_by_user_id',
    ];

    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentSubjectEnrollment::class, 'student_subject_enrollment_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(AttendanceRecordChange::class)->orderBy('changed_at');
    }
}
