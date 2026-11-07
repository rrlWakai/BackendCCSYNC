<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentSubjectEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'subject_offering_id',
        'status',
        'enrolled_at',
        'dropped_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'dropped_at' => 'datetime',
        ];
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
}
