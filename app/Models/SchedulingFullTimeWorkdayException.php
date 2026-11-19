<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulingFullTimeWorkdayException extends Model
{
    use HasFactory;

    protected $table = 'scheduling_full_time_workday_exceptions';

    protected $fillable = [
        'policy_id',
        'professor_id',
        'authorized_additional_days',
    ];

    protected function casts(): array
    {
        return [
            'authorized_additional_days' => 'array',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(SchedulingFullTimeWorkdayPolicy::class, 'policy_id');
    }

    public function professor(): BelongsTo
    {
        return $this->belongsTo(Professor::class);
    }
}
