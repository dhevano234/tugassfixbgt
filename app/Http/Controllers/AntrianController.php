<?php
// File: app/Http/Controllers/AntrianController.php - FIXED ERRORS

namespace App\Http\Controllers;

use App\Models\Queue;
use App\Models\Service; 
use App\Models\User;
use App\Models\DoctorSchedule;
use App\Services\QueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AntrianController extends Controller
{
    protected QueueService $queueService;

    public function __construct(QueueService $queueService)
    {
        $this->queueService = $queueService;
    }

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
        
        // ✅ PERBAIKAN: Cek antrian aktif berdasarkan tanggal_antrian hari ini
        $existingQueue = Queue::where('user_id', $user->id)
                             ->whereIn('status', ['waiting', 'serving'])
                             ->whereDate('tanggal_antrian', today())
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
     * ✅ FIXED: Store dengan QueueService yang mendukung tanggal_antrian
     */
    public function store(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'doctor_id' => 'nullable|exists:doctor_schedules,id',
            'tanggal' => 'required|date|after_or_equal:today',
            'chief_complaint' => 'nullable|string|max:1000',
        ], [
            'service_id.required' => 'Layanan harus dipilih',
            'doctor_id.exists' => 'Dokter yang dipilih tidak valid',
            'tanggal.required' => 'Tanggal antrian harus dipilih',
            'tanggal.date' => 'Format tanggal tidak valid',
            'tanggal.after_or_equal' => 'Tanggal antrian tidak boleh lebih awal dari hari ini',
            'chief_complaint.max' => 'Keluhan maksimal 1000 karakter',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $tanggalAntrian = Carbon::parse($request->tanggal)->format('Y-m-d');

            // ✅ PERBAIKAN: Cek berdasarkan tanggal_antrian
            $existingQueue = Queue::where('user_id', $user->id)
                                 ->whereIn('status', ['waiting', 'serving'])
                                 ->whereDate('tanggal_antrian', $tanggalAntrian)
                                 ->first();

            if ($existingQueue) {
                DB::rollBack();
                return back()->withErrors([
                    'error' => 'Anda sudah memiliki antrian aktif pada tanggal ' . Carbon::parse($tanggalAntrian)->format('d F Y')
                ])->withInput();
            }

            // ✅ FIXED: Gunakan method yang ada di QueueService
            $queue = $this->queueService->addQueue(
                $request->service_id, 
                $user->id, 
                null, // ktpData
                $tanggalAntrian // tanggal_antrian
            );

            // ✅ UPDATE: Set data tambahan setelah queue dibuat
            $queue->update([
                'doctor_id' => $request->doctor_id,
                'chief_complaint' => $request->chief_complaint,
            ]);

            DB::commit();

            // ✅ INFO: Nomor antrian sudah benar per tanggal
            $message = 'Antrian berhasil dibuat untuk tanggal ' . Carbon::parse($tanggalAntrian)->format('d F Y') . '! Nomor antrian Anda: ' . $queue->number;
            
            // ✅ TAMBAH INFO: Tampilkan info nomor antrian per tanggal
            if (Carbon::parse($tanggalAntrian)->isToday()) {
                $message .= ' (hari ini)';
            } else {
                $message .= ' (untuk tanggal ' . Carbon::parse($tanggalAntrian)->format('d F Y') . ')';
            }
            
            if (!empty($request->chief_complaint)) {
                $shortComplaint = strlen($request->chief_complaint) > 50 
                    ? substr($request->chief_complaint, 0, 50) . '...'
                    : $request->chief_complaint;
                $message .= ' | Keluhan: ' . $shortComplaint;
            }
            
            if ($request->doctor_id) {
                $doctorSchedule = DoctorSchedule::find($request->doctor_id);
                if ($doctorSchedule) {
                    $message .= ' | Dokter: ' . $doctorSchedule->doctor_name;
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
            'chief_complaint' => 'nullable|string|max:1000',
        ], [
            'chief_complaint.max' => 'Keluhan maksimal 1000 karakter',
        ]);

        try {
            DB::beginTransaction();

            $updateData = [
                'service_id' => $request->service_id,
                'doctor_id' => $request->doctor_id,
                'chief_complaint' => $request->chief_complaint,
            ];
            
            // ✅ FIXED: Generate nomor baru berdasarkan tanggal_antrian jika ganti service
            if ($queue->service_id != $request->service_id) {
                $tanggalAntrian = $queue->tanggal_antrian;
                $updateData['number'] = $this->queueService->generateNumberForDate($request->service_id, $tanggalAntrian);
            }

            $queue->update($updateData);
            DB::commit();

            $message = 'Antrian berhasil diubah! Nomor: ' . $queue->number;
            
            if ($request->filled('chief_complaint')) {
                $shortComplaint = strlen($request->chief_complaint) > 50 
                    ? substr($request->chief_complaint, 0, 50) . '...'
                    : $request->chief_complaint;
                $message .= ' | Keluhan: ' . $shortComplaint;
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
     * ✅ FIXED: API untuk preview nomor antrian sebelum submit
     */
    public function previewQueueNumber(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'tanggal' => 'required|date|after_or_equal:today',
        ]);

        try {
            $serviceId = $request->service_id;
            $tanggalAntrian = Carbon::parse($request->tanggal)->format('Y-m-d');
            
            // Preview nomor antrian yang akan didapat
            $previewNumber = $this->queueService->generateNumberForDate($serviceId, $tanggalAntrian);
            
            // Hitung posisi dalam antrian
            $currentQueues = Queue::where('service_id', $serviceId)
                ->where('status', 'waiting')
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->count();
            
            $position = $currentQueues + 1;
            $service = Service::find($serviceId);
            
            return response()->json([
                'success' => true,
                'preview_number' => $previewNumber,
                'position' => $position,
                'service_name' => $service->name,
                'date' => Carbon::parse($tanggalAntrian)->format('d F Y'),
                'estimated_time' => $position * 15, // menit
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ FIXED: API untuk cek available slots per tanggal
     */
    public function checkAvailableSlots(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'tanggal' => 'required|date|after_or_equal:today',
        ]);

        try {
            $serviceId = $request->service_id;
            $tanggalAntrian = Carbon::parse($request->tanggal)->format('Y-m-d');
            
            // Hitung antrian yang sudah ada
            $existingQueues = Queue::where('service_id', $serviceId)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->count();
            
            $service = Service::find($serviceId);
            $maxSlots = pow(10, $service->padding) - 1; // Maksimal slot berdasarkan padding
            $availableSlots = max(0, $maxSlots - $existingQueues);
            
            return response()->json([
                'success' => true,
                'existing_queues' => $existingQueues,
                'available_slots' => $availableSlots,
                'max_slots' => $maxSlots,
                'service_name' => $service->name,
                'date' => Carbon::parse($tanggalAntrian)->format('d F Y'),
                'is_full' => $availableSlots <= 0,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}