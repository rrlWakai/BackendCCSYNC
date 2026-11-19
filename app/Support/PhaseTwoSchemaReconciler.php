<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PhaseTwoSchemaReconciler
{
    public function buildPlan(PhaseTwoSchemaIntrospector $introspector): PhaseTwoSchemaPlan
    {
        $plan = new PhaseTwoSchemaPlan();
        $verification = (new PhaseTwoSchemaVerifier($introspector))->verify(false);
        $plan->warnings = $verification['warnings'];

        foreach ($verification['issues'] as $issue) {
            if ($this->isPlannableIssue($issue)) {
                continue;
            }

            $plan->blockers[] = $issue;
        }

        $existingTables = [];

        foreach ($introspector->tableNames() as $table) {
            $existingTables[strtolower($table)] = $table;
        }

        foreach (PhaseTwoSchemaCatalog::tableNames() as $table) {
            $key = strtolower($table);

            if (isset($existingTables[$key])) {
                $this->planExistingTable($plan, $table, $introspector);
            } else {
                $this->planCreateTable($plan, $table);
            }
        }

        $this->addPreconditions($plan, $introspector, $existingTables);
        $this->addLedgerDecision($plan, $introspector);

        return $plan;
    }

    private function isPlannableIssue(string $issue): bool
    {
        if (str_contains($issue, 'required index PRIMARY is missing')) {
            return false;
        }

        if (str_contains($issue, '.id: required column is missing')) {
            return false;
        }

        if (str_starts_with($issue, 'candidate_meeting_placements.meeting_instance_id: type is')
            || str_starts_with($issue, 'candidate_meeting_placements.meeting_instance_id: nullability is')) {
            return true;
        }

        return str_ends_with($issue, 'is missing.');
    }

    private function planExistingTable(
        PhaseTwoSchemaPlan $plan,
        string $table,
        PhaseTwoSchemaIntrospector $introspector,
    ): void {
        $definition = PhaseTwoSchemaCatalog::table($table);
        $actualColumns = $introspector->columns($table);
        $availableColumns = array_keys($actualColumns);

        foreach ($definition['columns'] as $name => $spec) {
            if (isset($actualColumns[$name])) {
                continue;
            }

            $after = $this->previousAvailableColumn($definition['columns'], $name, $availableColumns);
            $availableColumns[] = $name;

            $plan->addOperation(
                sprintf('column:%s.%s', $table, $name),
                $table,
                'ADD COLUMN',
                sprintf(
                    'Add %s.%s %s%s%s%s',
                    $table,
                    $name,
                    $spec['type'],
                    $spec['nullable'] ? ' NULL' : ' NOT NULL',
                    $spec['default'] === null ? '' : sprintf(' DEFAULT %s', $spec['default']),
                    $after === null ? '' : sprintf(' AFTER %s', $after),
                ),
                function () use ($table, $name, $spec, $after): void {
                    Schema::table($table, function (Blueprint $blueprint) use ($name, $spec, $after): void {
                        $this->applyColumn($blueprint, $name, $spec, $after);
                    });
                },
            );
        }

        if ($table === 'candidate_meeting_placements' && isset($actualColumns['meeting_instance_id'])) {
            $column = $actualColumns['meeting_instance_id'];

            if ($column['type'] !== 'varchar(191)' || $column['nullable']) {
                $plan->addOperation(
                    'modify:candidate_meeting_placements.meeting_instance_id',
                    $table,
                    'MODIFY COLUMN',
                    'ALTER TABLE candidate_meeting_placements MODIFY meeting_instance_id VARCHAR(191) NOT NULL',
                    static fn (): mixed => DB::statement(
                        'ALTER TABLE candidate_meeting_placements MODIFY meeting_instance_id VARCHAR(191) NOT NULL',
                    ),
                );
            }
        }

        $actualIndexes = $introspector->indexes($table);

        foreach ($definition['indexes'] as $index) {
            if ($index['name'] === 'PRIMARY'
                || PhaseTwoSchemaCatalog::matchingIndexName($index, $actualIndexes) !== null) {
                continue;
            }

            $plan->addOperation(
                sprintf('index:%s.%s', $table, $index['name']),
                $table,
                $index['unique'] ? 'ADD UNIQUE' : 'ADD INDEX',
                sprintf(
                    'Add %s %s (%s)',
                    $index['unique'] ? 'unique index' : 'index',
                    $index['name'],
                    implode(', ', $index['columns']),
                ),
                function () use ($table, $index): void {
                    Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                        if ($index['unique']) {
                            $blueprint->unique($index['columns'], $index['name']);
                        } else {
                            $blueprint->index($index['columns'], $index['name']);
                        }
                    });
                },
            );
        }

        foreach (PhaseTwoSchemaCatalog::obsoleteUniques() as $obsoleteTable => $indexName) {
            if ($obsoleteTable !== $table || ! isset($actualIndexes[$indexName])) {
                continue;
            }

            $plan->addOperation(
                sprintf('drop-obsolete-unique:%s.%s', $table, $indexName),
                $table,
                'DROP OBSOLETE UNIQUE',
                sprintf('ALTER TABLE %s DROP INDEX %s', $table, $indexName),
                static fn (): mixed => DB::statement(sprintf('ALTER TABLE %s DROP INDEX %s', $table, $indexName)),
            );
        }

        $actualForeignKeys = $introspector->foreignKeys($table);

        foreach ($definition['foreignKeys'] as $foreignKey) {
            if (isset($actualForeignKeys[$foreignKey['name']])) {
                continue;
            }

            $plan->addOperation(
                sprintf('foreign-key:%s.%s', $table, $foreignKey['name']),
                $table,
                'ADD FOREIGN KEY',
                sprintf(
                    'Add %s.%s -> %s.%s ON DELETE %s',
                    $table,
                    $foreignKey['column'],
                    $foreignKey['referencedTable'],
                    $foreignKey['referencedColumn'],
                    $foreignKey['onDelete'],
                ),
                function () use ($table, $foreignKey): void {
                    Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                        $foreign = $blueprint
                            ->foreign($foreignKey['column'], $foreignKey['name'])
                            ->references($foreignKey['referencedColumn'])
                            ->on($foreignKey['referencedTable']);

                        $this->applyDeleteAction($foreign, $foreignKey['onDelete']);
                    });
                },
            );
        }

        $actualChecks = $introspector->checks($table);

        foreach ($definition['checks'] as $check) {
            if (isset($actualChecks[$check['name']])) {
                continue;
            }

            $plan->addOperation(
                sprintf('check:%s.%s', $table, $check['name']),
                $table,
                'ADD CHECK',
                sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $check['name'], $check['clause']),
                static fn (): mixed => DB::statement(sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
                    $table,
                    $check['name'],
                    $check['clause'],
                )),
            );
        }
    }

    private function planCreateTable(PhaseTwoSchemaPlan $plan, string $table): void
    {
        $plan->addOperation(
            sprintf('create-table:%s', $table),
            $table,
            'CREATE TABLE',
            sprintf(
                'Create missing table %s with its intended columns, indexes, unique constraints, and foreign keys',
                $table,
            ),
            function () use ($table): void {
                $this->createTable($table);
            },
        );

        foreach (PhaseTwoSchemaCatalog::table($table)['checks'] as $check) {
            $plan->addOperation(
                sprintf('check:%s.%s', $table, $check['name']),
                $table,
                'ADD CHECK',
                sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $check['name'], $check['clause']),
                static fn (): mixed => DB::statement(sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
                    $table,
                    $check['name'],
                    $check['clause'],
                )),
            );
        }
    }

    private function createTable(string $table): void
    {
        $definition = PhaseTwoSchemaCatalog::table($table);

        Schema::create($table, function (Blueprint $blueprint) use ($definition): void {
            $blueprint->engine = 'InnoDB';
            $blueprint->charset = 'utf8mb4';
            $blueprint->collation = 'utf8mb4_unicode_ci';

            foreach ($definition['columns'] as $name => $spec) {
                $this->applyColumn($blueprint, $name, $spec);
            }

            foreach ($definition['indexes'] as $index) {
                if ($index['name'] === 'PRIMARY') {
                    continue;
                }

                if ($index['unique']) {
                    $blueprint->unique($index['columns'], $index['name']);
                } else {
                    $blueprint->index($index['columns'], $index['name']);
                }
            }

            foreach ($definition['foreignKeys'] as $foreignKey) {
                $foreign = $blueprint
                    ->foreign($foreignKey['column'], $foreignKey['name'])
                    ->references($foreignKey['referencedColumn'])
                    ->on($foreignKey['referencedTable']);

                $this->applyDeleteAction($foreign, $foreignKey['onDelete']);
            }
        });
    }

    private function applyColumn(Blueprint $blueprint, string $name, array $spec, ?string $after = null): void
    {
        if ($spec['method'] === 'id') {
            $column = $blueprint->id();
        } else {
            $column = $blueprint->{$spec['method']}($name, ...$spec['arguments']);
        }

        if ($spec['nullable']) {
            $column->nullable();
        }

        if ($spec['default'] !== null) {
            $column->default($spec['default']);
        }

        if ($after !== null) {
            $column->after($after);
        }
    }

    private function applyDeleteAction(object $foreign, string $onDelete): void
    {
        match ($onDelete) {
            'CASCADE' => $foreign->cascadeOnDelete(),
            'SET NULL' => $foreign->nullOnDelete(),
            default => $foreign->restrictOnDelete(),
        };
    }

    private function previousAvailableColumn(array $columns, string $name, array $availableColumns): ?string
    {
        $names = array_keys($columns);
        $position = array_search($name, $names, true);

        if ($position === false) {
            return null;
        }

        for ($index = $position - 1; $index >= 0; $index--) {
            if (in_array($names[$index], $availableColumns, true)) {
                return $names[$index];
            }
        }

        return null;
    }

    private function addPreconditions(
        PhaseTwoSchemaPlan $plan,
        PhaseTwoSchemaIntrospector $introspector,
        array $existingTables,
    ): void {
        foreach ($existingTables as $key => $table) {
            $count = $introspector->rowCount($table);
            $plan->preconditions[sprintf('rows.%s', $key)] = $count;

            if ($count > 0) {
                $plan->blockers[] = sprintf('%s contains %d rows; forward-only recovery requires empty tables.', $table, $count);
            }
        }

        if (isset($existingTables['candidate_meeting_placements'])) {
            $invalidUuids = $introspector->invalidPlacementUuidCount();
            $plan->preconditions['invalid_placement_uuids'] = $invalidUuids;

            if ($invalidUuids > 0) {
                $plan->blockers[] = sprintf(
                    'candidate_meeting_placements contains %d invalid UUID values; record rewrites are outside this command.',
                    $invalidUuids,
                );
            }
        }
    }

    private function addLedgerDecision(PhaseTwoSchemaPlan $plan, PhaseTwoSchemaIntrospector $introspector): void
    {
        $counts = $introspector->migrationCounts();
        $plan->migrationCounts = $counts;

        if ($counts === []) {
            $plan->blockers[] = 'The migrations table does not exist.';

            return;
        }

        foreach ($counts as $migration => $count) {
            if ($count > 1) {
                $plan->blockers[] = sprintf('Migration ledger contains duplicate rows for %s.', $migration);
            }
        }

        $applied = array_filter($counts, fn (int $count): bool => $count === 1);
        $appliedCount = count($applied);

        if ($appliedCount === count(PhaseTwoSchemaCatalog::MIGRATIONS)) {
            $plan->ledgerAction = 'none';

            return;
        }

        if ($appliedCount > 0) {
            $plan->blockers[] = 'Migration ledger is partially applied; manual reconciliation is required.';

            return;
        }

        $plan->ledgerAction = 'insert';
    }
}
