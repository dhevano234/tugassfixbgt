<?php
// File: app/Console/Commands/UpdateOverdueQueues.php - FIXED untuk tanggal_antrian

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Queue;
use App\Services\QueueService;
use Carbon\Carbon;

class UpdateOverdueQueues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:update-overdue {--date= : Specific date to update (Y-m-d format)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update ALL queues when any queue becomes overdue (+5 minutes for all on specific date)';

    protected QueueService $queueService;

    public function __construct(QueueService $queueService)
    {
        parent::__construct();
        $this->queueService = $queueService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // ✅ PERBAIKAN: Tentukan tanggal yang akan diproses
        $targetDate = $this->option('date') ? Carbon::parse($this->option('date')) : today();
        
        $this->info("🕐 Checking for overdue queues on {$targetDate->format('Y-m-d')}...");
        
        // ✅ PERBAIKAN: Cari antrian yang sudah lewat estimasi berdasarkan tanggal_antrian
        $overdueQueues = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $targetDate) // ✅ FIXED: tanggal_antrian
            ->where('estimated_call_time', '<', now())
            ->get();

        if ($overdueQueues->isEmpty()) {
            $this->info('✅ No overdue queues found');
            return 0;
        }

        $this->info("🔍 Found {$overdueQueues->count()} overdue queues on {$targetDate->format('d F Y')}");

        // ✅ PERBAIKAN UTAMA: Update SEMUA antrian di tanggal tersebut
        $updatedCount = $this->queueService->updateOverdueQueuesForDate($targetDate);

        if ($updatedCount > 0) {
            $this->info("✅ Updated ALL {$updatedCount} queues on {$targetDate->format('d F Y')} with +5 minutes delay");
            
            // ✅ DETAIL: Tampilkan info per service
            $this->showServiceBreakdown($targetDate);
        } else {
            $this->warn("⚠️ No queues were updated on {$targetDate->format('d F Y')}");
        }
        
        return 0;
    }

    /**
     * ✅ NEW: Tampilkan breakdown per service
     */
    private function showServiceBreakdown($targetDate): void
    {
        $serviceBreakdown = Queue::with('service')
            ->where('status', 'waiting')
            ->whereDate('tanggal_antrian', $targetDate)
            ->get()
            ->groupBy('service.name')
            ->map(function ($queues, $serviceName) {
                return [
                    'service' => $serviceName,
                    'total_queues' => $queues->count(),
                    'avg_delay' => round($queues->avg('extra_delay_minutes'), 1),
                    'max_delay' => $queues->max('extra_delay_minutes'),
                    'overdue_count' => $queues->where('estimated_call_time', '<', now())->count(),
                ];
            });

        if ($serviceBreakdown->isNotEmpty()) {
            $this->newLine();
            $this->info("📊 Service Breakdown for {$targetDate->format('d F Y')}:");
            
            $headers = ['Service', 'Total Queues', 'Avg Delay', 'Max Delay', 'Overdue'];
            $rows = [];
            
            foreach ($serviceBreakdown as $data) {
                $rows[] = [
                    $data['service'],
                    $data['total_queues'],
                    $data['avg_delay'] . ' min',
                    $data['max_delay'] . ' min',
                    $data['overdue_count'],
                ];
            }
            
            $this->table($headers, $rows);
        }
    }

    /**
     * ✅ NEW: Method untuk update semua tanggal (bulk operation)
     */
    public function updateAllDates(): int
    {
        $this->info('🔄 Updating overdue queues for all active dates...');
        
        // Ambil semua tanggal yang punya antrian waiting
        $activeDates = Queue::where('status', 'waiting')
            ->distinct('tanggal_antrian')
            ->pluck('tanggal_antrian')
            ->filter()
            ->sort();

        $totalUpdated = 0;
        
        foreach ($activeDates as $date) {
            $this->info("Processing date: {$date->format('d F Y')}");
            
            $overdueQueues = Queue::where('status', 'waiting')
                ->whereDate('tanggal_antrian', $date)
                ->where('estimated_call_time', '<', now())
                ->get();
            
            if ($overdueQueues->isNotEmpty()) {
                $updated = $this->queueService->updateOverdueQueuesForDate($date);
                $totalUpdated += $updated;
                $this->line("  ✅ Updated {$updated} queues");
            } else {
                $this->line("  ⏭️ No overdue queues");
            }
        }
        
        $this->info("🎉 Total updated: {$totalUpdated} queues across " . $activeDates->count() . " dates");
        
        return $totalUpdated;
    }
}