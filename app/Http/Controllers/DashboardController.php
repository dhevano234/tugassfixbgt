<?php
// File: app/Http/Controllers/DashboardController.php - FIXED ESTIMATION + QuotaInfo

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Queue;
use App\Models\User;
use App\Models\Service;
use App\Models\DoctorSchedule;
use App\Models\DailyQuota; // ✅ TAMBAH: Import DailyQuota
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = Auth::id();
        $today = Carbon::today();
        
        // ✅ PERBAIKAN: Antrian aktif user berdasarkan tanggal_antrian hari ini
        $antrianAktif = Queue::where('user_id', $userId)
            ->whereIn('status', ['waiting', 'serving'])
            ->whereDate('tanggal_antrian', $today) // ✅ FIXED: Gunakan tanggal_antrian
            ->with(['service', 'counter'])
            ->first();

        // ✅ STATISTIK REAL untuk cards
        $stats = [
            'antrian_hari_ini' => Queue::whereDate('tanggal_antrian', $today)->count(), // ✅ FIXED
            // ✅ HAPUS: total_pasien dan dokter_aktif (diganti dengan quota)
        ];

        // ✅ NEW: Tambah quota info untuk menggantikan total pasien & dokter aktif
        $quotaInfo = $this->getTodayQuotaInfo();

        // ✅ STATUS ANTRIAN REAL untuk chart - berdasarkan tanggal_antrian
        $statusAntrian = [
            'menunggu' => Queue::where('status', 'waiting')->whereDate('tanggal_antrian', $today)->count(),
            'dipanggil' => Queue::where('status', 'serving')->whereDate('tanggal_antrian', $today)->count(),
            'selesai' => Queue::where('status', 'finished')->whereDate('tanggal_antrian', $today)->count(),
            'dibatalkan' => Queue::where('status', 'canceled')->whereDate('tanggal_antrian', $today)->count(),
        ];

        // ✅ ESTIMASI WAKTU TUNGGU untuk antrian aktif user - FIXED
        $estimasiInfo = null;
        if ($antrianAktif && $antrianAktif->status === 'waiting') {
            $estimasiInfo = $this->calculateWaitingTime($antrianAktif);
        }

        return view('frontend.dashboard', compact(
            'antrianAktif', 
            'stats', 
            'quotaInfo', // ✅ TAMBAH: quotaInfo ke compact
            'statusAntrian', 
            'estimasiInfo'
        ));
    }

    /**
     * ✅ NEW: Get quota information untuk hari ini
     */
    private function getTodayQuotaInfo()
    {
        $today = today();
        
        // Get all active quotas untuk hari ini
        $quotas = DailyQuota::with(['doctorSchedule.service'])
            ->where('quota_date', $today)
            ->where('is_active', true)
            ->orderBy('used_quota', 'desc') // Urutkan berdasarkan yang paling banyak terpakai
            ->get();

        if ($quotas->isEmpty()) {
            return [
                'has_quotas' => false,
                'total_doctors' => 0,
                'quotas' => collect([])
            ];
        }

        return [
            'has_quotas' => true,
            'total_doctors' => $quotas->count(),
            'quotas' => $quotas->map(function ($quota) {
                return [
                    'doctor_name' => $quota->doctorSchedule->doctor_name ?? 'Unknown',
                    'service_name' => $quota->doctorSchedule->service->name ?? 'Unknown',
                    'total_quota' => $quota->total_quota,
                    'used_quota' => $quota->used_quota,
                    'available_quota' => $quota->available_quota,
                    'usage_percentage' => $quota->usage_percentage,
                    'status_color' => $quota->status_color,
                    'status_label' => $quota->status_label,
                    'formatted_quota' => $quota->formatted_quota,
                    'time_range' => $quota->doctorSchedule->time_range ?? 'Unknown',
                ];
            })
        ];
    }

    /**
     * ✅ FIXED: HITUNG ESTIMASI WAKTU TUNGGU berdasarkan tanggal antrian
     */
    private function calculateWaitingTime($queue)
    {
        // ✅ PERBAIKAN: Hitung berapa antrian di depan berdasarkan tanggal_antrian
        $antrianDidepan = Queue::where('service_id', $queue->service_id)
            ->where('status', 'waiting')
            ->where('id', '<', $queue->id)
            ->whereDate('tanggal_antrian', $queue->tanggal_antrian ?? today()) // ✅ FIXED
            ->count();

        // Setiap antrian butuh 15 menit + extra delay
        $baseMinutes = ($antrianDidepan + 1) * 15;
        $extraDelay = $queue->extra_delay_minutes ?: 0;
        $totalEstimatedMinutes = $baseMinutes + $extraDelay;

        // ✅ PERBAIKAN: Waktu estimasi berdasarkan estimated_call_time jika ada
        if ($queue->estimated_call_time) {
            $waktuEstimasi = $queue->estimated_call_time;
        } else {
            // Fallback: hitung dari created_at + estimasi
            $waktuEstimasi = $queue->created_at->addMinutes($totalEstimatedMinutes);
        }

        $sekarang = now();

        // Cek apakah sudah lewat estimasi
        if ($waktuEstimasi < $sekarang) {
            // Sudah lewat, gunakan extra delay atau default 5 menit
            $estimasiMenit = $extraDelay ?: 5;
            $status = 'delayed';
        } else {
            // Masih dalam estimasi
            $diffMinutes = $sekarang->diffInMinutes($waktuEstimasi);
            $estimasiMenit = $diffMinutes;
            $status = 'on_time';
        }

        return [
            'posisi' => $antrianDidepan + 1,
            'estimasi_menit' => (int) round($estimasiMenit),
            'waktu_estimasi' => $waktuEstimasi->format('H:i'),
            'status' => $status,
            'antrian_didepan' => $antrianDidepan,
            'extra_delay' => $extraDelay,
            'total_estimated_minutes' => $totalEstimatedMinutes
        ];
    }

    /**
     * ✅ FIXED: API ENDPOINT untuk update estimasi dengan tanggal antrian
     */
    public function realtimeEstimation(Request $request)
    {
        $userId = Auth::id();
        
        // ✅ PERBAIKAN: Cek antrian aktif berdasarkan tanggal_antrian hari ini
        $antrianAktif = Queue::where('user_id', $userId)
            ->whereIn('status', ['waiting', 'serving'])
            ->whereDate('tanggal_antrian', today()) // ✅ FIXED
            ->with(['service'])
            ->first();

        if (!$antrianAktif) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada antrian aktif'
            ]);
        }

        if ($antrianAktif->status === 'serving') {
            return response()->json([
                'success' => true,
                'status' => 'serving',
                'message' => 'Sedang dilayani'
            ]);
        }

        $estimasiInfo = $this->calculateWaitingTime($antrianAktif);

        return response()->json([
            'success' => true,
            'status' => 'waiting',
            'data' => $estimasiInfo,
            'queue_number' => $antrianAktif->number,
            'service_name' => $antrianAktif->service->name ?? 'Unknown',
            'queue_date' => $antrianAktif->tanggal_antrian ? $antrianAktif->tanggal_antrian->format('d F Y') : 'Hari ini'
        ]);
    }
}