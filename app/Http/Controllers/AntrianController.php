<?php

namespace App\Http\Controllers;

use App\Models\Queue;
use App\Models\Service; 
use App\Models\User;
use App\Models\DoctorSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AntrianController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $antrianTerbaru = Queue::with(['service', 'counter', 'user'])
                              ->where('user_id', $user->id)
                              ->whereNotIn('status', ['canceled']) 
                              ->latest('created_at') 
                              ->first();

        return view('antrian.index', compact('antrianTerbaru'));
    }

    public function create()
    {
        $user = Auth::user();
        
        // ✅ UBAH: Cek berdasarkan tanggal_antrian hari ini
        $existingQueue = Queue::where('user_id', $user->id)
                             ->whereIn('status', ['waiting', 'serving'])
                             ->whereDate('tanggal_antrian', today()) // ✅ UBAH INI
                             ->first();

        if ($existingQueue) {
            return redirect()->route('antrian.index')->withErrors([
                'error' => 'Anda masih memiliki antrian aktif. Harap selesaikan antrian tersebut terlebih dahulu.'
            ]);
        }

        $services = Service::where('is_active', true)->get();
        
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
     * ✅ PERBAIKAN: Store dengan pisahkan tanggal antrian dan tanggal ambil + KELUHAN
     */
    public function store(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'doctor_id' => 'nullable|exists:doctor_schedules,id',
            'tanggal' => 'required|date',
            'chief_complaint' => 'nullable|string|max:1000', // ✅ TAMBAH VALIDASI KELUHAN
        ], [
            'service_id.required' => 'Layanan harus dipilih',
            'doctor_id.exists' => 'Dokter yang dipilih tidak valid',
            'tanggal.required' => 'Tanggal antrian harus dipilih',
            'tanggal.date' => 'Format tanggal tidak valid',
            'chief_complaint.max' => 'Keluhan maksimal 1000 karakter', // ✅ TAMBAH INI
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            
            // ✅ PERBAIKAN: Pisahkan tanggal antrian dan tanggal ambil
            $tanggalAntrian = $request->tanggal; // Yang dipilih di date picker (misal: 2025-07-01)
            $tanggalAmbil = now(); // Kapan user mengambil nomor (sekarang: 2025-06-30 13:10)

            // ✅ UBAH: Cek berdasarkan tanggal_antrian, bukan created_at
            $existingQueue = Queue::where('user_id', $user->id)
                                 ->whereIn('status', ['waiting', 'serving'])
                                 ->whereDate('tanggal_antrian', $tanggalAntrian) // ✅ UBAH INI
                                 ->first();

            if ($existingQueue) {
                DB::rollBack();
                return back()->withErrors([
                    'error' => 'Anda sudah memiliki antrian aktif pada tanggal ' . Carbon::parse($tanggalAntrian)->format('d F Y')
                ])->withInput();
            }

            // ✅ UBAH: Generate nomor berdasarkan tanggal_antrian
            $queueNumber = $this->generateQueueNumber($request->service_id, $tanggalAntrian);

            // ✅ UBAH: Buat antrian dengan tanggal_antrian + KELUHAN
            $queue = Queue::create([
                'service_id' => $request->service_id,
                'user_id' => $user->id,
                'doctor_id' => $request->doctor_id,
                'number' => $queueNumber,
                'status' => 'waiting',
                'tanggal_antrian' => $tanggalAntrian,  // ✅ TAMBAH INI - Tanggal yang dipilih
                'chief_complaint' => $request->chief_complaint, // ✅ TAMBAH INI - Keluhan dari form
                'created_at' => $tanggalAmbil,         // ✅ UBAH INI - Kapan ambil nomor
                'updated_at' => $tanggalAmbil,
            ]);

            DB::commit();

            $message = 'Antrian berhasil dibuat untuk tanggal ' . Carbon::parse($tanggalAntrian)->format('d F Y') . '! Nomor antrian Anda: ' . $queueNumber;
            
            // ✅ TAMBAH INFO KELUHAN JIKA ADA
            if (!empty($request->chief_complaint)) {
                $shortComplaint = strlen($request->chief_complaint) > 50 
                    ? substr($request->chief_complaint, 0, 50) . '...'
                    : $request->chief_complaint;
                $message .= ' (Keluhan: ' . $shortComplaint . ')';
            }
            
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

    public function show($id)
    {
        $queue = Queue::with(['service', 'counter', 'user'])->findOrFail($id);
        
        if ($queue->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        return view('antrian.show', compact('queue'));
    }

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

        $request->validate([
            'service_id' => 'required|exists:services,id',
            'doctor_id' => 'nullable|exists:doctor_schedules,id',
            'chief_complaint' => 'nullable|string|max:1000', // ✅ TAMBAH VALIDASI KELUHAN
        ], [
            'chief_complaint.max' => 'Keluhan maksimal 1000 karakter', // ✅ TAMBAH INI
        ]);

        try {
            DB::beginTransaction();

            $updateData = [
                'service_id' => $request->service_id,
                'doctor_id' => $request->doctor_id,
                'chief_complaint' => $request->chief_complaint, // ✅ TAMBAH INI - Update keluhan
            ];
            
            // ✅ UBAH: Generate nomor baru berdasarkan tanggal_antrian
            if ($queue->service_id != $request->service_id) {
                $tanggalAntrian = $queue->tanggal_antrian; // ✅ UBAH INI
                $updateData['number'] = $this->generateQueueNumber($request->service_id, $tanggalAntrian);
            }

            $queue->update($updateData);
            DB::commit();

            $message = 'Antrian berhasil diubah!';
            
            // ✅ TAMBAH INFO KELUHAN JIKA DIUPDATE
            if ($request->filled('chief_complaint')) {
                $shortComplaint = strlen($request->chief_complaint) > 50 
                    ? substr($request->chief_complaint, 0, 50) . '...'
                    : $request->chief_complaint;
                $message .= ' (Keluhan: ' . $shortComplaint . ')';
            }

            return redirect()->route('antrian.index')->with('success', $message);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Terjadi kesalahan saat mengubah antrian.'])->withInput();
        }
    }

    public function destroy($id)
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

    public function print($id)
    {
        $queue = Queue::with(['service', 'counter', 'user'])->findOrFail($id);
        
        if ($queue->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        return view('antrian.print', ['antrian' => $queue]);
    }

    /**
     * ✅ UBAH: Generate nomor berdasarkan tanggal_antrian
     */
    private function generateQueueNumber($serviceId, $tanggalAntrian)
    {
        $service = Service::findOrFail($serviceId);
        
        // Pastikan format tanggal konsisten
        if ($tanggalAntrian instanceof Carbon) {
            $dateString = $tanggalAntrian->format('Y-m-d');
        } else {
            $dateString = $tanggalAntrian; // Sudah string Y-m-d
        }
        
        // ✅ UBAH: Query berdasarkan tanggal_antrian, bukan created_at
        $lastQueue = Queue::where('service_id', $serviceId)
                         ->whereDate('tanggal_antrian', $dateString) // ✅ UBAH INI
                         ->orderBy('id', 'desc')
                         ->first();
        
        $sequence = $lastQueue ? 
                   (int) substr($lastQueue->number, strlen($service->prefix)) + 1 : 1;
        
        return $service->prefix . sprintf('%0' . $service->padding . 'd', $sequence);
    }
}