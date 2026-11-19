<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchedulingSnapshot extends Model
{
    use HasFactory;

    protected $table = 'scheduling_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'academic_term_id',
        'version',
        'contract_version',
        'content_canonical',
        'content_sha256',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(SchedulingGeneration::class, 'scheduling_snapshot_id');
    }
}
