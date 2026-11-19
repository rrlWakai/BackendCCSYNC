<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulingTimeGridSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'time_grid_day_id',
        'start_time',
        'end_time',
        'label',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(SchedulingTimeGridDay::class, 'time_grid_day_id');
    }
}
