<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentMajorSelectionChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_major_selection_id',
        'previous_major_id',
        'new_major_id',
        'changed_by',
        'reason',
    ];

    public function selection(): BelongsTo
    {
        return $this->belongsTo(StudentMajorSelection::class, 'student_major_selection_id');
    }

    public function previousMajor(): BelongsTo
    {
        return $this->belongsTo(Major::class, 'previous_major_id');
    }

    public function newMajor(): BelongsTo
    {
        return $this->belongsTo(Major::class, 'new_major_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
