<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertPrerequisites();
        $this->ensureWithdrawalDate();

        $this->ensureTable(
            'attendance_excuse_requests',
            fn () => $this->createExcuseRequestsTable(),
            [
                'id' => $this->expectedColumn('bigint', unsigned: true, autoIncrement: true),
                'attendance_session_id' => $this->expectedColumn('bigint', unsigned: true),
                'student_subject_enrollment_id' => $this->expectedColumn('bigint', unsigned: true),
                'requested_by_user_id' => $this->expectedColumn('bigint', unsigned: true),
                'reason' => $this->expectedColumn('text'),
                'attachment_path' => $this->expectedColumn('varchar', nullable: true, length: 255),
                'status' => $this->expectedColumn('varchar', length: 16, default: 'pending'),
                'decided_by_user_id' => $this->expectedColumn('bigint', nullable: true, unsigned: true),
                'decision_reason' => $this->expectedColumn('text', nullable: true),
                'decided_at' => $this->expectedColumn('timestamp', nullable: true),
                'created_at' => $this->expectedColumn('timestamp', nullable: true),
                'updated_at' => $this->expectedColumn('timestamp', nullable: true),
            ],
        );

        $this->ensureTable(
            'attendance_excuse_request_changes',
            fn () => $this->createExcuseRequestChangesTable(),
            [
                'id' => $this->expectedColumn('bigint', unsigned: true, autoIncrement: true),
                'attendance_excuse_request_id' => $this->expectedColumn('bigint', unsigned: true),
                'previous_status' => $this->expectedColumn('varchar', nullable: true, length: 16),
                'new_status' => $this->expectedColumn('varchar', length: 16),
                'reason' => $this->expectedColumn('text'),
                'changed_by_user_id' => $this->expectedColumn('bigint', nullable: true, unsigned: true),
                'changed_at' => $this->expectedColumn('timestamp', default: 'current_timestamp'),
            ],
        );

        $this->ensureTable(
            'attendance_policies',
            fn () => $this->createAttendancePoliciesTable(),
            [
                'id' => $this->expectedColumn('bigint', unsigned: true, autoIncrement: true),
                'academic_term_id' => $this->expectedColumn('bigint', unsigned: true),
                'subject_offering_id' => $this->expectedColumn('bigint', nullable: true, unsigned: true),
                'allowed_absences' => $this->expectedColumn('smallint', unsigned: true),
                'effective_at' => $this->expectedColumn('timestamp'),
                'created_by_user_id' => $this->expectedColumn('bigint', nullable: true, unsigned: true),
                'created_at' => $this->expectedColumn('timestamp', nullable: true),
                'updated_at' => $this->expectedColumn('timestamp', nullable: true),
            ],
        );

        $this->ensureIndex(
            'attendance_excuse_requests',
            ['attendance_session_id', 'student_subject_enrollment_id'],
            true,
            'attendance_excuse_session_enrollment_unique',
        );
        $this->ensureIndex(
            'attendance_excuse_requests',
            ['status', 'attendance_session_id'],
            false,
            'attendance_excuse_status_session_index',
        );
        $this->ensureForeignKey(
            'attendance_excuse_requests',
            'attendance_session_id',
            'attendance_sessions',
            'restrict',
            'attendance_excuse_requests_session_fk',
        );
        $this->ensureForeignKey(
            'attendance_excuse_requests',
            'student_subject_enrollment_id',
            'student_subject_enrollments',
            'restrict',
            'attendance_excuse_requests_enrollment_fk',
        );
        $this->ensureForeignKey(
            'attendance_excuse_requests',
            'requested_by_user_id',
            'users',
            'restrict',
            'attendance_excuse_requests_requested_by_fk',
        );
        $this->ensureForeignKey(
            'attendance_excuse_requests',
            'decided_by_user_id',
            'users',
            'set null',
            'attendance_excuse_requests_decided_by_fk',
        );

        $this->ensureIndex(
            'attendance_excuse_request_changes',
            ['attendance_excuse_request_id', 'changed_at'],
            false,
            'attendance_excuse_changes_history_index',
        );
        $this->ensureForeignKey(
            'attendance_excuse_request_changes',
            'attendance_excuse_request_id',
            'attendance_excuse_requests',
            'restrict',
            'attendance_excuse_changes_request_fk',
        );
        $this->ensureForeignKey(
            'attendance_excuse_request_changes',
            'changed_by_user_id',
            'users',
            'set null',
            'attendance_excuse_changes_changed_by_fk',
        );

        $this->ensureIndex(
            'attendance_policies',
            ['academic_term_id', 'subject_offering_id', 'effective_at'],
            false,
            'attendance_policies_lookup_index',
        );
        $this->ensureForeignKey(
            'attendance_policies',
            'academic_term_id',
            'academic_terms',
            'restrict',
            'attendance_policies_term_fk',
        );
        $this->ensureForeignKey(
            'attendance_policies',
            'subject_offering_id',
            'subject_offerings',
            'restrict',
            'attendance_policies_offering_fk',
        );
        $this->ensureForeignKey(
            'attendance_policies',
            'created_by_user_id',
            'users',
            'set null',
            'attendance_policies_created_by_fk',
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'This migration is intentionally irreversible: some schema objects predate it, '
            .'so ownership cannot be determined safely. Reconcile forward without dropping data.',
        );
    }

    private function assertPrerequisites(): void
    {
        foreach ([
            'student_subject_enrollments' => ['id', 'dropped_at'],
            'attendance_sessions' => ['id'],
            'users' => ['id'],
            'academic_terms' => ['id'],
            'subject_offerings' => ['id'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required table [{$table}] is missing.");
            }

            $this->assertColumnsExist($table, $columns);
        }
    }

    private function ensureWithdrawalDate(): void
    {
        $columns = Schema::getColumns('student_subject_enrollments');
        $withdrawnAt = collect($columns)->firstWhere('name', 'withdrawn_at');

        if ($withdrawnAt !== null) {
            $type = strtolower($withdrawnAt['type_name'] ?? $withdrawnAt['type'] ?? '');

            if (! str_starts_with($type, 'timestamp') || ! $withdrawnAt['nullable']) {
                throw new RuntimeException(
                    'Existing [student_subject_enrollments.withdrawn_at] must be a nullable timestamp; '
                    .'the migration will not alter its definition.',
                );
            }

            return;
        }

        Schema::table('student_subject_enrollments', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('dropped_at');
        });
    }

    private function ensureTable(string $name, \Closure $create, array $requiredColumns): void
    {
        if (! Schema::hasTable($name)) {
            $create();
        }

        $this->assertColumnDefinitions($name, $requiredColumns);
    }

    private function expectedColumn(
        string $type,
        bool $nullable = false,
        bool $unsigned = false,
        ?int $length = null,
        mixed $default = null,
        bool $autoIncrement = false,
    ): array {
        return [
            'type' => $type,
            'nullable' => $nullable,
            'unsigned' => $unsigned,
            'length' => $length,
            'default' => $default,
            'auto_increment' => $autoIncrement,
        ];
    }

    private function assertColumnDefinitions(string $table, array $expectedColumns): void
    {
        $actualColumns = collect(Schema::getColumns($table))->keyBy('name');

        foreach ($expectedColumns as $name => $expected) {
            $actual = $actualColumns->get($name);

            if ($actual === null) {
                throw new RuntimeException("Existing table [{$table}] is missing required column [{$name}].");
            }

            $actualType = strtolower($actual['type_name']);
            $actualUnsigned = str_contains(strtolower($actual['type']), 'unsigned');
            $actualLength = null;
            if (preg_match('/\(([0-9]+)\)\z/', strtolower($actual['type']), $matches) === 1) {
                $actualLength = (int) $matches[1];
            }

            $matchesExpected = $actualType === $expected['type']
                && $actual['nullable'] === $expected['nullable']
                && $actualUnsigned === $expected['unsigned']
                && $actualLength === $expected['length']
                && $this->normalizeDefault($actual['default']) === $this->normalizeDefault($expected['default'])
                && $actual['auto_increment'] === $expected['auto_increment'];

            if (! $matchesExpected) {
                throw new RuntimeException(
                    "Column [{$table}.{$name}] has an incompatible definition; expected "
                    .$this->describeColumn($expected).'. Existing definitions are not altered.',
                );
            }
        }
    }

    private function normalizeDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $default, " \t\n\r\0\x0B'\""));

        return preg_replace('/\(\)\z/', '', $normalized);
    }

    private function describeColumn(array $definition): string
    {
        $type = $definition['type'];
        if ($definition['length'] !== null) {
            $type .= "({$definition['length']})";
        }
        if ($definition['unsigned']) {
            $type .= ' unsigned';
        }

        return $type
            .' '.($definition['nullable'] ? 'nullable' : 'not nullable')
            .' default '.var_export($definition['default'], true);
    }

    private function assertColumnsExist(string $table, array $requiredColumns): void
    {
        $existingColumns = array_column(Schema::getColumns($table), 'name');
        $missingColumns = array_values(array_diff($requiredColumns, $existingColumns));

        if ($missingColumns !== []) {
            throw new RuntimeException(
                "Existing table [{$table}] is incomplete; missing required column(s): "
                .implode(', ', $missingColumns).'. No existing table or record was changed.',
            );
        }
    }

    private function createExcuseRequestsTable(): void
    {
        Schema::create('attendance_excuse_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('attendance_session_id');
            $table->foreignId('student_subject_enrollment_id');
            $table->foreignId('requested_by_user_id');
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by_user_id')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['attendance_session_id', 'student_subject_enrollment_id'],
                'attendance_excuse_session_enrollment_unique',
            );
            $table->index(['status', 'attendance_session_id'], 'attendance_excuse_status_session_index');
        });
    }

    private function createExcuseRequestChangesTable(): void
    {
        Schema::create('attendance_excuse_request_changes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('attendance_excuse_request_id');
            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->text('reason');
            $table->foreignId('changed_by_user_id')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(
                ['attendance_excuse_request_id', 'changed_at'],
                'attendance_excuse_changes_history_index',
            );
        });
    }

    private function createAttendancePoliciesTable(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('academic_term_id');
            $table->foreignId('subject_offering_id')->nullable();
            $table->unsignedSmallInteger('allowed_absences');
            $table->timestamp('effective_at');
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(
                ['academic_term_id', 'subject_offering_id', 'effective_at'],
                'attendance_policies_lookup_index',
            );
        });
    }

    private function ensureIndex(string $table, array $columns, bool $unique, string $name): void
    {
        $normalizedColumns = array_map('strtolower', $columns);
        $indexes = Schema::getIndexes($table);
        $isMySql = Schema::getConnection()->getDriverName() === 'mysql';

        foreach ($indexes as $index) {
            $indexName = strtolower($index['name']);
            $indexColumns = array_map('strtolower', $index['columns']);
            $sameColumns = $indexColumns === $normalizedColumns;
            $typeMatches = ! $isMySql || strtolower($index['type']) === 'btree';
            $compatible = $sameColumns
                && $index['unique'] === $unique
                && ! $index['primary']
                && $typeMatches;

            if ($indexName === strtolower($name)) {
                if (! $compatible) {
                    throw new RuntimeException(
                        "Index [{$name}] on [{$table}] exists with an incompatible definition.",
                    );
                }

                return;
            }

            if ($sameColumns && ! $compatible) {
                throw new RuntimeException(
                    "Index on [{$table}] (".implode(', ', $columns)
                    .') exists with incompatible uniqueness, type, or primary-key properties.',
                );
            }

            if ($compatible) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $unique, $name) {
            if ($unique) {
                $blueprint->unique($columns, $name);
            } else {
                $blueprint->index($columns, $name);
            }
        });
    }

    private function ensureForeignKey(
        string $table,
        string $column,
        string $referencedTable,
        string $onDelete,
        string $name,
    ): void {
        $this->assertColumnsExist($table, [$column]);
        $this->assertColumnsExist($referencedTable, ['id']);
        $this->assertForeignKeyColumnTypesCompatible($table, $column, $referencedTable, 'id');

        if ($onDelete === 'set null') {
            $this->assertColumnNullable($table, $column);
        }

        $foreignKeys = Schema::getForeignKeys($table);
        $matchingColumnKeys = array_values(array_filter(
            $foreignKeys,
            fn (array $foreignKey) => in_array($column, $foreignKey['columns'], true),
        ));
        $hasMatchingKey = false;

        foreach ($matchingColumnKeys as $foreignKey) {
            $sameTarget = count($foreignKey['columns']) === 1
                && strtolower($foreignKey['foreign_table']) === strtolower($referencedTable)
                && array_map('strtolower', $foreignKey['foreign_columns']) === ['id'];
            $foreignSchema = $foreignKey['foreign_schema'] ?? null;
            $sameSchema = $foreignSchema === null
                || strtolower($foreignSchema) === strtolower(Schema::getConnection()->getDatabaseName());
            $actualDeleteRule = strtolower($foreignKey['on_delete']);
            $deleteRuleMatches = $onDelete === 'restrict'
                ? in_array($actualDeleteRule, ['restrict', 'no action'], true)
                : $actualDeleteRule === $onDelete;
            $actualUpdateRule = strtolower($foreignKey['on_update']);
            $updateRuleMatches = in_array($actualUpdateRule, ['restrict', 'no action'], true);

            if (! $sameTarget || ! $sameSchema || ! $deleteRuleMatches || ! $updateRuleMatches) {
                throw new RuntimeException(
                    "Foreign key on [{$table}.{$column}] exists with an incompatible definition.",
                );
            }

            $hasMatchingKey = true;
        }

        if ($hasMatchingKey) {
            return;
        }

        $this->assertNoOrphanedReferences($table, $column, $referencedTable);
        $this->assertForeignKeyNameAvailable($table, $name);

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $onDelete, $name) {
            $foreign = $blueprint->foreign($column, $name)
                ->references('id')
                ->on($referencedTable);

            if ($onDelete === 'set null') {
                $foreign->nullOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
        });
    }

    private function assertNoOrphanedReferences(string $table, string $column, string $referencedTable): void
    {
        $hasOrphans = DB::table("{$table} as child_rows")
            ->leftJoin($referencedTable.' as parent_rows', "child_rows.{$column}", '=', 'parent_rows.id')
            ->whereNotNull("child_rows.{$column}")
            ->whereNull('parent_rows.id')
            ->exists();

        if ($hasOrphans) {
            throw new RuntimeException(
                "Foreign key [{$table}.{$column}] cannot be added until orphaned references are resolved.",
            );
        }
    }

    private function assertForeignKeyNameAvailable(string $table, string $name): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $physicalTable = $connection->getTablePrefix().$table;
        $collision = $connection->selectOne(
            'SELECT TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS '
            .'WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = ? '
            .'AND CONSTRAINT_NAME = ? AND TABLE_NAME <> ?',
            ['FOREIGN KEY', $name, $physicalTable],
        );

        if ($collision !== null) {
            throw new RuntimeException(
                "Foreign key name [{$name}] is already used by another table; choose a unique name.",
            );
        }
    }

    private function assertColumnNullable(string $table, string $column): void
    {
        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

        if ($definition === null || ! $definition['nullable']) {
            throw new RuntimeException(
                "Column [{$table}.{$column}] must be nullable for its SET NULL foreign key.",
            );
        }
    }

    private function assertForeignKeyColumnTypesCompatible(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
    ): void {
        $child = $this->getColumnDefinition($table, $column);
        $parent = $this->getColumnDefinition($referencedTable, $referencedColumn);
        $integerTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];
        $childType = strtolower($child['type_name']);
        $parentType = strtolower($parent['type_name']);
        $childIsInteger = in_array($childType, $integerTypes, true);
        $parentIsInteger = in_array($parentType, $integerTypes, true);
        $childUnsigned = str_contains(strtolower($child['type']), 'unsigned');
        $parentUnsigned = str_contains(strtolower($parent['type']), 'unsigned');

        if (
            ! $childIsInteger
            || ! $parentIsInteger
            || $childType !== $parentType
            || $childUnsigned !== $parentUnsigned
        ) {
            throw new RuntimeException(
                "Foreign key columns [{$table}.{$column}] and [{$referencedTable}.{$referencedColumn}] "
                .'have incompatible integer types or signedness.',
            );
        }
    }

    private function getColumnDefinition(string $table, string $column): array
    {
        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

        if ($definition === null) {
            throw new RuntimeException("Required column [{$table}.{$column}] is missing.");
        }

        return $definition;
    }
};
