<?php
// File: app/Filament/Resources/DailyQuotaResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyQuotaResource\Pages;
use App\Models\DailyQuota;
use App\Models\DoctorSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Carbon\Carbon;

class DailyQuotaResource extends Resource
{
    protected static ?string $model = DailyQuota::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Administrasi';

    protected static ?string $navigationLabel = 'Kuota Antrian';

    protected static ?string $modelLabel = 'Kuota Harian';

    protected static ?string $pluralModelLabel = 'Kuota Antrian';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // ✅ SECTION: Informasi Dokter & Tanggal
                Forms\Components\Section::make('Informasi Kuota')
                    ->description('Pilih dokter dan tanggal untuk mengatur kuota antrian')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('doctor_schedule_id')
                                    ->label('Dokter')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->relationship('doctorSchedule', 'doctor_name')
                                    ->getOptionLabelFromRecordUsing(function ($record) {
                                        $serviceName = $record->service->name ?? 'Unknown';
                                        $timeRange = $record->start_time->format('H:i') . ' - ' . $record->end_time->format('H:i');
                                        return "{$record->doctor_name} - {$serviceName} ({$timeRange})";
                                    })
                                    ->helperText('Pilih dokter yang akan diberi kuota')
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        // Auto-check existing quota untuk kombinasi dokter + tanggal
                                        $quotaDate = $get('quota_date');
                                        if ($state && $quotaDate) {
                                            $existing = DailyQuota::where('doctor_schedule_id', $state)
                                                ->where('quota_date', $quotaDate)
                                                ->first();
                                            
                                            if ($existing) {
                                                $doctorSchedule = DoctorSchedule::find($state);
                                                Notification::make()
                                                    ->title('Kuota Sudah Ada')
                                                    ->body("Kuota untuk {$doctorSchedule?->doctor_name} pada tanggal {$existing->formatted_date} sudah ada ({$existing->formatted_quota})")
                                                    ->warning()
                                                    ->duration(5000)
                                                    ->send();
                                            }
                                        }
                                    }),

                                Forms\Components\DatePicker::make('quota_date')
                                    ->label('Tanggal Kuota')
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->minDate(today())
                                    ->maxDate(today()->addMonths(3))
                                    ->default(today())
                                    ->helperText('Pilih tanggal untuk kuota antrian')
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $doctorId = $get('doctor_schedule_id');
                                        if ($state && $doctorId) {
                                            $existing = DailyQuota::where('doctor_schedule_id', $doctorId)
                                                ->where('quota_date', $state)
                                                ->first();
                                            
                                            if ($existing) {
                                                $doctorSchedule = DoctorSchedule::find($doctorId);
                                                Notification::make()
                                                    ->title('Kuota Sudah Ada')
                                                    ->body("Kuota untuk {$doctorSchedule?->doctor_name} pada tanggal " . Carbon::parse($state)->format('d F Y') . " sudah ada")
                                                    ->warning()
                                                    ->duration(5000)
                                                    ->send();
                                            }
                                        }
                                    }),
                            ]),
                    ]),

                // ✅ SECTION: Pengaturan Kuota
                Forms\Components\Section::make('Pengaturan Kuota')
                    ->description('Atur jumlah kuota dan status')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('total_quota')
                                    ->label('Total Kuota')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->default(20)
                                    ->suffix('pasien')
                                    ->helperText('Maksimal pasien yang bisa antri'),

                                Forms\Components\TextInput::make('used_quota')
                                    ->label('Kuota Terpakai')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->suffix('pasien')
                                    ->helperText('Jumlah yang sudah booking (auto-calculated)')
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\Placeholder::make('available_quota_display')
                                    ->label('Kuota Tersisa')
                                    ->content(function (callable $get) {
                                        $total = $get('total_quota') ?? 0;
                                        $used = $get('used_quota') ?? 0;
                                        $available = max(0, $total - $used);
                                        
                                        $color = $available > 5 ? 'text-green-600' : ($available > 0 ? 'text-yellow-600' : 'text-red-600');
                                        
                                        return new \Illuminate\Support\HtmlString(
                                            "<span class='font-semibold {$color}'>{$available} pasien</span>"
                                        );
                                    }),
                            ]),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->helperText('Kuota hanya berlaku jika status aktif'),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->placeholder('Catatan tambahan untuk kuota ini...')
                            ->helperText('Opsional: catatan untuk admin lain'),
                    ]),

                // ✅ SECTION: Informasi Tambahan (hanya untuk edit)
                Forms\Components\Section::make('Informasi Statistik')
                    ->description('Data penggunaan kuota')
                    ->schema([
                        Forms\Components\Placeholder::make('usage_stats')
                            ->label('Statistik Penggunaan')
                            ->content(function ($record) {
                                if (!$record) return 'Data belum tersedia';
                                
                                $percentage = $record->usage_percentage;
                                $statusColor = $record->status_color;
                                $statusLabel = $record->status_label;
                                
                                return new \Illuminate\Support\HtmlString("
                                    <div class='space-y-2'>
                                        <div class='flex justify-between items-center'>
                                            <span>Persentase Terpakai:</span>
                                            <span class='font-semibold'>{$percentage}%</span>
                                        </div>
                                        <div class='w-full bg-gray-200 rounded-full h-2'>
                                            <div class='bg-blue-600 h-2 rounded-full' style='width: {$percentage}%'></div>
                                        </div>
                                        <div class='flex justify-between items-center'>
                                            <span>Status:</span>
                                            <span class='px-2 py-1 rounded text-xs font-medium bg-{$statusColor}-100 text-{$statusColor}-800'>
                                                {$statusLabel}
                                            </span>
                                        </div>
                                    </div>
                                ");
                            }),
                    ])
                    ->visible(fn ($record) => $record !== null)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // ✅ KOLOM: Tanggal dengan hari
                Tables\Columns\TextColumn::make('quota_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable()
                    ->description(fn (DailyQuota $record): string => $record->day_name)
                    ->badge()
                    ->color(fn (DailyQuota $record): string => 
                        $record->quota_date->isToday() ? 'success' : 
                        ($record->quota_date->isFuture() ? 'info' : 'gray')
                    ),

                // ✅ KOLOM: Informasi Dokter
                Tables\Columns\TextColumn::make('doctorSchedule.doctor_name')
                    ->label('Dokter')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(function (DailyQuota $record): string {
                        $service = $record->doctorSchedule->service->name ?? 'Unknown';
                        $time = $record->doctorSchedule->time_range ?? 'Unknown';
                        return "{$service} • {$time}";
                    })
                    ->wrap(),

                // ✅ KOLOM: Kuota dengan progress bar
                Tables\Columns\TextColumn::make('quota_usage')
                    ->label('Kuota')
                    ->state(function (DailyQuota $record): string {
                        return $record->formatted_quota;
                    })
                    ->description(function (DailyQuota $record): string {
                        $percentage = $record->usage_percentage;
                        return "Terpakai: {$percentage}%";
                    })
                    ->badge()
                    ->color(fn (DailyQuota $record): string => $record->status_color),

                // ✅ KOLOM: Progress visual
                Tables\Columns\ViewColumn::make('progress')
                    ->label('Progress')
                    ->view('filament.tables.columns.quota-progress')
                    ->sortable(false),

                // ✅ KOLOM: Status
                Tables\Columns\TextColumn::make('status_label')
                    ->label('Status Kuota')
                    ->badge()
                    ->color(fn (DailyQuota $record): string => $record->status_color),

                // ✅ KOLOM: Status Aktif
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                // ✅ KOLOM: Catatan
                Tables\Columns\TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(30)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 30 ? $state : null;
                    })
                    ->placeholder('Tidak ada catatan')
                    ->toggleable(isToggledHiddenByDefault: true),

                // ✅ KOLOM: Dibuat
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('quota_date', 'desc')
            ->filters([
                // ✅ FILTER: Dokter
                Tables\Filters\SelectFilter::make('doctor_schedule_id')
                    ->label('Dokter')
                    ->relationship('doctorSchedule', 'doctor_name')
                    ->searchable()
                    ->preload(),

                // ✅ FILTER: Status Aktif
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua Kuota')
                    ->trueLabel('Hanya Aktif')
                    ->falseLabel('Hanya Tidak Aktif'),

                // ✅ FILTER: Berdasarkan waktu
                Tables\Filters\Filter::make('today')
                    ->label('Hari Ini')
                    ->query(fn ($query) => $query->whereDate('quota_date', today()))
                    ->toggle(),

                Tables\Filters\Filter::make('this_week')
                    ->label('Minggu Ini')
                    ->query(fn ($query) => $query->whereBetween('quota_date', [
                        now()->startOfWeek(),
                        now()->endOfWeek()
                    ]))
                    ->toggle(),

                Tables\Filters\Filter::make('this_month')
                    ->label('Bulan Ini')
                    ->query(fn ($query) => $query->whereMonth('quota_date', now()->month)
                        ->whereYear('quota_date', now()->year))
                    ->toggle(),

                // ✅ FILTER: Status kuota
                Tables\Filters\Filter::make('available')
                    ->label('Masih Tersedia')
                    ->query(fn ($query) => $query->whereRaw('used_quota < total_quota'))
                    ->toggle(),

                Tables\Filters\Filter::make('full')
                    ->label('Sudah Penuh')
                    ->query(fn ($query) => $query->whereRaw('used_quota >= total_quota'))
                    ->toggle(),

                Tables\Filters\Filter::make('nearly_full')
                    ->label('Hampir Penuh (≥90%)')
                    ->query(fn ($query) => $query->whereRaw('(used_quota / total_quota * 100) >= 90'))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->label('Lihat Detail'),

                    Tables\Actions\EditAction::make()
                        ->label('Edit Kuota'),

                    // ✅ ACTION: Sync kuota dengan antrian aktual
                    Tables\Actions\Action::make('sync_quota')
                        ->label('Sinkron Kuota')
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->action(function (DailyQuota $record) {
                            $record->updateUsedQuota();
                            
                            Notification::make()
                                ->title('Kuota Berhasil Disinkron')
                                ->body("Kuota terpakai: {$record->fresh()->used_quota}/{$record->total_quota}")
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Sinkron Kuota dengan Antrian')
                        ->modalDescription('Akan menghitung ulang jumlah kuota terpakai berdasarkan antrian yang ada.')
                        ->tooltip('Hitung ulang kuota terpakai'),

                    // ✅ ACTION: Copy kuota ke hari lain
                    Tables\Actions\Action::make('copy_quota')
                        ->label('Copy ke Tanggal Lain')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('warning')
                        ->form([
                            Forms\Components\DatePicker::make('new_date')
                                ->label('Tanggal Tujuan')
                                ->required()
                                ->native(false)
                                ->minDate(today())
                                ->maxDate(today()->addMonths(3)),
                            Forms\Components\Toggle::make('copy_notes')
                                ->label('Copy Catatan Juga')
                                ->default(true),
                        ])
                        ->action(function (DailyQuota $record, array $data) {
                            try {
                                DailyQuota::create([
                                    'doctor_schedule_id' => $record->doctor_schedule_id,
                                    'quota_date' => $data['new_date'],
                                    'total_quota' => $record->total_quota,
                                    'used_quota' => 0,
                                    'is_active' => $record->is_active,
                                    'notes' => $data['copy_notes'] ? $record->notes : null,
                                ]);

                                Notification::make()
                                    ->title('Kuota Berhasil Dicopy')
                                    ->body("Kuota untuk " . Carbon::parse($data['new_date'])->format('d F Y') . " berhasil dibuat")
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Gagal Copy Kuota')
                                    ->body('Mungkin kuota untuk tanggal tersebut sudah ada')
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->tooltip('Salin kuota ke tanggal lain'),

                    Tables\Actions\DeleteAction::make()
                        ->label('Hapus')
                        ->requiresConfirmation(),
                ])
                ->label('Aksi')
                ->icon('heroicon-m-ellipsis-vertical')
                ->size('sm')
                ->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // ✅ BULK ACTION: Sync multiple quotas
                    Tables\Actions\BulkAction::make('sync_all')
                        ->label('Sinkron Semua')
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->action(function ($records) {
                            $synced = 0;
                            foreach ($records as $record) {
                                $record->updateUsedQuota();
                                $synced++;
                            }
                            
                            Notification::make()
                                ->title('Sinkronisasi Selesai')
                                ->body("{$synced} kuota berhasil disinkron")
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Sinkron Multiple Kuota')
                        ->modalDescription('Akan menghitung ulang semua kuota yang dipilih'),

                    // ✅ BULK ACTION: Set status aktif
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Aktifkan')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(function ($records) {
                            $activated = 0;
                            foreach ($records as $record) {
                                if (!$record->is_active) {
                                    $record->update(['is_active' => true]);
                                    $activated++;
                                }
                            }
                            
                            Notification::make()
                                ->title('Kuota Diaktifkan')
                                ->body("{$activated} kuota berhasil diaktifkan")
                                ->success()
                                ->send();
                        }),

                    // ✅ BULK ACTION: Set status tidak aktif
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Nonaktifkan')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->action(function ($records) {
                            $deactivated = 0;
                            foreach ($records as $record) {
                                if ($record->is_active) {
                                    $record->update(['is_active' => false]);
                                    $deactivated++;
                                }
                            }
                            
                            Notification::make()
                                ->title('Kuota Dinonaktifkan')
                                ->body("{$deactivated} kuota berhasil dinonaktifkan")
                                ->warning()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Nonaktifkan Kuota')
                        ->modalDescription('Kuota yang dinonaktifkan tidak dapat digunakan untuk booking'),

                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Hapus Terpilih'),
                ]),
            ])
            ->searchable()
            ->striped()
            ->paginated([10, 25, 50])
            ->poll('30s') // Auto refresh setiap 30 detik
            ->emptyStateHeading('Belum ada kuota harian')
            ->emptyStateDescription('Buat kuota harian pertama untuk mulai mengatur kapasitas antrian dokter.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyQuotas::route('/'),
            'create' => Pages\CreateDailyQuota::route('/create'),
            'view' => Pages\ViewDailyQuota::route('/{record}'),
            'edit' => Pages\EditDailyQuota::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        // Badge untuk kuota hari ini yang hampir penuh atau penuh
        $criticalQuotas = static::getModel()::where('quota_date', today())
            ->where('is_active', true)
            ->whereRaw('(used_quota / total_quota * 100) >= 90')
            ->count();

        return $criticalQuotas > 0 ? (string) $criticalQuotas : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Kuota hampir penuh atau sudah penuh hari ini';
    }
}