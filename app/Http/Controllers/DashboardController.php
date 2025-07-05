<?php
// File: app/Http/Controllers/DashboardController.php - FIXED ESTIMATION

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Queue;
use App\Models\User;
use App\Models\Service;
use App\Models\DoctorSchedule;
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
            'total_pasien' => User::where('role', 'user')->count(),
            'dokter_aktif' => DoctorSchedule::distinct('doctor_name')
                                           ->where('is_active', true)
                                           ->count('doctor_name'),
        ];

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
            'statusAntrian', 
            'estimasiInfo'
        ));
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
    public function getRealtimeEstimation(Request $request)
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