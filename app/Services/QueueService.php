<?php
// File: app/Services/QueueService.php - UPDATED dengan KTP support

namespace App\Services;

use App\Models\Counter;
use App\Models\Queue;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class QueueService
{
    /**
     * ✅ UPDATED: Add queue dengan support KTP untuk walk-in
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

            // Buat antrian
            $queue = Queue::create([
                'service_id' => $serviceId,
                'user_id' => $userId,
                'number' => $number,
                'status' => 'waiting',
            ]);

            return $queue;
        });
    }

    /**
     * ✅ NEW: Add queue untuk walk-in dengan input KTP manual
     */
    public function addQueueWithKtp($serviceId, string $ktp, array $patientData = [])
    {
        // Validasi KTP
        if (strlen($ktp) !== 16 || !is_numeric($ktp)) {
            throw new \InvalidArgumentException('Nomor KTP harus 16 digit angka');
        }

        // Data pasien dengan default values
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

    /**
     * EXISTING: Generate number (tidak berubah)
     */
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

    /**
     * EXISTING: Call next queue (tidak berubah)
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
                'called_at' => now()
            ]);
        }

        return $nextQueue;
    }
    
    /**
     * EXISTING: Serve queue (tidak berubah)
     */
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

    /**
     * EXISTING: Finish queue (tidak berubah)
     */
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

    /**
     * EXISTING: Cancel queue (tidak berubah)
     */
    public function cancelQueue(Queue $queue)
    {
        if (!in_array($queue->status, ['waiting', 'serving'])) {
            return;
        }

        $queue->update([
            'status' => 'canceled',
            'canceled_at' => now()
        ]);
    }

    /**
     * ✅ NEW: Get queue statistics by medical record number
     */
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

    /**
     * ✅ NEW: Search user by KTP or Medical Record Number
     */
    public function searchUserByIdentifier(string $identifier): ?User
    {
        // Coba cari berdasarkan nomor rekam medis dulu
        $user = User::where('medical_record_number', $identifier)->first();
        
        // Jika tidak ditemukan, coba berdasarkan KTP
        if (!$user && strlen($identifier) === 16 && is_numeric($identifier)) {
            $user = User::where('nomor_ktp', $identifier)->first();
        }
        
        return $user;
    }
}