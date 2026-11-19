<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchedulingFullTimeWorkdayPolicy extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $table = 'scheduling_full_time_workday_policies';

    protected $fillable = [
        'academic_term_id',
        'version',
        'status',
        'approved_at',
        'approved_by',
        'standard_days',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'approved_at' => 'datetime',
            'standard_days' => 'array',
        ];
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(SchedulingFullTimeWorkdayException::class, 'policy_id')
            ->orderBy('professor_id');
    }
}
