<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'student_number',
        'program_id',
        'section_id',
        'admitted_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'admitted_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentSubjectEnrollment::class);
    }

    public function subjectOfferings(): BelongsToMany
    {
        return $this->belongsToMany(
            SubjectOffering::class,
            'student_subject_enrollments',
            'student_id',
            'subject_offering_id',
        )->withPivot(['status', 'enrolled_at', 'dropped_at'])->withTimestamps();
    }
}
