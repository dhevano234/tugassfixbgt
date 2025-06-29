<?php

namespace App\Http\Controllers;

use App\Models\Queue;
use App\Models\Service; 
use App\Models\User;
use App\Models\DoctorSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AntrianController extends Controller
{
    /**
     * Dashboard antrian user
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // ✅ SUDAH BENAR - tambah relationship user
        $antrianTerbaru = Queue::with(['service', 'counter', 'user'])
                              ->where('user_id', $user->id)
                              ->latest()
                              ->first();

        return view('antrian.index', compact('antrianTerbaru'));
    }

    /**
     * Form buat antrian baru
     */
    public function create()
    {
        $user = Auth::user();
        
        // ✅ SUDAH BENAR
        $existingQueue = Queue::where('user_id', $user->id)
                             ->whereIn('status', ['waiting', 'serving'])
                             ->whereDate('created_at', today())
                             ->first();

        if ($existingQueue) {
            return redirect()->route('antrian.index')->withErrors([
                'error' => 'Anda masih memiliki antrian aktif. Harap selesaikan antrian tersebut terlebih dahulu.'
            ]);
        }

        $services = Service::where('is_active', true)->get();
        
        // ✅ PERBAIKAN: Ambil doctor schedules dengan format yang benar
        $doctors = collect();
        try {
            $doctors = DoctorSchedule::with('service')
                        ->where('is_active', true)
                        ->get();
        } catch (\Exception $e) {
            $doctors = collect();
        }
        
        return view('antrian.ambil', compact('services', 'doctors'));
    }

    /**
     * ✅ PERBAIKAN UTAMA: Simpan antrian baru dengan doctor_id
     */
    public function store(Request $request)
    {
        // ✅ PERBAIKAN: Validasi termasuk doctor_id
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'doctor_id' => 'nullable|exists:doctor_schedules,id',  // ✅ PERBAIKAN: Validasi doctor_id
        ], [
            'service_id.required' => 'Layanan harus dipilih',
            'doctor_id.exists' => 'Dokter yang dipilih tidak valid',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();

            // ✅ SUDAH BENAR
            $existingQueue = Queue::where('user_id', $user->id)
                                 ->whereIn('status', ['waiting', 'serving'])
                                 ->whereDate('created_at', today())
                                 ->first();

            if ($existingQueue) {
                DB::rollBack();
                return back()->withErrors([
                    'error' => 'Anda sudah memiliki antrian aktif hari ini.'
                ])->withInput();
            }

            // ✅ SUDAH BENAR
            $queueNumber = $this->generateQueueNumber($request->service_id);

            // ✅ PERBAIKAN: Data antrian dengan doctor_id
            $queueData = [
                'service_id' => $request->service_id,
                'user_id' => $user->id,
                'doctor_id' => $request->doctor_id,  // ✅ PERBAIKAN: Simpan doctor_id
                'number' => $queueNumber,
                'status' => 'waiting',
            ];

            $queue = Queue::create($queueData);

            DB::commit();

            // ✅ PERBAIKAN: Message dengan info dokter jika dipilih
            $message = 'Antrian berhasil dibuat! Nomor antrian Anda: ' . $queueNumber;
            if ($request->doctor_id) {
                $doctorSchedule = DoctorSchedule::find($request->doctor_id);
                if ($doctorSchedule) {
                    $message .= ' dengan ' . $doctorSchedule->doctor_name;
                }
            }

            return redirect()->route('antrian.index')->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors([
                'error' => 'Terjadi kesalahan saat membuat antrian: ' . $e->getMessage()
            ])->withInput();
        }
    }

    /**
     * Lihat detail antrian
     */
    public function show($id)
    {
        $queue = Queue::with(['service', 'counter', 'user'])->findOrFail($id);
        
        if ($queue->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        return view('antrian.show', compact('queue'));
    }

    /**
     * Edit antrian - PERBAIKAN
     */
    public function edit($id)
    {
        $queue = Queue::with(['service', 'counter', 'user'])->findOrFail($id);
        
        if ($queue->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if (!in_array($queue->status, ['waiting'])) {
            return redirect()->route('antrian.index')
                           ->withErrors(['error' => 'Antrian tidak dapat diedit karena sudah dipanggil atau selesai.']);
        }

        $services = Service::where('is_active', true)->get();
        
        // ✅ PERBAIKAN: Ambil doctor schedules
        $doctors = collect();
        try {
            $doctors = DoctorSchedule::with('service')
                        ->where('is_active', true)
                        ->get();
        } catch (\Exception $e) {
            $doctors = collect();
        }
        
        return view('antrian.edit', compact('queue', 'services', 'doctors'));
    }

    /**
     * ✅ PERBAIKAN: Update antrian dengan doctor_id
     */
    public function update(Request $request, $id)
    {
        $queue = Queue::findOrFail($id);
        
        if ($queue->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if (!in_array($queue->status, ['waiting'])) {
            return redirect()->route('antrian.index')
                           ->withErrors(['error' => 'Antrian tidak dapat diubah karena sudah dipanggil atau selesai.']);
        }

        // ✅ PERBAIKAN: Validasi termasuk doctor_id
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'doctor_id' => 'nullable|exists:doctor_schedules,id',  // ✅ PERBAIKAN: Validasi doctor_id
        ]);

        try {
            DB::beginTransaction();

            $updateData = [
                'service_id' => $request->service_id,
                'doctor_id' => $request->doctor_id,  // ✅ PERBAIKAN: Update doctor_id juga
            ];
            
            // Generate nomor antrian baru jika service berubah
            if ($queue->service_id != $request->service_id) {
                $updateData['number'] = $this->generateQueueNumber($request->service_id);
            }

            $queue->update($updateData);

            DB::commit();

            return redirect()->route('antrian.index')->with('success', 'Antrian berhasil diubah!');
            
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Terjadi kesalahan saat mengubah antrian.'])->withInput();
        }
    }

    /**
     * Batalkan antrian - SESUAI ROUTE YANG ADA
     */
    public function destroy($id) // ✅ Route: DELETE /antrian/{queue}
    {
        $queue = Queue::findOrFail($id);
        
        if ($queue->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if (!in_array($queue->status, ['waiting'])) {
            return redirect()->route('antrian.index')
                           ->withErrors(['error' => 'Antrian tidak dapat dibatalkan karena sudah dipanggil atau selesai.']);
        }

        try {
            $queue->update([
                'status' => 'canceled',
                'canceled_at' => now()
            ]);
            
            return redirect()->route('antrian.index')->with('success', 'Antrian berhasil dibatalkan!');
            
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => 'Terjadi kesalahan saat membatalkan antrian.'
            ]);
        }
    }

    /**
     * Print ticket - PERBAIKAN
     */
    public function print($id) // ✅ Route: GET /antrian/{queue}/print
    {
        $queue = Queue::with(['service', 'counter', 'user'])->findOrFail($id);
        
        if ($queue->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        // ✅ PERBAIKAN: Gunakan nama variabel yang konsisten
        return view('antrian.print', ['antrian' => $queue]);
    }

    /**
     * Generate Queue Number
     */
    private function generateQueueNumber($serviceId)
    {
        $service = Service::findOrFail($serviceId);
        
        $lastQueue = Queue::where('service_id', $serviceId)
                         ->whereDate('created_at', today())
                         ->orderBy('id', 'desc')
                         ->first();
        
        $sequence = $lastQueue ? 
                   (int) substr($lastQueue->number, strlen($service->prefix)) + 1 : 1;
        
        return $service->prefix . sprintf('%0' . $service->padding . 'd', $sequence);
    }
}