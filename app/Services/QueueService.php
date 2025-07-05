<?php
// File: app/Services/QueueService.php - FIXED GENERATE NUMBER

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
     * ✅ FIXED: Add queue dengan nomor berdasarkan tanggal_antrian
     */
    public function addQueue($serviceId, $userId = null, $ktpData = null, $tanggalAntrian = null)
    {
        return DB::transaction(function () use ($serviceId, $userId, $ktpData, $tanggalAntrian) {
            // ✅ PERBAIKAN: Generate nomor berdasarkan tanggal_antrian
            $tanggalAntrian = $tanggalAntrian ?? today();
            $number = $this->generateNumberForDate($serviceId, $tanggalAntrian);
            
            // Jika ada data KTP (untuk walk-in), cari atau buat user
            if ($ktpData && isset($ktpData['nomor_ktp'])) {
                $user = User::getOrCreateByKtp($ktpData['nomor_ktp'], $ktpData);
                $userId = $user->id;
            }
            
            // Gunakan user yang sedang login jika userId tidak diberikan
            $userId = $userId ?? Auth::id();

            // ✅ HITUNG ESTIMASI berdasarkan tanggal_antrian
            $estimatedCallTime = $this->calculateEstimatedCallTimeForDate($serviceId, $tanggalAntrian);

            // Buat antrian
            $queue = Queue::create([
                'service_id' => $serviceId,
                'user_id' => $userId,
                'number' => $number,
                'status' => 'waiting',
                'tanggal_antrian' => $tanggalAntrian, // ✅ SET tanggal_antrian
                'estimated_call_time' => $estimatedCallTime,
                'extra_delay_minutes' => $this->getGlobalDelayForDate($tanggalAntrian),
            ]);

            // ✅ UPDATE ESTIMASI untuk antrian lain di tanggal yang sama
            $this->updateEstimationsAfterNewQueue($serviceId, $queue->id, $tanggalAntrian);

            return $queue;
        });
    }

    /**
     * ✅ NEW: Generate nomor antrian berdasarkan tanggal_antrian spesifik
     */
    public function generateNumberForDate($serviceId, $tanggalAntrian)
    {
        $service = Service::findOrFail($serviceId);

        // ✅ PERBAIKAN UTAMA: Cari antrian terakhir berdasarkan tanggal_antrian
        $lastQueue = Queue::where('service_id', $serviceId)
            ->whereDate('tanggal_antrian', $tanggalAntrian) // ✅ FIXED: tanggal_antrian
            ->orderByDesc('id')
            ->first();

        // ✅ RESET nomor untuk tanggal baru
        $lastQueueNumber = $lastQueue ? intval(
            substr($lastQueue->number, strlen($service->prefix))
        ) : 0;

        $newQueueNumber = $lastQueueNumber + 1;
        $maximumNumber = pow(10, $service->padding) - 1;

        // ✅ RESET ke 1 jika sudah mencapai maksimum
        if ($newQueueNumber > $maximumNumber) {
            $newQueueNumber = 1;
        }

        return $service->prefix . str_pad($newQueueNumber, $service->padding, "0", STR_PAD_LEFT);
    }

    /**
     * ✅ IMPROVED: Hitung estimasi untuk tanggal spesifik
     */
    private function calculateEstimatedCallTimeForDate($serviceId, $tanggalAntrian)
    {
        // ✅ HITUNG HANYA antrian di tanggal yang sama
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian) // ✅ FIXED
            ->count();

        $queuePosition = $waitingQueues + 1;
        $baseMinutes = $queuePosition * 15;

        // ✅ TAMBAH: Global delay untuk tanggal tersebut
        $globalDelay = $this->getGlobalDelayForDate($tanggalAntrian);
        $totalMinutes = $baseMinutes + $globalDelay;

        // ✅ ESTIMASI: Jika untuk hari ini, dari sekarang. Jika untuk masa depan, dari jam 8 pagi
        if (Carbon::parse($tanggalAntrian)->isToday()) {
            return now()->addMinutes($totalMinutes);
        } else {
            // Untuk tanggal masa depan, mulai dari jam 8 pagi
            return Carbon::parse($tanggalAntrian)->setTime(8, 0)->addMinutes($totalMinutes);
        }
    }

    /**
     * ✅ NEW: Get global delay untuk tanggal tertentu
     */
    private function getGlobalDelayForDate($tanggalAntrian)
    {
        $maxDelay = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->max('extra_delay_minutes');

        return $maxDelay ?: 0;
    }

    /**
     * ✅ FIXED: Update estimasi setelah antrian baru untuk tanggal spesifik
     */
    private function updateEstimationsAfterNewQueue($serviceId, $excludeQueueId, $tanggalAntrian)
    {
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('status', 'waiting')
            ->where('id', '!=', $excludeQueueId)
            ->whereDate('tanggal_antrian', $tanggalAntrian) // ✅ FIXED
            ->orderBy('id', 'asc')
            ->get();

        $globalDelay = $this->getGlobalDelayForDate($tanggalAntrian);
        
        foreach ($waitingQueues as $queue) {
            // ✅ HITUNG posisi berdasarkan tanggal antrian
            $queuePosition = Queue::where('service_id', $serviceId)
                ->where('status', 'waiting')
                ->where('id', '<', $queue->id)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->count() + 1;
            
            $baseMinutes = $queuePosition * 15;
            $totalMinutes = $baseMinutes + $globalDelay;
            
            // ✅ ESTIMASI berdasarkan tanggal
            if (Carbon::parse($tanggalAntrian)->isToday()) {
                $estimatedTime = now()->addMinutes($totalMinutes);
            } else {
                $estimatedTime = Carbon::parse($tanggalAntrian)->setTime(8, 0)->addMinutes($totalMinutes);
            }
            
            $queue->update([
                'estimated_call_time' => $estimatedTime,
                'extra_delay_minutes' => $globalDelay
            ]);
        }
    }

    /**
     * ✅ FIXED: Call next queue - hanya untuk hari ini
     */
    public function callNextQueue($counterId)
    {
        $counter = Counter::findOrFail($counterId);

        $nextQueue = Queue::where('status', 'waiting')
            ->where('service_id', $counter->service_id)
            ->where(function ($query) use ($counterId) {
                $query->whereNull('counter_id')->orWhere('counter_id', $counterId);
            })
            ->whereDate('tanggal_antrian', today()) // ✅ FIXED: hanya hari ini
            ->orderBy('id')
            ->first();

        if ($nextQueue && !$nextQueue->counter_id) {
            $nextQueue->update([
                'counter_id' => $counterId,
                'called_at' => now(),
                'status' => 'serving'
            ]);

            // ✅ UPDATE ESTIMASI untuk antrian yang tersisa hari ini
            $this->updateEstimationsAfterQueueCalled($counter->service_id, today());
        }

        return $nextQueue;
    }

    /**
     * ✅ FIXED: Update estimasi setelah ada antrian yang dipanggil
     */
    private function updateEstimationsAfterQueueCalled($serviceId, $tanggalAntrian)
    {
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian) // ✅ FIXED
            ->orderBy('id', 'asc')
            ->get();

        $globalDelay = $this->getGlobalDelayForDate($tanggalAntrian);
        
        foreach ($waitingQueues as $queue) {
            $queuePosition = Queue::where('service_id', $serviceId)
                ->where('status', 'waiting')
                ->where('id', '<', $queue->id)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->count() + 1;
            
            $baseMinutes = $queuePosition * 15;
            $totalMinutes = $baseMinutes + $globalDelay;
            
            if (Carbon::parse($tanggalAntrian)->isToday()) {
                $estimatedTime = now()->addMinutes($totalMinutes);
            } else {
                $estimatedTime = Carbon::parse($tanggalAntrian)->setTime(8, 0)->addMinutes($totalMinutes);
            }
            
            $queue->update([
                'estimated_call_time' => $estimatedTime,
                'extra_delay_minutes' => $globalDelay
            ]);
        }
    }

    /**
     * ✅ IMPROVED: Update SEMUA antrian pada tanggal tertentu ketika ada delay
     */
    public function updateOverdueQueuesForDate($tanggalAntrian = null)
    {
        $tanggalAntrian = $tanggalAntrian ?? today();
        
        $overdueQueues = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->where('estimated_call_time', '<', now())
            ->get();

        if ($overdueQueues->isEmpty()) {
            return 0;
        }

        // ✅ UPDATE SEMUA antrian di tanggal tersebut
        $allQueuesOnDate = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->get();

        foreach ($allQueuesOnDate as $queue) {
            $newExtraDelay = $queue->extra_delay_minutes + 5;
            
            $queuePosition = Queue::where('service_id', $queue->service_id)
                ->where('status', 'waiting')
                ->where('id', '<', $queue->id)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->count() + 1;
            
            $baseMinutes = $queuePosition * 15;
            $totalMinutes = $baseMinutes + $newExtraDelay;
            
            if (Carbon::parse($tanggalAntrian)->isToday()) {
                $newEstimation = now()->addMinutes($totalMinutes - $baseMinutes + 5);
            } else {
                $newEstimation = Carbon::parse($tanggalAntrian)->setTime(8, 0)->addMinutes($totalMinutes);
            }
            
            $queue->update([
                'estimated_call_time' => $newEstimation,
                'extra_delay_minutes' => $newExtraDelay
            ]);
        }

        return $allQueuesOnDate->count();
    }

    /**
     * ✅ LEGACY method untuk backward compatibility
     */
    public function generateNumber($serviceId)
    {
        return $this->generateNumberForDate($serviceId, today());
    }

    /**
     * ✅ LEGACY method untuk backward compatibility
     */
    public function updateOverdueQueues()
    {
        return $this->updateOverdueQueuesForDate(today());
    }

    // ✅ EXISTING METHODS dengan perbaikan minor
    public function addQueueWithKtp($serviceId, string $ktp, array $patientData = [], $tanggalAntrian = null)
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

        return $this->addQueue($serviceId, null, $userData, $tanggalAntrian);
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
        
        // ✅ UPDATE estimasi queue lain setelah ada yang cancel
        if ($queue->status === 'waiting') {
            $this->updateEstimationsAfterQueueCalled($queue->service_id, $queue->tanggal_antrian);
        }
    }

    public function searchUserByIdentifier(string $identifier): ?User
    {
        $user = User::where('medical_record_number', $identifier)->first();
        
        if (!$user && strlen($identifier) === 16 && is_numeric($identifier)) {
            $user = User::where('nomor_ktp', $identifier)->first();
        }
        
        return $user;
    }

    /**
     * ✅ NEW: Reset estimasi untuk tanggal tertentu
     */
    public function resetEstimationsForDate($tanggalAntrian)
    {
        $waitingQueues = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->orderBy('id', 'asc')
            ->get();

        $updatedCount = 0;

        foreach ($waitingQueues as $queue) {
            $queuePosition = Queue::where('service_id', $queue->service_id)
                ->where('status', 'waiting')
                ->where('id', '<', $queue->id)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->count() + 1;
            
            $baseMinutes = $queuePosition * 15;
            
            if (Carbon::parse($tanggalAntrian)->isToday()) {
                $estimatedTime = now()->addMinutes($baseMinutes);
            } else {
                $estimatedTime = Carbon::parse($tanggalAntrian)->setTime(8, 0)->addMinutes($baseMinutes);
            }
            
            $queue->update([
                'estimated_call_time' => $estimatedTime,
                'extra_delay_minutes' => 0
            ]);
            
            $updatedCount++;
        }

        return $updatedCount;
    }
}