<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceManagementService;
use Illuminate\Console\Command;

final class ProcessAttendanceDeadlines extends Command
{
    protected $signature = 'attendance:process-deadlines';

    protected $description = 'Close QR windows, record absences, and finalize due attendance sessions.';

    public function handle(AttendanceManagementService $attendance): int
    {
        $count = $attendance->processDueSessions();
        $this->info("Processed {$count} open attendance session(s).");

        return self::SUCCESS;
    }
}
