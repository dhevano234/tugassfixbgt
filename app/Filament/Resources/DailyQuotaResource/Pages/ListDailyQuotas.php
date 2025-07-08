<?php
// File: app/Filament/Resources/DailyQuotaResource/Pages/ListDailyQuotas.php

namespace App\Filament\Resources\DailyQuotaResource\Pages;

use App\Filament\Resources\DailyQuotaResource;
use App\Models\DailyQuota;
use App\Models\DoctorSchedule;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class ListDailyQuotas extends ListRecords
{
    protected static string $resource = DailyQuotaResource::class;

    protected static ?string $title = 'Kuota Antrian Harian';

    protected function getHeaderActions(): array
    {
        return [
            // ✅ ACTION: Buat kuota untuk semua dokter hari ini
            Actions\Action::make('create_today_quotas')
                ->label('Buat Kuota Hari Ini')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->action(function () {
                    $today = today();
                    $dayOfWeek = strtolower($today->format('l'));
                    
                    // Get all active doctors yang praktik hari ini
                    $todayDoctors = DoctorSchedule::where('is_active', true)
                        ->whereJsonContains('days', $dayOfWeek)
                        ->get();
                    
                    $created = 0;
                    $existing = 0;
                    
                    foreach ($todayDoctors as $doctor) {
                        $quota = DailyQuota::where('doctor_schedule_id', $doctor->id)
                            ->where('quota_date', $today)
                            ->first();
                        
                        if (!$quota) {
                            DailyQuota::create([
                                'doctor_schedule_id' => $doctor->id,
                                'quota_date' => $today,
                                'total_quota' => 20, // Default quota
                                'used_quota' => 0,
                                'is_active' => true,
                            ]);
                            $created++;
                        } else {
                            $existing++;
                        }
                    }
                    
                    if ($created > 0) {
                        \Filament\Notifications\Notification::make()
                            ->title('Kuota Hari Ini Berhasil Dibuat')
                            ->body("Dibuat: {$created} kuota baru" . ($existing > 0 ? ", {$existing} sudah ada" : ""))
                            ->success()
                            ->duration(5000)
                            ->send();
                    } else {
                        \Filament\Notifications\Notification::make()
                            ->title('Kuota Sudah Lengkap')
                            ->body("Semua dokter hari ini sudah memiliki kuota ({$existing} kuota)")
                            ->info()
                            ->send();
                    }
                })
                ->requiresConfirmation()
                ->modalHeading('Buat Kuota untuk Semua Dokter Hari Ini')
                ->modalDescription('Akan membuat kuota otomatis untuk semua dokter yang praktik hari ini (yang belum ada kuotanya)')
                ->modalSubmitActionLabel('Ya, Buat Kuota'),

            Actions\CreateAction::make()
                ->label('Tambah Kuota Manual')
                ->icon('heroicon-o-plus'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua Kuota')
                ->icon('heroicon-m-queue-list')
                ->badge(DailyQuota::count()),

            'today' => Tab::make('Hari Ini')
                ->icon('heroicon-m-calendar')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('quota_date', today()))
                ->badge(DailyQuota::whereDate('quota_date', today())->count())
                ->badgeColor('success'),

            'tomorrow' => Tab::make('Besok')
                ->icon('heroicon-m-arrow-right')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('quota_date', today()->addDay()))
                ->badge(DailyQuota::whereDate('quota_date', today()->addDay())->count())
                ->badgeColor('info'),

            'this_week' => Tab::make('Minggu Ini')
                ->icon('heroicon-m-calendar-days')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereBetween('quota_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ]))
                ->badge(DailyQuota::whereBetween('quota_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])->count()),

            'available' => Tab::make('Masih Tersedia')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('used_quota < total_quota')
                    ->where('is_active', true))
                ->badge(DailyQuota::whereRaw('used_quota < total_quota')
                    ->where('is_active', true)->count())
                ->badgeColor('success'),

            'nearly_full' => Tab::make('Hampir Penuh')
                ->icon('heroicon-m-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('(used_quota / total_quota * 100) >= 90')
                    ->whereRaw('used_quota < total_quota')
                    ->where('is_active', true))
                ->badge(DailyQuota::whereRaw('(used_quota / total_quota * 100) >= 90')
                    ->whereRaw('used_quota < total_quota')
                    ->where('is_active', true)->count())
                ->badgeColor('warning'),

            'full' => Tab::make('Sudah Penuh')
                ->icon('heroicon-m-x-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('used_quota >= total_quota')
                    ->where('is_active', true))
                ->badge(DailyQuota::whereRaw('used_quota >= total_quota')
                    ->where('is_active', true)->count())
                ->badgeColor('danger'),
        ];
    }
}

// =================================================================

// File: app/Filament/Resources/DailyQuotaResource/Pages/CreateDailyQuota.php

namespace App\Filament\Resources\DailyQuotaResource\Pages;

use App\Filament\Resources\DailyQuotaResource;
use App\Models\DailyQuota;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateDailyQuota extends CreateRecord
{
    protected static string $resource = DailyQuotaResource::class;

    protected static ?string $title = 'Buat Kuota Harian';

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Simpan Kuota'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Auto-calculate used quota berdasarkan antrian yang sudah ada
        if (isset($data['doctor_schedule_id']) && isset($data['quota_date'])) {
            $existingQueues = \App\Models\Queue::where('doctor_id', $data['doctor_schedule_id'])
                ->whereDate('tanggal_antrian', $data['quota_date'])
                ->whereIn('status', ['waiting', 'serving', 'finished'])
                ->count();
            
            $data['used_quota'] = $existingQueues;
        }
        
        return $data;
    }

    protected function afterCreate(): void
    {
        $quota = $this->record;
        
        Notification::make()
            ->title('Kuota Berhasil Dibuat')
            ->body("Kuota untuk {$quota->doctorSchedule->doctor_name} pada {$quota->formatted_date}: {$quota->formatted_quota}")
            ->success()
            ->duration(5000)
            ->send();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return null; // Disable default notification
    }
}

// =================================================================

// File: app/Filament/Resources/DailyQuotaResource/Pages/EditDailyQuota.php

namespace App\Filament\Resources\DailyQuotaResource\Pages;

use App\Filament\Resources\DailyQuotaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class EditDailyQuota extends EditRecord
{
    protected static string $resource = DailyQuotaResource::class;

    protected static ?string $title = 'Edit Kuota Harian';

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->label('Simpan Perubahan'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()
                ->label('Lihat Detail'),

            // ✅ ACTION: Sync kuota dengan antrian
            Actions\Action::make('sync_quota')
                ->label('Sinkron dengan Antrian')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->action(function () {
                    $this->record->updateUsedQuota();
                    $this->record->refresh();
                    
                    Notification::make()
                        ->title('Kuota Berhasil Disinkron')
                        ->body("Kuota terpakai: {$this->record->used_quota}/{$this->record->total_quota}")
                        ->success()
                        ->send();
                        
                    // Refresh form
                    $this->fillForm();
                })
                ->requiresConfirmation()
                ->modalHeading('Sinkron Kuota')
                ->modalDescription('Akan menghitung ulang kuota terpakai berdasarkan antrian yang ada'),

            Actions\DeleteAction::make()
                ->label('Hapus Kuota')
                ->requiresConfirmation(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Jika total_quota berubah, pastikan tidak kurang dari used_quota
        if (isset($data['total_quota']) && $data['total_quota'] < $this->record->used_quota) {
            Notification::make()
                ->title('Peringatan: Total Kuota Terlalu Kecil')
                ->body("Total kuota ({$data['total_quota']}) lebih kecil dari yang sudah terpakai ({$this->record->used_quota})")
                ->warning()
                ->duration(8000)
                ->send();
        }
        
        return $data;
    }

    protected function afterSave(): void
    {
        $quota = $this->record;
        
        Notification::make()
            ->title('Kuota Berhasil Diperbarui')
            ->body("Kuota {$quota->doctorSchedule->doctor_name} - {$quota->formatted_date}: {$quota->formatted_quota}")
            ->success()
            ->duration(5000)
            ->send();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return null; // Disable default notification
    }
}

// =================================================================

// File: app/Filament/Resources/DailyQuotaResource/Pages/ViewDailyQuota.php

namespace App\Filament\Resources\DailyQuotaResource\Pages;

use App\Filament\Resources\DailyQuotaResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewDailyQuota extends ViewRecord
{
    protected static string $resource = DailyQuotaResource::class;

    protected static ?string $title = 'Detail Kuota Harian';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Edit Kuota'),
            
            Actions\Action::make('sync_quota')
                ->label('Sinkron Kuota')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->action(function () {
                    $this->record->updateUsedQuota();
                    $this->record->refresh();
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Kuota Berhasil Disinkron')
                        ->success()
                        ->send();
                }),
                
            Actions\DeleteAction::make()
                ->label('Hapus'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // ✅ SECTION: Informasi Umum
                Infolists\Components\Section::make('Informasi Kuota')
                    ->icon('heroicon-o-calendar-days')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('quota_date')
                                    ->label('Tanggal Kuota')
                                    ->date('l, d F Y')
                                    ->badge()
                                    ->color('info'),
                                    
                                Infolists\Components\TextEntry::make('day_name')
                                    ->label('Hari')
                                    ->badge()
                                    ->color('primary'),
                                    
                                Infolists\Components\TextEntry::make('is_active')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Tidak Aktif')
                                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                            ]),
                    ]),

                // ✅ SECTION: Informasi Dokter
                Infolists\Components\Section::make('Informasi Dokter')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('doctorSchedule.doctor_name')
                                    ->label('Nama Dokter')
                                    ->weight('semibold')
                                    ->size('lg'),
                                    
                                Infolists\Components\TextEntry::make('doctorSchedule.service.name')
                                    ->label('Poli/Layanan')
                                    ->badge()
                                    ->color('info'),
                                    
                                Infolists\Components\TextEntry::make('doctorSchedule.time_range')
                                    ->label('Jam Praktik')
                                    ->badge()
                                    ->color('success'),
                            ]),
                    ]),

                // ✅ SECTION: Detail Kuota
                Infolists\Components\Section::make('Detail Kuota')
                    ->icon('heroicon-o-chart-bar')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_quota')
                                    ->label('Total Kuota')
                                    ->badge()
                                    ->color('primary')
                                    ->suffix(' pasien'),
                                    
                                Infolists\Components\TextEntry::make('used_quota')
                                    ->label('Kuota Terpakai')
                                    ->badge()
                                    ->color('warning')
                                    ->suffix(' pasien'),
                                    
                                Infolists\Components\TextEntry::make('available_quota')
                                    ->label('Kuota Tersisa')
                                    ->badge()
                                    ->color(fn ($record) => $record->available_quota > 0 ? 'success' : 'danger')
                                    ->suffix(' pasien'),
                            ]),
                            
                        Infolists\Components\TextEntry::make('usage_percentage')
                            ->label('Persentase Terpakai')
                            ->badge()
                            ->color(fn ($record) => $record->status_color)
                            ->suffix('%'),
                            
                        Infolists\Components\TextEntry::make('status_label')
                            ->label('Status Kuota')
                            ->badge()
                            ->color(fn ($record) => $record->status_color),
                    ]),

                // ✅ SECTION: Catatan
                Infolists\Components\Section::make('Catatan')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->label('Catatan Admin')
                            ->columnSpanFull()
                            ->prose()
                            ->placeholder('Tidak ada catatan'),
                    ])
                    ->visible(fn ($record) => !empty($record->notes)),

                // ✅ SECTION: Informasi Sistem
                Infolists\Components\Section::make('Informasi Sistem')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Dibuat')
                                    ->dateTime('d/m/Y H:i')
                                    ->since(),
                                    
                                Infolists\Components\TextEntry::make('updated_at')
                                    ->label('Terakhir Diubah')
                                    ->dateTime('d/m/Y H:i')
                                    ->since(),
                            ]),
                    ])
                    ->collapsed()
                    ->collapsible(),
            ]);
    }
}