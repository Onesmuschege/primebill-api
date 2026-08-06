<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditService;
use Illuminate\Console\Command;

class CleanupAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:cleanup {--days=365 : Number of days to retain logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old audit logs based on retention policy';

    /**
     * Execute the console command.
     */
    public function handle(AuditService $auditService): int
    {
        $days = (int) $this->option('days');

        $this->info("Cleaning up audit logs older than {$days} days...");

        $deleted = $auditService->cleanOldLogs($days);

        $this->info("Deleted {$deleted} old audit log entries.");

        return Command::SUCCESS;
    }
}
