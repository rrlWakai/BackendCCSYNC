<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class StudentSubjectEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'subject_offering_id',
        'status',
        'enrolled_at',
        'dropped_at',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'dropped_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $enrollment): void {
            if (! $enrollment->isDirty('status')) {
                return;
            }

            if ($enrollment->exists
                && in_array($enrollment->getOriginal('status'), ['dropped', 'withdrawn'], true)) {
                throw new LogicException(
                    'A dropped or withdrawn enrollment status cannot be changed without enrollment-period history.',
                );
            }

            if ($enrollment->status === 'dropped' && $enrollment->dropped_at === null) {
                $enrollment->dropped_at = now();
            }

            if ($enrollment->status === 'withdrawn' && $enrollment->withdrawn_at === null) {
                $enrollment->withdrawn_at = now();
            }
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(StudentAttendance::class, 'student_subject_enrollment_id');
    }

    public function attendanceExcuseRequests(): HasMany
    {
        return $this->hasMany(AttendanceExcuseRequest::class, 'student_subject_enrollment_id');
    }
}
