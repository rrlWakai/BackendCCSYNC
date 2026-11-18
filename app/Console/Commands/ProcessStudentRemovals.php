<?php

namespace App\Console\Commands;

use App\Services\Students\StudentLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProcessStudentRemovals extends Command
{
    protected $signature = 'students:process-removals';

    protected $description = 'Permanently anonymize student accounts whose seven-day recovery period has expired.';

    public function handle(StudentLifecycleService $lifecycle): int
    {
        $lockName = 'ccsync:students:removals';
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ($lock === null || $lock->acquired === null) {
            throw new RuntimeException('Unable to acquire the student removal processing lock.');
        }
        if ((int) $lock->acquired !== 1) {
            $this->info('Skipped: another student removal processor holds the lock.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;
        try {
            $count = $lifecycle->purgeDue();
            $this->info("Processed {$count} expired student removal(s).");
        } finally {
            $released = DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            if ($released === null || (int) $released->released !== 1) {
                $this->error('Unable to release the student removal processing lock.');
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }
}
