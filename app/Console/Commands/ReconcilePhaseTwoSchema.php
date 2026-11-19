<?php

namespace App\Console\Commands;

use App\Support\PhaseTwoSchemaCatalog;
use App\Support\PhaseTwoSchemaIntrospector;
use App\Support\PhaseTwoSchemaPlan;
use App\Support\PhaseTwoSchemaReconciler;
use App\Support\PhaseTwoSchemaVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReconcilePhaseTwoSchema extends Command
{
    protected $signature = 'scheduling:reconcile-phase-two-schema
        {--apply : Apply the reconciliation operations instead of only reporting them}';

    protected $description = 'Safely reconcile the partially created Phase 2 scheduling schema.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        try {
            $connection = DB::connection();
            $connection->getPdo();
            $introspector = new PhaseTwoSchemaIntrospector($connection);
        } catch (Throwable $exception) {
            $this->error(sprintf('Database connection check failed: %s', $exception->getMessage()));

            return self::FAILURE;
        }

        if ($connection->getDriverName() !== 'mysql') {
            $this->error(sprintf('Refusing to run: default connection driver is %s, expected mysql.', $connection->getDriverName()));

            return self::FAILURE;
        }

        if ($introspector->database() !== PhaseTwoSchemaCatalog::DATABASE) {
            $this->error(sprintf(
                'Refusing to run: selected database is %s, expected %s.',
                $introspector->database() === '' ? '(none)' : $introspector->database(),
                PhaseTwoSchemaCatalog::DATABASE,
            ));

            return self::FAILURE;
        }

        if (! Schema::hasTable('migrations')) {
            $this->error('Refusing to run: migrations table does not exist.');

            return self::FAILURE;
        }

        $plan = (new PhaseTwoSchemaReconciler())->buildPlan($introspector);

        $this->report(
            $plan,
            $apply,
            (string) ($connection->getConfig('host') ?? ''),
            (string) ($connection->getConfig('port') ?? ''),
        );

        if ($plan->blockers !== []) {
            $this->error('Preflight blocked reconciliation. No changes were made.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('Dry-run complete. No schema or migration-ledger changes were made.');

            return self::SUCCESS;
        }

        $completed = [];

        foreach ($plan->operations() as $operation) {
            try {
                $operation['execute']();
                $completed[] = $operation;
                $this->line(sprintf(
                    '  [OK] %s | %s | %s',
                    $operation['id'],
                    $operation['type'],
                    $operation['description'],
                ));
            } catch (Throwable $exception) {
                $this->error(sprintf(
                    '  [FAIL] %s | %s | %s',
                    $operation['id'],
                    $operation['type'],
                    $exception->getMessage(),
                ));
                $this->reportCompleted($completed);

                return self::FAILURE;
            }
        }

        $verification = (new PhaseTwoSchemaVerifier($introspector))->verify(true);

        if ($verification['issues'] !== []) {
            $this->error('Post-apply verification failed. Migration ledger was not modified.');
            $this->reportIssues($verification['issues']);
            $this->reportCompleted($completed);

            return self::FAILURE;
        }

        if ($verification['warnings'] !== []) {
            $this->warn('Post-apply verification completed with non-blocking warnings:');

            foreach ($verification['warnings'] as $warning) {
                $this->line(sprintf('  - %s', $warning));
            }
        } else {
            $this->info('Post-apply verification passed.');
        }

        if ($plan->ledgerAction === 'insert') {
            try {
                DB::transaction(function (): void {
                    $batch = ((int) DB::table('migrations')->max('batch')) + 1;

                    foreach (PhaseTwoSchemaCatalog::MIGRATIONS as $migration) {
                        DB::table('migrations')->insert([
                            'migration' => $migration,
                            'batch' => $batch,
                        ]);
                    }
                });
            } catch (Throwable $exception) {
                $this->error(sprintf('Migration ledger insert failed: %s', $exception->getMessage()));
                $this->reportCompleted($completed);

                return self::FAILURE;
            }

            foreach (PhaseTwoSchemaCatalog::MIGRATIONS as $migration) {
                $this->line(sprintf('  [OK] ledger | %s', $migration));
            }
        } else {
            $this->line('  [SKIP] ledger | Both Phase 2 migrations are already recorded.');
        }

        $this->info('Phase 2 schema reconciliation completed.');

        return self::SUCCESS;
    }

    private function report(PhaseTwoSchemaPlan $plan, bool $apply, string $host, string $port): void
    {
        $this->info('Phase 2 production schema reconciliation');
        $this->line(sprintf('  Mode: %s', $apply ? 'APPLY' : 'DRY-RUN'));
        $this->line(sprintf('  Driver: mysql'));
        $this->line(sprintf('  Database: %s', PhaseTwoSchemaCatalog::DATABASE));
        $this->line(sprintf('  Host: %s', $host));
        $this->line(sprintf('  Port: %s', $port));
        $this->line(sprintf('  Ledger action: %s', $plan->ledgerAction));

        foreach (PhaseTwoSchemaCatalog::MIGRATIONS as $migration) {
            $this->line(sprintf(
                '  Ledger count: %s = %d',
                $migration,
                $plan->migrationCounts[$migration] ?? 0,
            ));
        }

        $this->line('  Preconditions:');

        foreach ($plan->preconditions as $name => $value) {
            $this->line(sprintf('    - %s: %s', $name, is_int($value) ? (string) $value : $value));
        }

        if ($plan->warnings !== []) {
            $this->line('  Warnings:');

            foreach ($plan->warnings as $warning) {
                $this->line(sprintf('    - %s', $warning));
            }
        }

        if ($plan->blockers !== []) {
            $this->line('  Blockers:');
            $this->reportIssues($plan->blockers);
        }

        $operations = $plan->operations();
        $this->line(sprintf('  Planned operations: %d', count($operations)));

        foreach ($operations as $operation) {
            $this->line(sprintf(
                '    [%s] %s | %s | %s',
                $operation['id'],
                $operation['table'],
                $operation['type'],
                $operation['description'],
            ));
        }

        if ($operations === []) {
            $this->line('    (none)');
        }
    }

    private function reportIssues(array $issues): void
    {
        foreach ($issues as $issue) {
            $this->line(sprintf('  - %s', $issue));
        }
    }

    private function reportCompleted(array $completed): void
    {
        $this->line(sprintf('Completed operations: %d', count($completed)));

        foreach ($completed as $operation) {
            $this->line(sprintf('  - %s', $operation['id']));
        }
    }
}
