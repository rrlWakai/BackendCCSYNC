<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessorRegistrationReview extends Model
{
    protected $fillable = [
        'account_user_id',
        'actor_user_id',
        'action',
        'reason',
        'registration_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'registration_snapshot' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
