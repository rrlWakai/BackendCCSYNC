<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_term_id',
        'subject_offering_id',
        'allowed_absences',
        'effective_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'allowed_absences' => 'integer',
            'effective_at' => 'datetime',
        ];
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
