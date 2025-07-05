<?php
// File: app/Filament/Pages/QueueKiosk.php - FIXED NOMOR PER TANGGAL

namespace App\Filament\Pages;

use App\Models\Service;
use App\Services\QueueService;
use App\Services\ThermalPrinterService;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class QueueKiosk extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Ambil Antrian';
    protected static string $view = 'filament.pages.queue-kiosk';
    protected static string $layout = 'filament.layouts.base-kiosk';

    // ✅ FORM DATA PROPERTIES
    public ?array $data = [];
    public $service_id = null;
    public $nomor_ktp = '';
    public $name = '';
    public $phone = '';
    public $tanggal_antrian = null; // ✅ TAMBAH tanggal antrian
    public $showKtpForm = false;

    protected ThermalPrinterService $thermalPrinterService;
    protected QueueService $queueService;

    public function __construct()
    {
        $this->thermalPrinterService = app(ThermalPrinterService::class);
        $this->queueService = app(QueueService::class);
    }

    /**
     * ✅ FORM untuk input KTP, data pasien, dan tanggal antrian
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('tanggal_antrian')
                    ->label('Tanggal Antrian')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->minDate(today())
                    ->maxDate(today()->addDays(30))
                    ->default(today())
                    ->helperText('Pilih tanggal untuk antrian Anda')
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        // Update preview nomor antrian ketika tanggal berubah
                        if ($state && $this->service_id) {
                            $this->updateQueueNumberPreview($this->service_id, $state);
                        }
                    }),

                TextInput::make('nomor_ktp')
                    ->label('Nomor KTP')
                    ->required()
                    ->numeric()
                    ->length(16)
                    ->placeholder('Masukkan 16 digit nomor KTP')
                    ->helperText('Contoh: 3201012345678901')
                    ->reactive()
                    ->afterStateUpdated(function ($state) {
                        if (strlen($state) === 16) {
                            $this->searchExistingUser($state);
                        }
                    }),

                TextInput::make('name')
                    ->label('Nama Lengkap')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Masukkan nama lengkap'),

                TextInput::make('phone')
                    ->label('Nomor HP (Opsional)')
                    ->tel()
                    ->maxLength(15)
                    ->placeholder('08123456789'),
            ])
            ->statePath('data')
            ->columns(1);
    }

    /**
     * ✅ SEARCH existing user berdasarkan KTP
     */
    public function searchExistingUser(string $ktp): void
    {
        if (strlen($ktp) !== 16) return;

        $existingUser = $this->queueService->searchUserByIdentifier($ktp);
        
        if ($existingUser) {
            // Auto-fill data jika user sudah ada
            $this->data['name'] = $existingUser->name;
            $this->data['phone'] = $existingUser->phone;
            
            Notification::make()
                ->title('Data Ditemukan')
                ->body("Pasien: {$existingUser->name} | No. RM: {$existingUser->medical_record_number}")
                ->success()
                ->duration(3000)
                ->send();
        }
    }

    /**
     * ✅ SHOW form untuk input KTP dengan tanggal antrian
     */
    public function showKtpInput($serviceId)
    {
        $this->service_id = $serviceId;
        $this->showKtpForm = true;
        $this->data = [
            'tanggal_antrian' => today()->format('Y-m-d'),
            'nomor_ktp' => '',
            'name' => '',
            'phone' => '',
        ];

        // ✅ PREVIEW nomor antrian untuk hari ini
        $this->updateQueueNumberPreview($serviceId, today());
    }

    /**
     * ✅ NEW: Update preview nomor antrian
     */
    public function updateQueueNumberPreview($serviceId, $tanggalAntrian): void
    {
        try {
            $previewNumber = $this->queueService->generateNumberForDate($serviceId, $tanggalAntrian);
            $service = Service::find($serviceId);
            
            $currentQueues = \App\Models\Queue::where('service_id', $serviceId)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->count();
            
            $position = $currentQueues + 1;
            $tanggalFormatted = Carbon::parse($tanggalAntrian)->format('d F Y');
            
            Notification::make()
                ->title('Preview Nomor Antrian')
                ->body("Nomor: {$previewNumber} | Posisi: {$position} | Tanggal: {$tanggalFormatted}")
                ->info()
                ->duration(5000)
                ->send();
                
        } catch (\Exception $e) {
            // Silent error, tidak mengganggu UX
        }
    }

    /**
     * ✅ SUBMIT form dan buat antrian dengan tanggal yang dipilih
     */
    public function submit()
    {
        // Validasi form data
        $validator = Validator::make($this->data, [
            'tanggal_antrian' => 'required|date|after_or_equal:today',
            'nomor_ktp' => 'required|digits:16',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:15',
        ], [
            'tanggal_antrian.required' => 'Tanggal antrian harus dipilih',
            'tanggal_antrian.after_or_equal' => 'Tanggal antrian tidak boleh lebih awal dari hari ini',
            'nomor_ktp.required' => 'Nomor KTP harus diisi',
            'nomor_ktp.digits' => 'Nomor KTP harus 16 digit',
            'name.required' => 'Nama harus diisi',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                Notification::make()
                    ->title('Error Validasi')
                    ->body($error)
                    ->danger()
                    ->duration(5000)
                    ->send();
            }
            return;
        }

        if (!$this->service_id) {
            Notification::make()
                ->title('Error')
                ->body('Layanan belum dipilih')
                ->danger()
                ->send();
            return;
        }

        try {
            $tanggalAntrian = $this->data['tanggal_antrian'];
            
            // ✅ CEK: Apakah user sudah punya antrian di tanggal tersebut
            $existingUser = $this->queueService->searchUserByIdentifier($this->data['nomor_ktp']);
            if ($existingUser) {
                $existingQueue = \App\Models\Queue::where('user_id', $existingUser->id)
                    ->whereIn('status', ['waiting', 'serving'])
                    ->whereDate('tanggal_antrian', $tanggalAntrian)
                    ->first();
                    
                if ($existingQueue) {
                    Notification::make()
                        ->title('Antrian Sudah Ada')
                        ->body("Anda sudah memiliki antrian #{$existingQueue->number} pada tanggal " . Carbon::parse($tanggalAntrian)->format('d F Y'))
                        ->warning()
                        ->duration(8000)
                        ->send();
                    return;
                }
            }

            // ✅ PERBAIKAN UTAMA: Buat antrian dengan tanggal_antrian
            $newQueue = $this->queueService->addQueueWithKtp(
                $this->service_id,
                $this->data['nomor_ktp'],
                [
                    'name' => $this->data['name'],
                    'phone' => $this->data['phone'],
                ],
                $tanggalAntrian // ✅ TAMBAH tanggal antrian
            );

            $service = $newQueue->service;
            $user = $newQueue->user;
            $tanggalFormatted = Carbon::parse($tanggalAntrian)->format('d F Y');

            // Notification sukses dengan info tanggal
            $successMessage = "Antrian berhasil dibuat!";
            $successMessage .= "\nNomor: {$newQueue->number}";
            $successMessage .= "\nTanggal: {$tanggalFormatted}";
            $successMessage .= "\nNo. RM: {$user->medical_record_number}";
            
            if (Carbon::parse($tanggalAntrian)->isToday()) {
                $successMessage .= "\n⏰ Untuk hari ini";
            } else {
                $successMessage .= "\n📅 Untuk tanggal {$tanggalFormatted}";
            }

            Notification::make()
                ->title('Antrian Berhasil Dibuat!')
                ->body($successMessage)
                ->success()
                ->duration(10000)
                ->send();

            // Print ticket dengan info tanggal
            $this->printTicket($newQueue);

            // Reset form
            $this->resetForm();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('Terjadi kesalahan: ' . $e->getMessage())
                ->danger()
                ->duration(8000)
                ->send();
        }
    }

    /**
     * ✅ PRINT ticket thermal dengan info tanggal antrian
     */
    private function printTicket($queue)
    {
        $user = $queue->user;
        $service = $queue->service;
        $tanggalAntrian = Carbon::parse($queue->tanggal_antrian);

        $text = $this->thermalPrinterService->createText([
            ['text' => 'Klinik Pratama Hadiana Sehat', 'align' => 'center'],
            ['text' => 'Jl. Raya Banjaran Barat No.658A', 'align' => 'center'],
            ['text' => '-----------------', 'align' => 'center'],
            ['text' => 'NOMOR ANTRIAN', 'align' => 'center'],
            ['text' => $queue->number, 'align' => 'center', 'style' => 'double'],
            ['text' => '-----------------', 'align' => 'center'],
            ['text' => 'Layanan: ' . $service->name, 'align' => 'center'],
            ['text' => 'Pasien: ' . $user->name, 'align' => 'center'],
            ['text' => 'No. RM: ' . $user->medical_record_number, 'align' => 'center'],
            ['text' => '-----------------', 'align' => 'center'],
            ['text' => 'TANGGAL ANTRIAN:', 'align' => 'center'],
            ['text' => $tanggalAntrian->format('d F Y'), 'align' => 'center', 'style' => 'double'],
            ['text' => $tanggalAntrian->isToday() ? '(HARI INI)' : '(' . $tanggalAntrian->diffForHumans() . ')', 'align' => 'center'],
            ['text' => '-----------------', 'align' => 'center'],
            ['text' => 'Diambil: ' . $queue->created_at->format('d-M-Y H:i'), 'align' => 'center'],
            ['text' => '-----------------', 'align' => 'center'],
            ['text' => 'Mohon hadir sesuai tanggal', 'align' => 'center'],
            ['text' => 'antrian yang tertera', 'align' => 'center'],
            ['text' => 'Terima kasih', 'align' => 'center']
        ]);

        $this->dispatch("print-start", $text);
    }

    /**
     * ✅ RESET form setelah submit
     */
    public function resetForm()
    {
        $this->showKtpForm = false;
        $this->service_id = null;
        $this->tanggal_antrian = null;
        $this->data = [];
        $this->form->fill([]);
    }

    /**
     * ✅ LEGACY method untuk backward compatibility
     */
    public function print($serviceId)
    {
        // Redirect ke form KTP input
        $this->showKtpInput($serviceId);
    }

    public function getViewData(): array
    {
        return [
            'services' => Service::where('is_active', true)->get(),
            'showKtpForm' => $this->showKtpForm,
            'selectedService' => $this->service_id ? Service::find($this->service_id) : null,
        ];
    }

    /**
     * ✅ NEW: Method untuk cek slot tersedia per tanggal
     */
    public function checkAvailableSlots($serviceId, $tanggalAntrian)
    {
        try {
            $service = Service::find($serviceId);
            $existingQueues = \App\Models\Queue::where('service_id', $serviceId)
                ->whereDate('tanggal_antrian', $tanggalAntrian)
                ->count();
            
            $maxSlots = pow(10, $service->padding) - 1;
            $availableSlots = max(0, $maxSlots - $existingQueues);
            
            if ($availableSlots <= 0) {
                Notification::make()
                    ->title('Slot Penuh')
                    ->body("Antrian untuk {$service->name} pada tanggal " . Carbon::parse($tanggalAntrian)->format('d F Y') . " sudah penuh")
                    ->warning()
                    ->duration(5000)
                    ->send();
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            return false;
        }
    }
}