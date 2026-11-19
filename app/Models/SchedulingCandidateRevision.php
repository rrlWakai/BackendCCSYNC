<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SchedulingCandidateRevision extends Model
{
    use HasFactory;

    private const IMMUTABLE_IDENTITY_FIELDS = [
        'uuid',
        'scheduling_candidate_id',
        'parent_revision_id',
        'revision_number',
        'base_snapshot_sha256',
        'base_revision_sha256',
        'created_by_user_id',
    ];

    private const IMMUTABLE_CONTENT_FIELDS = [
        'content_canonical',
        'content_sha256',
    ];

    private const IMMUTABLE_CONTENT_STATUSES = ['validated', 'superseded', 'discarded'];

    protected $table = 'scheduling_candidate_revisions';

    protected $fillable = [
        'uuid',
        'scheduling_candidate_id',
        'parent_revision_id',
        'revision_number',
        'content_canonical',
        'change_summary',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if ($revision->isDirty(self::IMMUTABLE_IDENTITY_FIELDS)) {
                throw new LogicException('Candidate revision identity and provenance are immutable.');
            }

            $originalStatus = (string) $revision->getOriginal('status');
            if (in_array($originalStatus, self::IMMUTABLE_CONTENT_STATUSES, true)
                && $revision->isDirty(self::IMMUTABLE_CONTENT_FIELDS)) {
                throw new LogicException('Historical candidate revision content cannot be overwritten.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Candidate revision history cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SchedulingCandidate::class, 'scheduling_candidate_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(CandidateRevisionStatusEvent::class, 'scheduling_candidate_revision_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
