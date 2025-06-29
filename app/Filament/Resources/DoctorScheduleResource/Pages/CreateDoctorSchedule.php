<?php
// File: app/Filament/Resources/DoctorScheduleResource/Pages/CreateDoctorSchedule.php
// UPDATED: Single record untuk multiple days

namespace App\Filament\Resources\DoctorScheduleResource\Pages;

use App\Filament\Resources\DoctorScheduleResource;
use App\Models\DoctorSchedule;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateDoctorSchedule extends CreateRecord
{
    protected static string $resource = DoctorScheduleResource::class;

    protected static ?string $title = 'Tambah Jadwal Dokter';

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Simpan Jadwal'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        // ✅ NEW LOGIC: Simpan sebagai satu record dengan multiple days
        
        $days = $data['days'] ?? [];
        $doctorName = $data['doctor_name'];
        $serviceId = $data['service_id'];
        $startTime = $data['start_time'];
        $endTime = $data['end_time'];

        // ✅ VALIDASI: Basic validation
        if (empty($days)) {
            Notification::make()
                ->title('❌ Error')
                ->body('Harap pilih minimal satu hari praktik')
                ->danger()
                ->send();
            return new DoctorSchedule();
        }

        if ($startTime >= $endTime) {
            Notification::make()
                ->title('❌ Error')
                ->body('Jam mulai harus lebih awal dari jam selesai')
                ->danger()
                ->send();
            return new DoctorSchedule();
        }

        // ✅ CEK KONFLIK: Apakah ada konflik dengan schedule existing
        if (DoctorSchedule::hasConflict($doctorName, $serviceId, $days, $startTime, $endTime)) {
            Notification::make()
                ->title('⚠️ Konflik Jadwal')
                ->body('Jadwal bertabrakan dengan jadwal yang sudah ada untuk dokter ini')
                ->warning()
                ->send();
            return new DoctorSchedule();
        }

        try {
            // ✅ CREATE: Buat satu record dengan multiple days
            $schedule = DoctorSchedule::create([
                'doctor_name' => $doctorName,
                'service_id' => $serviceId,
                'days' => $days, // Array of days
                'start_time' => $startTime,
                'end_time' => $endTime,
                'is_active' => $data['is_active'] ?? true,
                'foto' => $data['foto'] ?? null,
            ]);

            // ✅ SUCCESS NOTIFICATION
            $dayNames = array_map(function($day) {
                $names = [
                    'monday' => 'Senin',
                    'tuesday' => 'Selasa',
                    'wednesday' => 'Rabu',
                    'thursday' => 'Kamis',
                    'friday' => 'Jumat',
                    'saturday' => 'Sabtu',
                    'sunday' => 'Minggu',
                ];
                return $names[$day] ?? $day;
            }, $days);

            Notification::make()
                ->title('✅ Jadwal Berhasil Dibuat')
                ->body("Jadwal untuk {$doctorName} pada hari " . implode(', ', $dayNames) . " berhasil disimpan")
                ->success()
                ->duration(5000)
                ->send();

            return $schedule;

        } catch (\Exception $e) {
            Notification::make()
                ->title('❌ Error')
                ->body('Gagal menyimpan jadwal: ' . $e->getMessage())
                ->danger()
                ->send();
            
            return new DoctorSchedule();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return null; // Disable default notification
    }

    /**
     * ✅ VALIDATION: Form validation sebelum submit
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Validasi data sebelum proses
        if (empty($data['days'])) {
            throw new \Exception('Harap pilih minimal satu hari praktik');
        }

        if (empty($data['doctor_name'])) {
            throw new \Exception('Nama dokter harus diisi');
        }

        if (empty($data['service_id'])) {
            throw new \Exception('Poli harus dipilih');
        }

        if (empty($data['start_time']) || empty($data['end_time'])) {
            throw new \Exception('Jam praktik harus diisi');
        }

        return $data;
    }
}