<?php
// File: app/Filament/Pages/QueueKiosk.php - UPDATED dengan KTP input

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
use Filament\Forms\Form;
use Illuminate\Support\Facades\Validator;

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
    public $showKtpForm = false;

    protected ThermalPrinterService $thermalPrinterService;
    protected QueueService $queueService;

    public function __construct()
    {
        $this->thermalPrinterService = app(ThermalPrinterService::class);
        $this->queueService = app(QueueService::class);
    }

    /**
     * ✅ FORM untuk input KTP dan data pasien
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
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
     * ✅ SHOW form untuk input KTP
     */
    public function showKtpInput($serviceId)
    {
        $this->service_id = $serviceId;
        $this->showKtpForm = true;
        $this->data = [
            'nomor_ktp' => '',
            'name' => '',
            'phone' => '',
        ];
    }

    /**
     * ✅ SUBMIT form dan buat antrian
     */
    public function submit()
    {
        // Validasi form data
        $validator = Validator::make($this->data, [
            'nomor_ktp' => 'required|digits:16',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:15',
        ], [
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
            // Buat antrian dengan data KTP
            $newQueue = $this->queueService->addQueueWithKtp(
                $this->service_id,
                $this->data['nomor_ktp'],
                [
                    'name' => $this->data['name'],
                    'phone' => $this->data['phone'],
                ]
            );

            $service = $newQueue->service;
            $user = $newQueue->user;

            // Notification sukses
            Notification::make()
                ->title('Antrian Berhasil Dibuat!')
                ->body("Nomor Antrian: {$newQueue->number} | No. RM: {$user->medical_record_number}")
                ->success()
                ->duration(10000)
                ->send();

            // Print ticket
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
     * ✅ PRINT ticket thermal
     */
    private function printTicket($queue)
    {
        $user = $queue->user;
        $service = $queue->service;

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
            ['text' => $queue->created_at->format('d-M-Y H:i'), 'align' => 'center'],
            ['text' => '-----------------', 'align' => 'center'],
            ['text' => 'Mohon menunggu panggilan', 'align' => 'center'],
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
}