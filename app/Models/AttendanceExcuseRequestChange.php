<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceExcuseRequestChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'attendance_excuse_request_id',
        'previous_status',
        'new_status',
        'reason',
        'changed_by_user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function excuseRequest(): BelongsTo
    {
        return $this->belongsTo(AttendanceExcuseRequest::class, 'attendance_excuse_request_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
