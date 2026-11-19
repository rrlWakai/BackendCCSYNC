<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SchedulingGeneration extends Model
{
    use HasFactory;

    protected $table = 'scheduling_generations';

    protected $fillable = [
        'uuid',
        'academic_term_id',
        'scheduling_snapshot_id',
        'status',
        'solver_status',
        'result_status',
        'generation_scope',
        'solver_config',
        'solver_version',
        'contract_version',
        'solver_execution_ms',
        'objective_value',
        'best_bound',
        'optimality_proven',
        'time_limit_reached',
        'unscheduled_mandatory_meeting_ids',
        'response_payload',
        'requested_by_user_id',
        'requested_at',
        'started_at',
        'completed_at',
        'failure_code',
        'failure_message',
        'diagnostics',
        'input_metrics',
        'workload_metrics',
        'placement_metrics',
        'retention_until',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'generation_scope' => 'array',
            'solver_config' => 'array',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'diagnostics' => 'array',
            'input_metrics' => 'array',
            'workload_metrics' => 'array',
            'placement_metrics' => 'array',
            'unscheduled_mandatory_meeting_ids' => 'array',
            'response_payload' => 'array',
            'solver_execution_ms' => 'integer',
            'optimality_proven' => 'boolean',
            'time_limit_reached' => 'boolean',
            'retention_until' => 'date',
        ];
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SchedulingSnapshot::class, 'scheduling_snapshot_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(SchedulingGenerationOffering::class, 'scheduling_generation_id');
    }

    public function candidate(): HasOne
    {
        return $this->hasOne(SchedulingCandidate::class, 'scheduling_generation_id');
    }
}
