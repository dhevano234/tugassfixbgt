<?php
// File: app/Console/Kernel.php - CORRECT AND SIMPLE VERSION

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // ✅ HANYA INI YANG DIPERLUKAN: Update estimasi antrian yang overdue setiap 5 menit
        $schedule->command('queue:update-overdue')
                 ->everyFiveMinutes()
                 ->between('07:00', '22:00') // Hanya jam operasional klinik
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/queue-updates.log'))
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Queue overdue update completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Queue overdue update failed');
                 });
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}