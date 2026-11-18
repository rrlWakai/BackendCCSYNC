<?php

namespace App\Console\Commands;

use App\Services\Academic\PromotionService;
use Illuminate\Console\Command;

final class ProcessDuePromotions extends Command
{
    protected $signature = 'promotions:process-due';

    protected $description = 'Report academic years whose Second Semester has ended and promotion has not been prepared.';

    public function handle(PromotionService $promotions): int
    {
        $due = collect($promotions->duePromotions());

        if ($due->isEmpty()) {
            $this->info('No academic years are due for promotion.');

            return self::SUCCESS;
        }

        $this->table(
            ['Academic year', 'Source term', 'Ended on', 'Promotion status', 'Run ID'],
            $due->map(fn (array $item): array => [
                $item['academic_year'] ?? '—',
                $item['source_term'] ?? '—',
                $item['ends_on'] ?? '—',
                $item['status'],
                $item['promotion_run_id'] ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
