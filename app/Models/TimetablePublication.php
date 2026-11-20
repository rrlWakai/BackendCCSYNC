<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimetablePublication extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'academic_term_id',
        'scheduling_candidate_id',
        'publication_type',
        'previous_publication_id',
        'published_by_user_id',
        'status',
        'published_at',
        'superseded_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SchedulingCandidate::class, 'scheduling_candidate_id');
    }

    public function previousPublication(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_publication_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClassScheduleEvent::class);
    }
}
