<?php

namespace App\Console\Commands;

use App\Services\Academic\PlacementService;
use Illuminate\Console\Command;

final class BackfillStudentPlacements extends Command
{
    protected $signature = 'placements:backfill';

    protected $description = 'Create current academic placements from existing student section assignments.';

    public function handle(PlacementService $placements): int
    {
        $result = $placements->backfill();

        $this->info(sprintf(
            'Placements backfilled: %d created, %d skipped, %d missing section.',
            $result['created'],
            $result['skipped'],
            $result['missing_section'],
        ));

        return self::SUCCESS;
    }
}
