<?php
// File: app/Console/Kernel.php - ADD scheduler

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
        // ✅ UPDATE estimasi antrian yang sudah lewat waktu setiap 5 menit
        $schedule->command('queue:update-overdue')
                 ->everyFiveMinutes()
                 ->between('07:00', '22:00') // Hanya jam operasional klinik
                 ->withoutOverlapping()
                 ->runInBackground();

        // ✅ LOG untuk monitoring (optional)
        $schedule->command('queue:update-overdue')
                 ->everyFiveMinutes()
                 ->appendOutputTo(storage_path('logs/queue-updates.log'));
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