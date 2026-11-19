<?php

namespace App\Support;

final class PhaseTwoSchemaCatalog
{
    public const DATABASE = 'ccsync_db';

    public const MIGRATION_100003 = '2026_11_22_100003_create_scheduling_generation_and_candidate_tables';

    public const MIGRATION_100004 = '2026_11_22_100004_correct_scheduling_candidate_integrity';

    public const MIGRATIONS = [
        self::MIGRATION_100003,
        self::MIGRATION_100004,
    ];

    private const FLEXIBLE_INDEX_NAMES = [
        'scheduling_snapshots_uuid_unique',
        'scheduling_snapshots_content_sha256_unique',
        'scheduling_generations_uuid_unique',
        'scheduling_generations_correlation_id_unique',
        'scheduling_candidates_uuid_unique',
        'scheduling_candidates_scheduling_generation_id_unique',
        'candidate_meeting_placements_uuid_unique',
        'cmp_offering_req_fk',
        'cmp_prev_schedule_fk',
        'cmo_placement_fk',
        'cmpf_placement_fk',
        'cmpf_offering_fk',
        'cra_placement_fk',
        'crse_revision_fk',
        'scheduling_candidate_revisions_uuid_unique',
    ];

    private static function tableDefinitions(): array
    {
        return [
            'scheduling_snapshots' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'uuid' => self::column('uuid', 'char(36)'),
                'academic_term_id' => self::column('foreignId', 'bigint unsigned'),
                'version' => self::column('unsignedInteger', 'int unsigned'),
                'contract_version' => self::column('string', 'varchar(32)', false, null, [32]),
                'content_canonical' => self::column('longText', 'longtext'),
                'content_sha256' => self::column('char', 'char(64)', false, null, [64]),
                'created_by_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'created_at' => self::column('timestamp', 'timestamp'),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('scheduling_snapshots_uuid_unique', ['uuid'], true),
                self::index('scheduling_snapshots_content_sha256_unique', ['content_sha256'], true),
                self::index('scheduling_snapshots_term_version_unique', ['academic_term_id', 'version'], true),
                self::index('scheduling_snapshots_term_created_index', ['academic_term_id', 'created_at']),
                self::index('scheduling_snapshots_academic_term_id_foreign', ['academic_term_id']),
                self::index('scheduling_snapshots_created_by_user_id_foreign', ['created_by_user_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('scheduling_snapshots_academic_term_id_foreign', 'academic_term_id', 'academic_terms'),
                self::foreignKey('scheduling_snapshots_created_by_user_id_foreign', 'created_by_user_id', 'users', 'RESTRICT'),
            ],
            'checks' => [
                self::check('scheduling_snapshots_version_check', 'version > 0'),
                self::check('scheduling_snapshots_hash_check', 'CHAR_LENGTH(content_sha256) = 64'),
            ],
        ],
        'scheduling_generations' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'uuid' => self::column('uuid', 'char(36)'),
                'academic_term_id' => self::column('foreignId', 'bigint unsigned'),
                'scheduling_snapshot_id' => self::column('foreignId', 'bigint unsigned'),
                'status' => self::column('string', 'varchar(16)', false, null, [16]),
                'solver_status' => self::column('string', 'varchar(20)', true, null, [20]),
                'result_status' => self::column('string', 'varchar(20)', true, null, [20]),
                'generation_scope' => self::column('json', 'json'),
                'solver_config' => self::column('json', 'json'),
                'solver_version' => self::column('string', 'varchar(64)', true, null, [64]),
                'contract_version' => self::column('string', 'varchar(32)', false, null, [32]),
                'solver_execution_ms' => self::column('unsignedInteger', 'int unsigned', true),
                'objective_value' => self::column('decimal', 'decimal(20,6)', true, null, [20, 6]),
                'best_bound' => self::column('decimal', 'decimal(20,6)', true, null, [20, 6]),
                'optimality_proven' => self::column('boolean', 'tinyint(1)', true),
                'time_limit_reached' => self::column('boolean', 'tinyint(1)', true),
                'unscheduled_mandatory_meeting_ids' => self::column('json', 'json', true),
                'response_payload' => self::column('json', 'json', true),
                'requested_by_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'requested_at' => self::column('timestamp', 'timestamp'),
                'started_at' => self::column('timestamp', 'timestamp', true),
                'completed_at' => self::column('timestamp', 'timestamp', true),
                'failure_code' => self::column('string', 'varchar(64)', true, null, [64]),
                'failure_message' => self::column('text', 'text', true),
                'diagnostics' => self::column('json', 'json', true),
                'input_metrics' => self::column('json', 'json', true),
                'workload_metrics' => self::column('json', 'json', true),
                'placement_metrics' => self::column('json', 'json', true),
                'retention_until' => self::column('date', 'date', true),
                'correlation_id' => self::column('char', 'char(36)', true, null, [36]),
                'created_at' => self::column('timestamp', 'timestamp', true),
                'updated_at' => self::column('timestamp', 'timestamp', true),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('scheduling_generations_uuid_unique', ['uuid'], true),
                self::index('scheduling_generations_correlation_id_unique', ['correlation_id'], true),
                self::index('scheduling_generations_term_status_requested_index', ['academic_term_id', 'status', 'requested_at']),
                self::index('scheduling_generations_snapshot_index', ['scheduling_snapshot_id']),
                self::index('scheduling_generations_requester_index', ['requested_by_user_id', 'requested_at']),
                self::index('scheduling_generations_academic_term_id_foreign', ['academic_term_id']),
                self::index('scheduling_generations_scheduling_snapshot_id_foreign', ['scheduling_snapshot_id']),
                self::index('scheduling_generations_requested_by_user_id_foreign', ['requested_by_user_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('scheduling_generations_academic_term_id_foreign', 'academic_term_id', 'academic_terms'),
                self::foreignKey('scheduling_generations_scheduling_snapshot_id_foreign', 'scheduling_snapshot_id', 'scheduling_snapshots'),
                self::foreignKey('scheduling_generations_requested_by_user_id_foreign', 'requested_by_user_id', 'users', 'RESTRICT'),
            ],
            'checks' => [
                self::check('scheduling_generations_status_check', "status IN ('queued','running','completed','failed','cancelled')"),
                self::check('scheduling_generations_solver_status_check', "solver_status IS NULL OR solver_status IN ('optimal','feasible','infeasible','unknown','error')"),
                self::check('scheduling_generations_result_status_check', "result_status IS NULL OR result_status IN ('feasible','infeasible','unknown','time_limit')"),
            ],
        ],
        'scheduling_generation_offerings' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'scheduling_generation_id' => self::column('foreignId', 'bigint unsigned'),
                'subject_offering_id' => self::column('foreignId', 'bigint unsigned'),
                'is_required' => self::column('boolean', 'tinyint(1)', false, '1'),
                'inclusion_reason' => self::column('string', 'varchar(32)', false, null, [32]),
                'created_at' => self::column('timestamp', 'timestamp', true),
                'updated_at' => self::column('timestamp', 'timestamp', true),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('scheduling_generation_offerings_generation_offering_unique', ['scheduling_generation_id', 'subject_offering_id'], true),
                self::index('scheduling_generation_offerings_offering_index', ['subject_offering_id']),
                self::index('scheduling_generation_offerings_scheduling_generation_id_foreign', ['scheduling_generation_id']),
                self::index('scheduling_generation_offerings_subject_offering_id_foreign', ['subject_offering_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('scheduling_generation_offerings_scheduling_generation_id_foreign', 'scheduling_generation_id', 'scheduling_generations', 'CASCADE'),
                self::foreignKey('scheduling_generation_offerings_subject_offering_id_foreign', 'subject_offering_id', 'subject_offerings'),
            ],
            'checks' => [
                self::check('scheduling_generation_offerings_reason_check', "inclusion_reason IN ('open_required','published_baseline','fixed','preserved_draft')"),
            ],
        ],
        'scheduling_candidates' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'uuid' => self::column('uuid', 'char(36)'),
                'scheduling_generation_id' => self::column('foreignId', 'bigint unsigned'),
                'academic_term_id' => self::column('foreignId', 'bigint unsigned'),
                'version' => self::column('unsignedInteger', 'int unsigned'),
                'status' => self::column('string', 'varchar(20)', false, null, [20]),
                'validation_status' => self::column('string', 'varchar(20)', false, null, [20]),
                'validation_summary' => self::column('json', 'json', true),
                'validation_errors' => self::column('json', 'json', true),
                'approved_by_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'rejected_by_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'published_by_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'approved_at' => self::column('timestamp', 'timestamp', true),
                'rejected_at' => self::column('timestamp', 'timestamp', true),
                'published_at' => self::column('timestamp', 'timestamp', true),
                'rejection_reason' => self::column('text', 'text', true),
                'previous_candidate_id' => self::column('foreignId', 'bigint unsigned', true),
                'snapshot_sha256' => self::column('char', 'char(64)', false, null, [64]),
                'source_response_sha256' => self::column('char', 'char(64)', true, null, [64]),
                'created_by_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'created_at' => self::column('timestamp', 'timestamp', true),
                'updated_at' => self::column('timestamp', 'timestamp', true),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('scheduling_candidates_uuid_unique', ['uuid'], true),
                self::index('scheduling_candidates_scheduling_generation_id_unique', ['scheduling_generation_id'], true),
                self::index('scheduling_candidates_term_version_unique', ['academic_term_id', 'version'], true),
                self::index('scheduling_candidates_term_status_created_index', ['academic_term_id', 'status', 'created_at']),
                self::index('scheduling_candidates_previous_index', ['previous_candidate_id']),
                self::index('scheduling_candidates_academic_term_id_foreign', ['academic_term_id']),
                self::index('scheduling_candidates_approved_by_user_id_foreign', ['approved_by_user_id']),
                self::index('scheduling_candidates_rejected_by_user_id_foreign', ['rejected_by_user_id']),
                self::index('scheduling_candidates_published_by_user_id_foreign', ['published_by_user_id']),
                self::index('scheduling_candidates_previous_candidate_id_foreign', ['previous_candidate_id']),
                self::index('scheduling_candidates_created_by_user_id_foreign', ['created_by_user_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('scheduling_candidates_scheduling_generation_id_foreign', 'scheduling_generation_id', 'scheduling_generations'),
                self::foreignKey('scheduling_candidates_academic_term_id_foreign', 'academic_term_id', 'academic_terms'),
                self::foreignKey('scheduling_candidates_approved_by_user_id_foreign', 'approved_by_user_id', 'users', 'RESTRICT'),
                self::foreignKey('scheduling_candidates_rejected_by_user_id_foreign', 'rejected_by_user_id', 'users', 'RESTRICT'),
                self::foreignKey('scheduling_candidates_published_by_user_id_foreign', 'published_by_user_id', 'users', 'RESTRICT'),
                self::foreignKey('scheduling_candidates_previous_candidate_id_foreign', 'previous_candidate_id', 'scheduling_candidates', 'RESTRICT'),
                self::foreignKey('scheduling_candidates_created_by_user_id_foreign', 'created_by_user_id', 'users', 'SET NULL'),
            ],
            'checks' => [
                self::check('scheduling_candidates_version_check', 'version > 0'),
                self::check('scheduling_candidates_status_check', "status IN ('generated','under_review','approved','rejected','published')"),
                self::check('scheduling_candidates_validation_status_check', "validation_status IN ('not_validated','valid','invalid')"),
                self::check('scheduling_candidates_hash_check', 'CHAR_LENGTH(snapshot_sha256) = 64'),
                self::check('scheduling_candidates_source_response_hash_check', 'source_response_sha256 IS NULL OR CHAR_LENGTH(source_response_sha256) = 64'),
            ],
        ],
        'candidate_meeting_placements' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'uuid' => self::column('uuid', 'char(36)'),
                'scheduling_candidate_id' => self::column('foreignId', 'bigint unsigned'),
                'offering_meeting_requirement_id' => self::column('foreignId', 'bigint unsigned'),
                'previous_published_schedule_id' => self::column('foreignId', 'bigint unsigned', true),
                'meeting_instance_id' => self::column('string', 'varchar(191)', false, null, [191]),
                'occurrence_number' => self::column('unsignedSmallInteger', 'smallint unsigned'),
                'day_of_week' => self::column('unsignedTinyInteger', 'tinyint unsigned'),
                'start_time' => self::column('time', 'time'),
                'end_time' => self::column('time', 'time'),
                'duration_minutes' => self::column('unsignedSmallInteger', 'smallint unsigned'),
                'meeting_type' => self::column('string', 'varchar(16)', false, null, [16]),
                'modality' => self::column('string', 'varchar(20)', false, null, [20]),
                'is_primary' => self::column('boolean', 'tinyint(1)', false, '1'),
                'status' => self::column('string', 'varchar(20)', false, null, [20]),
                'previous_meeting_instance_id' => self::column('char', 'char(64)', true, null, [64]),
                'placement_change_status' => self::column('string', 'varchar(16)', false, null, [16]),
                'previous_day_of_week' => self::column('unsignedTinyInteger', 'tinyint unsigned', true),
                'previous_start_time' => self::column('time', 'time', true),
                'previous_end_time' => self::column('time', 'time', true),
                'created_at' => self::column('timestamp', 'timestamp', true),
                'updated_at' => self::column('timestamp', 'timestamp', true),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('candidate_meeting_placements_uuid_unique', ['uuid'], true),
                self::index('candidate_meeting_placements_requirement_occurrence_unique', ['scheduling_candidate_id', 'offering_meeting_requirement_id', 'occurrence_number'], true),
                self::index('candidate_meeting_placements_candidate_instance_unique', ['scheduling_candidate_id', 'meeting_instance_id'], true),
                self::index('candidate_meeting_placements_schedule_index', ['scheduling_candidate_id', 'day_of_week', 'start_time', 'end_time']),
                self::index('candidate_meeting_placements_status_index', ['scheduling_candidate_id', 'status']),
                self::index('candidate_meeting_placements_previous_schedule_index', ['previous_published_schedule_id']),
                self::index('candidate_meeting_placements_scheduling_candidate_id_foreign', ['scheduling_candidate_id']),
                self::index('cmp_offering_req_fk', ['offering_meeting_requirement_id']),
                self::index('cmp_prev_schedule_fk', ['previous_published_schedule_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('candidate_meeting_placements_scheduling_candidate_id_foreign', 'scheduling_candidate_id', 'scheduling_candidates', 'CASCADE'),
                self::foreignKey('cmp_offering_req_fk', 'offering_meeting_requirement_id', 'scheduling_meeting_requirements'),
                self::foreignKey('cmp_prev_schedule_fk', 'previous_published_schedule_id', 'class_schedules', 'RESTRICT'),
            ],
            'checks' => [
                self::check('candidate_meeting_placements_occurrence_check', 'occurrence_number > 0'),
                self::check('candidate_meeting_placements_day_check', 'day_of_week BETWEEN 1 AND 5'),
                self::check('candidate_meeting_placements_time_check', 'start_time < end_time'),
                self::check('candidate_meeting_placements_duration_check', 'duration_minutes > 0'),
                self::check('candidate_meeting_placements_modality_check', "modality IN ('face_to_face','online','hybrid')"),
                self::check('candidate_meeting_placements_status_check', "status IN ('proposed','validated','invalid','approved','published','superseded')"),
                self::check('candidate_meeting_placements_change_status_check', "placement_change_status IN ('unchanged','moved','new','removed','not_comparable')"),
            ],
        ],
        'candidate_meeting_offerings' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'candidate_meeting_placement_id' => self::column('foreignId', 'bigint unsigned'),
                'subject_offering_id' => self::column('foreignId', 'bigint unsigned'),
                'is_primary' => self::column('boolean', 'tinyint(1)', false, '1'),
                'created_at' => self::column('timestamp', 'timestamp', true),
                'updated_at' => self::column('timestamp', 'timestamp', true),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('candidate_meeting_offerings_placement_offering_unique', ['candidate_meeting_placement_id', 'subject_offering_id'], true),
                self::index('candidate_meeting_offerings_id_offering_unique', ['id', 'subject_offering_id'], true),
                self::index('candidate_meeting_offerings_offering_index', ['subject_offering_id']),
                self::index('cmo_placement_fk', ['candidate_meeting_placement_id']),
                self::index('candidate_meeting_offerings_subject_offering_id_foreign', ['subject_offering_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('cmo_placement_fk', 'candidate_meeting_placement_id', 'candidate_meeting_placements', 'CASCADE'),
                self::foreignKey('candidate_meeting_offerings_subject_offering_id_foreign', 'subject_offering_id', 'subject_offerings'),
            ],
            'checks' => [],
        ],
        'candidate_meeting_professors' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'candidate_meeting_placement_id' => self::column('foreignId', 'bigint unsigned'),
                'candidate_meeting_offering_id' => self::column('foreignId', 'bigint unsigned'),
                'subject_offering_id' => self::column('foreignId', 'bigint unsigned'),
                'faculty_assignment_id' => self::column('foreignId', 'bigint unsigned'),
                'is_primary' => self::column('boolean', 'tinyint(1)', false, '1'),
                'created_at' => self::column('timestamp', 'timestamp', true),
                'updated_at' => self::column('timestamp', 'timestamp', true),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('candidate_meeting_professors_placement_assignment_unique', ['candidate_meeting_placement_id', 'faculty_assignment_id'], true),
                self::index('candidate_meeting_professors_assignment_index', ['faculty_assignment_id']),
                self::index('cmpf_placement_fk', ['candidate_meeting_placement_id']),
                self::index('cmpf_offering_fk', ['candidate_meeting_offering_id']),
                self::index('candidate_meeting_professors_subject_offering_id_foreign', ['subject_offering_id']),
                self::index('candidate_meeting_professors_faculty_assignment_id_foreign', ['faculty_assignment_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('cmpf_placement_fk', 'candidate_meeting_placement_id', 'candidate_meeting_placements', 'CASCADE'),
                self::foreignKey('cmpf_offering_fk', 'candidate_meeting_offering_id', 'candidate_meeting_offerings', 'CASCADE'),
                self::foreignKey('candidate_meeting_professors_subject_offering_id_foreign', 'subject_offering_id', 'subject_offerings'),
                self::foreignKey('candidate_meeting_professors_faculty_assignment_id_foreign', 'faculty_assignment_id', 'faculty_assignments'),
            ],
            'checks' => [],
        ],
        'candidate_room_allocations' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'candidate_meeting_placement_id' => self::column('foreignId', 'bigint unsigned'),
                'room_id' => self::column('foreignId', 'bigint unsigned'),
                'status' => self::column('string', 'varchar(16)', false, null, [16]),
                'created_at' => self::column('timestamp', 'timestamp', true),
                'updated_at' => self::column('timestamp', 'timestamp', true),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('candidate_room_allocations_placement_room_unique', ['candidate_meeting_placement_id', 'room_id'], true),
                self::index('candidate_room_allocations_room_status_index', ['room_id', 'status']),
                self::index('candidate_room_allocations_placement_status_index', ['candidate_meeting_placement_id', 'status']),
                self::index('cra_placement_fk', ['candidate_meeting_placement_id']),
                self::index('candidate_room_allocations_room_id_foreign', ['room_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('cra_placement_fk', 'candidate_meeting_placement_id', 'candidate_meeting_placements', 'CASCADE'),
                self::foreignKey('candidate_room_allocations_room_id_foreign', 'room_id', 'rooms'),
            ],
            'checks' => [
                self::check('candidate_room_allocations_status_check', "status IN ('proposed','approved','published','released')"),
            ],
        ],
        'candidate_status_events' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'scheduling_candidate_id' => self::column('foreignId', 'bigint unsigned'),
                'from_status' => self::column('string', 'varchar(20)', true, null, [20]),
                'to_status' => self::column('string', 'varchar(20)', false, null, [20]),
                'actor_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'reason' => self::column('text', 'text', true),
                'metadata' => self::column('json', 'json', true),
                'created_at' => self::column('timestamp', 'timestamp'),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('candidate_status_events_candidate_created_index', ['scheduling_candidate_id', 'created_at']),
                self::index('candidate_status_events_scheduling_candidate_id_foreign', ['scheduling_candidate_id']),
                self::index('candidate_status_events_actor_user_id_foreign', ['actor_user_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('candidate_status_events_scheduling_candidate_id_foreign', 'scheduling_candidate_id', 'scheduling_candidates', 'CASCADE'),
                self::foreignKey('candidate_status_events_actor_user_id_foreign', 'actor_user_id', 'users', 'RESTRICT'),
            ],
            'checks' => [
                self::check('candidate_status_events_to_status_check', "to_status IN ('generated','under_review','approved','rejected','published')"),
            ],
        ],
        'scheduling_candidate_revisions' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'uuid' => self::column('uuid', 'char(36)'),
                'scheduling_candidate_id' => self::column('foreignId', 'bigint unsigned'),
                'parent_revision_id' => self::column('foreignId', 'bigint unsigned', true),
                'revision_number' => self::column('unsignedInteger', 'int unsigned'),
                'status' => self::column('string', 'varchar(20)', false, null, [20]),
                'validation_status' => self::column('string', 'varchar(20)', true, null, [20]),
                'content_canonical' => self::column('longText', 'longtext'),
                'content_sha256' => self::column('char', 'char(64)', false, null, [64]),
                'base_snapshot_sha256' => self::column('char', 'char(64)', false, null, [64]),
                'base_revision_sha256' => self::column('char', 'char(64)', true, null, [64]),
                'version' => self::column('unsignedInteger', 'int unsigned', false, '1'),
                'change_summary' => self::column('text', 'text', true),
                'created_by_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'updated_by_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'validated_by_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'submitted_at' => self::column('timestamp', 'timestamp', true),
                'validated_at' => self::column('timestamp', 'timestamp', true),
                'created_at' => self::column('timestamp', 'timestamp', true),
                'updated_at' => self::column('timestamp', 'timestamp', true),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('scheduling_candidate_revisions_uuid_unique', ['uuid'], true),
                self::index('scheduling_candidate_revisions_candidate_number_unique', ['scheduling_candidate_id', 'revision_number'], true),
                self::index('scheduling_candidate_revisions_candidate_status_index', ['scheduling_candidate_id', 'status', 'created_at']),
                self::index('scheduling_candidate_revisions_parent_index', ['parent_revision_id']),
                self::index('scheduling_candidate_revisions_scheduling_candidate_id_foreign', ['scheduling_candidate_id']),
                self::index('scheduling_candidate_revisions_created_by_user_id_foreign', ['created_by_user_id']),
                self::index('scheduling_candidate_revisions_updated_by_user_id_foreign', ['updated_by_user_id']),
                self::index('scheduling_candidate_revisions_validated_by_user_id_foreign', ['validated_by_user_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('scheduling_candidate_revisions_scheduling_candidate_id_foreign', 'scheduling_candidate_id', 'scheduling_candidates', 'CASCADE'),
                self::foreignKey('scheduling_candidate_revisions_parent_revision_id_foreign', 'parent_revision_id', 'scheduling_candidate_revisions', 'SET NULL'),
                self::foreignKey('scheduling_candidate_revisions_created_by_user_id_foreign', 'created_by_user_id', 'users', 'SET NULL'),
                self::foreignKey('scheduling_candidate_revisions_updated_by_user_id_foreign', 'updated_by_user_id', 'users', 'SET NULL'),
                self::foreignKey('scheduling_candidate_revisions_validated_by_user_id_foreign', 'validated_by_user_id', 'users', 'SET NULL'),
            ],
            'checks' => [
                self::check('scheduling_candidate_revisions_status_check', "status IN ('draft','under_review','validated','needs_revision','superseded','discarded')"),
                self::check('scheduling_candidate_revisions_validation_status_check', "validation_status IS NULL OR validation_status IN ('valid','invalid','not_validated')"),
                self::check('scheduling_candidate_revisions_revision_number_check', 'revision_number > 0'),
                self::check('scheduling_candidate_revisions_version_check', 'version > 0'),
                self::check('scheduling_candidate_revisions_content_hash_check', 'CHAR_LENGTH(content_sha256) = 64'),
                self::check('scheduling_candidate_revisions_snapshot_hash_check', 'CHAR_LENGTH(base_snapshot_sha256) = 64'),
                self::check('scheduling_candidate_revisions_base_revision_hash_check', 'base_revision_sha256 IS NULL OR CHAR_LENGTH(base_revision_sha256) = 64'),
            ],
        ],
        'candidate_revision_status_events' => [
            'columns' => [
                'id' => self::column('id', 'bigint unsigned', false, null, [], true),
                'scheduling_candidate_revision_id' => self::column('foreignId', 'bigint unsigned'),
                'from_status' => self::column('string', 'varchar(20)', true, null, [20]),
                'to_status' => self::column('string', 'varchar(20)', false, null, [20]),
                'actor_user_id' => self::column('foreignId', 'bigint unsigned', true),
                'reason' => self::column('text', 'text', true),
                'expected_version' => self::column('unsignedInteger', 'int unsigned', true),
                'observed_version' => self::column('unsignedInteger', 'int unsigned'),
                'metadata' => self::column('json', 'json', true),
                'created_at' => self::column('timestamp', 'timestamp'),
            ],
            'indexes' => [
                self::index('PRIMARY', ['id'], true),
                self::index('candidate_revision_status_events_revision_created_index', ['scheduling_candidate_revision_id', 'created_at']),
                self::index('crse_revision_fk', ['scheduling_candidate_revision_id']),
                self::index('candidate_revision_status_events_actor_user_id_foreign', ['actor_user_id']),
            ],
            'foreignKeys' => [
                self::foreignKey('crse_revision_fk', 'scheduling_candidate_revision_id', 'scheduling_candidate_revisions', 'CASCADE'),
                self::foreignKey('candidate_revision_status_events_actor_user_id_foreign', 'actor_user_id', 'users', 'SET NULL'),
            ],
            'checks' => [
                self::check('candidate_revision_status_events_to_status_check', "to_status IN ('draft','under_review','validated','needs_revision','superseded','discarded')"),
            ],
        ],
        ];
    }

    private const OBSOLETE_UNIQUES = [
        'candidate_meeting_placements' => 'candidate_meeting_placements_meeting_instance_id_unique',
        'candidate_meeting_professors' => 'candidate_meeting_professors_offering_row_unique',
    ];

    public static function tables(): array
    {
        return self::tableDefinitions();
    }

    public static function tableNames(): array
    {
        return array_keys(self::tableDefinitions());
    }

    public static function table(string $table): array
    {
        return self::tableDefinitions()[$table];
    }

    public static function obsoleteUniques(): array
    {
        return self::OBSOLETE_UNIQUES;
    }

    public static function normalizeCheckClause(string $clause): string
    {
        $clause = strtolower(trim($clause));
        $clause = str_replace("\\'", "'", $clause);
        $clause = str_replace('`', '', $clause);
        $clause = (string) preg_replace("/_(?:utf8mb4|utf8mb3|utf8)(?=\\s*')/", '', $clause);

        return (string) preg_replace('/[\s()]/', '', $clause);
    }

    public static function indexMatches(array $expected, array $actual): bool
    {
        return $actual['columns'] === $expected['columns']
            && $actual['unique'] === $expected['unique']
            && ($actual['type'] ?? 'btree') === $expected['type'];
    }

    public static function matchingIndexName(array $expected, array $actualIndexes): ?string
    {
        if (isset($actualIndexes[$expected['name']]) && self::indexMatches($expected, $actualIndexes[$expected['name']])) {
            return $expected['name'];
        }

        if ($expected['requireName']) {
            return null;
        }

        foreach ($actualIndexes as $name => $actual) {
            if (self::indexMatches($expected, $actual)) {
                return (string) $name;
            }
        }

        return null;
    }

    private static function column(
        string $method,
        string $type,
        bool $nullable = false,
        mixed $default = null,
        array $arguments = [],
        bool $autoIncrement = false,
    ): array {
        return [
            'method' => $method,
            'type' => $type,
            'nullable' => $nullable,
            'default' => $default,
            'arguments' => $arguments,
            'autoIncrement' => $autoIncrement,
        ];
    }

    private static function index(string $name, array $columns, bool $unique = false): array
    {
        return [
            'name' => $name,
            'columns' => $columns,
            'unique' => $unique,
            'type' => 'btree',
            'requireName' => ! str_ends_with($name, '_foreign')
                && ! in_array($name, self::FLEXIBLE_INDEX_NAMES, true),
        ];
    }

    private static function foreignKey(
        string $name,
        string $column,
        string $referencedTable,
        string $onDelete = 'RESTRICT',
        string $referencedColumn = 'id',
    ): array {
        return [
            'name' => $name,
            'column' => $column,
            'referencedTable' => $referencedTable,
            'referencedColumn' => $referencedColumn,
            'onDelete' => $onDelete,
            'onUpdate' => 'RESTRICT',
        ];
    }

    private static function check(string $name, string $clause): array
    {
        return [
            'name' => $name,
            'clause' => $clause,
        ];
    }
}
