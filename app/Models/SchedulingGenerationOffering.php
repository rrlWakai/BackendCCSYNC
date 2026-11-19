<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulingGenerationOffering extends Model
{
    use HasFactory;

    protected $table = 'scheduling_generation_offerings';

    protected $fillable = [
        'scheduling_generation_id',
        'subject_offering_id',
        'is_required',
        'inclusion_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(SchedulingGeneration::class, 'scheduling_generation_id');
    }

    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }
}
