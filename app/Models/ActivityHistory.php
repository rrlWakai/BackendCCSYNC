<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'activity_history';

    protected $fillable = [
        'activity_id',
        'acted_by_user_id',
        'action',
        'previous_status',
        'new_status',
        'previous_values',
        'new_values',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
