<?php

namespace App\Console\Commands;

use App\Services\Rooms\RoomMonitoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProcessRoomCheckouts extends Command
{
    protected $signature = 'rooms:process-checkouts';

    protected $description = 'Automatically close room occupancies at their scheduled end times.';

    public function handle(RoomMonitoringService $monitoring): int
    {
        $lockName = 'ccsync:room-monitoring:checkouts';
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ($lock === null || $lock->acquired === null) {
            throw new RuntimeException('Unable to acquire the room checkout processing lock.');
        }
        if ((int) $lock->acquired !== 1) {
            $this->info('Skipped: another room checkout processor holds the lock.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;
        try {
            $count = $monitoring->processExpired();
            $this->info("Processed {$count} expired room occupancy record(s).");
        } finally {
            $released = DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            if ($released === null || (int) $released->released !== 1) {
                $this->error('Unable to release the room checkout processing lock.');
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }
}
