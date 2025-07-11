<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Queue;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Schema;

class SendWhatsAppRemindersCommand extends Command
{
    protected $signature = 'whatsapp:send-reminders {--dry-run : Show what would be sent without actually sending}';
    protected $description = 'Send WhatsApp reminders 5 minutes BEFORE estimated call time';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        
        if ($isDryRun) {
            $this->warn('🧪 DRY RUN MODE - No messages will be sent');
        }
        
        $this->info('🔍 Checking for queues to remind...');
        
        $now = now();
        
        // ✅ FIXED LOGIC: Cari antrian yang 5 menit lagi akan dipanggil
        // Jika sekarang 02:07, cari antrian estimasi 02:11-02:13 (5±1 menit dari sekarang)
        $fiveMinutesFromNow = $now->copy()->addMinutes(5);
        $toleranceStart = $fiveMinutesFromNow->copy()->subMinutes(1); // 4 menit dari sekarang
        $toleranceEnd = $fiveMinutesFromNow->copy()->addMinutes(1);   // 6 menit dari sekarang
        
        $this->info("⏰ Time now: {$now->format('H:i:s')}");
        $this->info("🎯 Looking for queues estimated between: {$toleranceStart->format('H:i:s')} - {$toleranceEnd->format('H:i:s')}");
        $this->info("📝 Logic: Send WhatsApp 5 minutes BEFORE estimated call time");
        
        // Cek apakah migration sudah jalan
        $hasWhatsAppColumn = Schema::hasColumn('queues', 'whatsapp_reminder_sent_at');
        
        if (!$hasWhatsAppColumn) {
            $this->error("❌ Migration belum jalan! Jalankan: php artisan migrate");
            $this->warn("💡 Atau hapus baris whereNull('whatsapp_reminder_sent_at') jika tidak mau migration");
            return 1;
        }
        
        // Query antrian yang perlu reminder
        $queues = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', today())
            ->whereBetween('estimated_call_time', [$toleranceStart, $toleranceEnd])
            ->whereNull('whatsapp_reminder_sent_at') // Belum pernah dikirim
            ->whereHas('user', function($query) {
                $query->whereNotNull('phone');
            })
            ->with(['user', 'service'])
            ->get();
        
        $this->info("📋 Found {$queues->count()} queues in reminder time range");
        
        if ($queues->isEmpty()) {
            $this->info('✅ No queues found that need reminders at this time');
            return 0;
        }
        
        // Display table dengan detail
        $table = [];
        $sentCount = 0;
        $failedCount = 0;
        
        $whatsAppService = new WhatsAppService();
        
        foreach ($queues as $queue) {
            $minutesUntilCall = $now->diffInMinutes($queue->estimated_call_time, false);
            
            $status = 'Would send';
            if (!$isDryRun) {
                try {
                    $this->info("📤 Sending WhatsApp to {$queue->user->name} ({$queue->user->phone})...");
                    
                    $success = $whatsAppService->sendReminder($queue);
                    
                    if ($success) {
                        $status = '✅ Sent';
                        $sentCount++;
                        
                        // Update timestamp di database
                        $queue->update(['whatsapp_reminder_sent_at' => now()]);
                        
                        $this->info("✅ SUCCESS: WhatsApp sent to {$queue->user->name}");
                    } else {
                        $status = '❌ Failed';
                        $failedCount++;
                        $this->error("❌ FAILED: WhatsApp gagal ke {$queue->user->name}");
                    }
                } catch (\Exception $e) {
                    $status = '❌ Error: ' . $e->getMessage();
                    $failedCount++;
                    $this->error("❌ ERROR: {$e->getMessage()}");
                }
                
                // Delay antar pengiriman
                if ($sentCount > 0 && $sentCount < $queues->count()) {
                    sleep(2); // 2 detik delay
                }
            }
            
            $table[] = [
                $queue->number,
                $queue->user->name,
                $queue->user->phone,
                $queue->service->name ?? 'N/A',
                $queue->estimated_call_time->format('H:i:s'),
                round($minutesUntilCall) . ' min',
                $status
            ];
        }
        
        // Display hasil
        $this->table([
            'Queue #',
            'Patient',
            'Phone',
            'Service',
            'Est. Call Time',
            'Time Until Call',
            'WhatsApp Status'
        ], $table);
        
        // Summary
        if (!$isDryRun) {
            if ($sentCount > 0) {
                $this->info("🎉 Successfully sent {$sentCount} WhatsApp reminder(s)");
            }
            
            if ($failedCount > 0) {
                $this->error("😞 Failed to send {$failedCount} WhatsApp reminder(s)");
                $this->warn("💡 Check logs: tail -f storage/logs/laravel.log");
            }
        }
        
        return 0;
    }
}