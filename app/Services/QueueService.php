<?php
// File: app/Services/QueueService.php - UPDATED: Simplified Doctor Sessions dengan Quota Check

namespace App\Services;

use App\Models\Counter;
use App\Models\Queue;
use App\Models\Service;
use App\Models\User;
use App\Models\DoctorSchedule;
use App\Models\DailyQuota;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class QueueService
{
    /**
     * ✅ UPDATED: Get available doctor sessions - Simplified dengan quota check
     */
    public function getAvailableDoctorSessions($tanggalAntrian)
    {
        $tanggalCarbon = Carbon::parse($tanggalAntrian);
        $dayOfWeek = strtolower($tanggalCarbon->format('l'));
        
        // ✅ PERBAIKAN: Hanya cek waktu jika tanggal adalah hari ini
        $isToday = $tanggalCarbon->isToday();
        $currentTime = $isToday ? now()->format('H:i') : '00:00';
        
        $doctors = DoctorSchedule::where('is_active', true)
            ->whereJsonContains('days', $dayOfWeek)
            ->where(function($query) use ($isToday, $currentTime) {
                // ✅ PERBAIKAN: Jika bukan hari ini, semua session available
                if (!$isToday) {
                    // Untuk tanggal masa depan, tidak perlu filter waktu
                    return;
                }
                // ✅ Jika hari ini, hanya session yang belum selesai
                $query->whereTime('end_time', '>', $currentTime);
            })
            ->with('service')
            ->get();
            
        return $doctors->map(function($doctor) use ($tanggalAntrian, $isToday) {
            // ✅ UPDATED: Cek quota availability
            $quotaCheck = $this->checkQuotaAvailability($doctor->id, $tanggalAntrian);
            
            return [
                'id' => $doctor->id,
                'doctor_name' => $doctor->doctor_name,
                'service_name' => $doctor->service->name ?? 'Unknown',
                'start_time' => $doctor->start_time->format('H:i'),
                'end_time' => $doctor->end_time->format('H:i'),
                'time_range' => $doctor->start_time->format('H:i') . ' - ' . $doctor->end_time->format('H:i'),
                'is_available' => $quotaCheck['available'],
                'quota_status' => $quotaCheck['available'] ? 'Tersedia' : 'Penuh',
                'quota_info' => $quotaCheck['quota'] ? [
                    'used' => $quotaCheck['quota']->used_quota,
                    'total' => $quotaCheck['quota']->total_quota,
                    'remaining' => $quotaCheck['quota']->available_quota
                ] : null,
                'is_today' => $isToday,
                'selected_date' => $tanggalAntrian
            ];
        })->filter(function($session) {
            // ✅ UPDATED: Filter hanya yang tersedia (ada quota)
            return $session['is_available'];
        });
    }

    /**
     * ✅ UPDATED: Add queue dengan session dokter support dan quota checking
     */
    public function addQueue($serviceId, $userId = null, $ktpData = null, $tanggalAntrian = null, $doctorId = null)
    {
        return DB::transaction(function () use ($serviceId, $userId, $ktpData, $tanggalAntrian, $doctorId) {
            $tanggalAntrian = $tanggalAntrian ?? today();
            
            // ✅ NEW: Check quota availability
            $quotaCheck = $this->checkQuotaAvailability($doctorId, $tanggalAntrian);
            
            if (!$quotaCheck['available']) {
                throw new \Exception($quotaCheck['message']);
            }
            
            // ✅ NEW: Validasi session dokter jika ada doctor_id
            if ($doctorId) {
                $this->validateDoctorSession($doctorId, $tanggalAntrian);
            }
            
            // Generate nomor berdasarkan session atau service
            $number = $this->generateNumberForSession($serviceId, $tanggalAntrian, $doctorId);
            
            // Handle KTP data
            if ($ktpData && isset($ktpData['nomor_ktp'])) {
                $user = User::getOrCreateByKtp($ktpData['nomor_ktp'], $ktpData);
                $userId = $user->id;
            }
            
            $userId = $userId ?? Auth::id();

            // Hitung estimasi berdasarkan session dokter
            $estimatedCallTime = $this->calculateSessionEstimatedTime($serviceId, $tanggalAntrian, $doctorId);

            // Buat antrian
            $queue = Queue::create([
                'service_id' => $serviceId,
                'user_id' => $userId,
                'doctor_id' => $doctorId,
                'number' => $number,
                'status' => 'waiting',
                'tanggal_antrian' => $tanggalAntrian,
                'estimated_call_time' => $estimatedCallTime,
                'extra_delay_minutes' => $this->getSessionDelay($doctorId, $tanggalAntrian),
            ]);

            // ✅ NEW: Update quota usage
            if ($quotaCheck['quota']) {
                $quotaCheck['quota']->incrementUsedQuota();
            }

            // Update estimasi untuk antrian lain
            $this->updateSessionEstimations($serviceId, $tanggalAntrian, $doctorId, $queue->id);

            return $queue;
        });
    }

    /**
     * ✅ FIXED: Validasi session dokter dengan logic tanggal yang benar
     */
    public function validateDoctorSession($doctorId, $tanggalAntrian)
    {
        $doctor = DoctorSchedule::find($doctorId);
        if (!$doctor) {
            throw new \InvalidArgumentException('Dokter tidak ditemukan');
        }
        
        $tanggalCarbon = Carbon::parse($tanggalAntrian);
        
        // Cek apakah tanggal valid
        if ($tanggalCarbon->isPast() && !$tanggalCarbon->isToday()) {
            throw new \InvalidArgumentException('Tidak dapat membuat antrian untuk tanggal yang sudah lewat');
        }
        
        // Cek hari praktik dokter
        $dayOfWeek = strtolower($tanggalCarbon->format('l'));
        if (!in_array($dayOfWeek, $doctor->days ?? [])) {
            throw new \InvalidArgumentException("Dokter {$doctor->doctor_name} tidak praktik pada hari ini");
        }
        
        // ✅ PERBAIKAN: Hanya cek waktu jika tanggal adalah hari ini
        if ($tanggalCarbon->isToday()) {
            $currentTime = now()->format('H:i');
            $sessionEndTime = $doctor->end_time->format('H:i');
            
            if ($currentTime >= $sessionEndTime) {
                throw new \InvalidArgumentException(
                    "Sesi dokter {$doctor->doctor_name} sudah selesai (berakhir jam {$sessionEndTime}). " .
                    "Silakan pilih dokter lain atau jadwal untuk hari berikutnya."
                );
            }
        }
        // ✅ PERBAIKAN: Untuk tanggal masa depan, tidak perlu cek waktu
        
        return true;
    }

    /**
     * ✅ NEW: Generate nomor antrian berdasarkan session dokter
     */
    public function generateNumberForSession($serviceId, $tanggalAntrian, $doctorId = null)
    {
        $service = Service::findOrFail($serviceId);
        
        if ($doctorId) {
            // ✅ NOMOR BERDASARKAN SESSION DOKTER
            $lastQueue = Queue::where('service_id', $serviceId)
                ->where('doctor_id', $doctorId)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->orderByDesc('id')
                ->first();
        } else {
            // Fallback ke sistem lama (berdasarkan tanggal saja)
            $lastQueue = Queue::where('service_id', $serviceId)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->whereNull('doctor_id')
                ->orderByDesc('id')
                ->first();
        }
        
        $lastQueueNumber = $lastQueue ? intval(
            substr($lastQueue->number, strlen($service->prefix))
        ) : 0;

        $newQueueNumber = $lastQueueNumber + 1;
        $maximumNumber = pow(10, $service->padding) - 1;

        // Reset ke 1 jika sudah mencapai maksimum
        if ($newQueueNumber > $maximumNumber) {
            $newQueueNumber = 1;
        }

        return $service->prefix . str_pad($newQueueNumber, $service->padding, "0", STR_PAD_LEFT);
    }

    /**
     * ✅ NEW: Hitung estimasi berdasarkan session dokter
     */
    private function calculateSessionEstimatedTime($serviceId, $tanggalAntrian, $doctorId = null)
    {
        if (!$doctorId) {
            // Fallback ke sistem lama
            return $this->calculateEstimatedCallTimeForDate($serviceId, $tanggalAntrian);
        }
        
        $doctor = DoctorSchedule::find($doctorId);
        if (!$doctor) {
            return $this->calculateEstimatedCallTimeForDate($serviceId, $tanggalAntrian);
        }
        
        // Hitung antrian dalam session dokter yang sama
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('doctor_id', $doctorId)
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->where('status', 'waiting')
            ->count();

        $queuePosition = $waitingQueues + 1;
        $baseMinutes = $queuePosition * 15; // 15 menit per antrian
        
        $sessionDelay = $this->getSessionDelay($doctorId, $tanggalAntrian);
        $totalMinutes = $baseMinutes + $sessionDelay;

        $tanggalCarbon = Carbon::parse($tanggalAntrian);
        $sessionStartTime = $doctor->start_time;
        
        if ($tanggalCarbon->isToday()) {
            // ✅ JIKA HARI INI: Mulai dari jam session atau sekarang (yang lebih besar)
            $sessionStartDateTime = $tanggalCarbon->copy()->setTimeFromTimeString($sessionStartTime->format('H:i'));
            $startTime = now()->max($sessionStartDateTime);
        } else {
            // ✅ JIKA MASA DEPAN: Mulai dari jam session dokter
            $startTime = $tanggalCarbon->copy()->setTimeFromTimeString($sessionStartTime->format('H:i'));
        }
        
        return $startTime->addMinutes($totalMinutes);
    }

    /**
     * ✅ NEW: Get delay untuk session dokter tertentu
     */
    private function getSessionDelay($doctorId, $tanggalAntrian)
    {
        if (!$doctorId) {
            return $this->getGlobalDelayForDate($tanggalAntrian);
        }
        
        $maxDelay = Queue::where('doctor_id', $doctorId)
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->where('status', 'waiting')
            ->max('extra_delay_minutes');

        return $maxDelay ?: 0;
    }

    /**
     * ✅ NEW: Update estimasi untuk session tertentu
     */
    private function updateSessionEstimations($serviceId, $tanggalAntrian, $doctorId, $excludeQueueId = null)
    {
        if (!$doctorId) {
            return $this->updateEstimationsAfterNewQueue($serviceId, $excludeQueueId, $tanggalAntrian);
        }
        
        $doctor = DoctorSchedule::find($doctorId);
        if (!$doctor) {
            return;
        }
        
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('doctor_id', $doctorId)
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->where('status', 'waiting')
            ->when($excludeQueueId, function($query) use ($excludeQueueId) {
                return $query->where('id', '!=', $excludeQueueId);
            })
            ->orderBy('id', 'asc')
            ->get();

        $sessionDelay = $this->getSessionDelay($doctorId, $tanggalAntrian);
        $sessionStartTime = $doctor->start_time;
        $tanggalCarbon = Carbon::parse($tanggalAntrian);
        
        foreach ($waitingQueues as $index => $queue) {
            $queuePosition = $index + 1;
            $baseMinutes = $queuePosition * 15;
            $totalMinutes = $baseMinutes + $sessionDelay;
            
            if ($tanggalCarbon->isToday()) {
                $sessionStartDateTime = $tanggalCarbon->copy()->setTimeFromTimeString($sessionStartTime->format('H:i'));
                $startTime = now()->max($sessionStartDateTime);
            } else {
                $startTime = $tanggalCarbon->copy()->setTimeFromTimeString($sessionStartTime->format('H:i'));
            }
            
            $estimatedTime = $startTime->addMinutes($totalMinutes);
            
            $queue->update([
                'estimated_call_time' => $estimatedTime,
                'extra_delay_minutes' => $sessionDelay
            ]);
        }
    }

    /**
     * ✅ EXISTING: Generate nomor antrian berdasarkan tanggal_antrian spesifik (Fallback)
     */
    public function generateNumberForDate($serviceId, $tanggalAntrian)
    {
        return $this->generateNumberForSession($serviceId, $tanggalAntrian, null);
    }

    /**
     * ✅ EXISTING: Hitung estimasi untuk tanggal spesifik (Fallback untuk non-session)
     */
    private function calculateEstimatedCallTimeForDate($serviceId, $tanggalAntrian)
    {
        // HITUNG HANYA antrian di tanggal yang sama (non-session)
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->whereNull('doctor_id') // ✅ HANYA non-session queues
            ->count();

        $queuePosition = $waitingQueues + 1;
        $baseMinutes = $queuePosition * 15;

        // Global delay untuk tanggal tersebut (non-session)
        $globalDelay = $this->getGlobalDelayForDate($tanggalAntrian);
        $totalMinutes = $baseMinutes + $globalDelay;

        // ESTIMASI: Jika untuk hari ini, dari sekarang. Jika untuk masa depan, dari jam 8 pagi
        if (Carbon::parse($tanggalAntrian)->isToday()) {
            return now()->addMinutes($totalMinutes);
        } else {
            // Untuk tanggal masa depan, mulai dari jam 8 pagi
            return Carbon::parse($tanggalAntrian)->setTime(8, 0)->addMinutes($totalMinutes);
        }
    }

    /**
     * ✅ EXISTING: Get global delay untuk tanggal tertentu (non-session)
     */
    private function getGlobalDelayForDate($tanggalAntrian)
    {
        $maxDelay = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->whereNull('doctor_id') // ✅ HANYA non-session queues
            ->max('extra_delay_minutes');

        return $maxDelay ?: 0;
    }

    /**
     * ✅ UPDATED: Update estimasi setelah antrian baru untuk tanggal spesifik
     */
    private function updateEstimationsAfterNewQueue($serviceId, $excludeQueueId, $tanggalAntrian)
    {
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('status', 'waiting')
            ->where('id', '!=', $excludeQueueId)
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->whereNull('doctor_id') // ✅ HANYA non-session queues
            ->orderBy('id', 'asc')
            ->get();

        $globalDelay = $this->getGlobalDelayForDate($tanggalAntrian);
        
        foreach ($waitingQueues as $queue) {
            // HITUNG posisi berdasarkan tanggal antrian (non-session)
            $queuePosition = Queue::where('service_id', $serviceId)
                ->where('status', 'waiting')
                ->where('id', '<', $queue->id)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->whereNull('doctor_id')
                ->count() + 1;
            
            $baseMinutes = $queuePosition * 15;
            $totalMinutes = $baseMinutes + $globalDelay;
            
            // ESTIMASI berdasarkan tanggal
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
     * ✅ UPDATED: Call next queue dengan session support
     */
    public function callNextQueue($counterId)
    {
        $counter = Counter::findOrFail($counterId);

        // ✅ Priority: Cari session-based queue dulu, lalu non-session
        $nextQueue = Queue::where('status', 'waiting')
            ->where('service_id', $counter->service_id)
            ->where(function ($query) use ($counterId) {
                $query->whereNull('counter_id')->orWhere('counter_id', $counterId);
            })
            ->whereDate('tanggal_antrian', today())
            ->orderByRaw('doctor_id IS NULL ASC') // ✅ Session queues first
            ->orderBy('id')
            ->first();

        if ($nextQueue && !$nextQueue->counter_id) {
            $nextQueue->update([
                'counter_id' => $counterId,
                'called_at' => now(),
                'status' => 'serving'
            ]);

            // UPDATE ESTIMASI untuk antrian yang tersisa hari ini
            if ($nextQueue->doctor_id) {
                $this->updateSessionEstimations($counter->service_id, today(), $nextQueue->doctor_id);
            } else {
                $this->updateEstimationsAfterQueueCalled($counter->service_id, today());
            }
        }

        return $nextQueue;
    }

    /**
     * ✅ EXISTING: Update estimasi setelah ada antrian yang dipanggil (non-session)
     */
    private function updateEstimationsAfterQueueCalled($serviceId, $tanggalAntrian)
    {
        $waitingQueues = Queue::where('service_id', $serviceId)
            ->where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->whereNull('doctor_id') // ✅ HANYA non-session queues
            ->orderBy('id', 'asc')
            ->get();

        $globalDelay = $this->getGlobalDelayForDate($tanggalAntrian);
        
        foreach ($waitingQueues as $queue) {
            $queuePosition = Queue::where('service_id', $serviceId)
                ->where('status', 'waiting')
                ->where('id', '<', $queue->id)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->whereNull('doctor_id')
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
     * ✅ UPDATED: Update overdue queues dengan session support
     */
    public function updateOverdueQueuesForDate($tanggalAntrian = null)
    {
        $tanggalAntrian = $tanggalAntrian ?? today();
        
        // Update session-based queues (ada doctor_id)
        $sessionsWithOverdue = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->whereNotNull('doctor_id')
            ->where('estimated_call_time', '<', now())
            ->select('doctor_id')
            ->distinct()
            ->pluck('doctor_id');
        
        $updatedCount = 0;
        
        // Update per session dokter
        foreach ($sessionsWithOverdue as $doctorId) {
            $sessionQueues = Queue::where('status', 'waiting')
                ->where('doctor_id', $doctorId)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->get();
            
            foreach ($sessionQueues as $queue) {
                $newExtraDelay = $queue->extra_delay_minutes + 5;
                
                $doctor = DoctorSchedule::find($doctorId);
                if ($doctor) {
                    $queuePosition = Queue::where('doctor_id', $doctorId)
                        ->where('status', 'waiting')
                        ->where('id', '<', $queue->id)
                        ->whereDate('tanggal_antrian', $tanggalAntrian)
                        ->count() + 1;
                    
                    $baseMinutes = $queuePosition * 15;
                    $totalMinutes = $baseMinutes + $newExtraDelay;
                    
                    if (Carbon::parse($tanggalAntrian)->isToday()) {
                        $sessionStartDateTime = Carbon::parse($tanggalAntrian)->setTimeFromTimeString($doctor->start_time->format('H:i'));
                        $startTime = now()->max($sessionStartDateTime);
                        $newEstimation = $startTime->addMinutes($totalMinutes - $baseMinutes + 5);
                    } else {
                        $startTime = Carbon::parse($tanggalAntrian)->setTimeFromTimeString($doctor->start_time->format('H:i'));
                        $newEstimation = $startTime->addMinutes($totalMinutes);
                    }
                    
                    $queue->update([
                        'estimated_call_time' => $newEstimation,
                        'extra_delay_minutes' => $newExtraDelay
                    ]);
                    
                    $updatedCount++;
                }
            }
        }
        
        // Update non-session queues (fallback ke sistem lama)
        $nonSessionQueues = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->whereNull('doctor_id')
            ->where('estimated_call_time', '<', now())
            ->get();
        
        foreach ($nonSessionQueues as $queue) {
            $newExtraDelay = $queue->extra_delay_minutes + 5;
            $newEstimation = now()->addMinutes(5);
            
            $queue->update([
                'estimated_call_time' => $newEstimation,
                'extra_delay_minutes' => $newExtraDelay
            ]);
            
            $updatedCount++;
        }
        
        return $updatedCount;
    }

    // ✅ EXISTING METHODS tetap untuk backward compatibility

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

    /**
     * ✅ UPDATED: cancelQueue dengan quota decrement
     */
    public function cancelQueue(Queue $queue)
    {
        if (!in_array($queue->status, ['waiting', 'serving'])) {
            return;
        }

        DB::transaction(function () use ($queue) {
            $queue->update([
                'status' => 'canceled',
                'canceled_at' => now()
            ]);
            
            // ✅ NEW: Decrement quota jika ada doctor_id
            if ($queue->doctor_id && $queue->tanggal_antrian) {
                $quota = DailyQuota::where('doctor_schedule_id', $queue->doctor_id)
                    ->where('quota_date', $queue->tanggal_antrian)
                    ->first();
                
                if ($quota) {
                    $quota->decrementUsedQuota();
                }
            }
            
            // UPDATE estimasi queue lain setelah ada yang cancel
            if ($queue->status === 'waiting') {
                if ($queue->doctor_id) {
                    $this->updateSessionEstimations($queue->service_id, $queue->tanggal_antrian, $queue->doctor_id);
                } else {
                    $this->updateEstimationsAfterQueueCalled($queue->service_id, $queue->tanggal_antrian);
                }
            }
        });
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
     * ✅ UPDATED: Reset estimasi untuk tanggal tertentu dengan session support
     */
    public function resetEstimationsForDate($tanggalAntrian)
    {
        $updatedCount = 0;

        // Reset session-based queues per dokter
        $sessionGroups = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->whereNotNull('doctor_id')
            ->select('doctor_id')
            ->distinct()
            ->pluck('doctor_id');

        foreach ($sessionGroups as $doctorId) {
            $doctor = DoctorSchedule::find($doctorId);
            if (!$doctor) continue;

            $sessionQueues = Queue::where('status', 'waiting')
                ->where('doctor_id', $doctorId)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->orderBy('id', 'asc')
                ->get();

            foreach ($sessionQueues as $index => $queue) {
                $queuePosition = $index + 1;
                $baseMinutes = $queuePosition * 15;
                
                if (Carbon::parse($tanggalAntrian)->isToday()) {
                    $sessionStartDateTime = Carbon::parse($tanggalAntrian)->setTimeFromTimeString($doctor->start_time->format('H:i'));
                    $startTime = now()->max($sessionStartDateTime);
                    $estimatedTime = $startTime->addMinutes($baseMinutes);
                } else {
                    $estimatedTime = Carbon::parse($tanggalAntrian)->setTimeFromTimeString($doctor->start_time->format('H:i'))->addMinutes($baseMinutes);
                }
                
                $queue->update([
                    'estimated_call_time' => $estimatedTime,
                    'extra_delay_minutes' => 0
                ]);
                
                $updatedCount++;
            }
        }

        // Reset non-session queues
        $nonSessionQueues = Queue::where('status', 'waiting')
            ->whereDate('tanggal_antrian', $tanggalAntrian)
            ->whereNull('doctor_id')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($nonSessionQueues as $queue) {
            $queuePosition = Queue::where('service_id', $queue->service_id)
                ->where('status', 'waiting')
                ->where('id', '<', $queue->id)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->whereNull('doctor_id')
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

    // ✅ NEW QUOTA INTEGRATION METHODS

    /**
     * ✅ NEW: Check quota availability sebelum create queue
     */
    public function checkQuotaAvailability($doctorId, $tanggalAntrian): array
    {
        if (!$doctorId) {
            return [
                'available' => true,
                'quota' => null,
                'message' => 'Non-session queue - no quota limit'
            ];
        }
        
        $quota = DailyQuota::where('doctor_schedule_id', $doctorId)
            ->where('quota_date', $tanggalAntrian)
            ->where('is_active', true)
            ->first();
        
        if (!$quota) {
            // Auto-create quota with default 20
            $quota = DailyQuota::getOrCreateQuota($doctorId, $tanggalAntrian, 20);
            $quota->updateUsedQuota(); // Sync dengan antrian yang sudah ada
        }
        
        $available = !$quota->isQuotaFull();
        
        return [
            'available' => $available,
            'quota' => $quota,
            'message' => $available 
                ? "Kuota tersedia: {$quota->available_quota}/{$quota->total_quota}"
                : "Kuota sudah penuh: {$quota->used_quota}/{$quota->total_quota}"
        ];
    }

    /**
     * ✅ NEW: Get quota summary untuk tanggal tertentu
     */
    public function getQuotaSummaryForDate($date): array
    {
        return DailyQuota::getQuotaSummaryForDate($date);
    }

    /**
     * ✅ NEW: Get available doctors dengan quota info
     */
    public function getAvailableDoctorSessionsWithQuota($tanggalAntrian)
    {
        $sessions = $this->getAvailableDoctorSessions($tanggalAntrian);
        
        return $sessions->map(function ($session) use ($tanggalAntrian) {
            $quotaCheck = $this->checkQuotaAvailability($session['id'], $tanggalAntrian);
            
            $session['quota_info'] = [
                'available' => $quotaCheck['available'],
                'quota' => $quotaCheck['quota'] ? [
                    'total' => $quotaCheck['quota']->total_quota,
                    'used' => $quotaCheck['quota']->used_quota,
                    'remaining' => $quotaCheck['quota']->available_quota,
                    'percentage' => $quotaCheck['quota']->usage_percentage,
                    'status' => $quotaCheck['quota']->status_label,
                ] : null,
                'message' => $quotaCheck['message'],
            ];
            
            return $session;
        })->filter(function ($session) {
            // Filter hanya yang masih ada quota
            return $session['quota_info']['available'];
        });
    }

    /**
     * ✅ NEW: Bulk create quotas untuk semua dokter
     */
    public function createDailyQuotasForDate($date, $defaultQuota = 20): array
    {
        $tanggalCarbon = Carbon::parse($date);
        $dayOfWeek = strtolower($tanggalCarbon->format('l'));
        
        // Get all active doctors yang praktik di hari tersebut
        $doctors = DoctorSchedule::where('is_active', true)
            ->whereJsonContains('days', $dayOfWeek)
            ->get();
        
        $created = 0;
        $existing = 0;
        $results = [];
        
        foreach ($doctors as $doctor) {
            $quota = DailyQuota::where('doctor_schedule_id', $doctor->id)
                ->where('quota_date', $date)
                ->first();
            
            if (!$quota) {
                $quota = DailyQuota::create([
                    'doctor_schedule_id' => $doctor->id,
                    'quota_date' => $date,
                    'total_quota' => $defaultQuota,
                    'used_quota' => 0,
                    'is_active' => true,
                ]);
                
                // Sync dengan antrian yang sudah ada
                $quota->updateUsedQuota();
                $created++;
                
                $results[] = [
                    'doctor' => $doctor->doctor_name,
                    'service' => $doctor->service->name ?? 'Unknown',
                    'action' => 'created',
                    'quota' => $quota->formatted_quota,
                ];
            } else {
                $existing++;
                $results[] = [
                    'doctor' => $doctor->doctor_name,
                    'service' => $doctor->service->name ?? 'Unknown',
                    'action' => 'existing',
                    'quota' => $quota->formatted_quota,
                ];
            }
        }
        
        return [
            'created' => $created,
            'existing' => $existing,
            'total_doctors' => $doctors->count(),
            'results' => $results,
        ];
    }

    /**
     * ✅ NEW: Sync all quotas untuk tanggal tertentu
     */
    public function syncQuotasForDate($date): array
    {
        $quotas = DailyQuota::where('quota_date', $date)->get();
        $synced = 0;
        $results = [];
        
        foreach ($quotas as $quota) {
            $oldUsed = $quota->used_quota;
            $quota->updateUsedQuota();
            $newUsed = $quota->fresh()->used_quota;
            
            if ($oldUsed !== $newUsed) {
                $synced++;
            }
            
            $results[] = [
                'doctor' => $quota->doctorSchedule->doctor_name ?? 'Unknown',
                'old_used' => $oldUsed,
                'new_used' => $newUsed,
                'total' => $quota->total_quota,
                'changed' => $oldUsed !== $newUsed,
            ];
        }
        
        return [
            'synced' => $synced,
            'total_quotas' => $quotas->count(),
            'results' => $results,
        ];
    }

    /**
     * ✅ NEW: Get quota alerts (nearly full, full, etc.)
     */
    public function getQuotaAlerts($date = null): array
    {
        $date = $date ?? today();
        
        $quotas = DailyQuota::with('doctorSchedule.service')
            ->where('quota_date', $date)
            ->where('is_active', true)
            ->get();
        
        $alerts = [
            'full' => $quotas->filter->isQuotaFull(),
            'nearly_full' => $quotas->filter->isQuotaNearlyFull(),
            'available' => $quotas->filter(fn($q) => $q->available_quota > 0 && !$q->isQuotaNearlyFull()),
        ];
        
        return [
            'date' => Carbon::parse($date)->format('d F Y'),
            'total_quotas' => $quotas->count(),
            'full_count' => $alerts['full']->count(),
            'nearly_full_count' => $alerts['nearly_full']->count(),
            'available_count' => $alerts['available']->count(),
            'alerts' => $alerts,
            'summary' => DailyQuota::getQuotaSummaryForDate($date),
        ];
    }
}