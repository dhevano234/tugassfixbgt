<?php
// File: app/Http/Controllers/DashboardController.php - UPDATED: Remove quick actions, fix doctor count

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Queue;
use App\Models\User;
use App\Models\Service;
use App\Models\DoctorSchedule; // ✅ TAMBAH IMPORT INI
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        // ✅ GET REAL DATA untuk dashboard
        $userId = Auth::id();
        $today = Carbon::today();
        
        // ✅ Antrian aktif user hari ini
        $antrianAktif = Queue::where('user_id', $userId)
            ->whereIn('status', ['waiting', 'serving'])
            ->whereDate('created_at', $today)
            ->with(['service', 'counter'])
            ->first();

        // ✅ STATISTIK REAL untuk cards - PERBAIKAN DOKTER AKTIF
        $stats = [
            'antrian_hari_ini' => Queue::whereDate('created_at', $today)->count(),
            'total_pasien' => User::where('role', 'user')->count(),
            'dokter_aktif' => DoctorSchedule::distinct('doctor_name')
                                           ->where('is_active', true)
                                           ->count('doctor_name'), // ✅ UBAH: Hitung dari doctor_schedules
        ];

        // ✅ STATUS ANTRIAN REAL untuk chart
        $statusAntrian = [
            'menunggu' => Queue::where('status', 'waiting')->whereDate('created_at', $today)->count(),
            'dipanggil' => Queue::where('status', 'serving')->whereDate('created_at', $today)->count(),
            'selesai' => Queue::where('status', 'finished')->whereDate('created_at', $today)->count(),
            'dibatalkan' => Queue::where('status', 'canceled')->whereDate('created_at', $today)->count(),
        ];

        // ✅ ESTIMASI WAKTU TUNGGU untuk antrian aktif user
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
     * ✅ FIXED: HITUNG ESTIMASI WAKTU TUNGGU dengan format yang benar
     */
    private function calculateWaitingTime($queue)
    {
        // Hitung berapa antrian di depan (same service, status waiting, created sebelumnya)
        $antrianDidepan = Queue::where('service_id', $queue->service_id)
            ->where('status', 'waiting')
            ->where('id', '<', $queue->id) // ID lebih kecil = dibuat lebih dulu
            ->whereDate('created_at', today())
            ->count();

        // Setiap antrian butuh 15 menit
        $estimasiMenitRaw = ($antrianDidepan + 1) * 15;

        // Waktu estimasi = waktu buat antrian + estimasi menit
        $waktuEstimasi = $queue->created_at->addMinutes($estimasiMenitRaw);
        $sekarang = now();

        // Cek apakah sudah lewat estimasi
        if ($waktuEstimasi < $sekarang) {
            // Sudah lewat, tambah 5 menit
            $extraDelay = 5;
            $estimasiMenit = $extraDelay;
            $waktuEstimasi = $sekarang->addMinutes($extraDelay);
            $status = 'delayed';
        } else {
            // Masih dalam estimasi
            $diffMinutes = $sekarang->diffInMinutes($waktuEstimasi);
            $estimasiMenit = $diffMinutes;
            $status = 'on_time';
        }

        // ✅ PERBAIKAN: Format estimasi_menit jadi integer bersih
        return [
            'posisi' => $antrianDidepan + 1,
            'estimasi_menit' => (int) round($estimasiMenit), // ✅ CAST KE INTEGER dan ROUND
            'waktu_estimasi' => $waktuEstimasi->format('H:i'),
            'status' => $status,
            'antrian_didepan' => $antrianDidepan
        ];
    }

    /**
     * ✅ FIXED: API ENDPOINT untuk update estimasi dengan format yang benar
     */
    public function getRealtimeEstimation(Request $request)
    {
        $userId = Auth::id();
        
        $antrianAktif = Queue::where('user_id', $userId)
            ->whereIn('status', ['waiting', 'serving'])
            ->whereDate('created_at', today())
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
            'service_name' => $antrianAktif->service->name ?? 'Unknown'
        ]);
    }
}