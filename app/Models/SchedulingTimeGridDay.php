<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchedulingTimeGridDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'time_grid_id',
        'day_of_week',
        'operating_start',
        'operating_end',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public function timeGrid(): BelongsTo
    {
        return $this->belongsTo(SchedulingTimeGrid::class, 'time_grid_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(SchedulingTimeGridSlot::class, 'time_grid_day_id')
            ->orderBy('start_time');
    }
}
