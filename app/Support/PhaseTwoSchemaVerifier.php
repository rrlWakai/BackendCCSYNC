<?php

namespace App\Support;

final class PhaseTwoSchemaVerifier
{
    public function __construct(private readonly PhaseTwoSchemaIntrospector $introspector)
    {
    }

    public function verify(bool $requireComplete = true): array
    {
        $issues = [];
        $warnings = [];
        $existingTables = $this->introspector->tableNames();

        foreach (PhaseTwoSchemaCatalog::tables() as $table => $definition) {
            if (! in_array(strtolower($table), $existingTables, true)) {
                if ($requireComplete) {
                    $issues[] = sprintf('%s: required table is missing.', $table);
                } else {
                    $warnings[] = sprintf('%s: table is missing and will be created.', $table);
                }

                continue;
            }

            $this->verifyColumns($table, $definition['columns'], $issues, $warnings);
            $this->verifyIndexes($table, $definition['indexes'], $issues, $warnings);
            $this->verifyForeignKeys($table, $definition['foreignKeys'], $issues, $warnings);
            $this->verifyChecks($table, $definition['checks'], $issues, $warnings);
        }

        foreach (PhaseTwoSchemaCatalog::obsoleteUniques() as $table => $index) {
            if (! in_array(strtolower($table), $existingTables, true)) {
                continue;
            }

            if (isset($this->introspector->indexes($table)[$index])) {
                $message = sprintf('%s: obsolete unique index %s is still present.', $table, $index);

                if ($requireComplete) {
                    $issues[] = $message;
                } else {
                    $warnings[] = sprintf('%s: obsolete unique index %s will be removed.', $table, $index);
                }
            }
        }

        return [
            'issues' => $issues,
            'warnings' => $warnings,
            'checkedTables' => count($existingTables),
            'complete' => $issues === [],
        ];
    }

    private function verifyColumns(string $table, array $expected, array &$issues, array &$warnings): void
    {
        $actual = $this->introspector->columns($table);

        foreach ($expected as $name => $spec) {
            if (! isset($actual[$name])) {
                $issues[] = sprintf('%s.%s: required column is missing.', $table, $name);
                continue;
            }

            $found = $actual[$name];

            if ($found['type'] !== $spec['type']) {
                $issues[] = sprintf(
                    '%s.%s: type is %s, expected %s.',
                    $table,
                    $name,
                    $found['type'],
                    $spec['type'],
                );
            }

            if ($found['nullable'] !== $spec['nullable']) {
                $issues[] = sprintf(
                    '%s.%s: nullability is %s, expected %s.',
                    $table,
                    $name,
                    $found['nullable'] ? 'NULL' : 'NOT NULL',
                    $spec['nullable'] ? 'NULL' : 'NOT NULL',
                );
            }

            if ($found['default'] !== $spec['default']) {
                $issues[] = sprintf(
                    '%s.%s: default is %s, expected %s.',
                    $table,
                    $name,
                    $found['default'] === null ? 'NULL' : $found['default'],
                    $spec['default'] === null ? 'NULL' : $spec['default'],
                );
            }

            if ($found['autoIncrement'] !== $spec['autoIncrement']) {
                $issues[] = sprintf(
                    '%s.%s: auto_increment is %s, expected %s.',
                    $table,
                    $name,
                    $found['autoIncrement'] ? 'yes' : 'no',
                    $spec['autoIncrement'] ? 'yes' : 'no',
                );
            }
        }

        foreach (array_diff(array_keys($actual), array_keys($expected)) as $extra) {
            $warnings[] = sprintf('%s.%s: unexpected extra column is present.', $table, $extra);
        }
    }

    private function verifyIndexes(string $table, array $expected, array &$issues, array &$warnings): void
    {
        $actual = $this->introspector->indexes($table);
        $acceptedActualNames = [];

        foreach ($expected as $index) {
            $matchedName = PhaseTwoSchemaCatalog::matchingIndexName($index, $actual);

            if ($matchedName !== null) {
                $acceptedActualNames[$matchedName] = true;

                if ($matchedName !== $index['name']) {
                    $warnings[] = sprintf(
                        '%s: index %s satisfies expected index %s with a different name.',
                        $table,
                        $matchedName,
                        $index['name'],
                    );
                }

                continue;
            }

            if (! isset($actual[$index['name']])) {
                $issues[] = sprintf('%s: required index %s is missing.', $table, $index['name']);
                continue;
            }

            $found = $actual[$index['name']];

            if ($found['columns'] !== $index['columns']) {
                $issues[] = sprintf(
                    '%s: index %s columns are [%s], expected [%s].',
                    $table,
                    $index['name'],
                    implode(', ', $found['columns']),
                    implode(', ', $index['columns']),
                );
            }

            if ($found['unique'] !== $index['unique']) {
                $issues[] = sprintf(
                    '%s: index %s uniqueness is %s, expected %s.',
                    $table,
                    $index['name'],
                    $found['unique'] ? 'UNIQUE' : 'INDEX',
                    $index['unique'] ? 'UNIQUE' : 'INDEX',
                );
            }

            if (($found['type'] ?? 'btree') !== $index['type']) {
                $issues[] = sprintf(
                    '%s: index %s type is %s, expected %s.',
                    $table,
                    $index['name'],
                    $found['type'] ?? 'btree',
                    $index['type'],
                );
            }
        }

        $expectedNames = array_column($expected, 'name');

        foreach ($actual as $name => $found) {
            if (isset($acceptedActualNames[$name]) || in_array($name, $expectedNames, true)) {
                continue;
            }

            $warnings[] = sprintf('%s: unexpected extra index %s is present.', $table, $name);
        }
    }

    private function verifyForeignKeys(string $table, array $expected, array &$issues, array &$warnings): void
    {
        $actual = $this->introspector->foreignKeys($table);

        foreach ($expected as $foreignKey) {
            if (! isset($actual[$foreignKey['name']])) {
                $issues[] = sprintf('%s: required foreign key %s is missing.', $table, $foreignKey['name']);
                continue;
            }

            $found = $actual[$foreignKey['name']];

            foreach ([
                'column' => $foreignKey['column'],
                'referencedTable' => $foreignKey['referencedTable'],
                'referencedColumn' => $foreignKey['referencedColumn'],
                'onDelete' => $foreignKey['onDelete'],
                'onUpdate' => $foreignKey['onUpdate'],
            ] as $key => $expectedValue) {
                if ($found[$key] !== $expectedValue) {
                    $issues[] = sprintf(
                        '%s: foreign key %s has %s=%s, expected %s.',
                        $table,
                        $foreignKey['name'],
                        $key,
                        $found[$key],
                        $expectedValue,
                    );
                }
            }
        }

        foreach (array_diff(array_keys($actual), array_column($expected, 'name')) as $extra) {
            $warnings[] = sprintf('%s: unexpected extra foreign key %s is present.', $table, $extra);
        }
    }

    private function verifyChecks(string $table, array $expected, array &$issues, array &$warnings): void
    {
        $actual = $this->introspector->checks($table);

        foreach ($expected as $check) {
            if (! isset($actual[$check['name']])) {
                $issues[] = sprintf('%s: required check constraint %s is missing.', $table, $check['name']);
                continue;
            }

            $expectedClause = PhaseTwoSchemaCatalog::normalizeCheckClause($check['clause']);

            if ($actual[$check['name']] !== $expectedClause) {
                $issues[] = sprintf(
                    '%s: check constraint %s clause does not match the intended definition.',
                    $table,
                    $check['name'],
                );
            }
        }

        foreach (array_diff(array_keys($actual), array_column($expected, 'name')) as $extra) {
            $warnings[] = sprintf('%s: unexpected extra check constraint %s is present.', $table, $extra);
        }
    }
}
