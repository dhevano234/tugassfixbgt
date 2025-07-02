<?php
// File: app/Console/Commands/UpdateOverdueQueues.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Queue;
use Carbon\Carbon;

class UpdateOverdueQueues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:update-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update overdue queues with extra 5 minutes delay';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🕐 Checking for overdue queues...');
        
        // Cari antrian yang sudah lewat estimasi tapi belum dipanggil
        $overdueQueues = Queue::where('status', 'waiting')
            ->whereDate('created_at', today())
            ->where('estimated_call_time', '<', now())
            ->get();

        if ($overdueQueues->isEmpty()) {
            $this->info('✅ No overdue queues found');
            return 0;
        }

        $this->info("🔍 Found {$overdueQueues->count()} overdue queues");

        $updatedCount = 0;
        
        foreach ($overdueQueues as $queue) {
            // Tambah 5 menit extra delay
            $newExtraDelay = $queue->extra_delay_minutes + 5;
            $newEstimation = now()->addMinutes(5);
            
            $queue->update([
                'estimated_call_time' => $newEstimation,
                'extra_delay_minutes' => $newExtraDelay
            ]);
            
            $updatedCount++;
            
            $this->line("📋 Queue {$queue->number} - Extra delay: {$newExtraDelay} minutes");
        }

        $this->info("✅ Updated {$updatedCount} overdue queues with +5 minutes delay");
        
        return 0;
    }
}