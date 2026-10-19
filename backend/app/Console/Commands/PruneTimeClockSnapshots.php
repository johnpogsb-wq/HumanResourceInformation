<?php

namespace App\Console\Commands;

use App\Services\TimeClockService;
use Illuminate\Console\Command;

/** Deletes time-clock snapshots past `timeclock.snapshot_retention_days`. */
class PruneTimeClockSnapshots extends Command
{
    protected $signature = 'timeclock:prune-snapshots';

    protected $description = 'Delete time-clock punch photos older than the retention window (the punches themselves stay)';

    public function handle(TimeClockService $clock): int
    {
        $pruned = $clock->pruneSnapshots();

        $this->info("Deleted {$pruned} punch photo(s) older than ".config('timeclock.snapshot_retention_days').' days.');

        return self::SUCCESS;
    }
}
