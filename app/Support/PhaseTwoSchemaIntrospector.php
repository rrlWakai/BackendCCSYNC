<?php

namespace App\Support;

use Illuminate\Database\Connection;

final class PhaseTwoSchemaIntrospector
{
    private string $database;

    public function __construct(private readonly Connection $connection)
    {
        $result = $connection->selectOne('SELECT DATABASE() AS database_name');

        $this->database = $result && $result->database_name !== null
            ? (string) $result->database_name
            : '';
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function tableNames(): array
    {
        return $this->connection
            ->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $this->database)
            ->whereIn('TABLE_NAME', PhaseTwoSchemaCatalog::tableNames())
            ->pluck('TABLE_NAME')
            ->map(fn (mixed $table): string => strtolower((string) $table))
            ->all();
    }

    public function tableExists(string $table): bool
    {
        return in_array(strtolower($table), $this->tableNames(), true);
    }

    public function columns(string $table): array
    {
        $rows = $this->connection->select(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS is_nullable, '
            .'COLUMN_DEFAULT AS column_default, EXTRA AS extra '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->database, $table],
        );

        $columns = [];

        foreach ($rows as $row) {
            $columns[strtolower((string) $row->name)] = [
                'type' => strtolower(trim((string) $row->type)),
                'nullable' => strtoupper((string) $row->is_nullable) === 'YES',
                'default' => $row->column_default === null ? null : (string) $row->column_default,
                'autoIncrement' => str_contains(strtolower((string) $row->extra), 'auto_increment'),
            ];
        }

        return $columns;
    }

    public function indexes(string $table): array
    {
        $rows = $this->connection->select(
            'SELECT INDEX_NAME AS name, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS sequence, '
            .'NON_UNIQUE AS non_unique, INDEX_TYPE AS type '
            .'FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? '
            .'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->database, $table],
        );

        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;

            $indexes[$name]['unique'] = (int) $row->non_unique === 0;
            $indexes[$name]['type'] = strtolower((string) $row->type);
            $indexes[$name]['columns'][] = strtolower((string) $row->column_name);
        }

        return $indexes;
    }

    public function foreignKeys(string $table): array
    {
        $rows = $this->connection->select(
            'SELECT kcu.CONSTRAINT_NAME AS name, kcu.COLUMN_NAME AS column_name, '
            .'kcu.REFERENCED_TABLE_NAME AS referenced_table, kcu.REFERENCED_COLUMN_NAME AS referenced_column, '
            .'rc.DELETE_RULE AS delete_rule, rc.UPDATE_RULE AS update_rule '
            .'FROM information_schema.KEY_COLUMN_USAGE AS kcu '
            .'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc '
            .'ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA '
            .'AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME '
            .'AND rc.TABLE_NAME = kcu.TABLE_NAME '
            .'WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->database, $table],
        );

        $foreignKeys = [];

        foreach ($rows as $row) {
            $foreignKeys[(string) $row->name] = [
                'column' => strtolower((string) $row->column_name),
                'referencedTable' => strtolower((string) $row->referenced_table),
                'referencedColumn' => strtolower((string) $row->referenced_column),
                'onDelete' => $this->normalizeForeignKeyRule((string) $row->delete_rule),
                'onUpdate' => $this->normalizeForeignKeyRule((string) $row->update_rule),
            ];
        }

        return $foreignKeys;
    }

    public function checks(string $table): array
    {
        $rows = $this->connection->select(
            'SELECT tc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS clause '
            .'FROM information_schema.TABLE_CONSTRAINTS AS tc '
            .'INNER JOIN information_schema.CHECK_CONSTRAINTS AS cc '
            .'ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA '
            .'AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            .'WHERE tc.TABLE_SCHEMA = ? AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = ?',
            [$this->database, $table, 'CHECK'],
        );

        $checks = [];

        foreach ($rows as $row) {
            $checks[(string) $row->name] = PhaseTwoSchemaCatalog::normalizeCheckClause((string) $row->clause);
        }

        return $checks;
    }

    public function rowCount(string $table): int
    {
        return (int) $this->connection->table($table)->count();
    }

    public function invalidPlacementUuidCount(): int
    {
        $pattern = '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';

        return (int) $this->connection
            ->table('candidate_meeting_placements')
            ->whereRaw('REGEXP_LIKE(uuid, ?) = 0', [$pattern])
            ->count();
    }

    private function normalizeForeignKeyRule(string $rule): string
    {
        $rule = strtoupper(trim($rule));

        return $rule === 'NO ACTION' ? 'RESTRICT' : $rule;
    }

    public function migrationCounts(): array
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('migrations')) {
            return [];
        }

        $counts = [];

        foreach (PhaseTwoSchemaCatalog::MIGRATIONS as $migration) {
            $counts[$migration] = (int) $this->connection
                ->table('migrations')
                ->where('migration', $migration)
                ->count();
        }

        return $counts;
    }
}
