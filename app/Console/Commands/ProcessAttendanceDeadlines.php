<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProcessAttendanceDeadlines extends Command
{
    protected $signature = 'attendance:process-deadlines';

    protected $description = 'Close QR windows, record absences, and finalize due attendance sessions.';

    public function handle(AttendanceManagementService $attendance): int
    {
        $lockName = 'ccsync:attendance:deadlines';
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ($lock === null || $lock->acquired === null) {
            throw new RuntimeException('Unable to acquire the attendance deadline processing lock.');
        }
        if ((int) $lock->acquired !== 1) {
            $this->info('Skipped: another attendance deadline processor holds the lock.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;
        try {
            $count = $attendance->processDueSessions();
            $this->info("Processed {$count} open attendance session(s).");
        } finally {
            $released = DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            if ($released === null || (int) $released->released !== 1) {
                $this->error('Unable to release the attendance deadline processing lock.');
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }
}
