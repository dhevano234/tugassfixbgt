<?php
// File: app/Filament/Dokter/Resources/MedicalRecordResource/Pages/CreateMedicalRecord.php - SIMPLIFIED

namespace App\Filament\Dokter\Resources\MedicalRecordResource\Pages;

use App\Filament\Dokter\Resources\MedicalRecordResource;
use App\Models\User;
use App\Models\Queue;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class CreateMedicalRecord extends CreateRecord
{
    protected static string $resource = MedicalRecordResource::class;

    protected static ?string $title = 'Buat Rekam Medis';

    // Override method untuk menghilangkan tombol "Create & Create Another"
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Auto-set doctor_id
        $data['doctor_id'] = Auth::id();
        
        // ✅ VALIDASI: Pastikan user_id adalah role 'user'
        if (isset($data['user_id'])) {
            $user = User::find($data['user_id']);
            if (!$user || $user->role !== 'user') {
                throw new \Exception('Hanya pasien dengan role user yang dapat dibuatkan rekam medis.');
            }
        }
        
        // ✅ REMOVE display_medical_record_number dari data yang disimpan
        unset($data['display_medical_record_number']);
        
        return $data;
    }

    // Mount function untuk handle parameter dari queue
    public function mount(): void
    {
        parent::mount();
        
        // Check untuk parameter dari queue
        $userId = request()->get('user_id');
        $queueNumber = request()->get('queue_number');
        $serviceName = request()->get('service');
        
        if ($userId) {
            $user = User::find($userId);
            
            // ✅ VALIDASI: Pastikan user ada dan role-nya 'user'
            if ($user && $user->role === 'user') {
                // ✅ CARI KELUHAN DARI ANTRIAN TERBARU
                $queueWithComplaint = Queue::where('user_id', $userId)
                    ->whereNotNull('chief_complaint')
                    ->where('chief_complaint', '!=', '')
                    ->latest('created_at')
                    ->first();
                
                // Auto-populate user field, nomor RM, dan keluhan
                $formData = [
                    'user_id' => $userId,
                    'display_medical_record_number' => $user->medical_record_number ?? 'Belum ada nomor rekam medis',
                ];
                
                // ✅ AUTO-FILL KELUHAN JIKA ADA
                if ($queueWithComplaint && $queueWithComplaint->chief_complaint) {
                    $formData['chief_complaint'] = $queueWithComplaint->chief_complaint;
                }
                
                $this->form->fill($formData);
                
                // ✅ ENHANCED NOTIFICATION dengan info keluhan
                $notificationBody = "Auto-selected: {$user->name}";
                
                if ($user->medical_record_number) {
                    $notificationBody .= " | No. RM: {$user->medical_record_number}";
                } else {
                    $notificationBody .= " | Belum ada No. RM";
                }
                
                if ($queueNumber) {
                    $notificationBody .= " (Antrian: {$queueNumber})";
                }
                
                // ✅ TAMBAH INFO KELUHAN
                if ($queueWithComplaint && $queueWithComplaint->chief_complaint) {
                    $complainLimit = 60;
                    $shortComplaint = strlen($queueWithComplaint->chief_complaint) > $complainLimit 
                        ? substr($queueWithComplaint->chief_complaint, 0, $complainLimit) . '...'
                        : $queueWithComplaint->chief_complaint;
                    $notificationBody .= "\n📝 Keluhan dari antrian: \"{$shortComplaint}\"";
                } else {
                    $notificationBody .= "\n💬 Pasien tidak mengisi keluhan saat ambil antrian";
                }
                
                Notification::make()
                    ->title('Pasien Dari Antrian')
                    ->body($notificationBody)
                    ->success()
                    ->duration(8000) // Lebih lama karena ada info keluhan
                    ->send();
                    
                // Update page title jika ada queue number
                if ($queueNumber) {
                    static::$title = "Rekam Medis - Antrian {$queueNumber}";
                }
                
            } elseif ($user && $user->role !== 'user') {
                // ✅ WARNING: Jika user bukan role 'user'
                Notification::make()
                    ->title('Peringatan')
                    ->body("User {$user->name} bukan pasien (role: {$user->role}). Silakan pilih pasien yang valid.")
                    ->warning()
                    ->duration(8000)
                    ->send();
            } else {
                // ✅ ERROR: Jika user tidak ditemukan
                Notification::make()
                    ->title('Error')
                    ->body("User dengan ID {$userId} tidak ditemukan.")
                    ->danger()
                    ->duration(5000)
                    ->send();
            }
        } elseif ($queueNumber) {
            // ✅ HANDLE BERDASARKAN QUEUE NUMBER SAJA
            $queue = Queue::where('number', $queueNumber)
                ->whereDate('created_at', today())
                ->with('user')
                ->first();
                
            if ($queue && $queue->user) {
                $user = $queue->user;
                
                $formData = [
                    'user_id' => $user->id,
                    'display_medical_record_number' => $user->medical_record_number ?? 'Belum ada nomor rekam medis',
                ];
                
                // ✅ AUTO-FILL KELUHAN dari queue ini
                if ($queue->chief_complaint) {
                    $formData['chief_complaint'] = $queue->chief_complaint;
                }
                
                $this->form->fill($formData);
                
                $notificationBody = "Antrian {$queueNumber}: {$user->name}";
                if ($queue->chief_complaint) {
                    $shortComplaint = strlen($queue->chief_complaint) > 60 
                        ? substr($queue->chief_complaint, 0, 60) . '...'
                        : $queue->chief_complaint;
                    $notificationBody .= "\n📝 Keluhan: \"{$shortComplaint}\"";
                } else {
                    $notificationBody .= "\n💬 Tidak ada keluhan dari antrian";
                }
                
                Notification::make()
                    ->title('Data dari Antrian')
                    ->body($notificationBody)
                    ->success()
                    ->duration(8000)
                    ->send();
            }
        }
    }
    
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Rekam medis berhasil dibuat';
    }
    
    // Auto-finish queue setelah rekam medis dibuat
    protected function afterCreate(): void
    {
        // Optional: Auto-finish queue jika ada parameter queue
        $queueNumber = request()->get('queue_number');
        
        if ($queueNumber) {
            // Find and finish the queue
            $queue = Queue::where('number', $queueNumber)
                ->whereDate('created_at', today())
                ->first();
                
            if ($queue && in_array($queue->status, ['waiting', 'serving'])) {
                $queue->update([
                    'status' => 'finished',
                    'finished_at' => now(),
                ]);
                
                Notification::make()
                    ->title('Antrian Selesai')
                    ->body("Antrian {$queueNumber} otomatis ditandai selesai")
                    ->success()
                    ->send();
            }
        }
    }
}