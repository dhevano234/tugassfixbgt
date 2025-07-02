<?php
// File: app/Services/QueueService.php - IMPROVED VERSION

namespace App\Services;

use App\Models\Counter;
use App\Models\Queue;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class QueueService
{
    /**
     * ✅ UPDATED: Add queue dengan estimasi waktu
     */
    public function addQueue($serviceId, $userId = null, $ktpData = null)
    {
        return DB::transaction(function () use ($serviceId, $userId, $ktpData) {
            // Generate nomor antrian
            $number = $this->generateNumber($serviceId);
            
            // Jika ada data KTP (untuk walk-in), cari atau buat user
            if ($ktpData && isset($ktpData['nomor_ktp'])) {
                $user = User::getOrCreateByKtp($ktpData['nomor_ktp'], $ktpData);
                $userId = $user->id;
            }
            
            // Gunakan user yang sedang login jika userId tidak diberikan
            $userId = $userId ?? Auth::id();

            // ✅ HITUNG ESTIMASI WAKTU TUNGGU
            $estimatedCallTime = $this->calculateEstimatedCallTime($serviceId);

            // Buat antrian
            $queue = Queue::create([
                'service_id' => $serviceId,
                'user_id' => $userId,
                'number' => $number,
                'status' => 'waiting',
                'estimated_call_time' => $estimatedCallTime,
                'extra_delay_minutes' => 0,
            ]);

            // ✅ UPDATE ESTIMASI UNTUK ANTRIAN LAIN SETELAH ANTRIAN BARU
            $this->updateEstimationsAfterNewQueue($serviceId, $queue->id);

            return $queue;
        });
    }

    /**
     * ✅ IMPROVED: Hitung estimasi waktu panggilan berdasarkan antrian di depan
     */
    private function calculateEstimatedCallTime($serviceId)
    {
        // Hitung jumlah antrian yang masih menunggu di service yang sama
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('status', 'waiting')
            ->whereDate('created_at', today())
            ->count();

        // Setiap antrian butuh 15 menit, tapi antrian baru posisinya paling belakang
        // Jadi waitingQueues + 1 karena antrian baru belum masuk hitungan
        $estimatedMinutes = ($waitingQueues + 1) * 15;

        // ✅ PERBAIKAN: Estimasi dari sekarang, bukan dari created_at
        return now()->addMinutes($estimatedMinutes);
    }

    /**
     * ✅ IMPROVED: Update estimasi waktu untuk antrian lain setelah ada antrian baru
     */
    private function updateEstimationsAfterNewQueue($serviceId, $excludeQueueId)
    {
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('status', 'waiting')
            ->where('id', '!=', $excludeQueueId)
            ->whereDate('created_at', today())
            ->orderBy('id', 'asc')
            ->get();

        $baseTime = now();
        
        foreach ($waitingQueues as $index => $queue) {
            // ✅ PERBAIKAN: Setiap antrian estimasi +15 menit dari yang sebelumnya
            // Index dimulai dari 0, jadi antrian pertama = 15 menit
            $estimatedTime = $baseTime->copy()->addMinutes(($index + 1) * 15);
            
            $queue->update([
                'estimated_call_time' => $estimatedTime,
                'extra_delay_minutes' => 0 // Reset extra delay saat recalculate
            ]);
        }
    }

    /**
     * ✅ UPDATED: Call next queue dengan update estimasi
     */
    public function callNextQueue($counterId)
    {
        $counter = Counter::findOrFail($counterId);

        $nextQueue = Queue::where('status', 'waiting')
            ->where('service_id', $counter->service_id)
            ->where(function ($query) use ($counterId) {
                $query->whereNull('counter_id')->orWhere('counter_id', $counterId);
            })
            ->whereDate('created_at', now()->format('Y-m-d'))
            ->orderBy('id')
            ->first();

        if ($nextQueue && !$nextQueue->counter_id) {
            $nextQueue->update([
                'counter_id' => $counterId,
                'called_at' => now(),
                'status' => 'serving' // ✅ TAMBAH INI - langsung ubah status ke serving
            ]);

            // ✅ UPDATE ESTIMASI UNTUK ANTRIAN YANG TERSISA
            $this->updateEstimationsAfterQueueCalled($counter->service_id);
        }

        return $nextQueue;
    }

    /**
     * ✅ IMPROVED: Update estimasi setelah ada antrian yang dipanggil
     */
    private function updateEstimationsAfterQueueCalled($serviceId)
    {
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('status', 'waiting')
            ->whereDate('created_at', today())
            ->orderBy('id', 'asc')
            ->get();

        $baseTime = now();
        
        foreach ($waitingQueues as $index => $queue) {
            // ✅ PERBAIKAN: Recalculate estimasi dari sekarang
            // Semua queue maju ke depan, jadi estimasi berkurang 15 menit
            $estimatedTime = $baseTime->copy()->addMinutes(($index + 1) * 15);
            
            $queue->update([
                'estimated_call_time' => $estimatedTime,
                'extra_delay_minutes' => 0 // Reset extra delay
            ]);
        }
    }

    /**
     * ✅ IMPROVED: Check dan update extra delay untuk antrian yang sudah lewat estimasi
     */
    public function updateOverdueQueues()
    {
        $overdueQueues = Queue::where('status', 'waiting')
            ->whereDate('created_at', today())
            ->where('estimated_call_time', '<', now())
            ->get();

        foreach ($overdueQueues as $queue) {
            // ✅ PERBAIKAN: Lebih robust increment
            $newExtraDelay = $queue->extra_delay_minutes + 5;
            $newEstimation = now()->addMinutes(5);
            
            $queue->update([
                'estimated_call_time' => $newEstimation,
                'extra_delay_minutes' => $newExtraDelay
            ]);
        }

        return $overdueQueues->count();
    }

    /**
     * ✅ IMPROVED: Get real-time waiting time untuk specific queue
     */
    public function getWaitingTimeInfo($queueId)
    {
        $queue = Queue::with(['service', 'user'])->find($queueId);
        
        if (!$queue || $queue->status !== 'waiting') {
            return null;
        }

        $now = now();
        $estimatedTime = $queue->estimated_call_time;
        
        if ($estimatedTime && $estimatedTime > $now) {
            // Masih dalam estimasi
            $waitingMinutes = $now->diffInMinutes($estimatedTime);
            $status = 'on_time';
        } else {
            // Sudah lewat estimasi atau belum ada estimasi
            $waitingMinutes = $queue->extra_delay_minutes ?: 5;
            $status = 'delayed';
        }

        return [
            'queue_number' => $queue->number,
            'service_name' => $queue->service->name ?? 'Unknown',
            'patient_name' => $queue->user->name ?? 'Walk-in',
            'estimated_call_time' => $estimatedTime,
            'current_waiting_minutes' => $waitingMinutes,
            'extra_delay_minutes' => $queue->extra_delay_minutes,
            'status' => $status,
            'position_in_queue' => $this->getQueuePosition($queue)
        ];
    }

    /**
     * ✅ IMPROVED: Get posisi antrian dalam urutan
     */
    private function getQueuePosition($queue)
    {
        return Queue::where('service_id', $queue->service_id)
            ->where('status', 'waiting')
            ->where('id', '<', $queue->id)
            ->whereDate('created_at', today())
            ->count() + 1;
    }

    /**
     * ✅ NEW: Get dashboard stats untuk estimasi
     */
    public function getDashboardEstimationStats()
    {
        $today = today();
        
        return [
            'total_waiting' => Queue::where('status', 'waiting')->whereDate('created_at', $today)->count(),
            'on_time_queues' => Queue::where('status', 'waiting')
                ->whereDate('created_at', $today)
                ->where('estimated_call_time', '>=', now())
                ->count(),
            'overdue_queues' => Queue::where('status', 'waiting')
                ->whereDate('created_at', $today)
                ->where('estimated_call_time', '<', now())
                ->count(),
            'average_wait_time' => $this->calculateAverageWaitTime(),
        ];
    }

    /**
     * ✅ NEW: Calculate average wait time
     */
    private function calculateAverageWaitTime()
    {
        $finishedToday = Queue::where('status', 'finished')
            ->whereDate('created_at', today())
            ->whereNotNull('called_at')
            ->get();

        if ($finishedToday->isEmpty()) {
            return 0;
        }

        $totalWaitMinutes = $finishedToday->sum(function ($queue) {
            return $queue->created_at->diffInMinutes($queue->called_at);
        });

        return round($totalWaitMinutes / $finishedToday->count());
    }

    /**
     * ✅ NEW: Reset estimasi untuk semua queue (untuk maintenance)
     */
    public function resetAllEstimations()
    {
        $waitingQueues = Queue::where('status', 'waiting')
            ->whereDate('created_at', today())
            ->orderBy('id', 'asc')
            ->get();

        $baseTime = now();
        $updatedCount = 0;

        foreach ($waitingQueues as $index => $queue) {
            $estimatedTime = $baseTime->copy()->addMinutes(($index + 1) * 15);
            
            $queue->update([
                'estimated_call_time' => $estimatedTime,
                'extra_delay_minutes' => 0
            ]);
            
            $updatedCount++;
        }

        return $updatedCount;
    }

    // ✅ EXISTING METHODS - tetap sama
    public function addQueueWithKtp($serviceId, string $ktp, array $patientData = [])
    {
        if (strlen($ktp) !== 16 || !is_numeric($ktp)) {
            throw new \InvalidArgumentException('Nomor KTP harus 16 digit angka');
        }

        $userData = array_merge([
            'nomor_ktp' => $ktp,
            'name' => 'Pasien Walk-in - ' . substr($ktp, -4),
            'email' => 'patient' . substr($ktp, -4) . '@klinik.local',
            'address' => 'Alamat belum diisi',
            'phone' => null,
            'gender' => null,
            'birth_date' => null,
        ], $patientData);

        return $this->addQueue($serviceId, null, $userData);
    }

    public function generateNumber($serviceId)
    {
        $service = Service::findOrFail($serviceId);

        $lastQueue = Queue::where('service_id', $serviceId)
            ->whereDate('created_at', today())
            ->orderByDesc('id')
            ->first();

        $currentDate = now()->format('Y-m-d');
        $lastQueueDate = $lastQueue ? $lastQueue->created_at->format('Y-m-d') : null;
        $isSameDate = $currentDate === $lastQueueDate;

        $lastQueueNumber = $lastQueue ? intval(
            substr($lastQueue->number, strlen($service->prefix))
        ) : 0;

        $maximumNumber = pow(10, $service->padding) - 1;
        $isMaximumNumber = $lastQueueNumber === $maximumNumber;

        if ($isSameDate && !$isMaximumNumber) {
            $newQueueNumber = $lastQueueNumber + 1;
        } else {
            $newQueueNumber = 1;
        }

        return $service->prefix . str_pad($newQueueNumber, $service->padding, "0", STR_PAD_LEFT);
    }

    public function serveQueue(Queue $queue)
    {
        if ($queue->status !== 'waiting') {
            return;
        }

        $queue->update([
            'status' => 'serving',
            'served_at' => now()
        ]);
    }

    public function finishQueue(Queue $queue)
    {
        if ($queue->status !== 'serving') {
            return;
        }

        $queue->update([
            'status' => 'finished',
            'finished_at' => now()
        ]);
    }

    public function cancelQueue(Queue $queue)
    {
        if (!in_array($queue->status, ['waiting', 'serving'])) {
            return;
        }

        $queue->update([
            'status' => 'canceled',
            'canceled_at' => now()
        ]);
        
        // ✅ TAMBAH: Update estimasi queue lain setelah ada yang cancel
        if ($queue->status === 'waiting') {
            $this->updateEstimationsAfterQueueCalled($queue->service_id);
        }
    }

    public function getQueueStatsByMRN(string $medicalRecordNumber): array
    {
        $user = User::where('medical_record_number', $medicalRecordNumber)->first();
        
        if (!$user) {
            return [
                'total_queues' => 0,
                'finished' => 0,
                'canceled' => 0,
                'user' => null,
            ];
        }

        $queues = $user->queues();

        return [
            'total_queues' => $queues->count(),
            'finished' => $queues->where('status', 'finished')->count(),
            'canceled' => $queues->where('status', 'canceled')->count(),
            'last_visit' => $queues->latest()->first()?->created_at,
            'user' => $user,
        ];
    }

    public function searchUserByIdentifier(string $identifier): ?User
    {
        $user = User::where('medical_record_number', $identifier)->first();
        
        if (!$user && strlen($identifier) === 16 && is_numeric($identifier)) {
            $user = User::where('nomor_ktp', $identifier)->first();
        }
        
        return $user;
    }
}