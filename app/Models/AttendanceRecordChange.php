<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecordChange extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'student_attendance_id',
        'previous_status',
        'new_status',
        'previous_check_in_at',
        'new_check_in_at',
        'previous_remarks',
        'new_remarks',
        'correction_reason',
        'changed_by_user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(StudentAttendance::class, 'student_attendance_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
