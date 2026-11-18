<?php

namespace App\Console\Commands;

use App\Services\Academic\PromotionService;
use Illuminate\Console\Command;

final class ProcessDuePromotions extends Command
{
    protected $signature = 'promotions:process-due';

    protected $description = 'Process automatic promotions for Second Semesters that have ended.';

    public function handle(PromotionService $promotions): int
    {
        $results = collect($promotions->processDue());

        if ($results->isEmpty()) {
            $this->info('No academic years are due for promotion.');

            return self::SUCCESS;
        }

        $this->table(
            ['Academic year', 'Source term', 'Ended on', 'Status', 'Run ID', 'Promoted', 'Exceptions', 'Message'],
            $results->map(fn (array $item): array => [
                $item['academic_year'] ?? '—',
                $item['source_term'] ?? '—',
                $item['ends_on'] ?? '—',
                $item['status'],
                $item['promotion_run_id'] ?? '—',
                $item['promoted_count'] ?? 0,
                $item['exception_count'] ?? 0,
                $item['message'] ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
