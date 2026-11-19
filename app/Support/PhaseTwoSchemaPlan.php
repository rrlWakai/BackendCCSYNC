<?php

namespace App\Support;

use Closure;

final class PhaseTwoSchemaPlan
{
    public function __construct(
        public array $operations = [],
        public array $blockers = [],
        public array $warnings = [],
        public array $preconditions = [],
        public array $migrationCounts = [],
        public string $ledgerAction = 'none',
    ) {
    }

    public function addOperation(
        string $id,
        string $table,
        string $type,
        string $description,
        Closure $execute,
    ): void {
        $this->operations[] = [
            'id' => $id,
            'table' => $table,
            'type' => $type,
            'description' => $description,
            'execute' => $execute,
        ];
    }

    public function operations(): array
    {
        return $this->operations;
    }
}
